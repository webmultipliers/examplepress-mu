<?php
/**
 * Plugin Name: ExamplePress MU Bootstrapper
 * Description: Thin loader that fetches and executes the ExamplePress platform kernel from GitHub.
 * Version:     2.0.2
 * Author:      Web Multipliers
 * Author URI:  https://github.com/webmultipliers
 */

declare(strict_types=1);

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Thin MU loader. Checks if the kernel bootstrap exists.
 * If not, falls back to a minimal GitHub API call to download the ZIP.
 * No other logic.
 */
final class ExamplePress_MU_Bootstrapper {

    private static string $repoOwner = 'webmultipliers';
    private static string $repoName  = 'examplepress-mu';

    public static function boot(): void {
        $muDir    = __DIR__ . '/examplepress-mu';
        $bootFile = $muDir . '/bootstrap.php';

        if ( ! file_exists( $bootFile ) ) {
            self::fetchLatestFromGithub( __DIR__ );
        }

        if ( file_exists( $bootFile ) ) {
            require_once $bootFile;
        } else {
            error_log( 'ExamplePress MU Bootstrapper: Failed to load or download the core application.' );
        }
    }

    private static function fetchLatestFromGithub( string $targetMuDir ): bool {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $apiUrl  = 'https://api.github.com/repos/' . self::$repoOwner . '/' . self::$repoName . '/releases/latest';
        $response = wp_remote_get( $apiUrl, [
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

        $packageUrl = null;
        $checksum   = '';

        if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
            $updatesUrl = null;
            foreach ( $release->assets as $asset ) {
                if ( $asset->name === 'updates.json' ) {
                    $updatesUrl = $asset->browser_download_url;
                    break;
                }
            }

            if ( $updatesUrl ) {
                $updatesResponse = wp_remote_get( $updatesUrl, [
                    'headers' => [ 'User-Agent' => 'ExamplePress-Bootstrapper' ],
                    'timeout' => 10,
                ] );

                if ( ! is_wp_error( $updatesResponse ) && wp_remote_retrieve_response_code( $updatesResponse ) === 200 ) {
                    $updates = json_decode( wp_remote_retrieve_body( $updatesResponse ), true );
                    if ( ! empty( $updates['packages'][0]['package'] ) ) {
                        $packageUrl = $updates['packages'][0]['package'];
                        $checksum   = $updates['packages'][0]['checksum'] ?? '';
                    }
                }
            }
        }

        if ( ! $packageUrl ) {
            if ( empty( $release->zipball_url ) ) {
                error_log( 'ExamplePress MU Bootstrapper: No download URL found in release.' );
                return false;
            }
            $packageUrl = $release->zipball_url;
            // Zipball URLs are generated on the fly by GitHub — their SHA
            // changes over time, so checksum validation is not reliable here.
            $checksum = '';
        }

        $tempFile = download_url( $packageUrl );
        if ( is_wp_error( $tempFile ) ) {
            error_log( 'ExamplePress MU Bootstrapper: Download failed — ' . $tempFile->get_error_message() );
            return false;
        }

        if ( $checksum && hash_file( 'sha256', $tempFile ) !== $checksum ) {
            unlink( $tempFile );
            error_log( 'ExamplePress MU Bootstrapper: Checksum mismatch. Install aborted.' );
            return false;
        }

        $tempExtractDir = $targetMuDir . '/_ep_mu_temp';
        $wp_filesystem->mkdir( $tempExtractDir );

        $unzipResult = unzip_file( $tempFile, $tempExtractDir );
        unlink( $tempFile );

        if ( is_wp_error( $unzipResult ) ) {
            $wp_filesystem->delete( $tempExtractDir, true );
            error_log( 'ExamplePress MU Bootstrapper: Unzip failed — ' . $unzipResult->get_error_message() );
            return false;
        }

        $hasLoaderAtRoot = file_exists( $tempExtractDir . '/examplepress-mu.php' );

        if ( $hasLoaderAtRoot ) {
            if ( is_dir( $tempExtractDir . '/examplepress-mu' ) ) {
                $wp_filesystem->move( $tempExtractDir . '/examplepress-mu', $targetMuDir . '/examplepress-mu', true );
            }
            if ( file_exists( $tempExtractDir . '/examplepress-mu.php' ) ) {
                $wp_filesystem->move( $tempExtractDir . '/examplepress-mu.php', $targetMuDir . '/examplepress-mu.php', true );
            }
        } else {
            $extractedFolders = $wp_filesystem->dirlist( $tempExtractDir );
            if ( ! empty( $extractedFolders ) ) {
                $githubFolderName = array_keys( $extractedFolders )[0];
                $githubFolderPath = $tempExtractDir . '/' . $githubFolderName;

                if ( is_dir( $githubFolderPath . '/examplepress-mu' ) ) {
                    $wp_filesystem->move( $githubFolderPath . '/examplepress-mu', $targetMuDir . '/examplepress-mu', true );
                }
                if ( file_exists( $githubFolderPath . '/examplepress-mu.php' ) ) {
                    $wp_filesystem->move( $githubFolderPath . '/examplepress-mu.php', $targetMuDir . '/examplepress-mu.php', true );
                }
            }
        }

        $wp_filesystem->delete( $tempExtractDir, true );

        return true;
    }
}

ExamplePress_MU_Bootstrapper::boot();
