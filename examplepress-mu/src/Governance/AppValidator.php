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

        // Advisory: warn if the manifest is missing the `repository` field.
        // This is a non-blocking warning — the app still activates.
        if (empty($manifest['repository'])) {
            add_action('admin_notices', static function () use ($pluginBasename): void {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p><strong>ExamplePress:</strong> '
                    . 'App <code>%s</code> is missing the <code>repository</code> field in its manifest. '
                    . 'Codespaces and Propose Change workflows require this field.</p></div>',
                    esc_html(dirname($pluginBasename))
                );
            });
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

        // Rule 7: file count caps.
        if (count($files) > 50) {
            $errors[] = 'File count exceeds 50 (got ' . count($files) . '). Simplify the architecture.';
        }
        $blockFolderCounts = [];
        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['path'])) continue;
            if (preg_match('#^app/(?:templates|components)/([a-z0-9-]+)/#', (string) $f['path'], $m)) {
                $blockFolderCounts[$m[1]] = ($blockFolderCounts[$m[1]] ?? 0) + 1;
            }
        }
        foreach ($blockFolderCounts as $folder => $count) {
            if ($count > 8) {
                $errors[] = "Block folder {$folder} contains {$count} files (max 8). Split into multiple blocks.";
            }
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
            '/\bcreate_function\s*\(/i'            => 'create_function()',
            '/\bassert\s*\(\s*[\'"]/i'             => 'assert() with string argument',
            '/\b(?:include|include_once|require|require_once)\s+\$/i' => 'dynamic include/require',
            '/\bfsockopen\s*\(/i'                  => 'fsockopen()',
            '/\bstream_socket_client\s*\(/i'       => 'stream_socket_client()',
        ];

        // Rules 9, 10, 11: superglobals, write APIs, outbound HTTP — banned
        // in templates under app/, allowed in rpc.php and cron.php.
        $bannedInTemplates = [
            '/\$_GET\b/'                           => 'superglobal $_GET',
            '/\$_POST\b/'                          => 'superglobal $_POST',
            '/\$_REQUEST\b/'                       => 'superglobal $_REQUEST',
            '/\$_COOKIE\b/'                        => 'superglobal $_COOKIE',
            '/\$_SERVER\b/'                        => 'superglobal $_SERVER',
            '/\bupdate_option\s*\(/i'              => 'write API: update_option()',
            '/\badd_option\s*\(/i'                 => 'write API: add_option()',
            '/\bdelete_option\s*\(/i'              => 'write API: delete_option()',
            '/\b(?:add|update|delete)_post_meta\s*\(/i' => 'write API: *_post_meta()',
            '/\b(?:add|update|delete)_user_meta\s*\(/i' => 'write API: *_user_meta()',
            '/\bwp_(?:insert|update|delete)_post\s*\(/i' => 'write API: wp_*_post()',
            '/\bcurl_(?:init|exec|setopt)\s*\(/i'  => 'outbound HTTP: curl_*()',
            '/\bwp_remote_(?:get|post|head|request)\s*\(/i' => 'outbound HTTP: wp_remote_*()',
            '/\bfile_get_contents\s*\(\s*[\'"]https?:/i' => 'outbound HTTP: file_get_contents(URL)',
        ];

        // Rule 13: React / JSX / Gutenberg JS imports — banned in any file.
        $bannedJsImports = [
            '/from\s+[\'"]@wordpress\/element[\'"]/' => '@wordpress/element import',
            '/from\s+[\'"]@wordpress\/blocks[\'"]/'  => '@wordpress/blocks import',
            '/from\s+[\'"]@wordpress\/block-editor[\'"]/' => '@wordpress/block-editor import',
            '/\bregisterBlockType\s*\(/'             => 'registerBlockType() call',
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

                // Rules 9-11: superglobals, write APIs, outbound HTTP.
                // Banned in templates under app/, allowed only in rpc.php
                // and cron.php (which run in REST/cron contexts).
                $isRpcOrCron = preg_match('#/(rpc|cron)\.php$#', $path) === 1;
                if (str_starts_with($path, 'app/') && !$isRpcOrCron) {
                    foreach ($bannedInTemplates as $pattern => $label) {
                        if (preg_match($pattern, $contents)) {
                            $errors[] = "File {$path} contains {$label} (forbidden in templates; allowed only in rpc.php / cron.php).";
                        }
                    }
                }

                // Rule 8: escaping allowlist.
                if (str_starts_with($path, 'app/')) {
                    $unescaped = self::findUnescapedEchoes($contents);
                    foreach ($unescaped as $hit) {
                        $errors[] = "File {$path} line {$hit['line']}: unescaped echo. Wrap in esc_html/esc_attr/esc_url/wp_kses_post: " . $hit['snippet'];
                    }
                }

                // Rule 17: every index.php under app/ must have useBlockProps
                // on its first HTML element.
                if (preg_match('#^app/(?:templates|components)/[a-z0-9-]+/index\.php$#', $path)) {
                    if (!preg_match('/<[a-zA-Z][^>]*\buseBlockProps\b/', $contents)) {
                        $errors[] = "File {$path}: template root element is missing the useBlockProps directive.";
                    }
                }

                // Rule 18: db.php requires explicit userScoped declaration.
                if (str_ends_with($path, '/db.php') && str_starts_with($path, 'app/')) {
                    if (!preg_match('/[\'"]userScoped[\'"]\s*=>/', $contents)) {
                        $errors[] = "File {$path}: db.php must explicitly declare 'userScoped' (true or false). No default.";
                    }
                }
            }

            // CSS / PHP / JSON: rule 14 (no hardcoded hex), rule 15 (no px font-size).
            if (preg_match('#\.(php|css|json)$#', $path) && str_starts_with($path, 'app/')) {
                if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $contents, $hexMatch)) {
                    $errors[] = "File {$path} contains a hardcoded hex color ({$hexMatch[0]}). Use var(--wp--preset--color--*) instead.";
                }
                if (preg_match('/font-size\s*:\s*\d+px/i', $contents, $pxMatch)) {
                    $errors[] = "File {$path} contains a hardcoded pixel font size ({$pxMatch[0]}). Use var(--wp--preset--font-size--*) instead.";
                }
            }

            // Rule 16: forbidden Tailwind design-token classes in PHP/CSS/JSON.
            if (preg_match('#\.(php|css|json)$#', $path) && str_starts_with($path, 'app/')) {
                $forbiddenTw = '/\b(?:text|bg|border|ring|fill|stroke|placeholder|divide|outline|accent|caret)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/';
                if (preg_match($forbiddenTw, $contents, $twMatch)) {
                    $errors[] = "File {$path} uses forbidden Tailwind color class ({$twMatch[0]}). Use [var(--wp--preset--color--*)] arbitrary-value syntax instead.";
                }
                $forbiddenTwSize = '/\btext-(?:xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|8xl|9xl)\b/';
                if (preg_match($forbiddenTwSize, $contents, $twMatch)) {
                    $errors[] = "File {$path} uses forbidden Tailwind size class ({$twMatch[0]}). Use [var(--wp--preset--font-size--*)] arbitrary-value syntax instead.";
                }
                $forbiddenTwFont = '/\bfont-(?:sans|serif|mono)\b/';
                if (preg_match($forbiddenTwFont, $contents, $twMatch)) {
                    $errors[] = "File {$path} uses forbidden Tailwind font-family class ({$twMatch[0]}). Use [var(--wp--preset--font-family--*)] instead.";
                }
            }

            // Rule 13: React/JSX/Gutenberg JS imports — banned in any file.
            if (preg_match('#\.(js|jsx|ts|tsx|php)$#', $path)) {
                foreach ($bannedJsImports as $pattern => $label) {
                    if (preg_match($pattern, $contents)) {
                        $errors[] = "File {$path} contains forbidden {$label}. The view layer is Blockstudio PHP templates only.";
                    }
                }
            }

            // Rule 19: block.json attribute type allowlist.
            if (str_ends_with($path, '/block.json') && str_starts_with($path, 'app/')) {
                $errors = array_merge($errors, self::validateBlockJsonAttributes($path, $contents));
                // Rule 4 + 5: usesContext / parent must reference a block in the payload.
                $errors = array_merge($errors, self::validateBlockContextRefs($path, $contents, $files, $manifest['slug'] ?? ''));
            }
        }

        // Rule: No REST routes under /fs/ with write methods.
        // Companion plugins must not reintroduce the filesystem write
        // surface that the kernel removed for platform immutability.
        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['path'], $f['contents'])) {
                continue;
            }
            $fPath     = (string) $f['path'];
            $fContents = (string) $f['contents'];

            if (!str_ends_with($fPath, '.php')) {
                continue;
            }

            if (preg_match('/register_rest_route\s*\(/i', $fContents)
                && preg_match('#[\'"][^\'"]*/fs/[^\'"]*[\'"]#i', $fContents)
                && preg_match('/[\'"](?:POST|PUT|PATCH|DELETE|CREATABLE|EDITABLE|DELETABLE)[\'"]|WP_REST_Server::\s*(?:CREATABLE|EDITABLE|DELETABLE)/i', $fContents)
            ) {
                $errors[] = "File {$fPath} registers a REST route under /fs/ with a write method. Filesystem write endpoints are banned by platform-immutability policy.";
            }
        }

        // ── Structural rules (1, 2, 3): slug consistency, block name
        // derivation, and route-origin / template correspondence.
        if (!empty($manifest['slug']) && is_string($manifest['slug'])) {
            $errors = array_merge(
                $errors,
                self::validateStructuralCorrespondence($manifest['slug'], $files)
            );
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

    /**
     * Structural correspondence checks for an AI-generated payload.
     * Enforces three rules in one pass:
     *
     *   Rule 1 — Slug consistency. The manifest slug, the bootstrap
     *   filename ({slug}.php), and the `Text Domain:` header (when
     *   present) must all match.
     *
     *   Rule 2 — Block name derivation. Every block.json under
     *   app/templates/X must have name "{slug}/template-X". Every
     *   block.json under app/components/X must have name
     *   "{slug}/components-X".
     *
     *   Rule 3 — Route origin correspondence. Every slug passed to
     *   examplepress_register_route_origin() in the bootstrap must
     *   have a matching app/templates/{slug}/block.json in the
     *   payload.
     *
     * @param array<int,array{path:string,contents:string}> $files
     * @return array<int,string>
     */
    private static function validateStructuralCorrespondence(string $slug, array $files): array
    {
        $errors = [];

        // Build a quick lookup map: relative path => contents.
        $byPath = [];
        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['path'], $f['contents'])) {
                continue;
            }
            $byPath[(string) $f['path']] = (string) $f['contents'];
        }

        // ── Rule 1: bootstrap filename + Text Domain header ────────
        $expectedBootstrap = $slug . '.php';
        if (!isset($byPath[$expectedBootstrap])) {
            $errors[] = "Slug consistency: expected bootstrap file {$expectedBootstrap} not found in payload.";
        } else {
            $bootstrap = $byPath[$expectedBootstrap];
            if (preg_match('/Text Domain:\s*([a-zA-Z0-9_-]+)/', $bootstrap, $m)) {
                if ($m[1] !== $slug) {
                    $errors[] = "Slug consistency: bootstrap Text Domain header is \"{$m[1]}\" but manifest slug is \"{$slug}\".";
                }
            }
            // Bootstrap should also declare the manifest slug somewhere
            // (the route origin or the namespace) — surface a warning
            // if neither appears, since it usually means a mismatch.
            if (!str_contains($bootstrap, "'{$slug}'") && !str_contains($bootstrap, "\"{$slug}\"")) {
                $errors[] = "Slug consistency: bootstrap {$expectedBootstrap} does not reference the manifest slug \"{$slug}\".";
            }
        }

        // ── Rule 2: block.json name derivation ─────────────────────
        // Walk every block.json file, parse it, and check that its
        // `name` field matches the directory it lives in.
        $templateNames = []; // collected for rule 3 below
        foreach ($byPath as $path => $contents) {
            if (!preg_match('#^app/(templates|components)/([a-z0-9-]+)/block\.json$#', $path, $m)) {
                continue;
            }
            $section = $m[1]; // 'templates' or 'components'
            $folder  = $m[2];

            $expectedName = $slug . '/' . ($section === 'templates' ? 'template-' : 'components-') . $folder;

            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                $errors[] = "Block name derivation: {$path} is not valid JSON.";
                continue;
            }
            $actualName = (string) ($decoded['name'] ?? '');
            if ($actualName !== $expectedName) {
                $errors[] = "Block name derivation: {$path} has name \"{$actualName}\" but the folder structure requires \"{$expectedName}\".";
            }

            if ($section === 'templates') {
                $templateNames[$folder] = $expectedName;
            }
        }

        // ── Rule 3: route-origin → template correspondence ─────────
        // Parse the bootstrap file (if present) for slugs registered
        // via examplepress_register_route_origin($namespace, [...], $priority).
        if (isset($byPath[$expectedBootstrap])) {
            $registeredSlugs = self::extractRegisteredRouteSlugs($byPath[$expectedBootstrap]);
            foreach ($registeredSlugs as $routeSlug) {
                if (!isset($templateNames[$routeSlug])) {
                    $errors[] = "Route origin correspondence: bootstrap registers route slug \"{$routeSlug}\" but no app/templates/{$routeSlug}/block.json exists in the payload. The router will fatal on dispatch.";
                }
            }
        }

        return $errors;
    }

    /**
     * Extract every route slug passed to
     * examplepress_register_route_origin() in the bootstrap source.
     *
     * Returns the list of slug keys (the array keys of the second
     * argument). Best-effort regex parse — handles single-line and
     * multi-line array literals with single or double quotes.
     *
     * @return array<int,string>
     */
    private static function extractRegisteredRouteSlugs(string $bootstrap): array
    {
        if (!preg_match_all(
            '/examplepress_register_route_origin\s*\(\s*[\'"][a-z0-9-]+[\'"]\s*,\s*\[(.*?)\]\s*(?:,\s*\d+\s*)?\)/s',
            $bootstrap,
            $matches
        )) {
            return [];
        }

        $slugs = [];
        foreach ($matches[1] as $arrayBody) {
            // Match every "key" => or 'key' => occurrence in the body.
            if (preg_match_all('/[\'"]([a-z0-9_-]+)[\'"]\s*=>/', $arrayBody, $keyMatches)) {
                foreach ($keyMatches[1] as $k) {
                    $slugs[] = $k;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Rule 19: Blockstudio attribute type allowlist. Every
     * `blockstudio.attributes[].type` value must be one of the closed
     * set documented in skill 40.
     *
     * @return array<int,string>
     */
    private static function validateBlockJsonAttributes(string $path, string $contents): array
    {
        $errors = [];
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return $errors;
        }
        $bs = $decoded['blockstudio'] ?? null;
        if (!is_array($bs)) {
            return $errors;
        }
        $attrs = $bs['attributes'] ?? null;
        if (!is_array($attrs)) {
            return $errors;
        }

        $allowed = [
            'text', 'textarea', 'richtext', 'number', 'range', 'toggle',
            'select', 'color', 'files', 'link', 'repeater', 'query',
        ];

        $walk = function (array $items) use (&$walk, $allowed, $path, &$errors): void {
            foreach ($items as $attr) {
                if (!is_array($attr)) continue;
                $type = $attr['type'] ?? null;
                if ($type !== null && !in_array($type, $allowed, true)) {
                    $errors[] = "File {$path}: attribute type \"{$type}\" is not in the allowlist (text, textarea, richtext, number, range, toggle, select, color, files, link, repeater, query).";
                }
                // Recurse into repeater sub-attributes.
                if (($type === 'repeater') && isset($attr['attributes']) && is_array($attr['attributes'])) {
                    $walk($attr['attributes']);
                }
            }
        };
        $walk($attrs);

        return $errors;
    }

    /**
     * Rules 4 + 5: every usesContext / parent entry in a block.json
     * must reference either a core/* block or a block defined in the
     * same payload.
     *
     * @param array<int,array<string,mixed>> $files
     * @return array<int,string>
     */
    private static function validateBlockContextRefs(string $path, string $contents, array $files, string $slug): array
    {
        $errors = [];
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return $errors;
        }

        // Build the set of block names defined in this payload.
        $defined = [];
        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['path'], $f['contents'])) continue;
            if (preg_match('#^app/(?:templates|components)/[a-z0-9-]+/block\.json$#', (string) $f['path'])) {
                $d = json_decode((string) $f['contents'], true);
                if (is_array($d) && !empty($d['name'])) {
                    $defined[(string) $d['name']] = true;
                }
            }
        }

        $check = static function (array $names, string $field) use ($defined, $path, &$errors): void {
            foreach ($names as $name) {
                if (!is_string($name)) continue;
                if (str_starts_with($name, 'core/')) continue; // core blocks always pass
                if (isset($defined[$name])) continue;
                $errors[] = "File {$path}: {$field} references unknown block \"{$name}\".";
            }
        };

        if (isset($decoded['usesContext']) && is_array($decoded['usesContext'])) {
            $check($decoded['usesContext'], 'usesContext');
        }
        if (isset($decoded['parent']) && is_array($decoded['parent'])) {
            $check($decoded['parent'], 'parent');
        }

        return $errors;
    }

    /**
     * Walk a PHP file looking for `echo $variable` or `<?= $variable`
     * that is NOT wrapped in an allowlisted output-escaping function.
     *
     * Allowlisted prefixes: esc_html, esc_attr, esc_url, esc_textarea,
     * esc_js, esc_xml, wp_kses, wp_kses_post, wp_kses_data, absint,
     * intval, floatval, number_format, number_format_i18n, plus the
     * `__()`/`_e()`/`_x()` translation helpers wrapped in esc_*.
     *
     * Casts (`(int)`, `(float)`, `(bool)`, `(string)`) are also allowed
     * because they coerce away any HTML risk.
     *
     * Filterable via examplepress_mu_agent_escape_allowlist (returns
     * an array of additional allowed prefix function names).
     *
     * @return array<int,array{line:int,snippet:string}>
     */
    private static function findUnescapedEchoes(string $contents): array
    {
        // Strip line and block comments so commented-out examples don't fail.
        $stripped = preg_replace('#//[^\n]*#', '', $contents) ?? $contents;
        $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped) ?? $stripped;

        $allowed = array_merge([
            'esc_html', 'esc_html__', 'esc_html_e', 'esc_html_x',
            'esc_attr', 'esc_attr__', 'esc_attr_e', 'esc_attr_x',
            'esc_url', 'esc_url_raw',
            'esc_textarea', 'esc_js', 'esc_xml',
            'wp_kses', 'wp_kses_post', 'wp_kses_data',
            'absint', 'intval', 'floatval',
            'number_format', 'number_format_i18n',
            'sanitize_text_field', 'sanitize_key', 'sanitize_title',
            'count', 'sizeof', 'strlen',
        ], (array) apply_filters('examplepress_mu_agent_escape_allowlist', []));

        $allowedPattern = implode('|', array_map('preg_quote', $allowed));

        $hits = [];
        $lines = explode("\n", $stripped);

        foreach ($lines as $i => $line) {
            // Match every `echo`, `print`, or `<?=` statement on the line.
            if (!preg_match_all(
                '/(?:^|[\s;{}\(\[,])(echo|print|<\?=)\s+([^;?]*)/i',
                $line,
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $m) {
                $expr = trim($m[2]);
                if ($expr === '') {
                    continue;
                }

                // Strip leading casts: (int), (float), (bool), (string), (array).
                // If a cast is present, the result is type-coerced and safe — skip.
                $afterCast = preg_replace('/^\((?:int|float|double|bool|boolean|string|array)\)\s*/i', '', $expr) ?? $expr;
                if ($afterCast !== $expr) {
                    continue;
                }

                // Safe: starts with a literal string, number, true/false/null, array,
                // or a boolean-context helper (empty, isset, is_*, !).
                if (preg_match('/^(["\']|\d|true\b|false\b|null\b|\[|PHP_)/i', $expr)) {
                    continue;
                }
                if (preg_match('/^!?\s*(?:empty|isset|is_array|is_string|is_numeric|is_int|is_null|in_array|array_key_exists)\s*\(/i', $expr)) {
                    continue;
                }

                // Safe: starts with an UPPERCASE_CONSTANT
                if (preg_match('/^[A-Z_][A-Z0-9_]*\b(?!\s*\()/', $expr)) {
                    continue;
                }

                // Safe: starts with an allowlisted function call
                if (preg_match('/^(?:' . $allowedPattern . ')\s*\(/', $expr)) {
                    continue;
                }

                // Otherwise: unescaped variable echo. Flag it.
                $hits[] = [
                    'line'    => $i + 1,
                    'snippet' => substr($expr, 0, 80),
                ];
            }
        }

        return $hits;
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
