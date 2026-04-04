<?php
/**
 * ExamplePress MU — Platform Kernel Bootstrap
 *
 * This is the main entry point for the ExamplePress platform.
 * Called by the thin loader (examplepress-mu.php) in the mu-plugins root.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define platform constants.
define( 'EXAMPLEPRESS_MU_VERSION', '1.0.0' );
define( 'EXAMPLEPRESS_MU_DIR', __DIR__ );
define( 'EXAMPLEPRESS_MU_FILE', __DIR__ . '/bootstrap.php' );
