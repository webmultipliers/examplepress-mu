<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Core routing engine.
 *
 * The theme's templates/index.html block calls this namespaced class,
 * entirely avoiding the fatal redeclaration errors of the past.
 */
final class Router
{
    /**
     * Kernel API contract version. Themes/plugins assert against this to
     * verify compatibility with the platform. Bump when the kernel-facing
     * API changes in a breaking way.
     */
    public const API_VERSION = 1;

    /**
     * Resolve the current route via the route origin registry.
     *
     * @return array{namespace: string, slug: string}
     */
    public static function resolveRoute(): array
    {
        $origin = RouteRegistry::resolve();

        if ($origin) {
            return apply_filters('examplepress_mu_resolved_origin', $origin);
        }

        return [
            'namespace' => 'examplepress-theme',
            'slug'      => 'get-started',
        ];
    }

    /**
     * Get the template block prefix.
     */
    public static function templatePrefix(): string
    {
        return (string) apply_filters('examplepress_mu_template_prefix', 'template');
    }

    /**
     * Build the fully-qualified block name for a template slug.
     */
    public static function templateBlockName(string $slug, string $prefix, string $themeNs): string
    {
        return (string) apply_filters(
            'examplepress_mu_template_block_name',
            sprintf('%s/%s-%s', $themeNs, $prefix, $slug),
            $slug,
            $prefix,
            $themeNs
        );
    }

    /**
     * Register Blockstudio integration filters.
     *
     * Disables the default frontend wrapper around router and template blocks
     * so they render as transparent shells. This ships from the platform so
     * the theme stays free of behavioural code.
     */
    public static function init(): void
    {
        add_filter(
            'blockstudio/blocks/components/inner_blocks/frontend/wrap',
            [self::class, 'filterInnerBlocksWrap'],
            10,
            2
        );

        // Public global helper companion apps use to claim a routing
        // namespace + URL conditions. Thin wrapper around RouteRegistry
        // so the template repo's documented call site works as-is.
        if (!function_exists('examplepress_register_route_origin')) {
            /**
             * Register a route origin for an ExamplePress companion app.
             *
             * @param string                       $namespace  Block namespace this app claims (matches the plugin slug).
             * @param array<string, callable>      $routes     Map of slug => is-this-current-page condition.
             * @param int                          $priority   Lower wins; default 10.
             */
            function examplepress_register_route_origin(string $namespace, array $routes, int $priority = 10): void
            {
                RouteRegistry::register($namespace, $routes, $priority);
            }
        }
    }

    /**
     * @param mixed  $render Whether to wrap the inner blocks.
     * @param object $block  The Blockstudio block being rendered.
     * @return mixed
     */
    public static function filterInnerBlocksWrap($render, $block)
    {
        if (!isset($block->name) || !is_string($block->name)) {
            return $render;
        }

        if (strpos($block->name, 'examplepress-theme/router') === 0) {
            return false;
        }

        $templatePrefix = self::templatePrefix();

        $namespaces   = RouteRegistry::namespaces();
        $namespaces[] = 'examplepress-theme';
        $namespaces   = array_unique($namespaces);

        foreach ($namespaces as $ns) {
            if (strpos($block->name, sprintf('%s/%s-', $ns, $templatePrefix)) === 0) {
                return false;
            }
        }

        return $render;
    }
}
