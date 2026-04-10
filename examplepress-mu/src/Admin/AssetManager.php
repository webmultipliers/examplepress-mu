<?php

declare(strict_types=1);

namespace ExamplePress\MU\Admin;

/**
 * Reads the Vite manifest.json and enqueues scripts/styles for admin pages.
 */
final class AssetManager
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /** @var int|null mtime of the manifest file when $manifest was last read. */
    private static ?int $manifestMtime = null;

    /** @var string[] Vite script handles needing type="module". */
    private static array $viteHandles = [];

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
        if (!MenuManager::isExamplePressPage($hookSuffix)) {
            return;
        }

        $pageId = MenuManager::pageIdFromHook($hookSuffix);
        if ($pageId === null) {
            return;
        }

        // Design system CSS (single stylesheet, shared across all EP pages).
        wp_enqueue_style(
            'ep-design-system',
            EXAMPLEPRESS_MU_URI . '/assets/src/css/index.css',
            [],
            EXAMPLEPRESS_MU_VERSION
        );

        self::enqueueProductionEntry($pageId);

        // Localize the page data payload.
        DataProvider::localize($pageId);
    }

    /**
     * Rewrite Vite script tags to type="module".
     */
    public static function injectModuleType(string $tag, string $handle): string
    {
        if (!in_array($handle, self::$viteHandles, true)) {
            return $tag;
        }

        // Remove any existing type attribute, then add type="module".
        $tag = preg_replace('/\stype=["\'][^"\']*["\']/', '', $tag) ?? $tag;
        $tag = str_replace('<script ', '<script type="module" ', $tag);

        return $tag;
    }

    /**
     * Enqueue JS + CSS for a Vite entry point from the production manifest.
     */
    private static function enqueueProductionEntry(string $entry): void
    {
        $manifest = self::resolveManifest();
        if (empty($manifest)) {
            $path = EXAMPLEPRESS_MU_DIR . '/dist/.vite/manifest.json';
            wp_add_inline_script('jquery', 'console.error("EP: Vite manifest missing or empty at " + ' . wp_json_encode($path) . ' + " (exists: " + ' . wp_json_encode(file_exists($path) ? 'yes' : 'no') . ' + ")");');
            return;
        }

        $asset = self::findEntry($manifest, $entry);
        if ($asset === null) {
            wp_add_inline_script('jquery', 'console.error("EP: No manifest entry for ' . esc_js($entry) . '. Keys:", ' . wp_json_encode(array_keys($manifest)) . ');');
            return;
        }

        // Collect CSS from the entry itself and all its shared-chunk imports.
        $cssFiles = $asset['css'] ?? [];
        foreach ($asset['imports'] ?? [] as $importKey) {
            $import = $manifest[$importKey] ?? null;
            if ($import && !empty($import['css'])) {
                $cssFiles = array_merge($cssFiles, $import['css']);
            }
        }

        foreach (array_unique($cssFiles) as $cssFile) {
            wp_enqueue_style(
                'ep-page-' . $entry,
                EXAMPLEPRESS_MU_URI . '/dist/' . $cssFile,
                ['ep-design-system'],
                EXAMPLEPRESS_MU_VERSION
            );
        }

        // Enqueue the JS entry point.
        $handle = 'ep-' . $entry;
        $scriptUrl = EXAMPLEPRESS_MU_URI . '/dist/' . $asset['file'];
        wp_enqueue_script($handle, $scriptUrl, [], null, true);
        self::$viteHandles[] = $handle;
    }

    /**
     * Find an entry in the manifest regardless of key prefix.
     *
     * Vite keys are relative to its root. When the root is the repo root,
     * keys are prefixed (e.g. "examplepress-mu/assets/src/apps/main.js").
     * When the root is examplepress-mu/, keys are short ("assets/src/apps/main.js").
     *
     * @return array<string, mixed>|null
     */
    private static function findEntry(array $manifest, string $entry): ?array
    {
        $suffix = 'assets/src/' . $entry . '/main.js';

        // Try exact suffix match against every key.
        foreach ($manifest as $key => $value) {
            if (str_ends_with($key, $suffix) && !empty($value['isEntry'])) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Read and cache the Vite manifest.
     *
     * @return array<string, mixed>
     */
    private static function resolveManifest(): array
    {
        $path = EXAMPLEPRESS_MU_DIR . '/dist/.vite/manifest.json';

        if (!file_exists($path)) {
            self::$manifest      = [];
            self::$manifestMtime = null;
            return self::$manifest;
        }

        // Bust the in-process cache when the manifest file has changed on
        // disk. Without this, long-lived PHP-FPM workers keep serving stale
        // chunk hashes after a rebuild — which causes the browser to load
        // an old bundle against the new DOM and NPE on missing elements.
        $mtime = (int) @filemtime($path);
        if (self::$manifest !== null && self::$manifestMtime === $mtime) {
            return self::$manifest;
        }

        $data = json_decode((string) file_get_contents($path), true);
        self::$manifest      = is_array($data) ? $data : [];
        self::$manifestMtime = $mtime;

        return self::$manifest;
    }
}
