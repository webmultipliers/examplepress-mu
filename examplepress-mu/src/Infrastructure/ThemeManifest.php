<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Single source of truth for reading the active theme's root files.
 *
 * Before this class existed, the kernel resolved EP_THEME_PATH and read
 * the theme's examplepress.json / theme.json / blockstudio.json /
 * templates/index.html in five separate sites (ConfigManager,
 * RouteRegistry, DataProvider, and multiple helpers within DataProvider).
 * Each call did its own existence checks, its own file_get_contents, its
 * own json_decode — so a malformed theme file silently dropped data in
 * one place and fataled in another.
 *
 * This class consolidates the pattern behind one per-request cache with
 * uniform error handling:
 *   - path()                   — resolved theme directory (EP_THEME_PATH / get_template_directory)
 *   - filePath($rel)           — absolute path to a theme-root file
 *   - hasFile($rel)            — file_exists wrapper
 *   - readFile($rel)           — raw string contents or null
 *   - readJson($rel)           — decoded array or []; logs warnings on parse failure when WP_DEBUG
 *   - examplepress()           — examplepress.json decoded
 *   - themeJson()              — theme.json decoded
 *   - blockstudioJson()        — blockstudio.json decoded
 *   - indexTemplate()          — templates/index.html raw contents (trimmed) or ''
 *   - flush()                  — clear per-request cache
 *
 * ConfigManager::reset() chains into flush() so any hook that clears
 * the config cache also clears this cache — no cross-cache drift.
 */
final class ThemeManifest
{
    /** @var array<string, mixed> Per-request memo. */
    private static array $cache = [];

    /**
     * Resolved theme root directory. Returns EP_THEME_PATH if defined
     * (set by the kernel bootstrap), otherwise falls back to
     * get_template_directory(). Never returns a trailing slash.
     */
    public static function path(): string
    {
        if (isset(self::$cache['path'])) {
            return self::$cache['path'];
        }
        $path = defined('EP_THEME_PATH') ? EP_THEME_PATH : get_template_directory();
        return self::$cache['path'] = rtrim((string) $path, '/');
    }

    /**
     * Absolute path to a file under the theme root. The relative path
     * must not escape the theme directory — callers should pass literal
     * relative paths, not user input.
     */
    public static function filePath(string $relative): string
    {
        return self::path() . '/' . ltrim($relative, '/');
    }

    public static function hasFile(string $relative): bool
    {
        return file_exists(self::filePath($relative));
    }

    /**
     * Raw string contents of a theme-root file, or null if missing
     * or unreadable. Cached per request.
     */
    public static function readFile(string $relative): ?string
    {
        $key = 'file:' . $relative;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $path = self::filePath($relative);
        if (!is_readable($path)) {
            return self::$cache[$key] = null;
        }
        $raw = @file_get_contents($path);
        return self::$cache[$key] = is_string($raw) ? $raw : null;
    }

    /**
     * Decoded JSON contents of a theme-root file. Returns [] when the
     * file is absent, unreadable, or fails to parse. When WP_DEBUG is
     * on, a parse failure is logged so theme authors get feedback.
     *
     * @return array<string, mixed>
     */
    public static function readJson(string $relative): array
    {
        $key = 'json:' . $relative;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $raw = self::readFile($relative);
        if ($raw === null || $raw === '') {
            return self::$cache[$key] = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[ExamplePress ThemeManifest] %s failed to decode as JSON (got %s). Check for trailing commas or syntax errors.',
                    $relative,
                    gettype($decoded)
                ));
            }
            return self::$cache[$key] = [];
        }
        return self::$cache[$key] = $decoded;
    }

    /**
     * examplepress.json as a decoded array, or [] when missing/invalid.
     *
     * @return array<string, mixed>
     */
    public static function examplepress(): array
    {
        return self::readJson('examplepress.json');
    }

    /**
     * theme.json as a decoded array, or [] when missing/invalid.
     *
     * @return array<string, mixed>
     */
    public static function themeJson(): array
    {
        return self::readJson('theme.json');
    }

    /**
     * blockstudio.json as a decoded array, or [] when missing/invalid.
     *
     * @return array<string, mixed>
     */
    public static function blockstudioJson(): array
    {
        return self::readJson('blockstudio.json');
    }

    /**
     * templates/index.html raw contents, trimmed. Returns '' when
     * missing — the health check uses this to verify the theme is
     * still router-block-only and not a traditional FSE template.
     */
    public static function indexTemplate(): string
    {
        $raw = self::readFile('templates/index.html');
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * Drop the per-request cache. Called by ConfigManager::reset() so
     * that clearing the downstream config cache also clears this
     * upstream cache — otherwise a "refresh" from the admin UI could
     * return stale theme manifest data.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
