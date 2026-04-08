<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\ThemeUpdateProvider;

/**
 * REST API for the ExamplePress theme update pipeline.
 *
 * Replaces the former UpdaterController (which managed the lifecycle of
 * the now-deleted examplepress-theme-update companion plugin). The
 * theme update logic lives directly in the kernel, so these endpoints
 * operate on the theme itself — no plugin-lifecycle semantics.
 *
 * Namespace: examplepress-mu/v1
 * Routes:
 *   GET    /theme-update/status    Current version, channel, pin, last-checked.
 *   GET    /theme-update/releases  Cached GitHub releases list.
 *   POST   /theme-update/check     Flush cache, re-fetch, return fresh status.
 *   POST   /theme-update/channel   Set channel (stable | development).
 *   POST   /theme-update/pin       Set or clear pinned version.
 *   POST   /theme-update/install   Install latest or specific version.
 *   POST   /theme-update/reinstall Reinstall current (or specific) version.
 */
final class ThemeUpdateController
{
    private const NS   = 'examplepress-mu/v1';
    private const BASE = '/theme-update';

    public static function register(): void
    {
        register_rest_route(self::NS, self::BASE . '/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'status'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, self::BASE . '/releases', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'releases'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, self::BASE . '/check', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'check'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, self::BASE . '/channel', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'setChannel'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'channel' => [
                    'type'     => 'string',
                    'enum'     => ['stable', 'development'],
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route(self::NS, self::BASE . '/pin', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'setPin'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'version' => [
                    'type'     => ['string', 'null'],
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route(self::NS, self::BASE . '/install', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'install'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'version' => [
                    'type'     => 'string',
                    'required' => false,
                ],
            ],
        ]);

        register_rest_route(self::NS, self::BASE . '/reinstall', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reinstall'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => [
                'version' => [
                    'type'     => 'string',
                    'required' => false,
                ],
            ],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('update_themes');
    }

    // ── Handlers ────────────────────────────────────────────────────

    public static function status(): \WP_REST_Response
    {
        return rest_ensure_response(ThemeUpdateProvider::getStatus());
    }

    public static function releases(): \WP_REST_Response
    {
        $releases = ThemeUpdateProvider::getReleases();
        if ($releases === null) {
            return rest_ensure_response(['releases' => []]);
        }

        $simplified = [];
        foreach ($releases as $r) {
            $tag = (string) ($r['tag_name'] ?? '');
            $simplified[] = [
                'tag'        => $tag,
                'name'       => (string) ($r['name'] ?? ''),
                'version'    => ThemeUpdateProvider::extractVersionFromTag($tag),
                'prerelease' => !empty($r['prerelease']),
                'date'       => (string) ($r['published_at'] ?? ''),
                'body'       => (string) ($r['body'] ?? ''),
            ];
        }
        return rest_ensure_response(['releases' => $simplified]);
    }

    public static function check(): \WP_REST_Response
    {
        ThemeUpdateProvider::flushCache();
        return rest_ensure_response(ThemeUpdateProvider::getStatus());
    }

    public static function setChannel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $channel = (string) $request->get_param('channel');
        if (!ThemeUpdateProvider::setChannel($channel)) {
            $source = ThemeUpdateProvider::channelSource();
            return new \WP_Error(
                'ep_channel_locked',
                sprintf('Channel is locked by a %s and cannot be changed via the admin UI.', $source),
                ['status' => 409]
            );
        }
        ThemeUpdateProvider::flushCache();
        return rest_ensure_response(ThemeUpdateProvider::getStatus());
    }

    public static function setPin(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $version = $request->get_param('version');

        if ($version !== null && $version !== '') {
            $version = sanitize_text_field((string) $version);

            // Validate the version exists in the releases list.
            $releases = ThemeUpdateProvider::getReleases();
            if ($releases !== null) {
                $found = false;
                foreach ($releases as $r) {
                    if (ThemeUpdateProvider::extractVersionFromTag((string) ($r['tag_name'] ?? '')) === $version) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return new \WP_Error(
                        'ep_version_not_found',
                        sprintf('Version %s was not found in the releases list.', $version),
                        ['status' => 404]
                    );
                }
            }
            ThemeUpdateProvider::setPinnedVersion($version);
        } else {
            ThemeUpdateProvider::setPinnedVersion(null);
        }

        ThemeUpdateProvider::flushCache();
        return rest_ensure_response(ThemeUpdateProvider::getStatus());
    }

    public static function install(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $version = $request->get_param('version');
        $version = $version ? sanitize_text_field((string) $version) : null;

        $result = ThemeUpdateProvider::installVersion($version);

        if (!$result['success']) {
            return new \WP_Error('ep_theme_install_failed', $result['message'], ['status' => 500]);
        }
        return rest_ensure_response($result);
    }

    public static function reinstall(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $version = $request->get_param('version');
        $version = $version ? sanitize_text_field((string) $version) : null;

        $result = ThemeUpdateProvider::reinstall($version);

        if (!$result['success']) {
            return new \WP_Error('ep_theme_reinstall_failed', $result['message'], ['status' => 500]);
        }
        return rest_ensure_response($result);
    }
}
