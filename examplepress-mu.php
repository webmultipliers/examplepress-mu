<?php
/**
 * Plugin Name: ExamplePress MU Bootstrapper
 * Description: Thin loader that fetches and executes the ExamplePress platform kernel from GitHub.
 * Version:     2.0.6
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
        $backupDir      = $targetMuDir . '/_ep_mu_backup';
        $kernelDir      = $targetMuDir . '/examplepress-mu';
        $loaderFile     = $targetMuDir . '/examplepress-mu.php';

        // Clear leftovers from any prior failed attempt.
        $wp_filesystem->delete( $tempExtractDir, true );
        $wp_filesystem->delete( $backupDir, true );

        $wp_filesystem->mkdir( $tempExtractDir );

        $unzipResult = unzip_file( $tempFile, $tempExtractDir );
        unlink( $tempFile );

        if ( is_wp_error( $unzipResult ) ) {
            $wp_filesystem->delete( $tempExtractDir, true );
            error_log( 'ExamplePress MU Bootstrapper: Unzip failed — ' . $unzipResult->get_error_message() );
            return false;
        }

        // Locate the staged source (root of the archive or the GitHub-named folder).
        $stagedSource = null;
        if ( file_exists( $tempExtractDir . '/examplepress-mu.php' ) ) {
            $stagedSource = $tempExtractDir;
        } else {
            $extractedFolders = $wp_filesystem->dirlist( $tempExtractDir );
            if ( ! empty( $extractedFolders ) ) {
                $githubFolderName = array_keys( $extractedFolders )[0];
                $stagedSource     = $tempExtractDir . '/' . $githubFolderName;
            }
        }

        if ( ! $stagedSource ) {
            $wp_filesystem->delete( $tempExtractDir, true );
            error_log( 'ExamplePress MU Bootstrapper: Could not locate kernel payload in archive.' );
            return false;
        }

        // Stage backups of existing kernel/loader if present.
        $wp_filesystem->mkdir( $backupDir );
        $kernelBackedUp = false;
        $loaderBackedUp = false;

        if ( is_dir( $kernelDir ) ) {
            if ( ! $wp_filesystem->move( $kernelDir, $backupDir . '/examplepress-mu', true ) ) {
                $wp_filesystem->delete( $tempExtractDir, true );
                $wp_filesystem->delete( $backupDir, true );
                error_log( 'ExamplePress MU Bootstrapper: Could not stage kernel backup.' );
                return false;
            }
            $kernelBackedUp = true;
        }

        if ( file_exists( $loaderFile ) ) {
            if ( ! $wp_filesystem->move( $loaderFile, $backupDir . '/examplepress-mu.php', true ) ) {
                if ( $kernelBackedUp ) {
                    $wp_filesystem->move( $backupDir . '/examplepress-mu', $kernelDir, true );
                }
                $wp_filesystem->delete( $tempExtractDir, true );
                $wp_filesystem->delete( $backupDir, true );
                error_log( 'ExamplePress MU Bootstrapper: Could not stage loader backup.' );
                return false;
            }
            $loaderBackedUp = true;
        }

        $swapOk = true;

        if ( is_dir( $stagedSource . '/examplepress-mu' ) ) {
            $swapOk = $swapOk && $wp_filesystem->move( $stagedSource . '/examplepress-mu', $kernelDir, true );
        }
        if ( $swapOk && file_exists( $stagedSource . '/examplepress-mu.php' ) ) {
            $swapOk = $swapOk && $wp_filesystem->move( $stagedSource . '/examplepress-mu.php', $loaderFile, true );
        }

        if ( ! $swapOk ) {
            // Roll back to whatever was there before.
            $wp_filesystem->delete( $kernelDir, true );
            if ( $kernelBackedUp ) {
                $wp_filesystem->move( $backupDir . '/examplepress-mu', $kernelDir, true );
            }
            if ( $loaderBackedUp ) {
                $wp_filesystem->move( $backupDir . '/examplepress-mu.php', $loaderFile, true );
            }
            $wp_filesystem->delete( $tempExtractDir, true );
            $wp_filesystem->delete( $backupDir, true );
            error_log( 'ExamplePress MU Bootstrapper: Atomic swap failed; previous state restored.' );
            return false;
        }

        $wp_filesystem->delete( $tempExtractDir, true );
        $wp_filesystem->delete( $backupDir, true );

        return true;
    }
}

ExamplePress_MU_Bootstrapper::boot();
