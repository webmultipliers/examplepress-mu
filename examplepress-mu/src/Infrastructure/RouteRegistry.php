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
     * @var array<int, array{namespace: string, slug: string, priority: int, existing_namespace: string}>
     * Conflicts detected during register() calls — recorded at registration
     * time so the admin Route Visualizer can surface them without having to
     * re-walk the registry.
     */
    private static array $conflicts = [];

    /** Slug/namespace format enforced at registration. Matches the schema. */
    private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

    /** Priority bounds match schema/examplepress-app.json routing.priority. */
    private const MIN_PRIORITY = 1;
    private const MAX_PRIORITY = 99;

    /**
     * Register a route origin.
     *
     * Validates inputs at registration time. Failures are logged and the
     * entire call is dropped — partial registrations would be confusing.
     * A colliding slug at the SAME priority against an already-registered
     * namespace is logged as a conflict but the new registration still
     * takes effect for non-colliding slugs (via a filtered copy).
     *
     * @param string                  $namespace Block namespace the companion plugin claims.
     * @param array<string, callable> $routes    Map of slug => is-this-current-page condition.
     * @param int                     $priority  Lower wins; default 10.
     */
    public static function register(string $namespace, array $routes, int $priority = 10): void
    {
        // Namespace must be a valid slug.
        if ($namespace === '' || !preg_match(self::SLUG_PATTERN, $namespace)) {
            self::logRegistrationError(sprintf(
                'RouteRegistry::register rejected namespace "%s" — must match %s.',
                $namespace,
                self::SLUG_PATTERN
            ));
            return;
        }

        // Priority must be in the documented range.
        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY) {
            self::logRegistrationError(sprintf(
                'RouteRegistry::register rejected namespace "%s" — priority %d out of range [%d..%d].',
                $namespace,
                $priority,
                self::MIN_PRIORITY,
                self::MAX_PRIORITY
            ));
            return;
        }

        // Routes array must be non-empty — an origin with zero routes is
        // almost always a bug in the caller's routing conditionals.
        if (empty($routes)) {
            self::logRegistrationError(sprintf(
                'RouteRegistry::register rejected namespace "%s" — empty routes array.',
                $namespace
            ));
            return;
        }

        // Validate each entry: slug format + callable condition.
        $validated = [];
        foreach ($routes as $slug => $condition) {
            if (!is_string($slug) || !preg_match(self::SLUG_PATTERN, $slug)) {
                self::logRegistrationError(sprintf(
                    'RouteRegistry::register: namespace "%s" dropped invalid slug "%s" — must match %s.',
                    $namespace,
                    is_string($slug) ? $slug : gettype($slug),
                    self::SLUG_PATTERN
                ));
                continue;
            }
            if (!is_callable($condition)) {
                self::logRegistrationError(sprintf(
                    'RouteRegistry::register: namespace "%s" slug "%s" dropped — condition is not callable (got %s).',
                    $namespace,
                    $slug,
                    gettype($condition)
                ));
                continue;
            }
            $validated[$slug] = $condition;
        }

        if (empty($validated)) {
            self::logRegistrationError(sprintf(
                'RouteRegistry::register rejected namespace "%s" — every route entry failed validation.',
                $namespace
            ));
            return;
        }

        // Detect conflicts with already-registered routes at the SAME
        // priority. Resolution order inside a priority bucket is
        // "whichever was registered first wins", which is arbitrary —
        // log it so operators can reshuffle priorities.
        if (isset(self::$origins[$priority])) {
            foreach (self::$origins[$priority] as $existingNamespace => $existingRoutes) {
                foreach (array_keys($validated) as $newSlug) {
                    if (array_key_exists($newSlug, $existingRoutes) && $existingNamespace !== $namespace) {
                        self::$conflicts[] = [
                            'namespace'          => $namespace,
                            'slug'               => $newSlug,
                            'priority'           => $priority,
                            'existing_namespace' => $existingNamespace,
                        ];
                        self::logRegistrationError(sprintf(
                            'RouteRegistry::register: namespace "%s" slug "%s" at priority %d collides with existing namespace "%s". First registration wins; consider changing priority.',
                            $namespace,
                            $newSlug,
                            $priority,
                            $existingNamespace
                        ));
                    }
                }
            }
        }

        if (!isset(self::$origins[$priority])) {
            self::$origins[$priority] = [];
        }

        // If the same namespace re-registers at the same priority, merge
        // so a caller can add routes incrementally.
        if (isset(self::$origins[$priority][$namespace])) {
            self::$origins[$priority][$namespace] = array_merge(
                self::$origins[$priority][$namespace],
                $validated
            );
        } else {
            self::$origins[$priority][$namespace] = $validated;
        }
    }

    /**
     * Return every conflict recorded during register() calls this request.
     *
     * @return array<int, array{namespace: string, slug: string, priority: int, existing_namespace: string}>
     */
    public static function registrationConflicts(): array
    {
        return self::$conflicts;
    }

    /**
     * Log a registration-time validation error. Uses error_log directly
     * rather than do_action so failures are visible even before any
     * logging hooks are in place.
     */
    private static function logRegistrationError(string $message): void
    {
        error_log('[ExamplePress RouteRegistry] ' . $message);
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
     * Dot-prefixed entries (.git, .github, .gitignore, .distignore, .vscode,
     * etc.) are silently skipped — they're always dev/VCS metadata, never
     * load-bearing theme code, and flagging them produces noisy
     * false-positives in developer environments.
     *
     * The default whitelist covers a stock ExamplePress theme plus common
     * build-tooling files (package.json, vite.config.js, node_modules, etc).
     * Operators can extend via filters:
     *   - examplepress_mu_theme_immutability_dirs  (default dir whitelist)
     *   - examplepress_mu_theme_immutability_files (default file whitelist)
     *
     * @return string[] List of unexpected files or directories.
     */
    public static function checkThemeImmutability(): array
    {
        $defaultDirs = [
            'blockstudio', 'demo', 'docs', 'inc', 'languages', 'node_modules',
            'templates', 'vendor',
        ];
        $defaultFiles = [
            'AGENTS.md',
            'blockstudio.json',
            'composer.json', 'composer.lock',
            'examplepress.json',
            'functions.php',
            'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock',
            'postcss.config.js', 'tailwind.config.js', 'vite.config.js',
            'tsconfig.json',
            'README.md', 'readme.txt',
            'screenshot.png',
            'style.css',
            'theme.json',
        ];

        /**
         * Filter the whitelist of allowed theme-root directories.
         *
         * @param string[] $dirs Default directory whitelist.
         */
        $knownDirs = (array) apply_filters(
            'examplepress_mu_theme_immutability_dirs',
            $defaultDirs
        );

        /**
         * Filter the whitelist of allowed theme-root files.
         *
         * @param string[] $files Default file whitelist.
         */
        $knownRootFiles = (array) apply_filters(
            'examplepress_mu_theme_immutability_files',
            $defaultFiles
        );

        $themePath  = ThemeManifest::path();
        $unexpected = [];
        $entries    = @scandir($themePath);

        if (!$entries) {
            return $unexpected;
        }

        foreach ($entries as $entry) {
            // Skip '.', '..', and every other dot-prefixed entry —
            // they're always VCS or dev metadata.
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }

            $isDir   = is_dir($themePath . '/' . $entry);
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
