<?php
/**
 * ExamplePress MU — Shared Helpers
 *
 * Small utility functions used across multiple subsystems.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialise the WP_Filesystem and return it.
 *
 * @return WP_Filesystem_Base|false
 */
function examplepress_get_filesystem() {
    global $wp_filesystem;

    if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
        return $wp_filesystem;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';

    if ( ! WP_Filesystem() ) {
        return false;
    }

    return $wp_filesystem;
}

/**
 * Recursively copy a directory using WP_Filesystem.
 *
 * @param string $src Source directory.
 * @param string $dst Destination directory.
 * @return bool
 */
function examplepress_copy_dir( string $src, string $dst ): bool {
    $fs = examplepress_get_filesystem();

    if ( ! $fs ) {
        return false;
    }

    if ( ! $fs->is_dir( $src ) ) {
        return false;
    }

    if ( ! $fs->is_dir( $dst ) ) {
        $fs->mkdir( $dst );
    }

    $entries = $fs->dirlist( $src );
    if ( ! is_array( $entries ) ) {
        return false;
    }

    foreach ( $entries as $name => $info ) {
        $src_path = trailingslashit( $src ) . $name;
        $dst_path = trailingslashit( $dst ) . $name;

        if ( 'd' === $info['type'] ) {
            if ( ! examplepress_copy_dir( $src_path, $dst_path ) ) {
                return false;
            }
        } else {
            if ( ! $fs->copy( $src_path, $dst_path, true ) ) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Recursively delete a directory using WP_Filesystem.
 *
 * @param string $dir Directory to delete.
 * @return bool
 */
function examplepress_delete_dir( string $dir ): bool {
    $fs = examplepress_get_filesystem();

    if ( ! $fs ) {
        return false;
    }

    if ( ! $fs->is_dir( $dir ) ) {
        return true;
    }

    return $fs->delete( $dir, true );
}
