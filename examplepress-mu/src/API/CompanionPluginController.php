<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\GitHub;
use ExamplePress\MU\Infrastructure\Helpers;

/**
 * Shared base class for companion-plugin REST controllers.
 *
 * Currently the only consumer is `DemoController`. The base is kept as a
 * deliberate extension point so future companion plugins (analytics,
 * payment gateways, etc.) can register lifecycle endpoints by declaring
 * a small amount of identity (route prefix, repo slug, plugin file,
 * option keys, asset name) and inheriting identical install / uninstall /
 * check / settings / update / releases handlers.
 *
 * If you're adding a third subclass and find yourself fighting the shape,
 * inline the base back into its consumers — one consumer doesn't justify
 * an abstract layer, but two genuinely-similar consumers do.
 */
abstract class CompanionPluginController
{
    /** REST namespace + route prefix, e.g. 'updater' or 'demo'. */
    protected static string $routePrefix = '';

    /** Plugin file (e.g. 'examplepress-theme-update/examplepress-theme-update.php'). */
    protected static string $pluginFile = '';

    /** Plugin directory name under WP_PLUGIN_DIR (e.g. 'examplepress-theme-update'). */
    protected static string $pluginDir = '';

    /** GitHub owner/repo (e.g. 'webmultipliers/examplepress-theme-update'). */
    protected static string $repo = '';

    /** Release asset filename (e.g. 'examplepress-theme-update.zip'). */
    protected static string $assetName = '';

    /** Option key for the channel setting. */
    protected static string $channelOption = '';

    /** Option key for the pinned version setting. */
    protected static string $pinnedOption = '';

    /** Throttle transient key. */
    protected static string $throttleKey = '';

    /** Human-readable label, e.g. 'Updater' or 'Demo'. */
    protected static string $label = '';

    /** Per-class fetched-releases cache. */
    private static array $releaseCache = [];

    // ── Identity hooks (override in subclasses if needed) ──────────

    protected static function install_(): true|\WP_Error
    {
        // Subclasses must implement actual install via PluginManager.
        return new \WP_Error('not_implemented', 'install_() not implemented.');
    }

    protected static function statusFor(): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active(static::$pluginFile)) {
            return 'active';
        }
        if (array_key_exists(static::$pluginFile, get_plugins())) {
            return 'installed';
        }
        return 'not-installed';
    }

    /** Optional pre-uninstall guard (e.g. demo's foreign-plugin check). */
    protected static function uninstallGuard(string $pluginDirAbs): ?\WP_Error
    {
        return null;
    }

    // ── Route Registration ─────────────────────────────────────────

    public static function register(): void
    {
        $ns     = 'examplepress-mu/v1';
        $prefix = '/' . static::$routePrefix;

        register_rest_route($ns, $prefix . '/install', [
            'methods'             => 'POST',
            'callback'            => [static::class, 'install'],
            'permission_callback' => [static::class, 'permissionCheck'],
        ]);

        register_rest_route($ns, $prefix . '/uninstall', [
            'methods'             => 'POST',
            'callback'            => [static::class, 'uninstall'],
            'permission_callback' => [static::class, 'permissionCheck'],
        ]);

        register_rest_route($ns, $prefix . '/check', [
            'methods'             => 'POST',
            'callback'            => [static::class, 'check'],
            'permission_callback' => [static::class, 'permissionCheck'],
        ]);

        register_rest_route($ns, $prefix . '/settings', [
            'methods'             => 'GET',
            'callback'            => [static::class, 'getSettings'],
            'permission_callback' => [static::class, 'permissionCheck'],
        ]);

        register_rest_route($ns, $prefix . '/settings', [
            'methods'             => 'POST',
            'callback'            => [static::class, 'saveSettings'],
            'permission_callback' => [static::class, 'permissionCheck'],
            'args'                => [
                'channel' => [
                    'type'              => 'string',
                    'enum'              => ['stable', 'prerelease'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'pinned_version' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route($ns, $prefix . '/update', [
            'methods'             => 'POST',
            'callback'            => [static::class, 'update'],
            'permission_callback' => [static::class, 'permissionCheck'],
        ]);

        register_rest_route($ns, $prefix . '/releases', [
            'methods'             => 'GET',
            'callback'            => [static::class, 'releases'],
            'permission_callback' => [static::class, 'permissionCheck'],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    // ── Endpoints ──────────────────────────────────────────────────

    public static function install(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $status = static::statusFor();

        if ('active' === $status) {
            return rest_ensure_response([
                'success' => true,
                'status'  => 'active',
                'message' => static::$label . ' plugin is already installed and active.',
            ]);
        }

        if ('installed' === $status) {
            $result = activate_plugin(static::$pluginFile);

            if (is_wp_error($result)) {
                return rest_ensure_response([
                    'success' => false,
                    'status'  => 'installed',
                    'message' => static::$label . ' plugin is installed but could not be activated: ' . $result->get_error_message(),
                ]);
            }

            return rest_ensure_response([
                'success' => true,
                'status'  => 'active',
                'message' => static::$label . ' plugin activated.',
            ]);
        }

        $installed = static::install_();

        if (is_wp_error($installed)) {
            return new \WP_Error(
                strtolower(static::$routePrefix) . '_install_failed',
                'Failed to install ' . strtolower(static::$label) . ' plugin: ' . $installed->get_error_message(),
                ['status' => 500]
            );
        }

        $result = activate_plugin(static::$pluginFile);
        $final  = is_wp_error($result) ? 'installed' : 'active';

        if (static::$throttleKey) {
            delete_transient(static::$throttleKey);
        }

        return rest_ensure_response([
            'success' => true,
            'status'  => $final,
            'message' => 'active' === $final
                ? static::$label . ' plugin installed and activated.'
                : static::$label . ' plugin installed but could not be activated: ' . $result->get_error_message(),
        ]);
    }

    public static function uninstall(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . static::$pluginDir;

        if (!is_dir($plugin_dir)) {
            return rest_ensure_response([
                'success' => true,
                'status'  => 'not-installed',
                'message' => static::$label . ' plugin is not installed.',
            ]);
        }

        $guard = static::uninstallGuard($plugin_dir);
        if ($guard instanceof \WP_Error) {
            return $guard;
        }

        if (is_plugin_active(static::$pluginFile)) {
            deactivate_plugins(static::$pluginFile, true);
        }

        $fs = Helpers::filesystem(forceDirect: true);

        if (!$fs) {
            return new \WP_Error(
                'fs_unavailable',
                'Filesystem writes are not available on this host (no direct access).',
                ['status' => 501]
            );
        }

        if (!$fs->delete($plugin_dir, true)) {
            return new \WP_Error(
                strtolower(static::$routePrefix) . '_delete_failed',
                'Failed to remove the ' . strtolower(static::$label) . ' plugin directory.',
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'status'  => 'not-installed',
            'message' => static::$label . ' plugin removed.',
        ]);
    }

    public static function check(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins         = get_plugins();
        $current_version = $plugins[static::$pluginFile]['Version'] ?? null;
        $settings        = static::readSettings();
        $target          = static::resolveTarget($settings);

        $available = null;
        if ($target && $current_version) {
            $dominated = !empty($settings['pinned_version'])
                ? $target['version'] !== $current_version
                : version_compare($target['version'], $current_version, '>');

            if ($dominated) {
                $available = $target;
            }
        }

        return rest_ensure_response([
            'success'         => true,
            'current_version' => $current_version,
            'target'          => $target,
            'available'       => $available,
            'settings'        => $settings,
            'checked_at'      => current_time('c'),
        ]);
    }

    public static function getSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        return rest_ensure_response(static::readSettings());
    }

    public static function saveSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        $channel = $request->get_param('channel');
        $pinned  = $request->get_param('pinned_version');

        if (null !== $channel) {
            update_option(static::$channelOption, $channel);
        }

        if (null !== $pinned) {
            if ('' === $pinned) {
                delete_option(static::$pinnedOption);
            } else {
                update_option(static::$pinnedOption, $pinned);
            }
        }

        delete_site_transient('update_plugins');

        return rest_ensure_response([
            'success'  => true,
            'settings' => static::readSettings(),
            'message'  => 'Settings saved.',
        ]);
    }

    public static function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $settings = static::readSettings();
        $target   = static::resolveTarget($settings);

        if (!$target) {
            return new \WP_Error(
                'no_target',
                'Could not resolve a target version from your channel/pin settings.',
                ['status' => 400]
            );
        }

        $plugin_file = static::$pluginFile;
        $was_active  = is_plugin_active($plugin_file);

        if ($was_active) {
            deactivate_plugins($plugin_file, true);
        }

        $installed = static::install_();

        if (is_wp_error($installed)) {
            if ($was_active) {
                activate_plugin($plugin_file);
            }
            return new \WP_Error('install_failed', $installed->get_error_message(), ['status' => 500]);
        }

        if ($was_active) {
            activate_plugin($plugin_file);
        }

        wp_cache_delete('plugins', 'plugins');
        $plugins     = get_plugins();
        $new_version = $plugins[$plugin_file]['Version'] ?? null;

        return rest_ensure_response([
            'success'     => true,
            'status'      => is_plugin_active($plugin_file) ? 'active' : 'installed',
            'old_version' => $request->get_param('from_version'),
            'new_version' => $new_version,
            'message'     => 'Updated to ' . ($new_version ?: $target['version']) . '.',
        ]);
    }

    public static function releases(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $releases = static::fetchReleases();

        if (null === $releases) {
            return new \WP_Error('github_error', 'Failed to fetch releases from GitHub.', ['status' => 502]);
        }

        $list = [];
        foreach ($releases as $r) {
            $tag = ltrim($r['tag_name'] ?? '', 'v');
            $list[] = [
                'tag'        => $r['tag_name'],
                'version'    => $tag,
                'name'       => $r['name'] ?: $r['tag_name'],
                'prerelease' => !empty($r['prerelease']),
                'date'       => $r['published_at'] ?? '',
            ];
        }

        return rest_ensure_response([
            'success'  => true,
            'releases' => $list,
        ]);
    }

    // ── Internals ──────────────────────────────────────────────────

    protected static function readSettings(): array
    {
        return [
            'channel'        => get_option(static::$channelOption, 'stable'),
            'pinned_version' => get_option(static::$pinnedOption, ''),
        ];
    }

    protected static function resolveTarget(array $settings): ?array
    {
        $releases = static::fetchReleases();
        if (!$releases) {
            return null;
        }

        $pinned  = $settings['pinned_version'] ?? '';
        $channel = $settings['channel'] ?? 'stable';

        foreach ($releases as $r) {
            $version = ltrim($r['tag_name'] ?? '', 'v');

            if ($pinned && $version === $pinned) {
                return static::formatReleaseTarget($r, $version);
            }

            if (!$pinned) {
                $is_prerelease = !empty($r['prerelease']);

                if ('stable' === $channel && $is_prerelease) {
                    continue;
                }

                return static::formatReleaseTarget($r, $version);
            }
        }

        return null;
    }

    protected static function formatReleaseTarget(array $release, string $version): array
    {
        $has_package = false;
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === static::$assetName) {
                $has_package = true;
                break;
            }
        }

        return [
            'version'    => $version,
            'url'        => $release['html_url'] ?? '',
            'package'    => $has_package,
            'prerelease' => !empty($release['prerelease']),
        ];
    }

    protected static function fetchReleases(): ?array
    {
        $key = static::class;
        if (array_key_exists($key, self::$releaseCache)) {
            return self::$releaseCache[$key] ?: null;
        }

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ];

        $token = GitHub::bootstrapGetToken();
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/releases?per_page=30', static::$repo),
            ['timeout' => 10, 'headers' => $headers]
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            self::$releaseCache[$key] = [];
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            self::$releaseCache[$key] = [];
            return null;
        }

        self::$releaseCache[$key] = array_values(array_filter($data, fn($r) => empty($r['draft'])));
        return self::$releaseCache[$key];
    }
}
