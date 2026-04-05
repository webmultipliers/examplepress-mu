<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Companion plugin management — updater and demo plugins.
 *
 * Provides constants, install/cleanup utilities, ZIP URL resolvers,
 * and a shared token helper.
 */
final class PluginManager
{
    public const UPDATER_PLUGIN_FILE = 'examplepress-theme-update/examplepress-theme-update.php';
    public const UPDATER_GITHUB_REPO = 'webmultipliers/examplepress-theme-update';
    public const UPDATER_THROTTLE_KEY = 'ep_updater_install_attempted';

    public const DEMO_PLUGIN_FILE = 'examplepress-demo/examplepress-demo.php';
    public const DEMO_GITHUB_REPO = 'webmultipliers/examplepress-theme-demo';
    public const DEMO_THROTTLE_KEY = 'ep_demo_install_attempted';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'cleanupStaleUpdaterDirs']);
        add_action('admin_init', [self::class, 'cleanupStaleDemoDirs']);
    }

    // ── Cleanup — Updater ─────────────────────────────────────────

    public static function cleanupStaleUpdaterDirs(): void
    {
        if (wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST')) {
            return;
        }

        $pattern = WP_PLUGIN_DIR . '/examplepress-theme-update-*';
        $stale = glob($pattern, GLOB_ONLYDIR);

        if (empty($stale)) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
            return;
        }

        foreach ($stale as $dir) {
            $dirname = basename($dir);
            $stalePlugin = $dirname . '/examplepress-theme-update.php';

            if (is_plugin_active($stalePlugin)) {
                deactivate_plugins($stalePlugin, true);
            }

            $wp_filesystem->delete($dir, true);
        }
    }

    // ── Cleanup — Demo ────────────────────────────────────────────

    public static function cleanupStaleDemoDirs(): void
    {
        if (wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST')) {
            return;
        }

        $pattern = WP_PLUGIN_DIR . '/examplepress-demo-*';
        $stale = glob($pattern, GLOB_ONLYDIR);

        if (empty($stale)) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
            return;
        }

        foreach ($stale as $dir) {
            $dirname = basename($dir);
            $stalePlugin = $dirname . '/examplepress-demo.php';

            if (is_plugin_active($stalePlugin)) {
                deactivate_plugins($stalePlugin, true);
            }

            $wp_filesystem->delete($dir, true);
        }
    }

    // ── Install — Updater ─────────────────────────────────────────

    /**
     * @return true|\WP_Error
     */
    public static function installUpdater(): true|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $pluginDir = WP_PLUGIN_DIR . '/examplepress-theme-update';
        if (is_dir($pluginDir)) {
            WP_Filesystem();
            global $wp_filesystem;
            if ($wp_filesystem instanceof \WP_Filesystem_Base) {
                $wp_filesystem->delete($pluginDir, true);
            }
        }

        $zipUrl = self::resolveUpdaterZipUrl();

        if (!$zipUrl) {
            return new \WP_Error('ep_updater_no_source', 'Could not determine a download URL for the updater plugin.');
        }

        return self::installFromZip($zipUrl, 'examplepress-theme-update');
    }

    // ── Install — Demo ────────────────────────────────────────────

    /**
     * @return true|\WP_Error
     */
    public static function installDemo(): true|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $pluginDir = WP_PLUGIN_DIR . '/examplepress-demo';
        if (is_dir($pluginDir)) {
            WP_Filesystem();
            global $wp_filesystem;
            if ($wp_filesystem instanceof \WP_Filesystem_Base) {
                $wp_filesystem->delete($pluginDir, true);
            }
        }

        $zipUrl = self::resolveDemoZipUrl();

        if (!$zipUrl) {
            return new \WP_Error('ep_demo_no_source', 'Could not determine a download URL for the demo plugin.');
        }

        return self::installFromZip($zipUrl, 'examplepress-demo');
    }

    // ── Shared Install ────────────────────────────────────────────

    /**
     * @return true|\WP_Error
     */
    private static function installFromZip(string $zipUrl, string $expectedSlug): true|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $renameFilter = static function (string $source, string $remoteSource) use ($expectedSlug): string {
            $expected = trailingslashit($remoteSource) . $expectedSlug . '/';
            if ($source === $expected) {
                return $source;
            }
            $basename = basename(untrailingslashit($source));
            if (str_starts_with($basename, $expectedSlug) && $basename !== $expectedSlug) {
                global $wp_filesystem;
                if ($wp_filesystem->move($source, $expected, true)) {
                    return $expected;
                }
            }
            return $source;
        };

        add_filter('upgrader_source_selection', $renameFilter, 10, 2);

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->install($zipUrl);

        remove_filter('upgrader_source_selection', $renameFilter, 10);

        if (is_wp_error($result)) {
            return $result;
        }
        if (is_wp_error($skin->result)) {
            return $skin->result;
        }
        if (!$result) {
            return new \WP_Error('install_failed', 'The plugin installer returned an unexpected result.');
        }

        return true;
    }

    // ── ZIP URL Resolvers ─────────────────────────────────────────

    public static function resolveUpdaterZipUrl(): ?string
    {
        return self::resolveZipUrl(
            self::UPDATER_GITHUB_REPO,
            'examplepress-theme-update.zip'
        );
    }

    public static function resolveDemoZipUrl(): ?string
    {
        return self::resolveZipUrl(
            self::DEMO_GITHUB_REPO,
            'examplepress-demo.zip'
        );
    }

    private static function resolveZipUrl(string $repo, string $assetName): ?string
    {
        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ];

        $token = GitHub::bootstrapGetToken();
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get(
            sprintf('https://api.github.com/repos/%s/releases/latest', $repo),
            ['timeout' => 10, 'headers' => $headers]
        );

        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $release = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($release) && !empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (($asset['name'] ?? '') === $assetName) {
                        return $asset['browser_download_url'];
                    }
                }
            }
        }

        return sprintf('https://github.com/%s/archive/refs/heads/development.zip', $repo);
    }

    // ── Status helpers ────────────────────────────────────────────

    public static function getUpdaterStatus(): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active(self::UPDATER_PLUGIN_FILE)) {
            return 'active';
        }
        if (array_key_exists(self::UPDATER_PLUGIN_FILE, get_plugins())) {
            return 'installed';
        }
        return 'not-installed';
    }

    public static function getDemoStatus(): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $dest = WP_PLUGIN_DIR . '/examplepress-demo';
        if (!is_dir($dest)) {
            return 'not-installed';
        }
        if (!self::isDemoPlugin($dest)) {
            return 'foreign';
        }
        if (is_plugin_active(self::DEMO_PLUGIN_FILE)) {
            return 'active';
        }
        return 'installed';
    }

    public static function isDemoPlugin(string $pluginDir): bool
    {
        $mainFile = $pluginDir . '/examplepress-demo.php';
        if (!file_exists($mainFile)) {
            return false;
        }
        $header = get_file_data($mainFile, ['demo' => 'ExamplePress Demo']);
        return !empty($header['demo']) && strtolower($header['demo']) === 'true';
    }

    public static function getUpdaterPluginVersion(): ?string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return $plugins[self::UPDATER_PLUGIN_FILE]['Version'] ?? null;
    }

    public static function getDemoPluginVersion(): ?string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return $plugins[self::DEMO_PLUGIN_FILE]['Version'] ?? null;
    }
}
