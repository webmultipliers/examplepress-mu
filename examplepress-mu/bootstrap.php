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


// Theme hand-off signal and shared admin menu slug
define( 'EP_MU_ACTIVE', true );
define( 'EP_ADMIN_MENU_SLUG', 'examplepress' );

// ─── Includes — Security & Policy ────────────────────────────────────
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-fse-guard.php';
require_once __DIR__ . '/includes/class-app-validator.php';
require_once __DIR__ . '/includes/class-platform-policy.php';
require_once __DIR__ . '/includes/class-admin-policy.php';
require_once __DIR__ . '/includes/class-editor-policy.php';

// ─── Includes — Platform Infrastructure ─────────────────────────────
require_once __DIR__ . '/includes/class-helpers.php';
require_once __DIR__ . '/includes/class-app-discovery.php';
require_once __DIR__ . '/includes/class-app-registry.php';
require_once __DIR__ . '/includes/class-dependencies.php';
require_once __DIR__ . '/includes/class-github.php';
require_once __DIR__ . '/includes/class-scaffolder.php';
require_once __DIR__ . '/includes/class-notifications.php';
require_once __DIR__ . '/includes/class-plugin-manager.php';
require_once __DIR__ . '/includes/class-cli.php';

// ─── Includes — REST API ────────────────────────────────────────────
require_once __DIR__ . '/includes/api-apps.php';
require_once __DIR__ . '/api/apps.php';
require_once __DIR__ . '/api/connections.php';
require_once __DIR__ . '/api/demo.php';
require_once __DIR__ . '/api/updater.php';
require_once __DIR__ . '/api/filesystem.php';

// ─── Boot Subsystems ─────────────────────────────────────────────────
// FSE guards must register as early as possible so no plugin can unhook them.
ExamplePress_MU_FSE_Guard::init();

// App validator filters the active plugins list before WordPress loads them.
ExamplePress_MU_App_Validator::init();

// Apply fleet-wide platform policies
ExamplePress_MU_Platform_Policy::init();

// Admin policies: dashboard cleanup, post-lock window
ExamplePress_MU_Admin_Policy::init();

// Editor policies: block patterns, block types, Openverse
ExamplePress_MU_Editor_Policy::init();

// The updater hooks into admin_init (dashboard requests only).
ExamplePress_MU_Updater::init();

// ─── Admin Enhancements ──────────────────────────────────────────────
if ( is_admin() ) {
    require_once __DIR__ . '/admin/plugins-view.php';
    require_once __DIR__ . '/admin/admin-registry.php';
}
