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
     */
    public static function filesystem(): \WP_Filesystem_Base|false
    {
        global $wp_filesystem;

        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            return $wp_filesystem;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (!WP_Filesystem()) {
            return false;
        }

        return $wp_filesystem;
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
