<?php
/**
 * Plugin Name: ExamplePress MU Bootstrapper
 * Description: Thin loader that fetches and executes the ExamplePress platform kernel from GitHub.
 * Version:     2.4.0
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

    /** Short-lived in-flight lock to prevent concurrent first-load installs. */
    private const INFLIGHT_KEY = 'ep_mu_install_inflight';
    /** Back-off window after a failed cold-start install (seconds). */
    private const BACKOFF_KEY = 'ep_mu_install_backoff';
    /** Persistent counter incremented on each boot attempt; cleared after a clean boot. */
    private const BOOT_ATTEMPT_OPTION = 'ep_mu_boot_attempt';
    /** Option holding the last cold-start failure reason for admin display. */
    private const COLDSTART_ERROR_OPTION = 'ep_mu_coldstart_error';
    /** Option set when the loader has quarantined a fatal kernel. */
    private const QUARANTINE_OPTION = 'ep_mu_quarantined';
    /** Counter threshold that indicates a fatal loop. */
    private const BOOT_ATTEMPT_THRESHOLD = 3;
    /** Minimum PHP version required by the kernel. Loader refuses to require the kernel below this. */
    private const REQUIRED_PHP = '8.2';

    /**
     * Flag set by Kernel::boot() (via markKernelBooted) and checked on
     * `shutdown`. The fatal-loop counter is only cleared if BOTH happened,
     * so a kernel that registers hooks and then fatals mid-request does
     * not get credit for a successful boot.
     */
    private static bool $kernelBooted = false;

    public static function boot(): void {
        $targetDir = __DIR__;

        // Self-heal: if the loader file itself is newer than the recorded
        // quarantine timestamp, the operator has explicitly deployed a new
        // loader and we should assume they intend to recover. Clear the
        // fatal-loop counter and quarantine before reading state so the
        // normal decision logic gets a clean slate.
        self::maybeClearStaleQuarantine( __FILE__ );

        $state  = self::readBootState( $targetDir );
        $action = self::computeBootAction( $state );
        self::dispatchBootAction( $action, $targetDir, $state );
    }

    /**
     * Clear the fatal-loop counter and quarantine if the loader file has
     * been updated since the quarantine was recorded. This gives operators
     * a no-SSH recovery path: drop a new loader in place and the kernel
     * gets a fresh shot at booting.
     */
    private static function maybeClearStaleQuarantine( string $loaderFile ): void {
        if ( ! function_exists( 'get_option' ) ) {
            return;
        }
        $quarantine = get_option( self::QUARANTINE_OPTION, null );
        if ( ! is_array( $quarantine ) || empty( $quarantine['time'] ) ) {
            return;
        }
        $quarantineTime = (int) $quarantine['time'];

        // Recovery is signaled by EITHER the loader file OR the kernel
        // bootstrap file having been updated since the quarantine timestamp.
        // The loader-only check missed the case where the auto-updater wrote
        // a new kernel snapshot but left the loader untouched — that legit
        // recovery would silently stay quarantined. Now both paths recover.
        $candidates = [
            $loaderFile,
            dirname( $loaderFile ) . '/examplepress-mu/bootstrap.php',
            dirname( $loaderFile ) . '/examplepress-mu/examplepress-mu.php', // 2.x kernel layout
        ];
        $newer = false;
        foreach ( $candidates as $f ) {
            $mtime = @filemtime( $f );
            if ( $mtime !== false && $mtime > $quarantineTime ) {
                $newer = true;
                break;
            }
        }
        if ( ! $newer ) {
            return;
        }
        // Loader or kernel was updated after the quarantine — assume intentional recovery.
        if ( function_exists( 'update_option' ) ) {
            update_option( self::BOOT_ATTEMPT_OPTION, 0, false );
        }
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::QUARANTINE_OPTION );
            delete_option( self::COLDSTART_ERROR_OPTION );
        }
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::BACKOFF_KEY );
            delete_transient( self::INFLIGHT_KEY );
        }
    }

    /**
     * Build the URL for the "Reset boot counter" link in the quarantine
     * notice. Includes a nonce so the action can't be triggered by a
     * third party. Falls back gracefully if WordPress isn't loaded.
     */
    private static function buildClearQuarantineUrl(): string {
        $base = function_exists( 'admin_url' ) ? admin_url( 'index.php' ) : '/wp-admin/index.php';
        $args = [ 'ep_mu_clear_quarantine' => '1' ];
        if ( function_exists( 'wp_create_nonce' ) ) {
            $args['_wpnonce'] = wp_create_nonce( 'ep_mu_clear_quarantine' );
        }
        return $base . '?' . http_build_query( $args );
    }

    /**
     * Admin-init handler for the manual quarantine reset link. Verifies
     * the nonce and capability, clears state, and redirects back.
     */
    public static function maybeHandleManualClear(): void {
        if ( empty( $_GET['ep_mu_clear_quarantine'] ) ) {
            return;
        }
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'ep_mu_clear_quarantine' ) ) {
            return;
        }

        self::manualClearQuarantine();

        if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'admin_url' ) ) {
            wp_safe_redirect( admin_url() );
            exit;
        }
    }

    /**
     * Manual recovery hook: clears the quarantine option and counters
     * unconditionally. Called from the loader's admin notice via a
     * one-shot signed query string when the operator clicks "Reset boot
     * counter" — gives a no-SSH escape hatch even when the loader and
     * kernel files are both stale.
     */
    public static function manualClearQuarantine(): void {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( function_exists( 'update_option' ) ) {
            update_option( self::BOOT_ATTEMPT_OPTION, 0, false );
        }
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::QUARANTINE_OPTION );
            delete_option( self::COLDSTART_ERROR_OPTION );
        }
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::BACKOFF_KEY );
            delete_transient( self::INFLIGHT_KEY );
        }
    }

    /**
     * Read the full boot-time state into a plain array. Pure-ish — only
     * reads options/transients/filesystem, no writes. Separated from the
     * decision so the decision is trivially unit-testable with a hand-built
     * state array.
     *
     * @return array<string, mixed>
     */
    public static function readBootState( string $targetMuDir ): array {
        $bootFile    = $targetMuDir . '/examplepress-mu/bootstrap.php';
        $previousDir = $targetMuDir . '/examplepress-mu.previous';

        return [
            'phpVersionId'   => PHP_VERSION_ID,
            'requiredPhpId'  => self::phpVersionIdFromString( self::REQUIRED_PHP ),
            'attempts'       => function_exists( 'get_option' ) ? (int) get_option( self::BOOT_ATTEMPT_OPTION, 0 ) : 0,
            'threshold'      => self::BOOT_ATTEMPT_THRESHOLD,
            'bootFileExists' => file_exists( $bootFile ),
            'previousExists' => is_dir( $previousDir ),
            'backoffActive'  => function_exists( 'get_transient' ) && (bool) get_transient( self::BACKOFF_KEY ),
            'inflightActive' => function_exists( 'get_transient' ) && (bool) get_transient( self::INFLIGHT_KEY ),
        ];
    }

    /**
     * Pure decision function. Given a state array, return the action name
     * the dispatcher should take. No side effects; no WordPress calls.
     *
     * Possible return values:
     *   php_too_old            Runtime PHP version is below REQUIRED_PHP.
     *   fatal_loop_rollback    Attempt counter exceeded threshold; previous is available.
     *   fatal_loop_quarantine  Attempt counter exceeded threshold; no previous — brick.
     *   heal_promote_previous  Boot file missing but a previous snapshot exists.
     *   cold_start_backoff     Boot file missing, no previous, and backoff transient is active.
     *   cold_start_inflight    Boot file missing, no previous, another install is already running.
     *   cold_start_fetch       Boot file missing, no previous, fetch from GitHub.
     *   proceed                Boot file present and counter under threshold — normal boot.
     *
     * @param array<string, mixed> $state
     */
    public static function computeBootAction( array $state ): string {
        if ( $state['phpVersionId'] < $state['requiredPhpId'] ) {
            return 'php_too_old';
        }

        // Fatal-loop detection only applies when there's actually a kernel
        // present that COULD be fataling. If bootFileExists is false, any
        // elevated counter must be stale (e.g. the kernel was deleted by
        // hand between requests, or a previous session left the option
        // set). Fall through to the normal heal/cold-start pathways; the
        // dispatcher will reset the counter before installing anew.
        if ( $state['attempts'] >= $state['threshold'] && $state['bootFileExists'] ) {
            return $state['previousExists'] ? 'fatal_loop_rollback' : 'fatal_loop_quarantine';
        }

        if ( ! $state['bootFileExists'] ) {
            if ( $state['previousExists'] ) {
                return 'heal_promote_previous';
            }
            if ( $state['backoffActive'] ) {
                return 'cold_start_backoff';
            }
            if ( $state['inflightActive'] ) {
                return 'cold_start_inflight';
            }
            return 'cold_start_fetch';
        }

        return 'proceed';
    }

    /**
     * Act on a decision from computeBootAction(). All WordPress side effects
     * live here: option writes, transients, promotion, fetch, require.
     *
     * @param array<string, mixed> $state
     */
    /**
     * Actions for which we're about to require a FRESHLY installed kernel,
     * so any pre-existing attempt counter is meaningless and must be reset
     * before the (about-to-happen) increment. Otherwise a stale counter
     * could immediately re-trigger the fatal-loop branch on the next boot.
     */
    private const FRESH_KERNEL_ACTIONS = [
        'fatal_loop_rollback',
        'heal_promote_previous',
        'cold_start_fetch',
    ];

    private static function dispatchBootAction( string $action, string $targetMuDir, array $state ): void {
        $bootFile = $targetMuDir . '/examplepress-mu/bootstrap.php';

        switch ( $action ) {
            case 'php_too_old':
                self::registerAdminNotice( sprintf(
                    'ExamplePress MU requires PHP %s or newer. Current version: %s. The kernel will not be loaded.',
                    self::REQUIRED_PHP,
                    PHP_VERSION
                ) );
                return;

            case 'fatal_loop_quarantine':
                if ( function_exists( 'update_option' ) ) {
                    update_option( self::QUARANTINE_OPTION, [
                        'time'     => time(),
                        'attempts' => $state['attempts'],
                    ], false );
                }
                // Register the recovery handler — admins can click "Reset
                // boot counter" in the notice without SSH/file access.
                if ( function_exists( 'add_action' ) ) {
                    add_action( 'admin_init', [ self::class, 'maybeHandleManualClear' ] );
                }
                // Build URL lazily inside the notice callback — at boot-action
                // dispatch time we're still inside wp-settings.php, so pluggable
                // functions (wp_create_nonce) and the current user aren't loaded
                // yet. A nonce minted now would either be empty or seeded with
                // user ID 0 and would fail verification when the admin clicks
                // it later as a logged-in user. Defer until admin_notices.
                self::registerAdminNotice(
                    'ExamplePress MU: the kernel appears to be fataling on boot and no previous version is available to roll back to. The kernel has been quarantined. Restore from backup or reinstall — '
                    . '<a href="{{EP_MU_RESET_URL}}">Reset boot counter and try again</a>.'
                );
                return;

            case 'fatal_loop_rollback':
                if ( function_exists( 'update_option' ) ) {
                    update_option( self::QUARANTINE_OPTION, [
                        'time'     => time(),
                        'attempts' => $state['attempts'],
                    ], false );
                }
                if ( ! self::promotePreviousVersion( $targetMuDir ) ) {
                    self::registerAdminNotice(
                        'ExamplePress MU: rollback to the previous kernel failed. Restore from backup or reinstall.'
                    );
                    return;
                }
                // Reset counter so the promoted (known-good) kernel gets a
                // clean shot. This iteration still counts as one attempt.
                if ( function_exists( 'update_option' ) ) {
                    update_option( self::BOOT_ATTEMPT_OPTION, 0, false );
                }
                self::registerAdminNotice(
                    'ExamplePress MU: the kernel was fataling on boot and has been rolled back to the previous version. Check the logs for details.'
                );
                // Fall through to require.
                break;

            case 'heal_promote_previous':
                self::promotePreviousVersion( $targetMuDir );
                // Fall through to require (if promotion succeeded bootFile now exists).
                break;

            case 'cold_start_backoff':
                self::maybeShowColdStartNotice();
                return;

            case 'cold_start_inflight':
                return;

            case 'cold_start_fetch':
                if ( function_exists( 'set_transient' ) ) {
                    set_transient( self::INFLIGHT_KEY, '1', 120 );
                }
                $ok = self::fetchLatestFromGithub( $targetMuDir );
                if ( function_exists( 'delete_transient' ) ) {
                    delete_transient( self::INFLIGHT_KEY );
                }
                if ( ! $ok ) {
                    if ( function_exists( 'set_transient' ) ) {
                        set_transient( self::BACKOFF_KEY, '1', 5 * MINUTE_IN_SECONDS );
                    }
                    self::maybeShowColdStartNotice();
                    return;
                }
                break;

            case 'proceed':
                // Normal boot path.
                break;
        }

        // On any fresh-kernel path, zero the counter before the increment:
        // we're about to require an entirely new kernel and the old
        // counter has no bearing on its behaviour. Otherwise a stale
        // elevated counter (e.g. from a prior session) would re-trigger
        // fatal_loop_quarantine on the next boot.
        if ( in_array( $action, self::FRESH_KERNEL_ACTIONS, true ) ) {
            $state['attempts'] = 0;
        }

        // Increment the attempt counter BEFORE the require. markKernelBooted()
        // + the shutdown hook will clear it once Kernel::boot() AND the full
        // request complete successfully.
        if ( function_exists( 'update_option' ) ) {
            update_option( self::BOOT_ATTEMPT_OPTION, $state['attempts'] + 1, false );
        }

        if ( file_exists( $bootFile ) ) {
            require_once $bootFile;
            return;
        }

        self::registerAdminNotice(
            'ExamplePress MU kernel is not installed and cold-start fetch from GitHub failed. Check error_log for details; the loader will retry shortly.'
        );
        error_log( 'ExamplePress MU Bootstrapper: Failed to load or download the core application.' );
    }

    /**
     * Called by bootstrap.php after Kernel::boot() returns. Sets a flag that
     * the shutdown handler checks — the counter is only cleared if the
     * request also reaches shutdown (i.e. no fatal fired inside a later hook).
     */
    public static function markKernelBooted(): void {
        self::$kernelBooted = true;
    }

    /**
     * Public manual clear of the fatal-loop counter and quarantine state.
     * Called by the Updates admin page when an operator has remediated a
     * broken kernel and wants to stop the loader from short-circuiting on
     * the next request.
     */
    public static function clearQuarantineState(): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( self::BOOT_ATTEMPT_OPTION, 0, false );
        }
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::QUARANTINE_OPTION );
            delete_option( self::COLDSTART_ERROR_OPTION );
        }
    }

    /**
     * Shutdown callback. Clears the fatal-loop counter and quarantine state
     * iff markKernelBooted() was called during the same request.
     */
    public static function finalizeBootIfSuccessful(): void {
        if ( ! self::$kernelBooted ) {
            return;
        }
        if ( function_exists( 'update_option' ) ) {
            update_option( self::BOOT_ATTEMPT_OPTION, 0, false );
        }
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::QUARANTINE_OPTION );
            delete_option( self::COLDSTART_ERROR_OPTION );
        }
    }

    /**
     * Promote an `examplepress-mu.previous/` snapshot to the active kernel
     * directory. Used by both fatal-loop recovery and heal-on-missing.
     */
    public static function promotePreviousVersion( string $targetMuDir ): bool {
        $previousDir = $targetMuDir . '/examplepress-mu.previous';
        $kernelDir   = $targetMuDir . '/examplepress-mu';

        if ( ! is_dir( $previousDir ) ) {
            return false;
        }

        $fs = self::filesystem();
        if ( ! $fs ) {
            return false;
        }

        // Swap out the broken kernel (if any) and move previous into its place.
        if ( $fs->is_dir( $kernelDir ) ) {
            $broken = $targetMuDir . '/examplepress-mu.broken';
            $fs->delete( $broken, true );
            if ( ! $fs->move( $kernelDir, $broken, true ) ) {
                return false;
            }
        }

        return (bool) $fs->move( $previousDir, $kernelDir, true );
    }

    /**
     * Convert '8.1' / '8.1.0' to a PHP_VERSION_ID style integer.
     */
    private static function phpVersionIdFromString( string $version ): int {
        $parts = array_map( 'intval', explode( '.', $version ) + [ 0, 0, 0 ] );
        return $parts[0] * 10000 + ( $parts[1] ?? 0 ) * 100 + ( $parts[2] ?? 0 );
    }

    /**
     * Register a one-shot admin notice from the loader. Used when no kernel
     * is running (no namespaced classes loaded) so the operator still sees
     * why the site is empty.
     */
    private static function registerAdminNotice( string $message ): void {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }
        add_action( 'admin_notices', static function () use ( $message ): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            // Late-bind the quarantine reset URL — nonce must be minted with
            // a real logged-in user, which is only true at admin_notices time.
            if ( strpos( $message, '{{EP_MU_RESET_URL}}' ) !== false ) {
                $message = str_replace( '{{EP_MU_RESET_URL}}', esc_url( self::buildClearQuarantineUrl() ), $message );
            }
            // Allow a single anchor (used by the quarantine recovery link).
            // Everything else is stripped — these messages are loader-internal,
            // never user input.
            $allowed = [ 'a' => [ 'href' => true ], 'strong' => [], 'code' => [] ];
            printf(
                '<div class="notice notice-error"><p><strong>ExamplePress MU:</strong> %s</p></div>',
                function_exists( 'wp_kses' ) ? wp_kses( $message, $allowed ) : strip_tags( $message )
            );
        } );
    }

    /**
     * Show the stored cold-start error (set by fetchLatestFromGithub on failure).
     */
    private static function maybeShowColdStartNotice(): void {
        if ( ! function_exists( 'get_option' ) ) {
            return;
        }
        $error = get_option( self::COLDSTART_ERROR_OPTION, '' );
        $msg   = is_string( $error ) && $error !== ''
            ? 'ExamplePress MU kernel is not installed. Cold-start fetch failed: ' . $error
            : 'ExamplePress MU kernel is not installed. Cold-start fetch is backing off; the loader will retry shortly.';
        self::registerAdminNotice( $msg );
    }

    /**
     * Persist the latest cold-start failure reason for the admin notice.
     */
    private static function recordColdStartError( string $reason ): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( self::COLDSTART_ERROR_OPTION, $reason, false );
        }
    }

    private static function fetchLatestFromGithub( string $targetMuDir ): bool {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $apiUrl  = 'https://api.github.com/repos/' . self::$repoOwner . '/' . self::$repoName . '/releases/latest';
        $response = wp_remote_get( $apiUrl, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ExamplePress-Bootstrapper',
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $reason = is_wp_error( $response ) ? $response->get_error_message() : 'GitHub API unreachable';
            self::recordColdStartError( $reason );
            error_log( 'ExamplePress MU Bootstrapper: Could not reach GitHub API — ' . $reason );
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
                self::recordColdStartError( 'No download URL found in release.' );
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
            $reason = 'Download failed — ' . $tempFile->get_error_message();
            self::recordColdStartError( $reason );
            error_log( 'ExamplePress MU Bootstrapper: ' . $reason );
            return false;
        }

        if ( $checksum && hash_file( 'sha256', $tempFile ) !== $checksum ) {
            @unlink( $tempFile );
            error_log( 'ExamplePress MU Bootstrapper: Checksum mismatch. Install aborted.' );
            return false;
        }

        return self::installFromZip( $tempFile, $targetMuDir, 'Bootstrapper' );
    }

    // ── Shared installer ────────────────────────────────────────────────
    //
    // Public so that Infrastructure\Updater can call into the exact same
    // code path for its WP-Cron self-upgrade. The loader is guaranteed to
    // be in scope whenever the Updater runs — it's what booted the kernel
    // in the first place — so no autoloader/PSR-4 involvement is needed.

    /**
     * Initialize WP_Filesystem forcing the 'direct' transport so this
     * never prompts for FTP credentials during a cold boot / cron tick.
     *
     * @return \WP_Filesystem_Base|false
     */
    public static function filesystem() {
        global $wp_filesystem;

        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
            return $wp_filesystem;
        }

        $forceCb = static function () { return 'direct'; };
        add_filter( 'filesystem_method', $forceCb );
        ob_start();
        $ok = \WP_Filesystem();
        ob_end_clean();
        remove_filter( 'filesystem_method', $forceCb );

        if ( ! $ok || ! ( $wp_filesystem instanceof \WP_Filesystem_Base ) ) {
            return false;
        }

        return $wp_filesystem;
    }

    /**
     * Install/upgrade the ExamplePress kernel from a downloaded zip file.
     *
     * Stages to a temp dir, locates the payload (archive root OR single
     * wrapping folder, e.g. GitHub source zipball), backs up any existing
     * kernel/loader, swaps atomically, rolls back on failure, and cleans up.
     *
     * @param string $tempFile    Absolute path to the downloaded .zip.
     *                            Will be removed by this method.
     * @param string $targetMuDir Directory that contains (or will contain)
     *                            the `examplepress-mu/` kernel and the
     *                            `examplepress-mu.php` loader.
     * @param string $context     Log-prefix label ('Bootstrapper' / 'Updater').
     */
    public static function installFromZip( string $tempFile, string $targetMuDir, string $context = 'Installer' ): bool {
        $fs = self::filesystem();
        if ( ! $fs ) {
            self::logInstall( $context, 'Filesystem unavailable — direct access denied.' );
            self::unlinkTemp( $tempFile );
            self::fireInstallFailed( 'fs_unavailable', $context );
            return false;
        }

        $tempExtractDir = $targetMuDir . '/_ep_mu_install_temp';
        $previousDir    = $targetMuDir . '/examplepress-mu.previous';
        $kernelDir      = $targetMuDir . '/examplepress-mu';
        $loaderFile     = $targetMuDir . '/examplepress-mu.php';

        // Clear leftovers from a prior failed attempt.
        $fs->delete( $tempExtractDir, true );

        $fs->mkdir( $tempExtractDir );

        $unzipResult = unzip_file( $tempFile, $tempExtractDir );
        self::unlinkTemp( $tempFile );

        if ( is_wp_error( $unzipResult ) ) {
            $fs->delete( $tempExtractDir, true );
            $reason = 'Unzip failed — ' . $unzipResult->get_error_message();
            self::logInstall( $context, $reason );
            self::fireInstallFailed( $reason, $context );
            return false;
        }

        $stagedSource = self::locatePayload( $fs, $tempExtractDir );

        if ( ! $stagedSource ) {
            $fs->delete( $tempExtractDir, true );
            self::logInstall( $context, 'Could not locate kernel payload in archive.' );
            self::fireInstallFailed( 'payload_not_found', $context );
            return false;
        }

        $hasNewKernel = $fs->is_dir( $stagedSource . '/examplepress-mu' );
        $hasNewLoader = $fs->exists( $stagedSource . '/examplepress-mu.php' );

        if ( ! $hasNewKernel && ! $hasNewLoader ) {
            $fs->delete( $tempExtractDir, true );
            self::logInstall( $context, 'Payload missing expected kernel/loader files.' );
            self::fireInstallFailed( 'payload_incomplete', $context );
            return false;
        }

        // Staged requires_php check — refuse to install a release that
        // declares a newer PHP floor than the runtime.
        $stagedPhp = self::readStagedRequiresPhp( $stagedSource );
        if ( $stagedPhp !== null && PHP_VERSION_ID < self::phpVersionIdFromString( $stagedPhp ) ) {
            $fs->delete( $tempExtractDir, true );
            $reason = sprintf( 'Staged release requires PHP %s; runtime is %s. Aborting swap.', $stagedPhp, PHP_VERSION );
            self::logInstall( $context, $reason );
            self::fireInstallFailed( 'requires_php', $context );
            return false;
        }

        // Gather version info for the lifecycle hooks.
        $oldVersion = self::readLoaderVersion( $loaderFile );
        $newVersion = $hasNewLoader ? self::readLoaderVersion( $stagedSource . '/examplepress-mu.php' ) : $oldVersion;

        // Pre-install hook — listeners can abort by returning WP_Error via
        // a wrapping filter, or by throwing. Cold-start has no listeners;
        // the Updater path does.
        if ( function_exists( 'do_action' ) ) {
            do_action( 'examplepress_mu_pre_install', $stagedSource, $context, $oldVersion, $newVersion );
        }

        // Rotate any existing previous snapshot. On success, the current
        // kernel dir becomes `examplepress-mu.previous/` — a ready-to-promote
        // rollback target consumed by promotePreviousVersion() and the
        // loader's fatal-loop recovery path.
        $previousLoader = $targetMuDir . '/examplepress-mu.previous-loader.php';
        $fs->delete( $previousDir, true );
        $fs->delete( $previousLoader, true );

        $kernelBackedUp = false;
        if ( $hasNewKernel && $fs->is_dir( $kernelDir ) ) {
            if ( ! $fs->move( $kernelDir, $previousDir, true ) ) {
                $fs->delete( $tempExtractDir, true );
                self::logInstall( $context, 'Could not stage kernel backup.' );
                self::fireInstallFailed( 'backup_kernel', $context );
                return false;
            }
            $kernelBackedUp = true;
        }

        $loaderBackedUp = false;
        if ( $hasNewLoader && $fs->exists( $loaderFile ) ) {
            if ( ! $fs->copy( $loaderFile, $previousLoader, true ) ) {
                if ( $kernelBackedUp ) {
                    $fs->move( $previousDir, $kernelDir, true );
                }
                $fs->delete( $tempExtractDir, true );
                self::logInstall( $context, 'Could not stage loader backup.' );
                self::fireInstallFailed( 'backup_loader', $context );
                return false;
            }
            $loaderBackedUp = true;
        }

        // Atomic swap.
        $swapOk = true;

        if ( $hasNewKernel ) {
            $swapOk = $swapOk && $fs->move( $stagedSource . '/examplepress-mu', $kernelDir, true );
        }
        if ( $swapOk && $hasNewLoader ) {
            $swapOk = $swapOk && $fs->move( $stagedSource . '/examplepress-mu.php', $loaderFile, true );
        }

        if ( ! $swapOk ) {
            // Roll back. Delete any partially-moved new kernel and restore
            // the staged backups into place.
            $fs->delete( $kernelDir, true );
            if ( $kernelBackedUp ) {
                $fs->move( $previousDir, $kernelDir, true );
            }
            if ( $loaderBackedUp && $fs->exists( $previousLoader ) ) {
                $fs->move( $previousLoader, $loaderFile, true );
            }
            $fs->delete( $tempExtractDir, true );
            self::logInstall( $context, 'Atomic swap failed; previous version restored.' );
            self::fireInstallFailed( 'swap_failed', $context );
            return false;
        }

        $fs->delete( $tempExtractDir, true );
        // NOTE: we intentionally do NOT delete $previousDir or $previousLoader.
        // They are retained as the rollback snapshot consumed by
        // promotePreviousVersion() and the loader's fatal-loop recovery path.

        // OPcache: invalidate stale bytecode for the loader and every .php
        // file under the new kernel dir. Without this, the current worker
        // will keep serving the old bytecode against new file layouts until
        // it recycles — a recipe for class-not-found fatals.
        //
        // NOTE: opcache_invalidate() only affects the current worker.
        // Other PHP-FPM workers will still hold stale entries until their
        // own next request mtime-revalidates each file. Hosts running
        // opcache.validate_timestamps=0 will hold stale bytecode
        // indefinitely — those operators should wire the
        // examplepress_mu_post_install_opcache action below to a
        // pool-reload mechanism (e.g. SIGUSR2 to PHP-FPM). We do NOT
        // call opcache_reset() ourselves because it nukes the entire
        // cache pool including every other PHP app on the same host.
        self::invalidateOpcacheTree( $loaderFile );
        self::invalidateOpcacheTree( $kernelDir );

        if ( function_exists( 'do_action' ) ) {
            /**
             * Fires after a successful kernel install/upgrade and AFTER
             * the in-worker OPcache invalidation. Separate from
             * examplepress_mu_post_install so operators on locked-down
             * hosts can wire this specifically to a PHP-FPM pool reload
             * without coupling it to their other post-install logic.
             */
            do_action( 'examplepress_mu_post_install_opcache', $kernelDir, $loaderFile, $context );

            do_action( 'examplepress_mu_post_install', $oldVersion, $newVersion, $context );
        }

        return true;
    }

    /**
     * Parse the `requires_php` value out of a staged examplepress.json.
     * Returns null when the file is absent or the key is missing.
     */
    private static function readStagedRequiresPhp( string $stagedSource ): ?string {
        $candidates = [
            $stagedSource . '/examplepress-mu/examplepress.json',
            $stagedSource . '/examplepress.json',
        ];
        foreach ( $candidates as $path ) {
            if ( ! is_readable( $path ) ) {
                continue;
            }
            $decoded = json_decode( (string) @file_get_contents( $path ), true );
            if ( is_array( $decoded ) && ! empty( $decoded['requires_php'] ) ) {
                return (string) $decoded['requires_php'];
            }
        }
        return null;
    }

    /**
     * Read the `Version:` header out of an examplepress-mu.php loader file.
     */
    private static function readLoaderVersion( string $loaderPath ): string {
        if ( ! is_readable( $loaderPath ) ) {
            return '';
        }
        $head = (string) @file_get_contents( $loaderPath, false, null, 0, 8192 );
        if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $head, $m ) ) {
            return $m[1];
        }
        return '';
    }

    /**
     * Recursively invalidate OPcache entries for a file or directory.
     * No-op when OPcache is not loaded.
     */
    private static function invalidateOpcacheTree( string $path ): void {
        if ( ! function_exists( 'opcache_invalidate' ) ) {
            return;
        }
        if ( is_file( $path ) ) {
            @opcache_invalidate( $path, true );
            return;
        }
        if ( ! is_dir( $path ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() && substr( $file->getFilename(), -4 ) === '.php' ) {
                @opcache_invalidate( $file->getPathname(), true );
            }
        }
    }

    /**
     * Fire the install-failed hook if WP is far enough along to have it.
     */
    private static function fireInstallFailed( string $reason, string $context ): void {
        if ( function_exists( 'do_action' ) ) {
            do_action( 'examplepress_mu_install_failed', $reason, $context );
        }
    }

    /**
     * Locate the kernel payload root inside a freshly-extracted temp dir.
     * Handles both flat archives and single-wrapper-folder archives
     * (GitHub source zipballs).
     */
    private static function locatePayload( \WP_Filesystem_Base $fs, string $tempExtractDir ): ?string {
        if ( $fs->is_dir( $tempExtractDir . '/examplepress-mu' )
            || $fs->exists( $tempExtractDir . '/examplepress-mu.php' ) ) {
            return $tempExtractDir;
        }

        $entries = $fs->dirlist( $tempExtractDir );
        if ( empty( $entries ) || ! is_array( $entries ) ) {
            return null;
        }

        foreach ( $entries as $name => $info ) {
            if ( 'd' !== ( $info['type'] ?? '' ) ) {
                continue;
            }
            $candidate = $tempExtractDir . '/' . $name;
            if ( $fs->is_dir( $candidate . '/examplepress-mu' )
                || $fs->exists( $candidate . '/examplepress-mu.php' ) ) {
                return $candidate;
            }
        }

        return null;
    }

    private static function unlinkTemp( string $tempFile ): void {
        if ( $tempFile && file_exists( $tempFile ) ) {
            @unlink( $tempFile );
        }
    }

    private static function logInstall( string $context, string $message ): void {
        error_log( 'ExamplePress MU ' . $context . ': ' . $message );
    }
}

ExamplePress_MU_Bootstrapper::boot();
