<?php
/**
 * ExamplePress REST API — Updater Plugin Endpoints
 *
 * Handles installing, activating, and removing the companion
 * updater plugin (examplepress-theme-update) from GitHub.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'examplepress_register_updater_routes' );

function examplepress_register_updater_routes(): void {
	register_rest_route( 'examplepress/v1', '/updater/install', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_updater_install',
		'permission_callback' => function () {
			return current_user_can( 'install_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/updater/uninstall', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_updater_uninstall',
		'permission_callback' => function () {
			return current_user_can( 'delete_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/updater/check', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_updater_check',
		'permission_callback' => function () {
			return current_user_can( 'update_themes' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/updater/settings', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_handle_updater_settings_get',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/updater/settings', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_updater_settings_save',
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

	register_rest_route( 'examplepress/v1', '/updater/update', [
		'methods'             => 'POST',
		'callback'            => 'examplepress_handle_updater_update',
		'permission_callback' => function () {
			return current_user_can( 'update_plugins' );
		},
	] );

	register_rest_route( 'examplepress/v1', '/updater/releases', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_handle_updater_releases',
		'permission_callback' => function () {
			return current_user_can( 'update_themes' );
		},
	] );
}

/**
 * Get the installed version of the updater plugin.
 *
 * @return string|null  Version string, or null if not installed.
 */
function examplepress_get_updater_plugin_version(): ?string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins = get_plugins();
	return $plugins[ EP_UPDATER_PLUGIN_FILE ]['Version'] ?? null;
}

/**
 * Get the current updater plugin status.
 *
 * @return string 'not-installed' | 'installed' | 'active'
 */
function examplepress_get_updater_status(): string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_file = EP_UPDATER_PLUGIN_FILE;

	if ( is_plugin_active( $plugin_file ) ) {
		return 'active';
	}

	if ( array_key_exists( $plugin_file, get_plugins() ) ) {
		return 'installed';
	}

	return 'not-installed';
}

/**
 * Install the updater companion plugin from GitHub.
 */
function examplepress_handle_updater_install( WP_REST_Request $request ) {
	$status = examplepress_get_updater_status();

	// Already installed — just activate if needed.
	if ( 'active' === $status ) {
		return rest_ensure_response( [
			'success' => true,
			'status'  => 'active',
			'message' => 'Updater plugin is already installed and active.',
		] );
	}

	if ( 'installed' === $status ) {
		$result = activate_plugin( EP_UPDATER_PLUGIN_FILE );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => false,
				'status'  => 'installed',
				'message' => 'Updater plugin is installed but could not be activated: ' . $result->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'status'  => 'active',
			'message' => 'Updater plugin activated.',
		] );
	}

	// Not installed — download from GitHub.
	$installed = examplepress_install_updater_from_github();

	if ( is_wp_error( $installed ) ) {
		return new WP_Error(
			'updater_install_failed',
			'Failed to install updater plugin: ' . $installed->get_error_message(),
			[ 'status' => 500 ]
		);
	}

	$result = activate_plugin( EP_UPDATER_PLUGIN_FILE );
	$final  = is_wp_error( $result ) ? 'installed' : 'active';

	// Clear the throttle transient on success.
	delete_transient( EP_UPDATER_THROTTLE_KEY );

	return rest_ensure_response( [
		'success' => true,
		'status'  => $final,
		'message' => 'active' === $final
			? 'Updater plugin installed and activated.'
			: 'Updater plugin installed but could not be activated: ' . $result->get_error_message(),
	] );
}

/**
 * Uninstall the updater companion plugin.
 */
function examplepress_handle_updater_uninstall( WP_REST_Request $request ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_file = EP_UPDATER_PLUGIN_FILE;
	$plugin_dir  = WP_PLUGIN_DIR . '/examplepress-theme-update';

	if ( ! is_dir( $plugin_dir ) ) {
		return rest_ensure_response( [
			'success' => true,
			'status'  => 'not-installed',
			'message' => 'Updater plugin is not installed.',
		] );
	}

	// Deactivate if active.
	if ( is_plugin_active( $plugin_file ) ) {
		deactivate_plugins( $plugin_file, true );
	}

	// Delete the directory.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( ! $wp_filesystem instanceof WP_Filesystem_Base || ! $wp_filesystem->delete( $plugin_dir, true ) ) {
		return new WP_Error(
			'updater_delete_failed',
			'Failed to remove the updater plugin directory.',
			[ 'status' => 500 ]
		);
	}

	return rest_ensure_response( [
		'success' => true,
		'status'  => 'not-installed',
		'message' => 'Updater plugin removed.',
	] );
}

// =====================================================================
//  UPDATE CHECK  (for the updater plugin, not the theme)
// =====================================================================

/**
 * Check for a newer version of the examplepress-theme-update plugin.
 *
 * Respects the saved channel and pin settings:
 *   - channel "stable"     → only non-prerelease GitHub releases
 *   - channel "prerelease" → any non-draft release (prerelease or stable)
 *   - pinned_version       → that exact version is the target, regardless
 *                            of whether something newer exists
 *
 * Compares the target against the installed plugin version and returns
 * whether an update (or rollback to a pin) is available.
 */
function examplepress_handle_updater_check( WP_REST_Request $request ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins         = get_plugins();
	$current_version = $plugins[ EP_UPDATER_PLUGIN_FILE ]['Version'] ?? null;
	$settings        = examplepress_get_updater_settings();
	$target          = examplepress_resolve_updater_target( $settings );

	$available = null;
	if ( $target && $current_version ) {
		// If pinned, the target is exact — show it if it differs from installed.
		// If unpinned, show it only if it's newer.
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
 * Resolve the target version for the updater plugin based on settings.
 *
 * If a pin is set, finds that exact release. Otherwise walks the
 * releases list and returns the first that qualifies for the channel.
 *
 * @param  array $settings  From examplepress_get_updater_settings().
 * @return array{version: string, url: string, package: bool, prerelease: bool}|null
 */
function examplepress_resolve_updater_target( array $settings ): ?array {
	$releases = examplepress_fetch_updater_releases();
	if ( ! $releases ) {
		return null;
	}

	$pinned  = $settings['pinned_version'] ?? '';
	$channel = $settings['channel'] ?? 'stable';

	foreach ( $releases as $r ) {
		$version = ltrim( $r['tag_name'] ?? '', 'v' );

		// If pinned, match exactly.
		if ( $pinned && $version === $pinned ) {
			return examplepress_format_release_target( $r, $version );
		}

		// If not pinned, apply channel filter.
		if ( ! $pinned ) {
			$is_prerelease = ! empty( $r['prerelease'] );

			if ( 'stable' === $channel && $is_prerelease ) {
				continue;
			}

			// First qualifying release wins (list is newest-first).
			return examplepress_format_release_target( $r, $version );
		}
	}

	return null;
}

/**
 * Format a GitHub release into a target payload.
 */
function examplepress_format_release_target( array $release, string $version ): array {
	$has_package = false;
	foreach ( $release['assets'] ?? [] as $asset ) {
		if ( ( $asset['name'] ?? '' ) === 'examplepress-theme-update.zip' ) {
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
 * Fetch recent releases from the updater plugin's GitHub repo.
 *
 * Returns raw GitHub release objects (non-draft only), cached for
 * the duration of the request.
 *
 * @return array|null
 */
function examplepress_fetch_updater_releases(): ?array {
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
		sprintf( 'https://api.github.com/repos/%s/releases?per_page=30', EP_UPDATER_GITHUB_REPO ),
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

	// Filter out drafts.
	$cache = array_values( array_filter( $data, fn( $r ) => empty( $r['draft'] ) ) );
	return $cache;
}

// =====================================================================
//  UPDATE  (download and replace the updater plugin)
// =====================================================================

/**
 * Update the updater plugin to the target version resolved from
 * channel/pin settings.
 *
 * Deactivates the old plugin, deletes it, downloads the target
 * release from GitHub, installs, and re-activates.
 */
function examplepress_handle_updater_update( WP_REST_Request $request ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$settings = examplepress_get_updater_settings();
	$target   = examplepress_resolve_updater_target( $settings );

	if ( ! $target ) {
		return new WP_Error(
			'no_target',
			'Could not resolve a target version from your channel/pin settings.',
			[ 'status' => 400 ]
		);
	}

	// Find the download URL for this specific release.
	$download_url = examplepress_get_updater_release_download_url( $target['version'] );

	if ( ! $download_url ) {
		return new WP_Error(
			'no_package',
			'No downloadable zip found for version ' . $target['version'] . '.',
			[ 'status' => 404 ]
		);
	}

	$plugin_file = EP_UPDATER_PLUGIN_FILE;
	$plugin_dir  = WP_PLUGIN_DIR . '/examplepress-theme-update';
	$was_active  = is_plugin_active( $plugin_file );

	// Deactivate before replacing files.
	if ( $was_active ) {
		deactivate_plugins( $plugin_file, true );
	}

	// Remove the existing installation.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( is_dir( $plugin_dir ) && $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $plugin_dir, true );
	}

	// Install the target version.
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// Rename filter for GitHub archive zips.
	$rename_filter = function ( string $source, string $remote_source ) {
		$expected = trailingslashit( $remote_source ) . 'examplepress-theme-update/';
		if ( $source === $expected ) {
			return $source;
		}
		$basename = basename( untrailingslashit( $source ) );
		if ( str_starts_with( $basename, 'examplepress-theme-update' ) && $basename !== 'examplepress-theme-update' ) {
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

	// Re-activate.
	if ( $was_active ) {
		activate_plugin( $plugin_file );
	}

	// Read back the new version.
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
 * Get the browser_download_url for a specific release version.
 *
 * Looks for the examplepress-theme-update.zip asset first. Falls
 * back to the zipball_url (GitHub source archive) if no asset.
 *
 * @param  string $version  Version string (without leading 'v').
 * @return string|null
 */
function examplepress_get_updater_release_download_url( string $version ): ?string {
	$releases = examplepress_fetch_updater_releases();
	if ( ! $releases ) {
		return null;
	}

	foreach ( $releases as $r ) {
		$tag = ltrim( $r['tag_name'] ?? '', 'v' );
		if ( $tag !== $version ) {
			continue;
		}

		// Prefer the built zip asset.
		foreach ( $r['assets'] ?? [] as $asset ) {
			if ( ( $asset['name'] ?? '' ) === 'examplepress-theme-update.zip' ) {
				return $asset['browser_download_url'];
			}
		}

		// Fall back to GitHub's source archive.
		return $r['zipball_url'] ?? null;
	}

	return null;
}

// =====================================================================
//  SETTINGS  (channel + pin for the updater plugin)
// =====================================================================

/**
 * Get updater plugin settings.
 */
function examplepress_handle_updater_settings_get( WP_REST_Request $request ) {
	return rest_ensure_response( examplepress_get_updater_settings() );
}

/**
 * Save updater plugin settings.
 *
 * These control which release channel/version the theme uses when
 * auto-installing or updating the updater plugin.
 */
function examplepress_handle_updater_settings_save( WP_REST_Request $request ) {
	$channel = $request->get_param( 'channel' );
	$pinned  = $request->get_param( 'pinned_version' );

	if ( null !== $channel ) {
		update_option( 'ep_updater_plugin_channel', $channel );
	}

	if ( null !== $pinned ) {
		if ( '' === $pinned ) {
			delete_option( 'ep_updater_plugin_pinned' );
		} else {
			update_option( 'ep_updater_plugin_pinned', $pinned );
		}
	}

	// Flush plugin update transient so new settings take effect.
	delete_site_transient( 'update_plugins' );

	return rest_ensure_response( [
		'success'  => true,
		'settings' => examplepress_get_updater_settings(),
		'message'  => 'Settings saved.',
	] );
}

/**
 * Read the current updater plugin settings from wp_options.
 *
 * @return array{channel: string, pinned_version: string}
 */
function examplepress_get_updater_settings(): array {
	return [
		'channel'        => get_option( 'ep_updater_plugin_channel', 'stable' ),
		'pinned_version' => get_option( 'ep_updater_plugin_pinned', '' ),
	];
}

// =====================================================================
//  RELEASES LIST  (for the updater plugin repo)
// =====================================================================

/**
 * Fetch available releases of the updater plugin from GitHub.
 *
 * Returns the most recent non-draft releases with tag, name,
 * prerelease flag, and published date — used to populate the
 * "pin to version" dropdown.
 */
function examplepress_handle_updater_releases( WP_REST_Request $request ) {
	$releases = examplepress_fetch_updater_releases();

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
