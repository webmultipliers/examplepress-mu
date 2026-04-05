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
define( 'EXAMPLEPRESS_MU_VERSION', '2.0.0' );
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
