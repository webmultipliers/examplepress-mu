<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Scans installed plugins for examplepress.json to discover companion apps.
 */
final class AppDiscovery
{
    /**
     * Discover all ExamplePress apps by scanning plugin directories.
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

        $apps = [];
        $entries = scandir($pluginsDir);

        if (!$entries) {
            return [];
        }

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

            $app = self::parseApp($entry, $jsonPath, $pluginPath);

            if ($app) {
                $apps[] = $app;
            }
        }

        /**
         * Filter the discovered ExamplePress apps array.
         *
         * @param array $apps Discovered apps from the plugins directory.
         */
        return (array) apply_filters('examplepress_mu_discovered_apps', $apps);
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
