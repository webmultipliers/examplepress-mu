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
    public const DEMO_PLUGIN_FILE = 'examplepress-demo/examplepress-demo.php';
    public const DEMO_GITHUB_REPO = 'webmultipliers/examplepress-theme-demo';
    public const DEMO_THROTTLE_KEY = 'ep_demo_install_attempted';

    public const CLEANUP_CRON_HOOK = 'examplepress_mu_plugin_cleanup';

    public static function init(): void
    {
        // Run cleanup on a daily cron schedule rather than every admin_init
        // (which performed glob() filesystem scans on every page load).
        add_action(self::CLEANUP_CRON_HOOK, [self::class, 'cleanupStaleDemoDirs']);

        if (!wp_next_scheduled(self::CLEANUP_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_CRON_HOOK);
        }
    }

    // ── Cleanup — Demo ────────────────────────────────────────────

    public static function cleanupStaleDemoDirs(): void
    {
        // Runs on the daily examplepress_mu_plugin_cleanup cron hook.
        $pattern = WP_PLUGIN_DIR . '/examplepress-demo-*';
        $stale = glob($pattern, GLOB_ONLYDIR);

        if (empty($stale)) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $wp_filesystem = Helpers::filesystem(forceDirect: true);

        if (!$wp_filesystem) {
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

    // ── Install — Demo ────────────────────────────────────────────

    /**
     * Install (or reinstall) the demo companion plugin from GitHub.
     *
     * @param bool $overwrite Replace existing plugin directory if present.
     */
    public static function installDemo(bool $overwrite = false): true|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $zipUrl = self::resolveDemoZipUrl();

        if (!$zipUrl) {
            return new \WP_Error('ep_demo_no_source', 'Could not determine a download URL for the demo plugin.');
        }

        // Plugin slug is 'examplepress-demo' but the GitHub repo is
        // 'examplepress-theme-demo', so zipball extracts to
        // 'examplepress-theme-demo-{branch}/'. Pass both so the
        // rename filter catches either prefix.
        return self::installFromZip($zipUrl, 'examplepress-demo', $overwrite, 'examplepress-theme-demo');
    }

    // ── Shared Install ────────────────────────────────────────────

    /**
     * @return true|\WP_Error
     */
    /**
     * @param bool   $overwrite If true, overwrite existing plugin directory.
     * @param string $repoSlug  GitHub repo name slug (e.g. 'examplepress-theme-demo')
     *                          used to match extracted directory names from zipball archives.
     */
    private static function installFromZip(
        string $zipUrl,
        string $expectedSlug,
        bool $overwrite = false,
        string $repoSlug = ''
    ): true|\WP_Error {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $renameFilter = static function (string $source, string $remoteSource) use ($expectedSlug, $repoSlug): string {
            $expected = trailingslashit($remoteSource) . $expectedSlug . '/';
            if ($source === $expected) {
                return $source;
            }
            $basename = basename(untrailingslashit($source));
            // Match both the plugin slug prefix (e.g. examplepress-demo-*)
            // AND the repo slug prefix (e.g. examplepress-theme-demo-*) since
            // GitHub zipballs extract using the repo name, not the plugin slug.
            $isMatch = ($basename !== $expectedSlug) && (
                str_starts_with($basename, $expectedSlug) ||
                ($repoSlug && str_starts_with($basename, $repoSlug))
            );
            if ($isMatch) {
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

        // WP 5.8+ supports overwrite_package to replace existing plugins.
        $args = $overwrite ? ['overwrite_package' => true] : [];
        $result = $upgrader->install($zipUrl, $args);

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

    public static function demoRepo(): string
    {
        /**
         * Filter the GitHub repo (owner/name) used for the demo plugin.
         */
        return (string) apply_filters('examplepress_mu_demo_repo', self::DEMO_GITHUB_REPO);
    }

    public static function resolveDemoZipUrl(): ?string
    {
        // The release asset is named after the repo, not the plugin slug.
        return self::resolveZipUrl(
            self::demoRepo(),
            'examplepress-theme-demo.zip'
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
        // Verify this is actually the ExamplePress demo by checking
        // for the Theme header (all EP companion apps declare it) and
        // the plugin name.
        $headers = get_file_data($mainFile, [
            'name'  => 'Plugin Name',
            'theme' => 'Theme',
        ]);
        return ($headers['theme'] ?? '') === 'examplepress-theme'
            && str_contains($headers['name'] ?? '', 'ExamplePress Demo');
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
