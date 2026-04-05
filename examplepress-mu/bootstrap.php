<?php
/**
 * ExamplePress MU — Platform Kernel Bootstrap
 *
 * This is the main entry point for the ExamplePress platform.
 * Called by the thin loader (examplepress-mu.php) in the mu-plugins root.
 *
 * Execution order:
 *  1. Define constants.
 *  2. Load all subsystems.
 *  3. Initialize governance classes.
 *  4. Schedule feature boot for after_setup_theme.
 *  5. Load admin layer (admin requests only).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Platform Constants ──────────────────────────────────────────────
define( 'EXAMPLEPRESS_MU_VERSION', '1.0.1' );
define( 'EXAMPLEPRESS_MU_DIR', __DIR__ );
define( 'EXAMPLEPRESS_MU_URI', plugins_url( '', __FILE__ ) );

define( 'EP_MU_ACTIVE', true );
define( 'EP_ADMIN_MENU_SLUG', 'examplepress' );

// Bridge: theme directory for reading theme-owned files
// (examplepress.json design tokens, theme.json, blockstudio.json)
if ( ! defined( 'EP_THEME_PATH' ) ) {
    define( 'EP_THEME_PATH', get_template_directory() );
}

// ─── Governance (non-toggleable) ────────────────────────────────────
require_once __DIR__ . '/inc/class-updater.php';
require_once __DIR__ . '/inc/class-fse-guard.php';
require_once __DIR__ . '/inc/class-app-validator.php';
require_once __DIR__ . '/inc/class-platform-policy.php';

// ─── Infrastructure ─────────────────────────────────────────────────
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/app-discovery.php';
require_once __DIR__ . '/inc/app-registry.php';
require_once __DIR__ . '/inc/dependencies.php';
require_once __DIR__ . '/inc/github.php';
require_once __DIR__ . '/inc/scaffolder.php';
require_once __DIR__ . '/inc/notifications.php';
require_once __DIR__ . '/inc/plugin-manager.php';
require_once __DIR__ . '/inc/class-cli.php';

// ─── REST API ───────────────────────────────────────────────────────
require_once __DIR__ . '/api/agent.php';
require_once __DIR__ . '/api/apps.php';
require_once __DIR__ . '/api/connections.php';
require_once __DIR__ . '/api/demo.php';
require_once __DIR__ . '/api/updater.php';
require_once __DIR__ . '/api/filesystem.php';

// ─── Config, Features & Routing ─────────────────────────────────────
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/feature-registry.php';
require_once __DIR__ . '/inc/features.php';
require_once __DIR__ . '/inc/route-registry.php';
require_once __DIR__ . '/inc/router.php';

// ─── Boot ───────────────────────────────────────────────────────────
ExamplePress_MU_FSE_Guard::init();
ExamplePress_MU_App_Validator::init();
ExamplePress_MU_Platform_Policy::init();
ExamplePress_MU_Updater::init();

add_action( 'after_setup_theme', 'examplepress_boot_features' );

// ─── Admin ──────────────────────────────────────────────────────────
if ( is_admin() ) {
    require_once __DIR__ . '/inc/admin/plugins-view.php';
    require_once __DIR__ . '/inc/admin/admin-assets.php';
    require_once __DIR__ . '/inc/admin/settings-data.php';
    require_once __DIR__ . '/inc/admin/pages/shared.php';
    require_once __DIR__ . '/inc/admin/pages/apps.php';
    require_once __DIR__ . '/inc/admin/pages/theme.php';
    require_once __DIR__ . '/inc/admin/pages/navigation.php';
    require_once __DIR__ . '/inc/admin/pages/dependencies.php';
    require_once __DIR__ . '/inc/admin/pages/library.php';
    require_once __DIR__ . '/inc/admin/pages/settings.php';
    require_once __DIR__ . '/inc/admin/pages/notifications.php';
    require_once __DIR__ . '/inc/admin/pages/system.php';
    require_once __DIR__ . '/inc/admin/pages/docs.php';
    require_once __DIR__ . '/inc/admin/pages/editor.php';
    require_once __DIR__ . '/inc/admin/admin-registry.php';
}
