<?php
/**
 * ExamplePress MU — Platform Kernel Bootstrap
 *
 * This is the main entry point for the ExamplePress platform.
 * Called by the thin loader (examplepress-mu.php) in the mu-plugins root.
 *
 * Execution order:
 *  1. Define constants (including EP_MU_ACTIVE for theme hand-off).
 *  2. Load includes.
 *  3. Initialize subsystems on `muplugins_loaded`.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Platform Constants ──────────────────────────────────────────────
define( 'EXAMPLEPRESS_MU_VERSION', '1.0.1' );
define( 'EXAMPLEPRESS_MU_DIR', __DIR__ );
define( 'EXAMPLEPRESS_MU_FILE', __DIR__ . '/bootstrap.php' );

// Theme hand-off signal and shared admin menu slug
define( 'EP_MU_ACTIVE', true );
define( 'EP_ADMIN_MENU_SLUG', 'examplepress' );

// ─── Includes ────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-fse-guard.php';
require_once __DIR__ . '/includes/class-app-validator.php';
require_once __DIR__ . '/includes/class-platform-policy.php';
require_once __DIR__ . '/includes/api-apps.php';

// ─── Boot Subsystems ─────────────────────────────────────────────────
// FSE guards must register as early as possible so no plugin can unhook them.
ExamplePress_MU_FSE_Guard::init();

// App validator filters the active plugins list before WordPress loads them.
ExamplePress_MU_App_Validator::init();

// Apply fleet-wide platform policies
ExamplePress_MU_Platform_Policy::init();

// The updater hooks into admin_init (dashboard requests only).
ExamplePress_MU_Updater::init();

// ─── Admin Enhancements ──────────────────────────────────────────────
if ( is_admin() ) {
    require_once __DIR__ . '/admin/plugins-view.php';
    require_once __DIR__ . '/admin/admin-registry.php';
}
