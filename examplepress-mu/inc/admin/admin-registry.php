<?php
/**
 * ExamplePress Admin Page Registry
 *
 * Declarative registry for admin submenus — same pattern as the feature
 * registry and route origin registry. The theme registers its own pages,
 * companion plugins inject additional submenus via the
 * `examplepress_register_admin_pages` action.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parent menu slug. All submenus are children of this slug.
 */
if ( ! defined( 'EP_ADMIN_MENU_SLUG' ) ) {
	define( 'EP_ADMIN_MENU_SLUG', 'examplepress' );
}

/**
 * Global page store.
 *
 * @var array<string, array>
 */
$examplepress_admin_pages = [];

// ── Public API ──────────────────────────────────────────────────

/**
 * Register an admin page.
 *
 * @param string $id   Unique page identifier.
 * @param array  $args {
 *     @type string   $page_title  Browser tab title (required).
 *     @type string   $menu_title  Sidebar label (required).
 *     @type string   $capability  Required capability. Default 'manage_options'.
 *     @type int      $position    Sort order. Lower = higher. Default 50.
 *     @type callable $render      Render callback (required).
 *     @type callable $enqueue     Fires on admin_enqueue_scripts when page is active.
 *     @type bool     $hidden      If true, page is accessible but not in sidebar.
 * }
 */
function examplepress_register_admin_page( string $id, array $args ): void {
	global $examplepress_admin_pages;

	if ( isset( $examplepress_admin_pages[ $id ] ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf( 'Admin page "%s" is already registered.', esc_html( $id ) ),
			EXAMPLEPRESS_MU_VERSION
		);
		return;
	}

	$examplepress_admin_pages[ $id ] = wp_parse_args( $args, [
		'page_title' => '',
		'menu_title' => '',
		'capability' => 'manage_options',
		'position'   => 50,
		'render'     => '__return_empty_string',
		'enqueue'    => null,
		'hidden'     => false,
	] );
}

/**
 * Get all registered pages sorted by position.
 *
 * @return array<string, array>
 */
function examplepress_get_admin_pages(): array {
	global $examplepress_admin_pages;

	$pages = $examplepress_admin_pages;

	/**
	 * Filter the admin page registry before menu building.
	 *
	 * @param array $pages Registered pages keyed by ID.
	 */
	$pages = apply_filters( 'examplepress_admin_pages', $pages );

	uasort( $pages, fn( $a, $b ) => ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 ) );

	return $pages;
}

/**
 * Get the WordPress menu slug for a page ID.
 *
 * The first page (lowest position) shares the parent slug to avoid
 * WordPress's duplicate first-submenu behaviour.
 *
 * @param string $id Page ID.
 * @return string Menu slug.
 */
function examplepress_admin_page_slug( string $id ): string {
	$pages = examplepress_get_admin_pages();
	$first = array_key_first( $pages );

	if ( $id === $first ) {
		return EP_ADMIN_MENU_SLUG;
	}

	return EP_ADMIN_MENU_SLUG . '-' . $id;
}

/**
 * Get the full admin URL for a page.
 *
 * @param string $id   Page ID.
 * @param array  $args Optional query args.
 * @return string Admin URL.
 */
function examplepress_admin_page_url( string $id, array $args = [] ): string {
	$slug = examplepress_admin_page_slug( $id );
	$url  = admin_url( 'admin.php?page=' . $slug );

	if ( $args ) {
		$url = add_query_arg( $args, $url );
	}

	return $url;
}

// ── Menu Builder ────────────────────────────────────────────────

add_action( 'admin_menu', 'examplepress_build_admin_menu' );

function examplepress_build_admin_menu(): void {
	// 1. Register the theme's own pages.
	examplepress_register_core_admin_pages();

	// 2. Let companion plugins add their pages.
	do_action( 'examplepress_register_admin_pages' );

	$pages = examplepress_get_admin_pages();
	if ( empty( $pages ) ) {
		return;
	}

	// 3. Create the top-level menu (the kernel owns the parent page).
	add_menu_page(
		__( 'ExamplePress', 'examplepress-mu' ),
		'ExamplePress',
		'manage_options',
		EP_ADMIN_MENU_SLUG,
		'__return_empty_string',
		'dashicons-layout',
		55
	);

	// 4. Register all pages as submenus attaching to the parent slug.
	foreach ( $pages as $id => $page ) {
		$menu_slug = examplepress_admin_page_slug( $id );

		// Always attach to the parent 'examplepress' slug.
		$parent = $page['hidden'] ? null : EP_ADMIN_MENU_SLUG;

		$hook = add_submenu_page(
			$parent,
			$page['page_title'],
			$page['menu_title'],
			$page['capability'],
			$menu_slug,
			$page['render'],
			$page['position']
		);

		if ( $page['enqueue'] ) {
			$enqueue_cb = $page['enqueue'];
			add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) use ( $hook, $enqueue_cb ) {
				if ( $hook_suffix === $hook ) {
					$enqueue_cb();
				}
			} );
		}
	}
}

// ── Core Page Registration ──────────────────────────────────────

function examplepress_register_core_admin_pages(): void {
	examplepress_register_admin_page( 'apps', [
		'page_title' => __( 'ExamplePress — Apps', 'examplepress-mu' ),
		'menu_title' => __( 'Apps', 'examplepress-mu' ),
		'position'   => 0,
		'render'     => 'examplepress_render_apps_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'apps' );
			wp_add_inline_script(
				'ep-apps',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_apps_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'theme', [
		'page_title' => __( 'ExamplePress — Theme', 'examplepress-mu' ),
		'menu_title' => __( 'Theme', 'examplepress-mu' ),
		'position'   => 10,
		'render'     => 'examplepress_render_theme_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'theme' );
			wp_add_inline_script(
				'ep-theme',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_theme_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'navigation', [
		'page_title' => __( 'ExamplePress — Navigation', 'examplepress-mu' ),
		'menu_title' => __( 'Navigation', 'examplepress-mu' ),
		'position'   => 20,
		'render'     => 'examplepress_render_navigation_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'navigation' );
			wp_add_inline_script(
				'ep-navigation',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_navigation_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'dependencies', [
		'page_title' => __( 'ExamplePress — Dependencies', 'examplepress-mu' ),
		'menu_title' => __( 'Dependencies', 'examplepress-mu' ),
		'position'   => 30,
		'render'     => 'examplepress_render_dependencies_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'dependencies' );
			wp_add_inline_script(
				'ep-dependencies',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_dependencies_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'library', [
		'page_title' => __( 'ExamplePress — Library', 'examplepress-mu' ),
		'menu_title' => __( 'Library', 'examplepress-mu' ),
		'position'   => 40,
		'render'     => 'examplepress_render_library_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'library' );
			wp_add_inline_script(
				'ep-library',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_library_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'settings', [
		'page_title' => __( 'ExamplePress — Settings', 'examplepress-mu' ),
		'menu_title' => __( 'Settings', 'examplepress-mu' ),
		'position'   => 50,
		'render'     => 'examplepress_render_settings_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'settings' );
			wp_add_inline_script(
				'ep-settings',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_settings_page_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'notifications', [
		'page_title' => __( 'ExamplePress — Notifications', 'examplepress-mu' ),
		'menu_title' => __( 'Notifications', 'examplepress-mu' ),
		'position'   => 55,
		'render'     => 'examplepress_render_notifications_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'notifications' );
			wp_add_inline_script(
				'ep-notifications',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_notifications_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'system', [
		'page_title' => __( 'ExamplePress — System', 'examplepress-mu' ),
		'menu_title' => __( 'System', 'examplepress-mu' ),
		'position'   => 60,
		'render'     => 'examplepress_render_system_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'system' );
			wp_add_inline_script(
				'ep-system',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_system_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'docs', [
		'page_title' => __( 'ExamplePress — Docs', 'examplepress-mu' ),
		'menu_title' => __( 'Docs', 'examplepress-mu' ),
		'position'   => 70,
		'render'     => 'examplepress_render_docs_page',
		'enqueue'    => function () {
			examplepress_vite_enqueue( 'docs' );
			wp_add_inline_script(
				'ep-docs',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_docs_data() ) . ';',
				'before'
			);
		},
	] );

	examplepress_register_admin_page( 'editor', [
		'page_title' => __( 'ExamplePress — Editor', 'examplepress-mu' ),
		'menu_title' => __( 'Editor', 'examplepress-mu' ),
		'position'   => 999,
		'hidden'     => true,
		'render'     => 'examplepress_render_editor_page',
		'enqueue'    => function () {
			$slug = sanitize_title( $_GET['app'] ?? '' );
			examplepress_vite_enqueue( 'editor' );
			wp_add_inline_script(
				'ep-editor',
				'window.ExamplePressData = ' . wp_json_encode( examplepress_editor_data( $slug ) ) . ';',
				'before'
			);
		},
	] );
}
