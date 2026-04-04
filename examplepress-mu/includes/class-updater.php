<?php
/**
 * ExamplePress MU — Self-Updater
 *
 * Checks the updates.json attached to the latest GitHub release on a transient-based
 * schedule (every 12 hours). If a newer version is found, silently downloads and
 * overwrites the kernel and loader files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Updater {

    const CHECK_INTERVAL   = 12 * HOUR_IN_SECONDS;
    const TRANSIENT_KEY    = 'ep_mu_update_check';
    const UPDATES_FILENAME = 'updates.json';

    private static $repo_owner = 'webmultipliers';
    private static $repo_name  = 'examplepress-mu';

    /**
     * Register the update check on admin_init so it only runs for dashboard requests.
     */
    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'maybe_check_for_update' ] );
    }

    /**
     * Run the update check if the transient has expired.
     */
    public static function maybe_check_for_update() {
        if ( false !== get_transient( self::TRANSIENT_KEY ) ) {
            return;
        }

        // Set the transient immediately to prevent race conditions on concurrent requests.
        set_transient( self::TRANSIENT_KEY, 'checking', self::CHECK_INTERVAL );

        $remote_version = self::fetch_remote_version();

        if ( ! $remote_version ) {
            return;
        }

        if ( version_compare( $remote_version['version'], EXAMPLEPRESS_MU_VERSION, '>' ) ) {
            self::perform_update( $remote_version['package'], $remote_version['checksum'] );
        }
    }

    /**
     * Fetch the updates.json from the latest GitHub release.
     *
     * @return array|false  Array with 'version', 'package', 'checksum' keys, or false on failure.
     */
    private static function fetch_remote_version() {
        $api_url  = 'https://api.github.com/repos/' . self::$repo_owner . '/' . self::$repo_name . '/releases/latest';
        $response = wp_remote_get( $api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ExamplePress-Updater',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $release->assets ) || ! is_array( $release->assets ) ) {
            return false;
        }

        // Find the updates.json asset.
        $updates_url = null;
        foreach ( $release->assets as $asset ) {
            if ( $asset->name === self::UPDATES_FILENAME ) {
                $updates_url = $asset->browser_download_url;
                break;
            }
        }

        if ( ! $updates_url ) {
            return false;
        }

        $updates_response = wp_remote_get( $updates_url, [
            'headers' => [ 'User-Agent' => 'ExamplePress-Updater' ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $updates_response ) || wp_remote_retrieve_response_code( $updates_response ) !== 200 ) {
            return false;
        }

        $updates = json_decode( wp_remote_retrieve_body( $updates_response ), true );

        if ( empty( $updates['version'] ) || empty( $updates['packages'][0]['package'] ) ) {
            return false;
        }

        return [
            'version'  => $updates['version'],
            'package'  => $updates['packages'][0]['package'],
            'checksum' => $updates['packages'][0]['checksum'] ?? '',
        ];
    }

    /**
     * Download the ZIP and overwrite the kernel + loader in place.
     *
     * @param string $package_url  Direct download URL for the release ZIP.
     * @param string $checksum     Expected SHA-256 hash of the ZIP.
     * @return bool
     */
    private static function perform_update( $package_url, $checksum = '' ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $temp_file = download_url( $package_url );
        if ( is_wp_error( $temp_file ) ) {
            error_log( 'ExamplePress MU Updater: Download failed — ' . $temp_file->get_error_message() );
            return false;
        }

        // Verify checksum if provided.
        if ( $checksum && hash_file( 'sha256', $temp_file ) !== $checksum ) {
            unlink( $temp_file );
            error_log( 'ExamplePress MU Updater: Checksum mismatch. Update aborted.' );
            return false;
        }

        $mu_plugins_dir   = dirname( EXAMPLEPRESS_MU_DIR );
        $temp_extract_dir = $mu_plugins_dir . '/_ep_mu_update_temp';

        $wp_filesystem->mkdir( $temp_extract_dir );

        $unzip_result = unzip_file( $temp_file, $temp_extract_dir );
        unlink( $temp_file );

        if ( is_wp_error( $unzip_result ) ) {
            $wp_filesystem->delete( $temp_extract_dir, true );
            error_log( 'ExamplePress MU Updater: Unzip failed — ' . $unzip_result->get_error_message() );
            return false;
        }

        // The release ZIP (built by our workflow) places files at the root — no nested folder.
        // Overwrite the application directory.
        if ( is_dir( $temp_extract_dir . '/examplepress-mu' ) ) {
            // Remove the old kernel directory first to clear stale files.
            $wp_filesystem->delete( $mu_plugins_dir . '/examplepress-mu', true );
            $wp_filesystem->move( $temp_extract_dir . '/examplepress-mu', $mu_plugins_dir . '/examplepress-mu', true );
        }

        // Overwrite the loader.
        if ( file_exists( $temp_extract_dir . '/examplepress-mu.php' ) ) {
            $wp_filesystem->move( $temp_extract_dir . '/examplepress-mu.php', $mu_plugins_dir . '/examplepress-mu.php', true );
        }

        $wp_filesystem->delete( $temp_extract_dir, true );

        // Clear the transient so the next check picks up the new version.
        delete_transient( self::TRANSIENT_KEY );

        error_log( 'ExamplePress MU Updater: Successfully updated to latest version.' );

        return true;
    }
}
