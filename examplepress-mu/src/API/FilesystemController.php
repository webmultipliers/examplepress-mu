<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\Helpers;

/**
 * Filesystem REST API — read/write access to companion plugin directories
 * for the in-browser editor. All paths are strictly sandboxed within
 * wp-content/plugins/{slug}/.
 */
final class FilesystemController
{
    public static function register(): void
    {
        $slug_args = [
            'slug' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
                'validate_callback' => function ($value): bool {
                    return (bool) preg_match('/^[a-z0-9-]+$/', $value);
                },
            ],
        ];

        register_rest_route('examplepress-mu/v1', '/fs/(?P<slug>[a-z0-9-]+)/tree', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'tree'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => $slug_args,
        ]);

        register_rest_route('examplepress-mu/v1', '/fs/(?P<slug>[a-z0-9-]+)/file', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'readFile'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => array_merge($slug_args, [
                'path' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]),
        ]);

        register_rest_route('examplepress-mu/v1', '/fs/(?P<slug>[a-z0-9-]+)/file', [
            'methods'             => 'PUT',
            'callback'            => [self::class, 'writeFile'],
            'permission_callback' => [self::class, 'permissionCheck'],
            'args'                => array_merge($slug_args, [
                'path' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'content' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ]),
        ]);
    }

    public static function permissionCheck(): bool
    {
        $allowed = current_user_can('manage_options');

        // Respect WordPress's file-editing lockdown constants. PlatformPolicy
        // defines DISALLOW_FILE_EDIT at boot as part of the platform's
        // governance posture — it would be a contradiction to then expose a
        // REST write endpoint that silently bypasses it. Operators who want
        // the in-browser editor must opt in explicitly via the filter below.
        $locked = (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT)
            || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);

        if ($locked) {
            $allowed = false;
        }

        /**
         * Filter whether the current user can edit app files.
         *
         * @param bool $allowed Default: manage_options capability AND
         *                      neither DISALLOW_FILE_EDIT nor DISALLOW_FILE_MODS is set.
         *                      Return true explicitly to override the lockdown
         *                      for the in-browser editor use case.
         */
        return (bool) apply_filters('examplepress_mu_can_edit_app_files', $allowed);
    }

    // ── Tree ────────────────────────────────────────────────────────

    public static function tree(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug       = $request->get_param('slug');
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

        if (!is_dir($plugin_dir)) {
            return new \WP_Error('not_found', 'Plugin directory does not exist.', ['status' => 404]);
        }

        return rest_ensure_response(self::scanDir($plugin_dir, $plugin_dir));
    }

    // ── Read File ───────────────────────────────────────────────────

    public static function readFile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = $request->get_param('slug');
        $path = $request->get_param('path');

        $resolved = self::resolvePath($slug, $path);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!is_file($resolved)) {
            return new \WP_Error('not_found', 'File not found.', ['status' => 404]);
        }

        $size = filesize($resolved);

        if ($size > 1048576) {
            return new \WP_Error('too_large', 'File exceeds 1 MB limit.', ['status' => 413]);
        }

        $content = Helpers::readFile($resolved);

        if ($content === false) {
            return new \WP_Error('read_error', 'Could not read file.', ['status' => 500]);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return new \WP_Error('binary_file', 'File appears to be binary.', ['status' => 415]);
        }

        return rest_ensure_response([
            'path'     => $path,
            'content'  => $content,
            'size'     => $size,
            'language' => self::detectLanguage($path),
        ]);
    }

    // ── Write File ──────────────────────────────────────────────────

    public static function writeFile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug    = $request->get_param('slug');
        $path    = $request->get_param('path');
        $content = $request->get_param('content');

        $resolved = self::resolvePath($slug, $path, false);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        // Ensure parent directory exists.
        $parent = dirname($resolved);
        if (!is_dir($parent)) {
            wp_mkdir_p($parent);
        }

        // Force-direct so REST contexts never trigger an FTP-credentials prompt.
        $fs = Helpers::filesystem(forceDirect: true);

        if (!$fs) {
            return new \WP_Error(
                'fs_unavailable',
                'Filesystem writes are not available on this host (no direct access).',
                ['status' => 501]
            );
        }

        if (!$fs->put_contents($resolved, (string) $content, FS_CHMOD_FILE)) {
            return new \WP_Error('write_error', 'Could not write file.', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'path'    => $path,
            'size'    => strlen((string) $content),
        ]);
    }

    // ── Private: Path Validation ────────────────────────────────────

    /**
     * Resolve and validate a relative path within a plugin directory.
     *
     * @param string $slug          Plugin slug.
     * @param string $relative_path Relative file path.
     * @param bool   $must_exist    Whether the file must already exist.
     * @return string|\WP_Error Absolute path or error.
     */
    private static function resolvePath(string $slug, string $relative_path, bool $must_exist = true): string|\WP_Error
    {
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

        if (!is_dir($plugin_dir)) {
            return new \WP_Error('not_found', 'Plugin directory does not exist.', ['status' => 404]);
        }

        // Normalize.
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');

        // Reject traversal and null bytes.
        if (str_contains($relative_path, '..') || str_contains($relative_path, "\0")) {
            return new \WP_Error('forbidden', 'Invalid path.', ['status' => 403]);
        }

        $absolute = $plugin_dir . '/' . $relative_path;

        if ($must_exist) {
            $real_file = realpath($absolute);
            $real_base = realpath($plugin_dir);

            if (!$real_file || !$real_base || !str_starts_with($real_file, $real_base . '/')) {
                return new \WP_Error('forbidden', 'Path escapes the plugin boundary.', ['status' => 403]);
            }

            return $real_file;
        }

        // For writes to new files: first validate the lexical path stays
        // inside the plugin dir, then create the parent directory if it's
        // missing (so a write into a new subfolder succeeds), then compute
        // the real path.
        $real_base = realpath($plugin_dir);
        if (!$real_base) {
            return new \WP_Error('not_found', 'Plugin directory does not exist.', ['status' => 404]);
        }

        // Walk up the intended path until we find an existing ancestor; that
        // ancestor's real path must be inside the plugin dir. This defends
        // against symlink escapes even when the full parent chain doesn't
        // yet exist on disk.
        $ancestor = dirname($absolute);
        $probe    = $ancestor;
        while ($probe && !file_exists($probe)) {
            $probe = dirname($probe);
        }
        $real_probe = $probe ? realpath($probe) : false;
        if (!$real_probe || ($real_probe !== $real_base && !str_starts_with($real_probe, $real_base . '/'))) {
            return new \WP_Error('forbidden', 'Path escapes the plugin boundary.', ['status' => 403]);
        }

        // Materialize any missing intermediate directories now.
        if (!is_dir($ancestor)) {
            wp_mkdir_p($ancestor);
        }

        $real_parent = realpath($ancestor);
        if (!$real_parent || ($real_parent !== $real_base && !str_starts_with($real_parent, $real_base . '/'))) {
            return new \WP_Error('forbidden', 'Path escapes the plugin boundary.', ['status' => 403]);
        }

        return $real_parent . '/' . basename($relative_path);
    }

    // ── Private: Directory Scanning ─────────────────────────────────

    /**
     * Recursively scan a directory and return a tree structure.
     */
    private static function scanDir(string $dir, string $base): array
    {
        $skip    = ['.', '..', '.git', '.github', 'node_modules', 'vendor', '.DS_Store'];
        $entries = scandir($dir);
        $dirs    = [];
        $files   = [];

        foreach ($entries as $entry) {
            if (in_array($entry, $skip, true)) {
                continue;
            }

            $absolute = $dir . '/' . $entry;
            $relative = ltrim(str_replace($base, '', $absolute), '/');

            if (is_dir($absolute)) {
                $dirs[] = [
                    'name'     => $entry,
                    'path'     => $relative,
                    'type'     => 'directory',
                    'children' => self::scanDir($absolute, $base),
                ];
            } else {
                $files[] = [
                    'name' => $entry,
                    'path' => $relative,
                    'type' => 'file',
                    'size' => filesize($absolute),
                ];
            }
        }

        usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return array_merge($dirs, $files);
    }

    // ── Private: Language Detection ─────────────────────────────────

    /**
     * Detect Monaco language ID from file extension.
     */
    private static function detectLanguage(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $map = [
            'php'  => 'php',
            'js'   => 'javascript',
            'mjs'  => 'javascript',
            'ts'   => 'typescript',
            'json' => 'json',
            'css'  => 'css',
            'scss' => 'scss',
            'html' => 'html',
            'htm'  => 'html',
            'xml'  => 'xml',
            'md'   => 'markdown',
            'yaml' => 'yaml',
            'yml'  => 'yaml',
            'sh'   => 'shell',
            'bash' => 'shell',
            'sql'  => 'sql',
        ];

        return $map[$ext] ?? 'plaintext';
    }
}
