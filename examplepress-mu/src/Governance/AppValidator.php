<?php

declare(strict_types=1);

namespace ExamplePress\MU\Governance;

/**
 * Zero-trust plugin validation.
 *
 * Hooks both option_active_plugins (single site) and
 * site_option_active_sitewide_plugins (Multisite) so that
 * network-activated plugins cannot bypass governance.
 */
final class AppValidator
{
    /** Option key used to persist the validated plugin list across requests. */
    private const CACHE_OPTION = 'ep_mu_app_validator_cache';

    /** @var array<string, bool> Per-request validation cache. */
    private static array $cache = [];

    /** @var array<string, bool>|null Persistent cache hydrated once per request. */
    private static ?array $persistentCache = null;

    /** @var string|null Hash of the input list that produced $persistentCache. */
    private static ?string $persistentHash = null;

    /** @var bool Marks the persistent cache dirty so shutdown can flush it. */
    private static bool $persistentDirty = false;

    public static function init(): void
    {
        // Single site: filter active plugins before WordPress loads them.
        add_filter('option_active_plugins', [self::class, 'filterActivePlugins']);

        // Multisite: also filter network-activated plugins.
        add_filter('site_option_active_sitewide_plugins', [self::class, 'filterSitewidePlugins']);

        // Invalidate the persistent cache whenever the active-plugins option
        // changes — that's the only moment the validated list can drift.
        add_action('update_option_active_plugins', [self::class, 'invalidatePersistentCache']);
        add_action('update_site_option_active_sitewide_plugins', [self::class, 'invalidatePersistentCache']);

        // Defer persistent writes to shutdown so we never call update_option()
        // from inside an option filter (which runs during plugin-load and
        // would force extra synchronous DB queries).
        add_action('shutdown', [self::class, 'flushDirty']);
    }

    /**
     * Build a cache key from the plugin list AND the mtime of each
     * declared-ExamplePress manifest file. This way, editing
     * examplepress.json in an installed app invalidates the cached
     * decision without needing an explicit flushCache() call.
     *
     * @param list<string> $plugins Plugin basenames.
     */
    private static function buildHash(string $scope, array $plugins): string
    {
        $parts = [];
        foreach ($plugins as $basename) {
            $manifest = WP_PLUGIN_DIR . '/' . dirname($basename) . '/examplepress.json';
            $mtime    = is_readable($manifest) ? (int) @filemtime($manifest) : 0;
            $parts[]  = $basename . '@' . $mtime;
        }
        sort($parts);

        // Include the current blog id on multisite. Two blogs may register
        // different examplepress_mu_validate_app filter callbacks, and we
        // must not let one blog's cached decision leak into another's.
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

        return md5($scope . '|blog=' . $blogId . '|' . implode('|', $parts));
    }

    /**
     * Hydrate the persistent cache for a specific plugin-list hash. If the
     * stored hash doesn't match the current input, the cache is reset.
     */
    private static function hydratePersistent(string $hash): void
    {
        if (self::$persistentHash === $hash && self::$persistentCache !== null) {
            return;
        }

        $stored = get_option(self::CACHE_OPTION, null);

        if (is_array($stored) && ($stored['hash'] ?? '') === $hash && is_array($stored['results'] ?? null)) {
            self::$persistentCache = $stored['results'];
        } else {
            self::$persistentCache = [];
        }

        self::$persistentHash  = $hash;
        self::$persistentDirty = false;
    }

    /**
     * Shutdown callback: write the persistent cache to the DB iff it was
     * modified during the request. Never called from inside an option filter.
     */
    public static function flushDirty(): void
    {
        if (!self::$persistentDirty || self::$persistentHash === null || self::$persistentCache === null) {
            return;
        }
        update_option(
            self::CACHE_OPTION,
            ['hash' => self::$persistentHash, 'results' => self::$persistentCache],
            false
        );
        self::$persistentDirty = false;
    }

    public static function invalidatePersistentCache(): void
    {
        self::$persistentCache = null;
        self::$persistentHash  = null;
        self::$persistentDirty = false;
        delete_option(self::CACHE_OPTION);
    }

    /**
     * Filter the list of active plugins (single site).
     *
     * @param mixed $plugins List of active plugin basenames.
     * @return array<int, string>
     */
    public static function filterActivePlugins(mixed $plugins): array
    {
        if (!is_array($plugins)) {
            return [];
        }

        // Hydrate the persistent cache using a hash that reflects both the
        // input list AND each manifest's mtime, so we only re-run
        // get_file_data() / re-read manifests when something actually changes.
        $hash = self::buildHash('single', array_values($plugins));
        self::hydratePersistent($hash);

        // The persistent write is deferred to the shutdown hook to avoid
        // doing DB writes from inside an option filter.
        return array_values(array_filter($plugins, [self::class, 'validatePlugin']));
    }

    /**
     * Filter sitewide plugins (Multisite).
     *
     * Multisite stores sitewide plugins as slug => timestamp.
     *
     * @param mixed $plugins Associative array of plugin_file => timestamp.
     * @return array<string, int>
     */
    public static function filterSitewidePlugins(mixed $plugins): array
    {
        if (!is_array($plugins)) {
            return [];
        }

        $hash = self::buildHash('sitewide', array_keys($plugins));
        self::hydratePersistent($hash);

        $filtered = [];
        foreach ($plugins as $pluginBasename => $timestamp) {
            if (self::validatePlugin($pluginBasename)) {
                $filtered[$pluginBasename] = $timestamp;
            }
        }

        return $filtered;
    }

    /**
     * Validate a single plugin. Returns true if the plugin should remain active.
     *
     * Non-ExamplePress plugins always pass (we only govern our own ecosystem).
     */
    private static function validatePlugin(string $pluginBasename): bool
    {
        if (isset(self::$cache[$pluginBasename])) {
            return self::$cache[$pluginBasename];
        }

        // Persistent cache: skip disk I/O entirely when we've already
        // validated this basename under the current plugin-list hash.
        if (self::$persistentCache !== null && array_key_exists($pluginBasename, self::$persistentCache)) {
            $decision = self::$persistentCache[$pluginBasename];
            self::$cache[$pluginBasename] = $decision;
            return $decision;
        }

        $pluginDir = WP_PLUGIN_DIR . '/' . dirname($pluginBasename);
        $pluginFile = WP_PLUGIN_DIR . '/' . $pluginBasename;

        if (!file_exists($pluginFile)) {
            self::record($pluginBasename, true);
            return true;
        }

        $headers = get_file_data($pluginFile, ['Theme' => 'Theme']);

        if (empty($headers['Theme']) || 'examplepress-theme' !== $headers['Theme']) {
            self::record($pluginBasename, true);
            return true;
        }

        // This is a declared ExamplePress app. Validate it.
        $manifestPath = $pluginDir . '/examplepress.json';

        // Rule 1: Manifest must exist and be valid JSON.
        if (!file_exists($manifestPath)) {
            self::reject($pluginBasename, 'Missing examplepress.json manifest.');
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
            self::reject($pluginBasename, 'Invalid JSON in examplepress.json.');
            return false;
        }

        // Rule 2: Required fields.
        if (empty($manifest['name']) || empty($manifest['slug'])) {
            self::reject($pluginBasename, 'Manifest missing required fields (name, slug).');
            return false;
        }

        // Rule 3: No banned permissions (filterable; empty by default).
        /** @var array<int, string> $banned */
        $banned = (array) apply_filters('examplepress_mu_banned_permissions', []);
        $requested = $manifest['permissions'] ?? [];
        if (!empty($banned) && is_array($requested)) {
            $bannedFound = array_intersect($requested, $banned);
            if (!empty($bannedFound)) {
                self::reject(
                    $pluginBasename,
                    'Manifest requests banned permissions: ' . implode(', ', $bannedFound)
                );
                return false;
            }
        }

        /**
         * Final escape hatch: allow validators to override the result.
         * Return false to forcibly reject; true to accept.
         *
         * @param bool   $valid          Current validation state (true = accept).
         * @param string $pluginBasename Plugin file (e.g. 'foo/foo.php').
         * @param array  $manifest       Decoded examplepress.json manifest.
         */
        $valid = (bool) apply_filters('examplepress_mu_validate_app', true, $pluginBasename, $manifest);

        if (!$valid) {
            self::reject($pluginBasename, 'Rejected by examplepress_mu_validate_app filter.');
            return false;
        }

        self::record($pluginBasename, true);
        return true;
    }

    /**
     * Write a validation decision to both the per-request and persistent caches.
     */
    private static function record(string $pluginBasename, bool $decision): void
    {
        self::$cache[$pluginBasename] = $decision;
        if (self::$persistentCache !== null) {
            // Only mark dirty if we actually changed something.
            if (!array_key_exists($pluginBasename, self::$persistentCache)
                || self::$persistentCache[$pluginBasename] !== $decision) {
                self::$persistentCache[$pluginBasename] = $decision;
                self::$persistentDirty = true;
            }
        }
    }

    /**
     * Flush the per-request and persistent validation caches. Call after admin
     * "refresh" actions where the underlying manifest may have changed.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
        self::invalidatePersistentCache();
    }

    /**
     * Zero-trust validation for AI-generated app payloads.
     *
     * Runs BEFORE any code touches disk or GitHub. A failure here means
     * the generation is discarded — no repo, no commit, no install.
     *
     * @param array<string,mixed>                              $manifest Decoded examplepress.json the LLM produced.
     * @param array<int,array{path:string,contents:string}>   $files    File list the LLM produced.
     * @return array{ok:bool,errors:array<int,string>}
     */
    public static function validateGenerated(array $manifest, array $files): array
    {
        $errors = [];

        // Manifest: required fields.
        if (empty($manifest['name']) || !is_string($manifest['name'])) {
            $errors[] = 'Manifest missing required field: name.';
        }
        if (empty($manifest['slug']) || !is_string($manifest['slug']) || !preg_match('/^[a-z0-9-]+$/', $manifest['slug'])) {
            $errors[] = 'Manifest missing or invalid field: slug (must match [a-z0-9-]+).';
        }

        // Manifest: supports_ai_iteration must be boolean if present.
        if (array_key_exists('supports_ai_iteration', $manifest) && !is_bool($manifest['supports_ai_iteration'])) {
            $errors[] = 'Manifest field supports_ai_iteration must be a boolean.';
        }

        // Manifest: banned permissions (reuses existing filter).
        /** @var array<int,string> $banned */
        $banned = (array) apply_filters('examplepress_mu_banned_permissions', []);
        $requested = $manifest['permissions'] ?? [];
        if (!empty($banned) && is_array($requested)) {
            $found = array_intersect($requested, $banned);
            if (!empty($found)) {
                $errors[] = 'Manifest requests banned permissions: ' . implode(', ', $found);
            }
        }

        // Files: must be a non-empty list.
        if (empty($files)) {
            $errors[] = 'Generated payload contains no files.';
        }

        // Banned PHP tokens — hard reject.
        // base64_decode is rejected only when invoked on a variable; literal
        // string decodes are still allowed (and can be widened via filter).
        $bannedPhpPatterns = [
            '/\beval\s*\(/i'                       => 'eval()',
            '/\bexec\s*\(/i'                       => 'exec()',
            '/\bsystem\s*\(/i'                     => 'system()',
            '/\bshell_exec\s*\(/i'                 => 'shell_exec()',
            '/\bpassthru\s*\(/i'                   => 'passthru()',
            '/\bproc_open\s*\(/i'                  => 'proc_open()',
            '/\bpopen\s*\(/i'                      => 'popen()',
            '/`[^`]*\$[^`]*`/'                     => 'backtick operator',
            '/\bbase64_decode\s*\(\s*\$/i'         => 'base64_decode($variable)',
        ];

        foreach ($files as $i => $file) {
            if (!is_array($file) || !isset($file['path'], $file['contents'])) {
                $errors[] = "File entry #{$i} is malformed (expected {path,contents}).";
                continue;
            }

            $path     = (string) $file['path'];
            $contents = (string) $file['contents'];

            // Path traversal / absolute path / escape.
            if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $path)) {
                $errors[] = "File path is unsafe: {$path}";
                continue;
            }

            // PHP file scan.
            if (str_ends_with($path, '.php')) {
                foreach ($bannedPhpPatterns as $pattern => $label) {
                    if (preg_match($pattern, $contents)) {
                        $errors[] = "File {$path} contains banned token: {$label}";
                    }
                }
            }
        }

        $result = [
            'ok'     => empty($errors),
            'errors' => $errors,
        ];

        /**
         * Filter the result of generated-app validation. Return an array
         * matching the same shape to override.
         *
         * @param array{ok:bool,errors:array<int,string>} $result
         * @param array<string,mixed>                     $manifest
         * @param array<int,array{path:string,contents:string}> $files
         */
        return apply_filters('examplepress_mu_validate_generated_app', $result, $manifest, $files);
    }

    private static function reject(string $pluginBasename, string $reason): void
    {
        self::record($pluginBasename, false);
        error_log(sprintf(
            'ExamplePress MU App Validator: Deactivated "%s" — %s',
            $pluginBasename,
            $reason
        ));
    }
}
