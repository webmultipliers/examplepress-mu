<?php

declare(strict_types=1);

namespace ExamplePress\MU\Config;

use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\Helpers;

/**
 * Validates required ecosystem plugins.
 *
 * CRITICAL FIX: Aggregates dependencies from TWO sources:
 *  1. The MU plugin's own examplepress.json
 *  2. Any active companion apps that declare their own dependencies array
 */
final class DependencyManager
{
    /**
     * Resolve status for all declared dependencies from all sources.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(): array
    {
        $allDeps = self::aggregateDependencies();

        if (empty($allDeps)) {
            return [];
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = get_plugins();
        $active = array_map('plugin_basename', wp_get_active_and_valid_plugins());
        $result = [];

        foreach ($allDeps as $dep) {
            $slug = $dep['slug'] ?? '';
            $checkType = $dep['check_type'] ?? 'plugin';
            $target = $dep['check_target'] ?? $slug;

            if (empty($slug)) {
                continue;
            }

            $sourceType = $dep['source']['type'] ?? 'wporg';
            $sourceUrl = $dep['source']['url'] ?? '';
            $url = $sourceUrl;
            if (!$url && $sourceType === 'wporg') {
                $url = "https://wordpress.org/plugins/{$slug}/";
            }

            // Resolve the required minimum version. Either declared inline
            // (`min_version`) or sourced from a PHP constant defined elsewhere
            // — used by the MU loader to expose the Blockstudio version it
            // pins via its plugin header, instead of bundling Blockstudio.
            $minVersion = $dep['min_version'] ?? '';
            $minVersionConstant = $dep['min_version_constant'] ?? '';
            if (!$minVersion && $minVersionConstant && defined($minVersionConstant)) {
                $minVersion = (string) constant($minVersionConstant);
            }

            $item = [
                'slug'             => $slug,
                'name'             => $dep['name'] ?? $slug,
                'tier'             => $dep['tier'] ?? 'optional',
                'pricing'          => $dep['pricing'] ?? 'free',
                'cloud'            => !empty($dep['cloud_dependent']),
                'source'           => $sourceType,
                'url'              => $url,
                'checkType'        => $checkType,
                'status'           => 'missing',
                'requiredVersion'  => $minVersion,
                'installedVersion' => '',
            ];

            if ($checkType === 'class') {
                $item['status'] = class_exists($target) ? 'active' : 'missing';
            } elseif ($checkType === 'function') {
                $item['status'] = function_exists($target) ? 'active' : 'missing';
            } else {
                foreach ($installed as $file => $data) {
                    if (str_starts_with($file, $target . '/') || $file === $target . '.php') {
                        $item['name'] = $data['Name'];
                        $item['status'] = in_array($file, $active, true) ? 'active' : 'installed';
                        if (!empty($data['PluginURI']) && !$sourceUrl) {
                            $item['url'] = $data['PluginURI'];
                        }
                        break;
                    }
                }
            }

            // Look up the installed plugin's version by slug regardless of
            // check_type so that class/function-based checks can still
            // enforce min_version.
            foreach ($installed as $file => $data) {
                if (str_starts_with($file, $slug . '/') || $file === $slug . '.php') {
                    $item['installedVersion'] = (string) ($data['Version'] ?? '');
                    break;
                }
            }

            // Enforce minimum version. An active-but-outdated dep flips to
            // 'outdated' so the notification layer can surface it.
            if ($minVersion && $item['installedVersion'] && version_compare($item['installedVersion'], $minVersion, '<')) {
                if ($item['status'] === 'active' || $item['status'] === 'installed') {
                    $item['status'] = 'outdated';
                }
            }

            $fallbackSlug = $dep['fallback_slug'] ?? '';
            if ($fallbackSlug && $item['status'] !== 'active') {
                $fbStatus = 'missing';
                foreach ($installed as $file => $data) {
                    if (str_starts_with($file, $fallbackSlug . '/') || $file === $fallbackSlug . '.php') {
                        $fbStatus = in_array($file, $active, true) ? 'active' : 'installed';
                        break;
                    }
                }
                $item['fallback'] = [
                    'slug'   => $fallbackSlug,
                    'status' => $fbStatus,
                ];
                if ($item['status'] === 'missing' && $fbStatus === 'active') {
                    $item['status'] = 'fallback';
                }
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * CRITICAL FIX: Aggregate dependencies from MU config AND companion apps.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function aggregateDependencies(): array
    {
        // Source 1: MU plugin's own examplepress.json
        $config = ConfigManager::get();
        $deps = $config['dependencies'] ?? [];

        // Source 2: Active companion apps' examplepress.json files.
        // Only aggregate deps from apps the site operator has actually
        // activated — otherwise inactive apps in wp-content/plugins would
        // leak their requirements into the UI and notifications.
        $apps = array_filter(
            AppDiscovery::scan(),
            static fn(array $app): bool => !empty($app['active'])
        );
        foreach ($apps as $app) {
            // Use the on-disk directory name (id), NOT the manifest's
            // self-declared slug. An app that declares a different slug
            // in its manifest than its directory name would otherwise
            // be silently skipped here — the path
            //   WP_PLUGIN_DIR/{manifest_slug}/examplepress.json
            // wouldn't exist and file_exists() would return false.
            // $app['id'] always matches the actual directory scanned
            // by AppDiscovery.
            $pluginDirName = (string) ($app['id'] ?? '');
            if ($pluginDirName === '' || !Helpers::isSafeRelativePath($pluginDirName)) {
                // Defence in depth: an app with a malicious directory
                // name would already have been rejected by WordPress's
                // plugin loader, but we double-check because this is
                // the one place we concatenate it into a filesystem path.
                continue;
            }

            $appJsonPath = WP_PLUGIN_DIR . '/' . $pluginDirName . '/examplepress.json';
            if (!file_exists($appJsonPath)) {
                continue;
            }

            $appConfig = json_decode((string) file_get_contents($appJsonPath), true);
            if (!is_array($appConfig)) {
                continue;
            }

            $appDeps = $appConfig['dependencies'] ?? [];
            if (!is_array($appDeps)) {
                continue;
            }

            // Merge, deduplicating by slug.
            $existingSlugs = array_column($deps, 'slug');
            foreach ($appDeps as $appDep) {
                // Per-entry shape guard — a malformed manifest that
                // declared dependencies as a list of strings instead of
                // a list of objects would otherwise trigger a PHP 8
                // warning on $appDep['slug'].
                if (!is_array($appDep)) {
                    continue;
                }
                $depSlug = $appDep['slug'] ?? '';
                if (is_string($depSlug) && $depSlug !== '' && !in_array($depSlug, $existingSlugs, true)) {
                    $deps[] = $appDep;
                    $existingSlugs[] = $depSlug;
                }
            }
        }

        return $deps;
    }
}
