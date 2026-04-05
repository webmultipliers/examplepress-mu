<?php
/**
 * ExamplePress REST API — Demo Companion Plugin Endpoints
 *
 * Handles installing, activating, updating, and removing the demo
 * companion plugin (examplepress-demo) from GitHub.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'examplepress_register_demo_routes' );

function examplepress_register_demo_routes(): void {
	register_rest_route( 'examplepress/v1', '/demo/install', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_demo_install',
		'permission_callback' => function () {
			return current_user_can( 'install_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/demo/uninstall', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_demo_uninstall',
		'permission_callback' => function () {
			return current_user_can( 'delete_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/demo/check', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_demo_check',
		'permission_callback' => function () {
			return current_user_can( 'update_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/demo/settings', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_handle_demo_settings_get',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/demo/settings', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_demo_settings_save',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => [
			'channel' => [
				'type'              => 'string',
				'enum'              => [ 'stable', 'prerelease' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'pinned_version' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		],
	] );

	register_rest_route( 'examplepress/v1', '/demo/update', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_demo_update',
		'permission_callback' => function () {
			return current_user_can( 'update_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/demo/releases', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_handle_demo_releases',
		'permission_callback' => function () {
			return current_user_can( 'update_plugins' );
		},
	] );
}

// =====================================================================
//  STATUS & VERSION
// =====================================================================

/**
 * Get the installed version of the demo plugin.
 *
 * @return string|null  Version string, or null if not installed.
 */
function examplepress_get_demo_plugin_version(): ?string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins = get_plugins();
	return $plugins[ EP_DEMO_PLUGIN_FILE ]['Version'] ?? null;
}

/**
 * Get the current demo plugin status.
 *
 * @return string 'not-installed' | 'installed' | 'active' | 'foreign'
 */
function examplepress_get_demo_status(): string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$dest = WP_PLUGIN_DIR . '/examplepress-demo';

	if ( ! is_dir( $dest ) ) {
		return 'not-installed';
	}

	if ( ! examplepress_is_demo_plugin( $dest ) ) {
		return 'foreign';
	}

	if ( is_plugin_active( EP_DEMO_PLUGIN_FILE ) ) {
		return 'active';
	}

	return 'installed';
}

/**
 * Check if a plugin directory contains the ExamplePress Demo marker header.
 */
function examplepress_is_demo_plugin( string $plugin_dir ): bool {
	$main_file = $plugin_dir . '/examplepress-demo.php';
	if ( ! file_exists( $main_file ) ) {
		return false;
	}
	$header = get_file_data( $main_file, [ 'demo' => 'ExamplePress Demo' ] );
	return ! empty( $header['demo'] ) && strtolower( $header['demo'] ) === 'true';
}

// =====================================================================
//  INSTALL / UNINSTALL
// =====================================================================

/**
 * Install the demo companion plugin from GitHub.
 */
function examplepress_handle_demo_install( WP_REST_Request $request ) {
	$status = examplepress_get_demo_status();

	if ( 'active' === $status ) {
		return rest_ensure_response( [
			'success' => true,
			'status'  => 'active',
			'message' => 'Demo plugin is already installed and active.',
		] );
	}

	if ( 'foreign' === $status ) {
		return new WP_Error(
			'demo_conflict',
			'A plugin named examplepress-demo already exists but is not the ExamplePress demo. Remove it manually first.',
			[ 'status' => 409 ]
		);
	}

	if ( 'installed' === $status ) {
		$result = activate_plugin( EP_DEMO_PLUGIN_FILE );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'status'  => 'installed',
				'message' => 'Demo plugin is installed but could not be activated: ' . $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'status'  => 'active',
			'message' => 'Demo plugin activated.',
		] );
	}

	// Not installed — download from GitHub.
	$installed = examplepress_install_demo_from_github();

	if ( is_wp_error( $installed ) ) {
		return new WP_Error(
			'demo_install_failed',
			'Failed to install demo plugin: ' . $installed->get_error_message(),
			[ 'status' => 500 ]
		);
	}

	$result = activate_plugin( EP_DEMO_PLUGIN_FILE );
	$final  = is_wp_error( $result ) ? 'installed' : 'active';

	delete_transient( EP_DEMO_THROTTLE_KEY );

	return rest_ensure_response( [
		'success' => true,
		'status'  => $final,
		'message' => 'active' === $final
			? 'Demo plugin installed and activated.'
			: 'Demo plugin installed but could not be activated: ' . $result->get_error_message(),
	] );
}

/**
 * Uninstall the demo companion plugin.
 */
function examplepress_handle_demo_uninstall( WP_REST_Request $request ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_dir = WP_PLUGIN_DIR . '/examplepress-demo';

	if ( ! is_dir( $plugin_dir ) ) {
		return rest_ensure_response( [
			'success' => true,
			'status'  => 'not-installed',
			'message' => 'Demo plugin is not installed.',
		] );
	}

	if ( ! examplepress_is_demo_plugin( $plugin_dir ) ) {
		return new WP_Error(
			'demo_conflict',
			'The examplepress-demo plugin is not the ExamplePress demo. Refusing to delete.',
			[ 'status' => 409 ]
		);
	}

	if ( is_plugin_active( EP_DEMO_PLUGIN_FILE ) ) {
		deactivate_plugins( EP_DEMO_PLUGIN_FILE, true );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( ! $wp_filesystem instanceof WP_Filesystem_Base || ! $wp_filesystem->delete( $plugin_dir, true ) ) {
		return new WP_Error(
			'demo_delete_failed',
			'Failed to remove the demo plugin directory.',
			[ 'status' => 500 ]
		);
	}

	return rest_ensure_response( [
		'success' => true,
		'status'  => 'not-installed',
		'message' => 'Demo plugin removed.',
	] );
}

// =====================================================================
//  UPDATE CHECK
// =====================================================================

/**
 * Check for a newer version of the demo plugin.
 *
 * Respects the saved channel and pin settings.
 */
function examplepress_handle_demo_check( WP_REST_Request $request ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins         = get_plugins();
	$current_version = $plugins[ EP_DEMO_PLUGIN_FILE ]['Version'] ?? null;
	$settings        = examplepress_get_demo_settings();
	$target          = examplepress_resolve_demo_target( $settings );

	$available = null;
	if ( $target && $current_version ) {
		$dominated = ! empty( $settings['pinned_version'] )
			? $target['version'] !== $current_version
			: version_compare( $target['version'], $current_version, '>' );

		if ( $dominated ) {
			$available = $target;
		}
	}

	return rest_ensure_response( [
		'success'         => true,
		'current_version' => $current_version,
		'target'          => $target,
		'available'       => $available,
		'settings'        => $settings,
		'checked_at'      => current_time( 'c' ),
	] );
}

/**
 * Resolve the target version for the demo plugin based on settings.
 */
function examplepress_resolve_demo_target( array $settings ): ?array {
	$releases = examplepress_fetch_demo_releases();
	if ( ! $releases ) {
		return null;
	}

	$pinned  = $settings['pinned_version'] ?? '';
	$channel = $settings['channel'] ?? 'stable';

	foreach ( $releases as $r ) {
		$version = ltrim( $r['tag_name'] ?? '', 'v' );

		if ( $pinned && $version === $pinned ) {
			return examplepress_format_demo_release_target( $r, $version );
		}

		if ( ! $pinned ) {
			$is_prerelease = ! empty( $r['prerelease'] );

			if ( 'stable' === $channel && $is_prerelease ) {
				continue;
			}

			return examplepress_format_demo_release_target( $r, $version );
		}
	}

	return null;
}

/**
 * Format a GitHub release into a target payload.
 */
function examplepress_format_demo_release_target( array $release, string $version ): array {
	$has_package = false;
	foreach ( $release['assets'] ?? [] as $asset ) {
		if ( ( $asset['name'] ?? '' ) === 'examplepress-demo.zip' ) {
			$has_package = true;
			break;
		}
	}

	return [
		'version'    => $version,
		'url'        => $release['html_url'] ?? '',
		'package'    => $has_package,
		'prerelease' => ! empty( $release['prerelease'] ),
	];
}

/**
 * Fetch recent releases from the demo plugin's GitHub repo.
 *
 * @return array|null
 */
function examplepress_fetch_demo_releases(): ?array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$headers = [
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
	];

	$token = function_exists( 'examplepress_updater_bootstrap_get_token' )
		? examplepress_updater_bootstrap_get_token()
		: null;
	if ( $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	$response = wp_remote_get(
		sprintf( 'https://api.github.com/repos/%s/releases?per_page=30', EP_DEMO_GITHUB_REPO ),
		[ 'timeout' => 10, 'headers' => $headers ]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		$cache = [];
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		$cache = [];
		return null;
	}

	$cache = array_values( array_filter( $data, fn( $r ) => empty( $r['draft'] ) ) );
	return $cache;
}

// =====================================================================
//  UPDATE  (download and replace the demo plugin)
// =====================================================================

/**
 * Update the demo plugin to the target version.
 */
function examplepress_handle_demo_update( WP_REST_Request $request ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$settings = examplepress_get_demo_settings();
	$target   = examplepress_resolve_demo_target( $settings );

	if ( ! $target ) {
		return new WP_Error(
			'no_target',
			'Could not resolve a target version from your channel/pin settings.',
			[ 'status' => 400 ]
		);
	}

	$download_url = examplepress_get_demo_release_download_url( $target['version'] );

	if ( ! $download_url ) {
		return new WP_Error(
			'no_package',
			'No downloadable zip found for version ' . $target['version'] . '.',
			[ 'status' => 404 ]
		);
	}

	$plugin_file = EP_DEMO_PLUGIN_FILE;
	$plugin_dir  = WP_PLUGIN_DIR . '/examplepress-demo';
	$was_active  = is_plugin_active( $plugin_file );

	if ( $was_active ) {
		deactivate_plugins( $plugin_file, true );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( is_dir( $plugin_dir ) && $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $plugin_dir, true );
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$rename_filter = function ( string $source, string $remote_source ) {
		$expected = trailingslashit( $remote_source ) . 'examplepress-demo/';
		if ( $source === $expected ) {
			return $source;
		}
		$basename = basename( untrailingslashit( $source ) );
		if ( str_starts_with( $basename, 'examplepress-demo' ) && $basename !== 'examplepress-demo' ) {
			global $wp_filesystem;
			if ( $wp_filesystem->move( $source, $expected, true ) ) {
				return $expected;
			}
		}
		return $source;
	};

	add_filter( 'upgrader_source_selection', $rename_filter, 10, 2 );

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( $download_url );

	remove_filter( 'upgrader_source_selection', $rename_filter, 10 );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'install_failed', $result->get_error_message(), [ 'status' => 500 ] );
	}
	if ( is_wp_error( $skin->result ) ) {
		return new WP_Error( 'install_failed', $skin->result->get_error_message(), [ 'status' => 500 ] );
	}
	if ( ! $result ) {
		return new WP_Error( 'install_failed', 'Installer returned an unexpected result.', [ 'status' => 500 ] );
	}

	if ( $was_active ) {
		activate_plugin( $plugin_file );
	}

	wp_cache_delete( 'plugins', 'plugins' );
	$plugins     = get_plugins();
	$new_version = $plugins[ $plugin_file ]['Version'] ?? null;

	return rest_ensure_response( [
		'success'     => true,
		'status'      => is_plugin_active( $plugin_file ) ? 'active' : 'installed',
		'old_version' => $request->get_param( 'from_version' ),
		'new_version' => $new_version,
		'message'     => 'Updated to ' . ( $new_version ?: $target['version'] ) . '.',
	] );
}

/**
 * Get the download URL for a specific demo release version.
 */
function examplepress_get_demo_release_download_url( string $version ): ?string {
	$releases = examplepress_fetch_demo_releases();
	if ( ! $releases ) {
		return null;
	}

	foreach ( $releases as $r ) {
		$tag = ltrim( $r['tag_name'] ?? '', 'v' );
		if ( $tag !== $version ) {
			continue;
		}

		foreach ( $r['assets'] ?? [] as $asset ) {
			if ( ( $asset['name'] ?? '' ) === 'examplepress-demo.zip' ) {
				return $asset['browser_download_url'];
			}
		}

		return $r['zipball_url'] ?? null;
	}

	return null;
}

// =====================================================================
//  SETTINGS
// =====================================================================

/**
 * Get demo plugin settings.
 */
function examplepress_handle_demo_settings_get( WP_REST_Request $request ) {
	return rest_ensure_response( examplepress_get_demo_settings() );
}

/**
 * Save demo plugin settings.
 */
function examplepress_handle_demo_settings_save( WP_REST_Request $request ) {
	$channel = $request->get_param( 'channel' );
	$pinned  = $request->get_param( 'pinned_version' );

	if ( null !== $channel ) {
		update_option( 'ep_demo_plugin_channel', $channel );
	}

	if ( null !== $pinned ) {
		if ( '' === $pinned ) {
			delete_option( 'ep_demo_plugin_pinned' );
		} else {
			update_option( 'ep_demo_plugin_pinned', $pinned );
		}
	}

	delete_site_transient( 'update_plugins' );

	return rest_ensure_response( [
		'success'  => true,
		'settings' => examplepress_get_demo_settings(),
		'message'  => 'Settings saved.',
	] );
}

/**
 * Read the current demo plugin settings from wp_options.
 */
function examplepress_get_demo_settings(): array {
	return [
		'channel'        => get_option( 'ep_demo_plugin_channel', 'stable' ),
		'pinned_version' => get_option( 'ep_demo_plugin_pinned', '' ),
	];
}

// =====================================================================
//  RELEASES LIST
// =====================================================================

/**
 * Fetch available releases of the demo plugin from GitHub.
 */
function examplepress_handle_demo_releases( WP_REST_Request $request ) {
	$releases = examplepress_fetch_demo_releases();

	if ( null === $releases ) {
		return new WP_Error( 'github_error', 'Failed to fetch releases from GitHub.', [ 'status' => 502 ] );
	}

	$list = [];
	foreach ( $releases as $r ) {
		$tag = ltrim( $r['tag_name'] ?? '', 'v' );
		$list[] = [
			'tag'        => $r['tag_name'],
			'version'    => $tag,
			'name'       => $r['name'] ?: $r['tag_name'],
			'prerelease' => ! empty( $r['prerelease'] ),
			'date'       => $r['published_at'] ?? '',
		];
	}

	return rest_ensure_response( [
		'success'  => true,
		'releases' => $list,
	] );
}
