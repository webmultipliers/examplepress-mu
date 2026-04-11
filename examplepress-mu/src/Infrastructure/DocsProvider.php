<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

use ExamplePress\MU\Config\ConfigManager;

/**
 * Resolves the list of documentation links shown in the admin Docs tab.
 *
 * Three layers, merged in priority order (last write wins per link):
 *   1. Hardcoded kernel defaults (this class).
 *   2. config['docs'] from ConfigManager (MU + theme examplepress.json
 *      deep-merge — lets a theme ship its own doc set).
 *   3. examplepress_mu_docs_links filter — lets operators override
 *      without editing code, which is important for fleet operators
 *      running mirrored docs or forks.
 *
 * Previously the defaults lived in examplepress-mu/examplepress.json as
 * hardcoded URLs pinned to webmultipliers/examplepress-theme on the
 * `development` branch — a brittle data-in-config pattern that broke
 * silently whenever the upstream repo was renamed or its branches moved.
 * Moving them to PHP keeps the data close to the code that owns the
 * contract and lets forks change defaults without editing JSON.
 */
final class DocsProvider
{
    /**
     * Kernel-owned default documentation links. Intentionally keyed by
     * stable identifiers so the filter below can target a specific entry
     * for replacement (e.g. `$links['companion-plugin']['url'] = ...`).
     *
     * @return array<string, array{title: string, description: string, url: string, category: string}>
     */
    public static function defaults(): array
    {
        $base = 'https://github.com/webmultipliers/examplepress-theme/blob/development/docs';

        return [
            'companion-plugin' => [
                'title'       => 'Companion Plugin Guide',
                'description' => 'Build your first companion plugin: hook the router, register blocks, and take ownership of the frontend.',
                'url'         => $base . '/companion-plugin.md',
                'category'    => 'Start Here',
            ],
            'routing' => [
                'title'       => 'Router & Routing',
                'description' => 'How the single-entry-point router works, the filter chain, and routing cascade.',
                'url'         => $base . '/routing.md',
                'category'    => 'Architecture',
            ],
            'guards' => [
                'title'       => 'Guard System',
                'description' => 'Template lockdown layers and how to disable guards for development.',
                'url'         => $base . '/guards.md',
                'category'    => 'Architecture',
            ],
            'configuration' => [
                'title'       => 'Configuration Guide',
                'description' => 'JSON files, design tokens, strict mode, and the config loading pipeline.',
                'url'         => $base . '/configuration.md',
                'category'    => 'Configuration',
            ],
            'feature-registry' => [
                'title'       => 'Feature Registry API',
                'description' => 'Register features, check state, read options, and the filterable flag system.',
                'url'         => $base . '/feature-registry.md',
                'category'    => 'Configuration',
            ],
            'blockstudio' => [
                'title'       => 'Blockstudio',
                'description' => 'Block registration, fields, rendering, and hooks.',
                'url'         => 'https://blockstudio.dev/documentation/',
                'category'    => 'Blockstudio',
            ],
        ];
    }

    /**
     * Resolve the final list of documentation links, merged across all
     * three layers.
     *
     * Returns a positional array of link records (not keyed) so the
     * admin UI can render in filter-defined order.
     *
     * @return array<int, array{title: string, description: string, url: string, category: string}>
     */
    public static function get(): array
    {
        $defaults = self::defaults();

        // Layer 2: theme/MU config deep-merge result. Accepts either a
        // positional list (legacy shape from MU's old examplepress.json)
        // or an associative map keyed by stable id (preferred).
        $config = ConfigManager::get();
        $fromConfig = $config['docs'] ?? [];

        if (is_array($fromConfig)) {
            foreach ($fromConfig as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (is_string($key) && $key !== '') {
                    // Keyed override — merge field-by-field so a partial
                    // override (e.g. just replacing url) leaves other
                    // fields intact.
                    $defaults[$key] = array_merge($defaults[$key] ?? [], $entry);
                } else {
                    // Positional — append as a new entry. Can't be
                    // targeted for partial override, but it's the shape
                    // the theme's examplepress.json uses today.
                    $defaults[] = $entry;
                }
            }
        }

        /**
         * Filter the final list of admin Docs tab links.
         *
         * Receives the merged array (defaults + config overrides), keyed
         * by stable ids for default entries and positional for
         * theme/config-supplied entries. Filter callbacks can:
         *   - Replace a default entry: `$links['routing']['url'] = 'https://...';`
         *   - Remove a default: `unset($links['blockstudio']);`
         *   - Add a new one:    `$links[] = ['title' => ..., 'url' => ...];`
         *
         * @param array<string|int, array<string, string>> $links Merged link set.
         */
        $filtered = apply_filters('examplepress_mu_docs_links', $defaults);

        if (!is_array($filtered)) {
            return array_values($defaults);
        }

        // Normalise to a positional list and drop any entry that fails
        // the minimum shape (needs at least title + url).
        $out = [];
        foreach ($filtered as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $title = (string) ($entry['title'] ?? '');
            $url   = (string) ($entry['url'] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }
            $out[] = [
                'title'       => $title,
                'description' => (string) ($entry['description'] ?? ''),
                'url'         => $url,
                'category'    => (string) ($entry['category'] ?? ''),
            ];
        }

        return $out;
    }
}
