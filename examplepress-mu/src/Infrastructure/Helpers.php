<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

/**
 * Shared filesystem utility methods.
 */
final class Helpers
{
    /**
     * Initialise the WP_Filesystem and return it.
     *
     * @param bool $forceDirect When true, temporarily forces the 'direct'
     *                          transport via the filesystem_method filter so
     *                          REST/cron contexts cannot trigger an FTP-credentials
     *                          prompt. Returns false (never half-initialised) if
     *                          the filesystem cannot be brought up.
     */
    public static function filesystem(bool $forceDirect = false): \WP_Filesystem_Base|false
    {
        global $wp_filesystem;

        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            // Re-validate the cached instance the same way a fresh init would,
            // so a prior non-fatal error state doesn't get handed to new callers.
            if (isset($wp_filesystem->errors) && is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->has_errors()) {
                return false;
            }
            return $wp_filesystem;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $forceCb = static fn() => 'direct';
        if ($forceDirect) {
            add_filter('filesystem_method', $forceCb);
        }

        // Suppress any output from request_filesystem_credentials() — in REST
        // contexts there is nowhere to render the FTP form anyway.
        ob_start();
        $ok = WP_Filesystem();
        ob_end_clean();

        if ($forceDirect) {
            remove_filter('filesystem_method', $forceCb);
        }

        if (!$ok || !($wp_filesystem instanceof \WP_Filesystem_Base)) {
            return false;
        }

        if (isset($wp_filesystem->errors) && is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->has_errors()) {
            return false;
        }

        return $wp_filesystem;
    }

    /**
     * Read a file using native PHP. Avoids the WP_Filesystem FTP-prompt trap
     * for REST contexts where reads are safe and direct.
     *
     * @return string|false File contents, or false if unreadable.
     */
    public static function readFile(string $path): string|false
    {
        if (!is_readable($path)) {
            return false;
        }

        return @file_get_contents($path);
    }

    /**
     * Recursively copy a directory using WP_Filesystem.
     */
    public static function copyDir(string $src, string $dst): bool
    {
        $fs = self::filesystem();

        if (!$fs || !$fs->is_dir($src)) {
            return false;
        }

        if (!$fs->is_dir($dst)) {
            $fs->mkdir($dst);
        }

        $entries = $fs->dirlist($src);
        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $name => $info) {
            $srcPath = trailingslashit($src) . $name;
            $dstPath = trailingslashit($dst) . $name;

            if ('d' === $info['type']) {
                if (!self::copyDir($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!$fs->copy($srcPath, $dstPath, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory using WP_Filesystem.
     */
    public static function deleteDir(string $dir): bool
    {
        $fs = self::filesystem();

        if (!$fs) {
            return false;
        }

        if (!$fs->is_dir($dir)) {
            return true;
        }

        return $fs->delete($dir, true);
    }

    /**
     * Base64url encode (for JWT signing).
     */
    public static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
