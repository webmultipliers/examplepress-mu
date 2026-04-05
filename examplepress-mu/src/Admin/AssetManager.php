<?php

declare(strict_types=1);

namespace ExamplePress\MU\Admin;

/**
 * Reads the Vite manifest.json and enqueues scripts/styles for admin pages.
 *
 * Ported from inc/admin/admin-assets.php. Supports both Vite dev server
 * (HMR) and production builds with content-hashed filenames.
 */
final class AssetManager
{
    /**
     * Cached manifest data.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $manifest = null;

    /**
     * All Vite-managed script handles (for type="module" injection).
     *
     * @var string[]
     */
    private static array $viteHandles = [];

    /**
     * Hook into WordPress.
     */
    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueueForPage']);
        add_filter('script_loader_tag', [self::class, 'injectModuleType'], 10, 2);
    }

    /**
     * Enqueue assets for the current admin page if it belongs to ExamplePress.
     */
    public static function enqueueForPage(string $hookSuffix): void
    {
        // Always enqueue admin-settings CSS on EP pages.
        if (!MenuManager::isExamplePressPage($hookSuffix)) {
            return;
        }

        // Admin settings stylesheet (shared across all EP pages).
        wp_enqueue_style(
            'ep-admin-settings',
            EXAMPLEPRESS_MU_URI . '/assets/src/css/admin-settings.css',
            [],
            EXAMPLEPRESS_MU_VERSION
        );

        // Google Fonts (shared across all subpages).
        $fontsUrl = (string) apply_filters(
            'examplepress_settings_fonts_url',
            'https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500;600&display=swap'
        );

        if ($fontsUrl) {
            wp_enqueue_style('ep-settings-fonts', $fontsUrl, [], null);
        }

        // Determine which Vite entry point to load based on page ID.
        $pageId = MenuManager::pageIdFromHook($hookSuffix);

        if ($pageId === null) {
            return;
        }

        self::enqueueEntry($pageId, $fontsUrl);

        // Localize the page data payload.
        DataProvider::localize($pageId);
    }

    /**
     * Enqueue a Vite entry point's JS and CSS.
     */
    public static function enqueueEntry(string $entry, string $fontsUrl = ''): void
    {
        if (self::isDevServer()) {
            self::enqueueDevEntry($entry);
            return;
        }

        self::enqueueProductionEntry($entry, $fontsUrl);
    }

    /**
     * Add type="module" to Vite-managed script tags.
     */
    public static function injectModuleType(string $tag, string $handle): string
    {
        if (in_array($handle, self::$viteHandles, true)) {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }

        return $tag;
    }

    /**
     * Enqueue from the Vite dev server with HMR support.
     */
    private static function enqueueDevEntry(string $entry): void
    {
        $devUrl = 'http://localhost:5173';

        // Vite client (HMR runtime) — only enqueue once.
        if (!wp_script_is('ep-vite-client', 'enqueued')) {
            wp_enqueue_script(
                'ep-vite-client',
                $devUrl . '/@vite/client',
                [],
                null,
                true
            );
            self::$viteHandles[] = 'ep-vite-client';
        }

        $handle = 'ep-' . $entry;
        wp_enqueue_script(
            $handle,
            $devUrl . '/assets/src/' . $entry . '/main.js',
            ['ep-vite-client'],
            null,
            true
        );
        self::$viteHandles[] = $handle;
    }

    /**
     * Enqueue from the production Vite manifest.
     */
    private static function enqueueProductionEntry(string $entry, string $fontsUrl = ''): void
    {
        $manifest = self::resolveManifest();
        $key      = 'assets/src/' . $entry . '/main.js';
        $asset    = $manifest[$key] ?? null;

        if ($asset === null) {
            return;
        }

        $fontDeps = $fontsUrl ? ['ep-settings-fonts'] : [];

        // Collect all CSS from the entry and its shared chunk imports.
        $cssFiles = $asset['css'] ?? [];
        foreach ($asset['imports'] ?? [] as $importKey) {
            $import = $manifest[$importKey] ?? null;
            if ($import && !empty($import['css'])) {
                $cssFiles = array_merge($cssFiles, $import['css']);
            }
        }
        $cssFiles = array_unique($cssFiles);

        foreach ($cssFiles as $cssFile) {
            $cssHandle = 'ep-' . $entry . '-' . md5($cssFile);
            wp_enqueue_style(
                $cssHandle,
                EXAMPLEPRESS_MU_URI . '/dist/' . $cssFile,
                $fontDeps,
                null
            );
        }

        // Enqueue the JS entry point.
        $handle = 'ep-' . $entry;
        wp_enqueue_script(
            $handle,
            EXAMPLEPRESS_MU_URI . '/dist/' . $asset['file'],
            [],
            null,
            true
        );
        self::$viteHandles[] = $handle;
    }

    /**
     * Read and cache the Vite manifest.json.
     *
     * @return array<string, mixed>
     */
    private static function resolveManifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = EXAMPLEPRESS_MU_DIR . '/dist/.vite/manifest.json';

        if (!file_exists($path)) {
            self::$manifest = [];
            return self::$manifest;
        }

        $data = json_decode((string) file_get_contents($path), true);
        self::$manifest = is_array($data) ? $data : [];

        return self::$manifest;
    }

    /**
     * Check whether the Vite dev server is running.
     */
    private static function isDevServer(): bool
    {
        if (defined('EP_VITE_DEV') && EP_VITE_DEV) {
            return true;
        }

        // Optionally probe localhost:5173 (cached per request).
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }

        // Only auto-detect when not explicitly set via constant.
        $checked = false;
        return $checked;
    }
}
