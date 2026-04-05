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
}
