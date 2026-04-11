<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Scans installed plugins for examplepress.json to discover companion apps.
 */
final class AppDiscovery
{
    /** Transient key for the cross-request scan cache. */
    private const CACHE_KEY = 'ep_mu_app_discovery';

    /** TTL for the transient cache. Short — operators editing a manifest
     * want to see changes within a minute, and the mtime fingerprint
     * usually invalidates before the TTL expires anyway. */
    private const CACHE_TTL = 5 * 60;

    /** @var array<string, array<int, array<string, mixed>>> Per-request memo keyed by cache hash. */
    private static array $memo = [];

    /**
     * Discover all ExamplePress apps by scanning plugin directories.
     *
     * Caches in two layers:
     *  1. Per-request memoization keyed on a fingerprint hash.
     *  2. A transient keyed on the same hash. The hash includes each
     *     candidate manifest's mtime, the plugins dir mtime, the active
     *     plugins list, and the serialized excludes filter — so a changed
     *     manifest, an activate/deactivate, or a changed filter all
     *     invalidate the cache automatically with no explicit flush.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function scan(): array
    {
        $pluginsDir = WP_PLUGIN_DIR;

        if (!is_dir($pluginsDir)) {
            return [];
        }

        /**
         * Filter directory entries to exclude from app discovery scanning.
         *
         * @param array $excludes Default excluded entries.
         */
        $excludes = (array) apply_filters('examplepress_mu_app_scan_excludes', ['.', '..']);

        $entries = scandir($pluginsDir);
        if (!$entries) {
            return [];
        }

        // First pass: collect candidates and build the fingerprint. Cheap —
        // scandir + filemtime on each examplepress.json only.
        $candidates = [];
        $fingerprintParts = [];
        foreach ($entries as $entry) {
            if (in_array($entry, $excludes, true)) {
                continue;
            }
            $pluginPath = $pluginsDir . '/' . $entry;
            if (!is_dir($pluginPath)) {
                continue;
            }
            $jsonPath = $pluginPath . '/examplepress.json';
            if (!file_exists($jsonPath)) {
                continue;
            }
            $candidates[] = [
                'slug'       => $entry,
                'jsonPath'   => $jsonPath,
                'pluginPath' => $pluginPath,
            ];
            $fingerprintParts[] = $entry . '@' . (int) @filemtime($jsonPath);
        }

        sort($fingerprintParts);
        $activePlugins = (array) get_option('active_plugins', []);
        sort($activePlugins);
        $hash = md5(implode('|', [
            'entries=' . implode(',', $fingerprintParts),
            'plugins_dir_mtime=' . (int) @filemtime($pluginsDir),
            'active=' . implode(',', $activePlugins),
            'excludes=' . serialize(array_values($excludes)),
        ]));

        // Per-request memo — eliminates repeat calls within one admin page load.
        if (isset(self::$memo[$hash])) {
            return self::$memo[$hash];
        }

        // Cross-request cache. Stored under a single key; mismatching hash
        // just means we parse and overwrite.
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && ($cached['hash'] ?? '') === $hash && isset($cached['apps']) && is_array($cached['apps'])) {
            self::$memo[$hash] = $cached['apps'];
            return $cached['apps'];
        }

        // Cache miss — parse.
        $apps = [];
        foreach ($candidates as $candidate) {
            $app = self::parseApp($candidate['slug'], $candidate['jsonPath'], $candidate['pluginPath']);
            if ($app) {
                $apps[] = $app;
            }
        }

        /**
         * Filter the discovered ExamplePress apps array.
         *
         * @param array $apps Discovered apps from the plugins directory.
         */
        $apps = (array) apply_filters('examplepress_mu_discovered_apps', $apps);

        self::$memo[$hash] = $apps;
        set_transient(self::CACHE_KEY, ['hash' => $hash, 'apps' => $apps], self::CACHE_TTL);

        return $apps;
    }

    /**
     * Drop all AppDiscovery caches. Call after explicit state changes
     * (app install/destroy, manifest write) if you don't want to wait for
     * the fingerprint-based invalidation to catch up.
     */
    public static function flushCache(): void
    {
        self::$memo = [];
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Parse a single app from its examplepress.json and plugin headers.
     *
     * @return array<string, mixed>|null
     */
    public static function parseApp(string $slug, string $jsonPath, string $pluginPath): ?array
    {
        $raw = Helpers::readFile($jsonPath);

        if ($raw === false || $raw === '') {
            return null;
        }

        $config = json_decode($raw, true);

        if (!is_array($config)) {
            return null;
        }

        $pluginFile = self::findPluginFile($slug, $pluginPath);

        if (!$pluginFile) {
            return null;
        }

        $headers = get_file_data($pluginPath . '/' . $pluginFile, [
            'name'        => 'Plugin Name',
            'description' => 'Description',
            'version'     => 'Version',
            'theme'       => 'Theme',
            'troy'        => 'Troy',
        ]);

        if (empty($headers['theme']) || $headers['theme'] !== 'examplepress-theme') {
            return null;
        }

        $troy = $config['troy'] ?? [];
        $troyServer = $troy['server_url'] ?? '';
        $troyRepo = $troy['repo'] ?? '';
        $troyRepoId = $troy['repo_id'] ?? '';

        $isConnected = !empty($troyServer) && !empty($troyRepo);

        $mismatches = [];
        $headerTroy = $headers['troy'] ?? '';

        if ($isConnected && empty($headerTroy)) {
            $mismatches[] = 'troy_header_missing';
        } elseif ($isConnected && $headerTroy !== $troyServer) {
            $mismatches[] = 'troy_header_mismatch';
        }

        $relativeFile = $slug . '/' . $pluginFile;

        $routing = $config['routing'] ?? [];
        $priority = (int) ($routing['priority'] ?? 10);
        $routeMeta = $routing['routes'] ?? [];

        return [
            'id'          => $slug,
            'name'        => $config['name'] ?? $headers['name'] ?? $slug,
            'slug'        => $config['slug'] ?? $slug,
            'description' => $config['description'] ?? $headers['description'] ?? '',
            'version'     => $config['version'] ?? $headers['version'] ?? '0.0.0',
            'status'      => $isConnected ? 'connected' : 'disconnected',
            'active'      => is_plugin_active($relativeFile),
            'routing'     => [
                'priority' => $priority,
                'routes'   => $routeMeta,
            ],
            'troy'        => [
                'server_url' => $troyServer,
                'repo'       => $troyRepo,
                'repo_id'    => $troyRepoId,
            ],
            'plugin_file' => $relativeFile,
            'mismatches'  => $mismatches,
        ];
    }

    /**
     * Find the main plugin PHP file in a plugin directory.
     */
    public static function findPluginFile(string $slug, string $pluginPath): ?string
    {
        if (file_exists($pluginPath . '/' . $slug . '.php')) {
            return $slug . '.php';
        }

        $files = glob($pluginPath . '/*.php');

        if (!$files) {
            return null;
        }

        foreach ($files as $file) {
            $data = get_file_data($file, ['name' => 'Plugin Name']);
            if (!empty($data['name'])) {
                return basename($file);
            }
        }

        return null;
    }

    /**
     * Slugify a plugin name for directory/file naming.
     */
    public static function slugify(string $name): string
    {
        // Use WordPress's battle-tested sanitiser, which handles
        // transliteration, accents, and edge cases properly.
        return sanitize_title($name);
    }
}
