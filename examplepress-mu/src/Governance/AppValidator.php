<?php

declare(strict_types=1);

namespace ExamplePress\MU\Governance;

/**
 * Zero-trust plugin validation.
 *
 * Hooks both option_active_plugins (single site) and
 * site_option_active_sitewide_plugins (Multisite) so that
 * network-activated plugins cannot bypass governance.
 */
final class AppValidator
{
    /** @var array<string, bool> Per-request validation cache. */
    private static array $cache = [];

    public static function init(): void
    {
        // Single site: filter active plugins before WordPress loads them.
        add_filter('option_active_plugins', [self::class, 'filterActivePlugins']);

        // CRITICAL FIX: Multisite — also filter network-activated plugins.
        add_filter('site_option_active_sitewide_plugins', [self::class, 'filterSitewidePlugins']);
    }

    /**
     * Filter the list of active plugins (single site).
     *
     * @param mixed $plugins List of active plugin basenames.
     * @return array<int, string>
     */
    public static function filterActivePlugins(mixed $plugins): array
    {
        if (!is_array($plugins)) {
            return is_array($plugins) ? $plugins : [];
        }

        return array_values(array_filter($plugins, [self::class, 'validatePlugin']));
    }

    /**
     * Filter sitewide plugins (Multisite).
     *
     * Multisite stores sitewide plugins as slug => timestamp.
     *
     * @param mixed $plugins Associative array of plugin_file => timestamp.
     * @return array<string, int>
     */
    public static function filterSitewidePlugins(mixed $plugins): array
    {
        if (!is_array($plugins)) {
            return [];
        }

        $filtered = [];
        foreach ($plugins as $pluginBasename => $timestamp) {
            if (self::validatePlugin($pluginBasename)) {
                $filtered[$pluginBasename] = $timestamp;
            }
        }

        return $filtered;
    }

    /**
     * Validate a single plugin. Returns true if the plugin should remain active.
     *
     * Non-ExamplePress plugins always pass (we only govern our own ecosystem).
     */
    private static function validatePlugin(string $pluginBasename): bool
    {
        if (isset(self::$cache[$pluginBasename])) {
            return self::$cache[$pluginBasename];
        }

        $pluginDir = WP_PLUGIN_DIR . '/' . dirname($pluginBasename);
        $pluginFile = WP_PLUGIN_DIR . '/' . $pluginBasename;

        if (!file_exists($pluginFile)) {
            self::$cache[$pluginBasename] = true;
            return true;
        }

        $headers = get_file_data($pluginFile, ['Theme' => 'Theme']);

        if (empty($headers['Theme']) || 'examplepress-theme' !== $headers['Theme']) {
            self::$cache[$pluginBasename] = true;
            return true;
        }

        // This is a declared ExamplePress app. Validate it.
        $manifestPath = $pluginDir . '/examplepress.json';

        // Rule 1: Manifest must exist and be valid JSON.
        if (!file_exists($manifestPath)) {
            self::reject($pluginBasename, 'Missing examplepress.json manifest.');
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
            self::reject($pluginBasename, 'Invalid JSON in examplepress.json.');
            return false;
        }

        // Rule 2: Required fields.
        if (empty($manifest['name']) || empty($manifest['slug'])) {
            self::reject($pluginBasename, 'Manifest missing required fields (name, slug).');
            return false;
        }

        // Rule 3: No banned permissions (filterable; empty by default).
        /** @var array<int, string> $banned */
        $banned = (array) apply_filters('examplepress_mu_banned_permissions', []);
        $requested = $manifest['permissions'] ?? [];
        if (!empty($banned) && is_array($requested)) {
            $bannedFound = array_intersect($requested, $banned);
            if (!empty($bannedFound)) {
                self::reject(
                    $pluginBasename,
                    'Manifest requests banned permissions: ' . implode(', ', $bannedFound)
                );
                return false;
            }
        }

        /**
         * Final escape hatch: allow validators to override the result.
         * Return false to forcibly reject; true to accept.
         *
         * @param bool   $valid          Current validation state (true = accept).
         * @param string $pluginBasename Plugin file (e.g. 'foo/foo.php').
         * @param array  $manifest       Decoded examplepress.json manifest.
         */
        $valid = (bool) apply_filters('examplepress_mu_validate_app', true, $pluginBasename, $manifest);

        if (!$valid) {
            self::reject($pluginBasename, 'Rejected by examplepress_mu_validate_app filter.');
            return false;
        }

        self::$cache[$pluginBasename] = true;
        return true;
    }

    /**
     * Flush the per-request validation cache. Call this after admin "refresh"
     * actions where the underlying manifest may have changed.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    private static function reject(string $pluginBasename, string $reason): void
    {
        self::$cache[$pluginBasename] = false;
        error_log(sprintf(
            'ExamplePress MU App Validator: Deactivated "%s" — %s',
            $pluginBasename,
            $reason
        ));
    }
}
