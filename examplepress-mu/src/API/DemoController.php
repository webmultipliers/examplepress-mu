<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\GitHub;
use ExamplePress\MU\Infrastructure\PluginManager;

/**
 * Demo companion plugin REST API — install, uninstall, update, check,
 * settings, and release listing for the ExamplePress demo plugin.
 */
final class DemoController
{
    public static function register(): void
    {
        register_rest_route('examplepress-mu/v1', '/demo/install', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'install'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/demo/uninstall', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'uninstall'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/demo/check', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'check'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/demo/settings', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getSettings'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/demo/settings', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'saveSettings'],
            'permission_callback' => [self::class, 'permissionCheck'],
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

        register_rest_route('examplepress-mu/v1', '/demo/update', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'update'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route('examplepress-mu/v1', '/demo/releases', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'releases'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    // ── Install ─────────────────────────────────────────────────────

    public static function install(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $status = PluginManager::getDemoStatus();

        if ('active' === $status) {
            return rest_ensure_response([
                'success' => true,
                'status'  => 'active',
                'message' => 'Demo plugin is already installed and active.',
            ]);
        }

        if ('foreign' === $status) {
            return new \WP_Error(
                'demo_conflict',
                'A plugin named examplepress-demo already exists but is not the ExamplePress demo. Remove it manually first.',
                ['status' => 409]
            );
        }

        if ('installed' === $status) {
            $result = activate_plugin(PluginManager::DEMO_PLUGIN_FILE);

            if (is_wp_error($result)) {
                return rest_ensure_response([
                    'success' => false,
                    'status'  => 'installed',
                    'message' => 'Demo plugin is installed but could not be activated: ' . $result->get_error_message(),
                ]);
            }

            return rest_ensure_response([
                'success' => true,
                'status'  => 'active',
                'message' => 'Demo plugin activated.',
            ]);
        }

        // Not installed — download from GitHub.
        $installed = PluginManager::installDemo();

        if (is_wp_error($installed)) {
            return new \WP_Error(
                'demo_install_failed',
                'Failed to install demo plugin: ' . $installed->get_error_message(),
                ['status' => 500]
            );
        }

        $result = activate_plugin(PluginManager::DEMO_PLUGIN_FILE);
        $final  = is_wp_error($result) ? 'installed' : 'active';

        delete_transient(PluginManager::DEMO_THROTTLE_KEY);

        return rest_ensure_response([
            'success' => true,
            'status'  => $final,
            'message' => 'active' === $final
                ? 'Demo plugin installed and activated.'
                : 'Demo plugin installed but could not be activated: ' . $result->get_error_message(),
        ]);
    }

    // ── Uninstall ───────────────────────────────────────────────────

    public static function uninstall(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_dir = WP_PLUGIN_DIR . '/examplepress-demo';

        if (!is_dir($plugin_dir)) {
            return rest_ensure_response([
                'success' => true,
                'status'  => 'not-installed',
                'message' => 'Demo plugin is not installed.',
            ]);
        }

        if (!PluginManager::isDemoPlugin($plugin_dir)) {
            return new \WP_Error(
                'demo_conflict',
                'The examplepress-demo plugin is not the ExamplePress demo. Refusing to delete.',
                ['status' => 409]
            );
        }

        if (is_plugin_active(PluginManager::DEMO_PLUGIN_FILE)) {
            deactivate_plugins(PluginManager::DEMO_PLUGIN_FILE, true);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem instanceof \WP_Filesystem_Base || !$wp_filesystem->delete($plugin_dir, true)) {
            return new \WP_Error(
                'demo_delete_failed',
                'Failed to remove the demo plugin directory.',
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'status'  => 'not-installed',
            'message' => 'Demo plugin removed.',
        ]);
    }

    // ── Check ───────────────────────────────────────────────────────

    public static function check(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins         = get_plugins();
        $current_version = $plugins[PluginManager::DEMO_PLUGIN_FILE]['Version'] ?? null;
        $settings        = self::readSettings();
        $target          = self::resolveTarget($settings);

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

    // ── Settings GET ────────────────────────────────────────────────

    public static function getSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        return rest_ensure_response(self::readSettings());
    }

    // ── Settings POST ───────────────────────────────────────────────

    public static function saveSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        $channel = $request->get_param('channel');
        $pinned  = $request->get_param('pinned_version');

        if (null !== $channel) {
            update_option('ep_demo_plugin_channel', $channel);
        }

        if (null !== $pinned) {
            if ('' === $pinned) {
                delete_option('ep_demo_plugin_pinned');
            } else {
                update_option('ep_demo_plugin_pinned', $pinned);
            }
        }

        delete_site_transient('update_plugins');

        return rest_ensure_response([
            'success'  => true,
            'settings' => self::readSettings(),
            'message'  => 'Settings saved.',
        ]);
    }

    // ── Update ──────────────────────────────────────────────────────

    public static function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $settings = self::readSettings();
        $target   = self::resolveTarget($settings);

        if (!$target) {
            return new \WP_Error(
                'no_target',
                'Could not resolve a target version from your channel/pin settings.',
                ['status' => 400]
            );
        }

        $download_url = self::getReleaseDownloadUrl($target['version']);

        if (!$download_url) {
            return new \WP_Error(
                'no_package',
                'No downloadable zip found for version ' . $target['version'] . '.',
                ['status' => 404]
            );
        }

        $plugin_file = PluginManager::DEMO_PLUGIN_FILE;
        $plugin_dir  = WP_PLUGIN_DIR . '/examplepress-demo';
        $was_active  = is_plugin_active($plugin_file);

        if ($was_active) {
            deactivate_plugins($plugin_file, true);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (is_dir($plugin_dir) && $wp_filesystem instanceof \WP_Filesystem_Base) {
            $wp_filesystem->delete($plugin_dir, true);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $rename_filter = function (string $source, string $remote_source): string {
            $expected = trailingslashit($remote_source) . 'examplepress-demo/';
            if ($source === $expected) {
                return $source;
            }
            $basename = basename(untrailingslashit($source));
            if (str_starts_with($basename, 'examplepress-demo') && $basename !== 'examplepress-demo') {
                global $wp_filesystem;
                if ($wp_filesystem->move($source, $expected, true)) {
                    return $expected;
                }
            }
            return $source;
        };

        add_filter('upgrader_source_selection', $rename_filter, 10, 2);

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result   = $upgrader->install($download_url);

        remove_filter('upgrader_source_selection', $rename_filter, 10);

        if (is_wp_error($result)) {
            return new \WP_Error('install_failed', $result->get_error_message(), ['status' => 500]);
        }
        if (is_wp_error($skin->result)) {
            return new \WP_Error('install_failed', $skin->result->get_error_message(), ['status' => 500]);
        }
        if (!$result) {
            return new \WP_Error('install_failed', 'Installer returned an unexpected result.', ['status' => 500]);
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

    // ── Releases ────────────────────────────────────────────────────

    public static function releases(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $releases = self::fetchReleases();

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

    // ── Private Helpers ─────────────────────────────────────────────

    private static function readSettings(): array
    {
        return [
            'channel'        => get_option('ep_demo_plugin_channel', 'stable'),
            'pinned_version' => get_option('ep_demo_plugin_pinned', ''),
        ];
    }

    private static function resolveTarget(array $settings): ?array
    {
        $releases = self::fetchReleases();
        if (!$releases) {
            return null;
        }

        $pinned  = $settings['pinned_version'] ?? '';
        $channel = $settings['channel'] ?? 'stable';

        foreach ($releases as $r) {
            $version = ltrim($r['tag_name'] ?? '', 'v');

            if ($pinned && $version === $pinned) {
                return self::formatReleaseTarget($r, $version);
            }

            if (!$pinned) {
                $is_prerelease = !empty($r['prerelease']);

                if ('stable' === $channel && $is_prerelease) {
                    continue;
                }

                return self::formatReleaseTarget($r, $version);
            }
        }

        return null;
    }

    private static function formatReleaseTarget(array $release, string $version): array
    {
        $has_package = false;
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === 'examplepress-demo.zip') {
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

    private static function fetchReleases(): ?array
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
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
            sprintf('https://api.github.com/repos/%s/releases?per_page=30', PluginManager::DEMO_GITHUB_REPO),
            ['timeout' => 10, 'headers' => $headers]
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $cache = [];
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $cache = [];
            return null;
        }

        $cache = array_values(array_filter($data, fn($r) => empty($r['draft'])));
        return $cache;
    }

    private static function getReleaseDownloadUrl(string $version): ?string
    {
        $releases = self::fetchReleases();
        if (!$releases) {
            return null;
        }

        foreach ($releases as $r) {
            $tag = ltrim($r['tag_name'] ?? '', 'v');
            if ($tag !== $version) {
                continue;
            }

            foreach ($r['assets'] ?? [] as $asset) {
                if (($asset['name'] ?? '') === 'examplepress-demo.zip') {
                    return $asset['browser_download_url'];
                }
            }

            return $r['zipball_url'] ?? null;
        }

        return null;
    }
}
