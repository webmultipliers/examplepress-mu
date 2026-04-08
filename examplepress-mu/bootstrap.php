<?php
/**
 * ExamplePress MU — Platform Kernel Bootstrap
 *
 * This is the main entry point for the ExamplePress platform.
 * Called by the thin loader (examplepress-mu.php) in the mu-plugins root.
 *
 * Execution order:
 *  1. Define constants.
 *  2. Require the Composer autoloader.
 *  3. Boot the Kernel.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Platform Constants ──────────────────────────────────────────────
// Version is the single source of truth in the loader header
// (examplepress-mu.php). We parse it at runtime so the kernel and the
// release-workflow scraper can never disagree.
if ( ! defined( 'EXAMPLEPRESS_MU_VERSION' ) ) {
    $ep_mu_loader = dirname( __DIR__ ) . '/examplepress-mu.php';
    $ep_mu_ver    = '0.0.0';
    if ( is_readable( $ep_mu_loader ) ) {
        if ( ! function_exists( 'get_file_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/functions.php';
        }
        $ep_mu_headers = get_file_data( $ep_mu_loader, [ 'Version' => 'Version' ] );
        if ( ! empty( $ep_mu_headers['Version'] ) ) {
            $ep_mu_ver = $ep_mu_headers['Version'];
        }
    }
    define( 'EXAMPLEPRESS_MU_VERSION', $ep_mu_ver );
    unset( $ep_mu_loader, $ep_mu_ver, $ep_mu_headers );
}
// Kernel API version — themes/plugins check this to verify compatibility
// with the platform contract. Bump when the kernel-facing API changes.
define( 'EXAMPLEPRESS_MU_API_VERSION', 1 );
define( 'EXAMPLEPRESS_MU_DIR', __DIR__ );
define( 'EXAMPLEPRESS_MU_URI', plugins_url( '', __FILE__ ) );
define( 'EP_MU_ACTIVE', true );
define( 'EP_ADMIN_MENU_SLUG', 'examplepress' );

// Bridge: theme directory for reading theme-owned files
// (blockstudio.json, theme.json, companion plugin design tokens)
if ( ! defined( 'EP_THEME_PATH' ) ) {
    define( 'EP_THEME_PATH', get_template_directory() );
}

// ─── Composer Autoloader ─────────────────────────────────────────────
$autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
    require_once $autoloader;
} else {
    // Fallback: register a minimal PSR-4 autoloader if Composer hasn't been run.
    spl_autoload_register( static function ( string $class ): void {
        $prefix = 'ExamplePress\\MU\\';
        $baseDir = __DIR__ . '/src/';

        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        $relativeClass = substr( $class, $len );
        $file = $baseDir . str_replace( '\\', '/', $relativeClass ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    } );
}

// ─── Boot the Kernel ─────────────────────────────────────────────────
\ExamplePress\MU\Kernel::boot();

// ─── Two-phase fatal-loop counter clear ─────────────────────────────
// Reaching this line only proves that Kernel::boot() didn't fatal during
// the require. A later hook could still fatal during the same request.
// So we set a "kernel booted" flag now and defer the actual counter
// clear to a shutdown callback that fires at the very end — if the
// request reaches shutdown with the flag set, the boot was genuinely
// successful and the counter can be safely cleared.
if ( class_exists( 'ExamplePress_MU_Bootstrapper', false ) ) {
    \ExamplePress_MU_Bootstrapper::markKernelBooted();
    if ( function_exists( 'add_action' ) ) {
        add_action(
            'shutdown',
            [ 'ExamplePress_MU_Bootstrapper', 'finalizeBootIfSuccessful' ],
            PHP_INT_MAX
        );
    }
}
