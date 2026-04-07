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
    /** Option key used to persist the validated plugin list across requests. */
    private const CACHE_OPTION = 'ep_mu_app_validator_cache';

    /** @var array<string, bool> Per-request validation cache. */
    private static array $cache = [];

    /** @var array<string, bool>|null Persistent cache hydrated once per request. */
    private static ?array $persistentCache = null;

    /** @var string|null Hash of the input list that produced $persistentCache. */
    private static ?string $persistentHash = null;

    public static function init(): void
    {
        // Single site: filter active plugins before WordPress loads them.
        add_filter('option_active_plugins', [self::class, 'filterActivePlugins']);

        // Multisite: also filter network-activated plugins.
        add_filter('site_option_active_sitewide_plugins', [self::class, 'filterSitewidePlugins']);

        // Invalidate the persistent cache whenever the active-plugins option
        // changes — this is the only moment the validated list can drift.
        add_action('update_option_active_plugins', [self::class, 'invalidatePersistentCache']);
        add_action('update_site_option_active_sitewide_plugins', [self::class, 'invalidatePersistentCache']);
    }

    /**
     * Hydrate the persistent cache for a specific plugin-list hash. If the
     * stored hash doesn't match the current input, the cache is reset.
     */
    private static function hydratePersistent(string $hash): void
    {
        if (self::$persistentHash === $hash && self::$persistentCache !== null) {
            return;
        }

        $stored = get_option(self::CACHE_OPTION, null);

        if (is_array($stored) && ($stored['hash'] ?? '') === $hash && is_array($stored['results'] ?? null)) {
            self::$persistentCache = $stored['results'];
        } else {
            self::$persistentCache = [];
        }

        self::$persistentHash = $hash;
    }

    private static function savePersistent(): void
    {
        if (self::$persistentHash === null || self::$persistentCache === null) {
            return;
        }
        update_option(
            self::CACHE_OPTION,
            ['hash' => self::$persistentHash, 'results' => self::$persistentCache],
            false
        );
    }

    public static function invalidatePersistentCache(): void
    {
        self::$persistentCache = null;
        self::$persistentHash  = null;
        delete_option(self::CACHE_OPTION);
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
            return [];
        }

        // Hydrate the persistent cache using a hash of the input list so we
        // only re-run get_file_data() when the active-plugins list changes.
        $hash = md5('single|' . implode('|', $plugins));
        self::hydratePersistent($hash);

        $result = array_values(array_filter($plugins, [self::class, 'validatePlugin']));

        self::savePersistent();

        return $result;
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

        $hash = md5('sitewide|' . implode('|', array_keys($plugins)));
        self::hydratePersistent($hash);

        $filtered = [];
        foreach ($plugins as $pluginBasename => $timestamp) {
            if (self::validatePlugin($pluginBasename)) {
                $filtered[$pluginBasename] = $timestamp;
            }
        }

        self::savePersistent();

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

        // Persistent cache: skip disk I/O entirely when we've already
        // validated this basename under the current plugin-list hash.
        if (self::$persistentCache !== null && array_key_exists($pluginBasename, self::$persistentCache)) {
            $decision = self::$persistentCache[$pluginBasename];
            self::$cache[$pluginBasename] = $decision;
            return $decision;
        }

        $pluginDir = WP_PLUGIN_DIR . '/' . dirname($pluginBasename);
        $pluginFile = WP_PLUGIN_DIR . '/' . $pluginBasename;

        if (!file_exists($pluginFile)) {
            self::record($pluginBasename, true);
            return true;
        }

        $headers = get_file_data($pluginFile, ['Theme' => 'Theme']);

        if (empty($headers['Theme']) || 'examplepress-theme' !== $headers['Theme']) {
            self::record($pluginBasename, true);
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

        self::record($pluginBasename, true);
        return true;
    }

    /**
     * Write a validation decision to both the per-request and persistent caches.
     */
    private static function record(string $pluginBasename, bool $decision): void
    {
        self::$cache[$pluginBasename] = $decision;
        if (self::$persistentCache !== null) {
            self::$persistentCache[$pluginBasename] = $decision;
        }
    }

    /**
     * Flush the per-request and persistent validation caches. Call after admin
     * "refresh" actions where the underlying manifest may have changed.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
        self::invalidatePersistentCache();
    }

    private static function reject(string $pluginBasename, string $reason): void
    {
        self::record($pluginBasename, false);
        error_log(sprintf(
            'ExamplePress MU App Validator: Deactivated "%s" — %s',
            $pluginBasename,
            $reason
        ));
    }
}
