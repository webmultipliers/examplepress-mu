<?php

declare(strict_types=1);

namespace ExamplePress\MU\Agent;

use ExamplePress\MU\Config\FeatureRegistry;

/**
 * Merge-tag resolver for the agent skill curriculum.
 *
 * Tags use {{name}} syntax inside skill markdown files. Each tag is
 * resolved at compile-time against the live install — color palette,
 * font sizes, theme slug, registered blocks, etc. — so the LLM
 * generates code that matches the actual environment, not a guess.
 *
 * Built-in tags are registered lazily on first use. Operators can
 * register additional tags via the `examplepress_mu_agent_merge_tags`
 * filter or by calling MergeTags::register() directly.
 */
final class MergeTags
{
    /** @var array<string, callable(): mixed> */
    private static array $resolvers = [];
    private static bool $bootstrapped = false;

    /**
     * Register a tag resolver. The callable receives no arguments and
     * returns a scalar (used as-is) or array/object (JSON-encoded).
     */
    public static function register(string $tag, callable $resolver): void
    {
        self::$resolvers[$tag] = $resolver;
    }

    /**
     * Resolve a single tag to its string representation. Returns an
     * empty string if no resolver is registered. Arrays/objects are
     * pretty-printed JSON. Throwables are caught so a misbehaving
     * resolver can never crash a generation job.
     */
    public static function resolve(string $tag): string
    {
        self::bootstrap();

        if (!isset(self::$resolvers[$tag])) {
            return '';
        }

        try {
            $value = (self::$resolvers[$tag])();
        } catch (\Throwable $e) {
            error_log("ExamplePress agent merge tag {$tag} failed: " . $e->getMessage());
            return '';
        }

        if ($value === null || $value === false) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        // Prefer wp_json_encode (handles WP-specific edge cases) but fall
        // back to native json_encode if it returns null/false so we never
        // silently emit an empty string for a non-empty payload.
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : false;
        if (!is_string($encoded) || $encoded === '') {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Substitute every {{tag}} occurrence in the body. Unknown tags are
     * left in place so an operator notices stale references in the
     * curriculum rather than getting silent empty strings.
     */
    public static function apply(string $body): string
    {
        self::bootstrap();

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $m): string {
                $tag = $m[1];
                // Registered tags substitute to whatever they resolve to,
                // INCLUDING empty string (a valid "no value yet" state).
                // Unregistered tags are left in place so operators notice
                // stale references in the curriculum.
                if (!isset(self::$resolvers[$tag])) {
                    return $m[0];
                }
                return self::resolve($tag);
            },
            $body
        );
    }

    /**
     * Return the full resolved tag map. Used by the skills preview UI
     * so operators can see exactly what every tag will expand to.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        self::bootstrap();

        $out = [];
        foreach (array_keys(self::$resolvers) as $tag) {
            $out[$tag] = self::resolve($tag);
        }
        ksort($out);
        return $out;
    }

    /**
     * Register the built-in tag set. Idempotent.
     */
    private static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        // ── Site identity ───────────────────────────────────────
        self::register('site_name', static fn (): string => (string) get_bloginfo('name'));
        self::register('site_url',  static fn (): string => (string) home_url());
        self::register('admin_url', static fn (): string => (string) admin_url());

        // ── Kernel ──────────────────────────────────────────────
        self::register('kernel_version', static fn (): string => (string) (defined('EXAMPLEPRESS_MU_VERSION') ? EXAMPLEPRESS_MU_VERSION : ''));
        self::register('kernel_api_version', static function (): string {
            if (class_exists(\ExamplePress\MU\Infrastructure\Router::class)
                && defined(\ExamplePress\MU\Infrastructure\Router::class . '::API_VERSION')) {
                return (string) \ExamplePress\MU\Infrastructure\Router::API_VERSION;
            }
            return '1';
        });
        self::register('template_prefix', static function (): string {
            if (class_exists(\ExamplePress\MU\Infrastructure\Router::class)
                && method_exists(\ExamplePress\MU\Infrastructure\Router::class, 'templatePrefix')) {
                return \ExamplePress\MU\Infrastructure\Router::templatePrefix();
            }
            return 'template';
        });

        // ── Theme ───────────────────────────────────────────────
        self::register('theme_slug', static function (): string {
            return function_exists('get_stylesheet') ? (string) get_stylesheet() : 'examplepress-theme';
        });
        self::register('theme_name', static function (): string {
            if (!function_exists('wp_get_theme')) {
                return '';
            }
            $t = wp_get_theme();
            return is_object($t) && method_exists($t, 'get') ? (string) $t->get('Name') : '';
        });
        self::register('theme_version', static function (): string {
            if (!function_exists('wp_get_theme')) {
                return '';
            }
            $t = wp_get_theme();
            return is_object($t) && method_exists($t, 'get') ? (string) $t->get('Version') : '';
        });

        // ── Design tokens (live from FeatureRegistry) ───────────
        self::register('color_palette', static function (): array {
            $palette = (array) FeatureRegistry::option('theme-colors', 'palette', []);
            return self::normalisePalette($palette);
        });

        self::register('color_slugs', static function (): string {
            $palette = (array) FeatureRegistry::option('theme-colors', 'palette', []);
            $slugs = [];
            foreach ($palette as $entry) {
                if (!empty($entry['slug'])) {
                    $slugs[] = '- ' . $entry['slug'];
                }
            }
            return implode("\n", $slugs);
        });

        self::register('wp_color_vars', static function (): string {
            $palette = (array) FeatureRegistry::option('theme-colors', 'palette', []);
            $vars = [];
            foreach ($palette as $entry) {
                if (!empty($entry['slug'])) {
                    $slug = $entry['slug'];
                    $vars[] = "--wp--preset--color--{$slug}: " . ($entry['color'] ?? '?') . ';';
                }
            }
            return implode("\n", $vars);
        });

        self::register('font_families', static function (): array {
            return (array) FeatureRegistry::option('theme-typography', 'font_families', []);
        });

        self::register('font_sizes', static function (): array {
            return (array) FeatureRegistry::option('theme-typography', 'font_sizes', []);
        });

        self::register('wp_font_size_vars', static function (): string {
            $sizes = (array) FeatureRegistry::option('theme-typography', 'font_sizes', []);
            $vars = [];
            foreach ($sizes as $size) {
                if (!empty($size['slug'])) {
                    $slug = $size['slug'];
                    $vars[] = "--wp--preset--font-size--{$slug}: " . ($size['size'] ?? '?') . ';';
                }
            }
            return implode("\n", $vars);
        });

        self::register('wide_size', static fn (): string => (string) FeatureRegistry::option('theme-layout', 'wide_size', '1200px'));
        self::register('content_size', static fn (): string => (string) FeatureRegistry::option('theme-layout', 'content_size', '800px'));

        self::register('spacing_sizes', static function (): array {
            return (array) FeatureRegistry::option('theme-spacing', 'spacing_sizes', []);
        });

        // ── Routing & blocks ───────────────────────────────────
        self::register('available_blocks', static function (): string {
            if (!class_exists(\WP_Block_Type_Registry::class)) {
                return '';
            }
            $registry = \WP_Block_Type_Registry::get_instance();
            $types = $registry->get_all_registered();
            $bs = [];
            foreach ($types as $name => $_type) {
                if (str_starts_with((string) $name, 'examplepress-theme/')
                    || str_starts_with((string) $name, 'blockstudio/')) {
                    $bs[] = '- ' . $name;
                }
            }
            sort($bs);
            return implode("\n", $bs);
        });

        // ── ExamplePress contract ───────────────────────────────
        self::register('manifest_schema_url', static fn (): string => 'examplepress.json');

        // ── Operator extension hook ─────────────────────────────
        // Allow filters to add or override tags after the built-ins
        // are registered. Filter receives the [tag => resolver] map.
        /** @var array<string, callable> $extra */
        $extra = (array) apply_filters('examplepress_mu_agent_merge_tags', []);
        foreach ($extra as $tag => $resolver) {
            if (is_string($tag) && is_callable($resolver)) {
                self::$resolvers[$tag] = $resolver;
            }
        }
    }

    /**
     * Normalise a palette array into the canonical [{slug,name,color}]
     * shape so the LLM sees a stable schema even when the source mixes
     * theme.json-style entries with feature-options entries.
     *
     * @param array<int|string, mixed> $palette
     * @return array<int, array{slug:string,name:string,color:string}>
     */
    private static function normalisePalette(array $palette): array
    {
        $out = [];
        foreach ($palette as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = (string) ($entry['slug'] ?? (is_string($key) ? $key : ''));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'slug'  => $slug,
                'name'  => (string) ($entry['name'] ?? ucfirst(str_replace('-', ' ', $slug))),
                'color' => (string) ($entry['color'] ?? ''),
            ];
        }
        return $out;
    }
}
