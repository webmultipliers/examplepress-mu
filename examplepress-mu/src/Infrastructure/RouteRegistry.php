<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Multi-origin routing: companion plugins register which routes they
 * provide and under which Blockstudio namespace.
 */
final class RouteRegistry
{
    /** @var array<int, array<string, array<string, callable>>> */
    private static array $origins = [];

    /**
     * Register a route origin.
     */
    public static function register(string $namespace, array $routes, int $priority = 10): void
    {
        if (!isset(self::$origins[$priority])) {
            self::$origins[$priority] = [];
        }

        self::$origins[$priority][$namespace] = $routes;
    }

    /**
     * Resolve the current request against all registered route origins.
     *
     * @return array{namespace: string, slug: string}|null
     */
    public static function resolve(): ?array
    {
        if (empty(self::$origins)) {
            return null;
        }

        ksort(self::$origins, SORT_NUMERIC);

        foreach (self::$origins as $originsAtPriority) {
            foreach ($originsAtPriority as $namespace => $routes) {
                foreach ($routes as $slug => $condition) {
                    if (is_callable($condition) && call_user_func($condition)) {
                        return [
                            'namespace' => $namespace,
                            'slug'      => $slug,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check whether any route origins have been registered.
     */
    public static function hasOrigins(): bool
    {
        return !empty(self::$origins);
    }

    /**
     * Get all registered route origin namespaces.
     *
     * @return string[]
     */
    public static function namespaces(): array
    {
        $namespaces = [];

        foreach (self::$origins as $originsAtPriority) {
            foreach ($originsAtPriority as $namespace => $routes) {
                $namespaces[] = $namespace;
            }
        }

        return array_unique($namespaces);
    }

    /**
     * Full introspection map of all registered route origins.
     *
     * @return array<int, array{namespace: string, priority: int, routes: string[]}>
     */
    public static function map(): array
    {
        $result = [];

        ksort(self::$origins, SORT_NUMERIC);

        foreach (self::$origins as $priority => $origins) {
            foreach ($origins as $namespace => $routes) {
                $result[] = [
                    'namespace' => $namespace,
                    'priority'  => $priority,
                    'routes'    => array_keys($routes),
                ];
            }
        }

        return $result;
    }

    /**
     * Check if a specific route slug is claimed by any origin.
     */
    public static function slugOwner(string $slug): ?string
    {
        ksort(self::$origins, SORT_NUMERIC);

        foreach (self::$origins as $origins) {
            foreach ($origins as $namespace => $routes) {
                if (array_key_exists($slug, $routes)) {
                    return $namespace;
                }
            }
        }

        return null;
    }

    /**
     * Detect route slug conflicts across origins.
     *
     * @return array<string, array<int, array{namespace: string, priority: int}>>
     */
    public static function detectConflicts(): array
    {
        $slugClaims = [];

        foreach (self::$origins as $priority => $origins) {
            foreach ($origins as $namespace => $routes) {
                foreach (array_keys($routes) as $slug) {
                    $slugClaims[$slug][] = [
                        'namespace' => $namespace,
                        'priority'  => $priority,
                    ];
                }
            }
        }

        return array_filter($slugClaims, static fn(array $claims): bool => count($claims) > 1);
    }

    /**
     * Assert that the theme directory has not been modified.
     *
     * @return string[] List of unexpected files.
     */
    public static function checkThemeImmutability(): array
    {
        $knownDirs = ['blockstudio', 'demo', 'docs', 'inc', 'languages', 'templates', 'vendor'];
        $knownRootFiles = [
            'blockstudio.json', 'composer.json', 'composer.lock', 'examplepress.json',
            'functions.php', 'screenshot.png', 'style.css', 'theme.json',
        ];

        $themePath = defined('EP_THEME_PATH') ? EP_THEME_PATH : get_template_directory();
        $unexpected = [];
        $entries = @scandir($themePath);

        if (!$entries) {
            return $unexpected;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $isDir = is_dir($themePath . '/' . $entry);
            $isKnown = $isDir
                ? in_array($entry, $knownDirs, true)
                : in_array($entry, $knownRootFiles, true);

            if (!$isKnown) {
                $unexpected[] = $entry;
            }
        }

        return $unexpected;
    }
}
