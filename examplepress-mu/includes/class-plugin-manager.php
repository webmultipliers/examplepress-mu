<?php
/**
 * ExamplePress MU — Plugin Manager
 *
 * Merged port of the theme's updater-bootstrap.php and demo-bootstrap.php.
 * Provides constants, install/cleanup utilities, ZIP URL resolvers, and a
 * shared token helper for the examplepress-theme-update and
 * examplepress-demo companion plugins.
 *
 * @package ExamplePress_MU
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Constants — Updater plugin
 * ---------------------------------------------------------------------- */

/**
 * The plugin file path relative to WP_PLUGIN_DIR.
 */
const EP_UPDATER_PLUGIN_FILE = 'examplepress-theme-update/examplepress-theme-update.php';

/**
 * GitHub repository for the updater plugin.
 */
const EP_UPDATER_GITHUB_REPO = 'webmultipliers/examplepress-theme-update';

/**
 * Transient key used to throttle install attempts.
 */
const EP_UPDATER_THROTTLE_KEY = 'ep_updater_install_attempted';

/* -------------------------------------------------------------------------
 * Constants — Demo plugin
 * ---------------------------------------------------------------------- */

/**
 * The plugin file path relative to WP_PLUGIN_DIR.
 */
const EP_DEMO_PLUGIN_FILE = 'examplepress-demo/examplepress-demo.php';

/**
 * GitHub repository for the demo plugin.
 */
const EP_DEMO_GITHUB_REPO = 'webmultipliers/examplepress-theme-demo';

/**
 * Transient key used to throttle install attempts.
 */
const EP_DEMO_THROTTLE_KEY = 'ep_demo_install_attempted';

/* -------------------------------------------------------------------------
 * Cleanup hooks
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', 'examplepress_cleanup_stale_updater_dirs' );
add_action( 'admin_init', 'examplepress_cleanup_stale_demo_dirs' );

/* -------------------------------------------------------------------------
 * Cleanup — Updater
 * ---------------------------------------------------------------------- */

/**
 * Remove any stale examplepress-theme-update-* directories.
 *
 * GitHub repo archive zips extract to "reponame-branch/" (e.g.
 * examplepress-theme-update-development/). If one of these exists
 * alongside the canonical directory, WordPress loads both and PHP
 * fatals on class redeclaration.
 */
function examplepress_cleanup_stale_updater_dirs(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
		return;
	}

	$pattern = WP_PLUGIN_DIR . '/examplepress-theme-update-*';
	$stale   = glob( $pattern, GLOB_ONLYDIR );

	if ( empty( $stale ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
		return;
	}

	foreach ( $stale as $dir ) {
		$dirname      = basename( $dir );
		$stale_plugin = $dirname . '/examplepress-theme-update.php';

		if ( is_plugin_active( $stale_plugin ) ) {
			deactivate_plugins( $stale_plugin, true );
		}

		$wp_filesystem->delete( $dir, true );
	}
}

/* -------------------------------------------------------------------------
 * Cleanup — Demo
 * ---------------------------------------------------------------------- */

/**
 * Remove any stale examplepress-demo-* directories.
 *
 * GitHub repo archive zips extract to "reponame-branch/" (e.g.
 * examplepress-demo-development/). If one of these exists
 * alongside the canonical directory, WordPress loads both and PHP
 * fatals on class redeclaration.
 */
function examplepress_cleanup_stale_demo_dirs(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
		return;
	}

	$pattern = WP_PLUGIN_DIR . '/examplepress-demo-*';
	// Exclude the canonical 'examplepress-demo' dir (glob won't match it
	// because the pattern requires at least one char after the dash).
	$stale = glob( $pattern, GLOB_ONLYDIR );

	if ( empty( $stale ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
		return;
	}

	foreach ( $stale as $dir ) {
		$dirname      = basename( $dir );
		$stale_plugin = $dirname . '/examplepress-demo.php';

		if ( is_plugin_active( $stale_plugin ) ) {
			deactivate_plugins( $stale_plugin, true );
		}

		$wp_filesystem->delete( $dir, true );
	}
}

/* -------------------------------------------------------------------------
 * Install — Updater
 * ---------------------------------------------------------------------- */

/**
 * Download and install the updater plugin from GitHub.
 *
 * Called by the REST API endpoint, not automatically.
 *
 * Tries the latest release asset first. If no releases exist,
 * falls back to a repo archive download.
 *
 * @return true|WP_Error
 */
function examplepress_install_updater_from_github() {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	// Clean up stale/broken directory if it exists without a valid plugin file.
	$plugin_dir = WP_PLUGIN_DIR . '/examplepress-theme-update';
	if ( is_dir( $plugin_dir ) ) {
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$wp_filesystem->delete( $plugin_dir, true );
		}
	}

	$zip_url = examplepress_resolve_updater_zip_url();

	if ( ! $zip_url ) {
		return new WP_Error(
			'ep_updater_no_source',
			'Could not determine a download URL for the updater plugin.'
		);
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// GitHub repo archives extract to "reponame-branch/" instead of "slug/".
	// Temporarily hook upgrader_source_selection to rename the directory.
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
	$result   = $upgrader->install( $zip_url );

	remove_filter( 'upgrader_source_selection', $rename_filter, 10 );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( is_wp_error( $skin->result ) ) {
		return $skin->result;
	}

	if ( ! $result ) {
		return new WP_Error(
			'ep_updater_install_failed',
			'The plugin installer returned an unexpected result.'
		);
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Install — Demo
 * ---------------------------------------------------------------------- */

/**
 * Download and install the demo plugin from GitHub.
 *
 * Called by the REST API endpoint, not automatically.
 *
 * Tries the latest release asset first. If no releases exist,
 * falls back to a repo archive download.
 *
 * @return true|WP_Error
 */
function examplepress_install_demo_from_github() {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	// Clean up stale/broken directory if it exists without a valid plugin file.
	$plugin_dir = WP_PLUGIN_DIR . '/examplepress-demo';
	if ( is_dir( $plugin_dir ) ) {
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$wp_filesystem->delete( $plugin_dir, true );
		}
	}

	$zip_url = examplepress_resolve_demo_zip_url();

	if ( ! $zip_url ) {
		return new WP_Error(
			'ep_demo_no_source',
			'Could not determine a download URL for the demo plugin.'
		);
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// GitHub repo archives extract to "reponame-branch/" instead of "slug/".
	// Temporarily hook upgrader_source_selection to rename the directory.
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
	$result   = $upgrader->install( $zip_url );

	remove_filter( 'upgrader_source_selection', $rename_filter, 10 );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( is_wp_error( $skin->result ) ) {
		return $skin->result;
	}

	if ( ! $result ) {
		return new WP_Error(
			'ep_demo_install_failed',
			'The plugin installer returned an unexpected result.'
		);
	}

	return true;
}

/* -------------------------------------------------------------------------
 * ZIP URL resolvers
 * ---------------------------------------------------------------------- */

/**
 * Resolve the best download URL for the updater plugin zip.
 *
 * Priority:
 *   1. Latest GitHub release asset named "examplepress-theme-update.zip"
 *   2. GitHub repo archive (development branch)
 *
 * @return string|null
 */
function examplepress_resolve_updater_zip_url(): ?string {
	$headers = [
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
	];

	$token = examplepress_updater_bootstrap_get_token();
	if ( $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	// Try latest release first.
	$response = wp_remote_get(
		sprintf( 'https://api.github.com/repos/%s/releases/latest', EP_UPDATER_GITHUB_REPO ),
		[ 'timeout' => 10, 'headers' => $headers ]
	);

	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $release ) && ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ( $asset['name'] ?? '' ) === 'examplepress-theme-update.zip' ) {
					return $asset['browser_download_url'];
				}
			}
		}
	}

	// Fallback: repo archive from the development branch.
	return sprintf(
		'https://github.com/%s/archive/refs/heads/development.zip',
		EP_UPDATER_GITHUB_REPO
	);
}

/**
 * Resolve the best download URL for the demo plugin zip.
 *
 * Priority:
 *   1. Latest GitHub release asset named "examplepress-demo.zip"
 *   2. GitHub repo archive (development branch)
 *
 * @return string|null
 */
function examplepress_resolve_demo_zip_url(): ?string {
	$headers = [
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
	];

	$token = examplepress_updater_bootstrap_get_token();
	if ( $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	// Try latest release first.
	$response = wp_remote_get(
		sprintf( 'https://api.github.com/repos/%s/releases/latest', EP_DEMO_GITHUB_REPO ),
		[ 'timeout' => 10, 'headers' => $headers ]
	);

	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $release ) && ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ( $asset['name'] ?? '' ) === 'examplepress-demo.zip' ) {
					return $asset['browser_download_url'];
				}
			}
		}
	}

	// Fallback: repo archive from the development branch.
	return sprintf(
		'https://github.com/%s/archive/refs/heads/development.zip',
		EP_DEMO_GITHUB_REPO
	);
}

/* -------------------------------------------------------------------------
 * Token helper
 * ---------------------------------------------------------------------- */

/**
 * Get a GitHub API token if one is available.
 *
 * Reuses the same token sources as the theme's GitHub integration.
 *
 * @return string|null
 */
function examplepress_updater_bootstrap_get_token(): ?string {
	$token = get_option( 'ep_troy_github_pat', '' );
	if ( $token ) {
		return $token;
	}

	$token = get_option( 'ep_github_pat', '' );
	if ( $token ) {
		return $token;
	}

	if ( function_exists( 'examplepress_github_app_get_installation_token' ) ) {
		$token = examplepress_github_app_get_installation_token();
		if ( $token && ! is_wp_error( $token ) ) {
			return $token;
		}
	}

	return null;
}
