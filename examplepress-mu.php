<?php
/**
 * Plugin Name: ExamplePress MU Bootstrapper
 * Description: Thin loader that fetches and executes the ExamplePress platform kernel from GitHub.
 * Version:     1.0.1
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

    /**
     * Fetch the latest release from GitHub using the updates.json manifest
     * attached to the release, then download and extract the built ZIP.
     *
     * @param string $target_mu_dir  The mu-plugins directory path.
     * @return bool
     */
    private static function fetch_latest_from_github( $target_mu_dir ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        // --- Resolve the download URL from updates.json in the latest release ---
        $api_url  = 'https://api.github.com/repos/' . self::$repo_owner . '/' . self::$repo_name . '/releases/latest';
        $response = wp_remote_get( $api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ExamplePress-Bootstrapper',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            error_log( 'ExamplePress MU Bootstrapper: Could not reach GitHub API.' );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );

        // Find the updates.json asset to get the built ZIP URL and checksum.
        $package_url = null;
        $checksum    = '';

        if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
            $updates_url = null;
            foreach ( $release->assets as $asset ) {
                if ( $asset->name === 'updates.json' ) {
                    $updates_url = $asset->browser_download_url;
                    break;
                }
            }

            if ( $updates_url ) {
                $updates_response = wp_remote_get( $updates_url, [
                    'headers' => [ 'User-Agent' => 'ExamplePress-Bootstrapper' ],
                    'timeout' => 10,
                ] );

                if ( ! is_wp_error( $updates_response ) && wp_remote_retrieve_response_code( $updates_response ) === 200 ) {
                    $updates = json_decode( wp_remote_retrieve_body( $updates_response ), true );
                    if ( ! empty( $updates['packages'][0]['package'] ) ) {
                        $package_url = $updates['packages'][0]['package'];
                        $checksum    = $updates['packages'][0]['checksum'] ?? '';
                    }
                }
            }
        }

        // Fallback: use the zipball_url (source archive) if no release asset found.
        if ( ! $package_url ) {
            if ( empty( $release->zipball_url ) ) {
                error_log( 'ExamplePress MU Bootstrapper: No download URL found in release.' );
                return false;
            }
            $package_url = $release->zipball_url;
        }

        // --- Download and extract ---
        $temp_file = download_url( $package_url );
        if ( is_wp_error( $temp_file ) ) {
            error_log( 'ExamplePress MU Bootstrapper: Download failed — ' . $temp_file->get_error_message() );
            return false;
        }

        // Verify checksum if available.
        if ( $checksum && hash_file( 'sha256', $temp_file ) !== $checksum ) {
            unlink( $temp_file );
            error_log( 'ExamplePress MU Bootstrapper: Checksum mismatch. Install aborted.' );
            return false;
        }

        $temp_extract_dir = $target_mu_dir . '/_ep_mu_temp';
        $wp_filesystem->mkdir( $temp_extract_dir );

        $unzip_result = unzip_file( $temp_file, $temp_extract_dir );
        unlink( $temp_file );

        if ( is_wp_error( $unzip_result ) ) {
            $wp_filesystem->delete( $temp_extract_dir, true );
            error_log( 'ExamplePress MU Bootstrapper: Unzip failed — ' . $unzip_result->get_error_message() );
            return false;
        }

        // Determine the layout of the extracted archive.
        // Built release ZIPs place files at the root (examplepress-mu.php + examplepress-mu/).
        // Source zipballs nest everything under an `owner-repo-hash` folder.
        $has_loader_at_root = file_exists( $temp_extract_dir . '/examplepress-mu.php' );

        if ( $has_loader_at_root ) {
            // Built release ZIP — files are at the root of the archive.
            if ( is_dir( $temp_extract_dir . '/examplepress-mu' ) ) {
                $wp_filesystem->move( $temp_extract_dir . '/examplepress-mu', $target_mu_dir . '/examplepress-mu', true );
            }
            if ( file_exists( $temp_extract_dir . '/examplepress-mu.php' ) ) {
                $wp_filesystem->move( $temp_extract_dir . '/examplepress-mu.php', $target_mu_dir . '/examplepress-mu.php', true );
            }
        } else {
            // Source zipball — files are nested inside a GitHub-generated folder.
            $extracted_folders = $wp_filesystem->dirlist( $temp_extract_dir );
            if ( ! empty( $extracted_folders ) ) {
                $github_folder_name = array_keys( $extracted_folders )[0];
                $github_folder_path = $temp_extract_dir . '/' . $github_folder_name;

                if ( is_dir( $github_folder_path . '/examplepress-mu' ) ) {
                    $wp_filesystem->move( $github_folder_path . '/examplepress-mu', $target_mu_dir . '/examplepress-mu', true );
                }
                if ( file_exists( $github_folder_path . '/examplepress-mu.php' ) ) {
                    $wp_filesystem->move( $github_folder_path . '/examplepress-mu.php', $target_mu_dir . '/examplepress-mu.php', true );
                }
            }
        }

        $wp_filesystem->delete( $temp_extract_dir, true );

        return true;
    }
}

ExamplePress_MU_Bootstrapper::boot();
