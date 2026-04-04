<?php
/**
 * Plugin Name: ExamplePress MU Bootstrapper
 * Description: Thin loader that fetches and executes the ExamplePress platform kernel from GitHub.
 * Version:     1.0.0
 * Author:      Web Multipliers
 * Author URI:  https://github.com/webmultipliers
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Bootstrapper {

    private static $repo_owner = 'webmultipliers';
    private static $repo_name  = 'examplepress-mu';

    public static function boot() {
        // Because this file sits at the root of mu-plugins/, __DIR__ is the mu-plugins folder.
        $mu_dir    = __DIR__ . '/examplepress-mu';
        $boot_file = $mu_dir . '/bootstrap.php';

        // 1. Download and extract if the core application directory is missing.
        if ( ! file_exists( $boot_file ) ) {
            self::fetch_latest_from_github( __DIR__ );
        }

        // 2. Execute the kernel if available.
        if ( file_exists( $boot_file ) ) {
            require_once $boot_file;
        } else {
            error_log( 'ExamplePress MU Bootstrapper: Failed to load or download the core application.' );
        }
    }

    private static function fetch_latest_from_github( $target_mu_dir ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $api_url  = "https://api.github.com/repos/" . self::$repo_owner . "/" . self::$repo_name . "/releases/latest";
        $response = wp_remote_get( $api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ExamplePress-Bootstrapper'
            ]
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            error_log( 'ExamplePress MU Bootstrapper: Could not reach GitHub API.' );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body->zipball_url ) ) {
            return false;
        }

        $temp_file = download_url( $body->zipball_url );
        if ( is_wp_error( $temp_file ) ) {
            return false;
        }

        $temp_extract_dir = $target_mu_dir . '/_ep_mu_temp';
        $wp_filesystem->mkdir( $temp_extract_dir );

        $unzip_result = unzip_file( $temp_file, $temp_extract_dir );
        unlink( $temp_file );

        if ( is_wp_error( $unzip_result ) ) {
            $wp_filesystem->delete( $temp_extract_dir, true );
            return false;
        }

        // GitHub zips extract into `owner-repo-hash`. We need the contents of that folder.
        $extracted_folders = $wp_filesystem->dirlist( $temp_extract_dir );
        if ( ! empty( $extracted_folders ) ) {
            $github_folder_name = array_keys( $extracted_folders )[0];
            $github_folder_path = $temp_extract_dir . '/' . $github_folder_name;

            // Move the application directory
            if ( is_dir( $github_folder_path . '/examplepress-mu' ) ) {
                $wp_filesystem->move( $github_folder_path . '/examplepress-mu', $target_mu_dir . '/examplepress-mu', true );
            }

            // Move the loader file (overwrites the current one to keep it updated)
            if ( file_exists( $github_folder_path . '/examplepress-mu.php' ) ) {
                $wp_filesystem->move( $github_folder_path . '/examplepress-mu.php', $target_mu_dir . '/examplepress-mu.php', true );
            }
        }

        $wp_filesystem->delete( $temp_extract_dir, true );

        return true;
    }
}

ExamplePress_MU_Bootstrapper::boot();
