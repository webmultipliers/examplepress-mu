<?php
/**
 * ExamplePress REST API — Filesystem Endpoints
 *
 * Read/write access to companion plugin directories for the in-browser
 * editor. All paths are strictly sandboxed within wp-content/plugins/{slug}/.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'examplepress_register_fs_routes' );

function examplepress_register_fs_routes(): void {
	$slug_args = [
		'slug' => [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_title',
			'validate_callback' => function ( $value ) {
				return (bool) preg_match( '/^[a-z0-9-]+$/', $value );
			},
		],
	];

	register_rest_route( 'examplepress/v1', '/fs/(?P<slug>[a-z0-9-]+)/tree', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_fs_tree',
		'permission_callback' => 'examplepress_fs_permission_check',
		'args'                => $slug_args,
	] );

	register_rest_route( 'examplepress/v1', '/fs/(?P<slug>[a-z0-9-]+)/file', [
		'methods'             => 'GET',
		'callback'            => 'examplepress_fs_read_file',
		'permission_callback' => 'examplepress_fs_permission_check',
		'args'                => array_merge( $slug_args, [
			'path' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		] ),
	] );

	register_rest_route( 'examplepress/v1', '/fs/(?P<slug>[a-z0-9-]+)/file', [
		'methods'             => 'PUT',
		'callback'            => 'examplepress_fs_write_file',
		'permission_callback' => 'examplepress_fs_permission_check',
		'args'                => array_merge( $slug_args, [
			'path' => [
				'required' => true,
				'type'     => 'string',
			],
			'content' => [
				'required' => true,
				'type'     => 'string',
			],
		] ),
	] );
}

// ── Permission ──────────────────────────────────────────────────

function examplepress_fs_permission_check(): bool {
	$allowed = current_user_can( 'manage_options' );

	/**
	 * Filter whether the current user can edit app files.
	 *
	 * @param bool $allowed Default: manage_options capability.
	 */
	return (bool) apply_filters( 'examplepress_can_edit_app_files', $allowed );
}

// ── Path Validation ─────────────────────────────────────────────

/**
 * Resolve and validate a relative path within a plugin directory.
 *
 * @param string $slug          Plugin slug.
 * @param string $relative_path Relative file path.
 * @param bool   $must_exist    Whether the file must already exist.
 * @return string|WP_Error Absolute path or error.
 */
function examplepress_fs_resolve_path( string $slug, string $relative_path, bool $must_exist = true ) {
	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

	if ( ! is_dir( $plugin_dir ) ) {
		return new WP_Error( 'not_found', 'Plugin directory does not exist.', [ 'status' => 404 ] );
	}

	// Normalize.
	$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );

	// Reject traversal and null bytes.
	if ( str_contains( $relative_path, '..' ) || str_contains( $relative_path, "\0" ) ) {
		return new WP_Error( 'forbidden', 'Invalid path.', [ 'status' => 403 ] );
	}

	$absolute = $plugin_dir . '/' . $relative_path;

	if ( $must_exist ) {
		$real_file = realpath( $absolute );
		$real_base = realpath( $plugin_dir );

		if ( ! $real_file || ! $real_base || ! str_starts_with( $real_file, $real_base . '/' ) ) {
			return new WP_Error( 'forbidden', 'Path escapes the plugin boundary.', [ 'status' => 403 ] );
		}

		return $real_file;
	}

	// For writes to new files: validate the parent directory.
	$parent_dir  = dirname( $absolute );
	$real_parent = realpath( $parent_dir );
	$real_base   = realpath( $plugin_dir );

	if ( ! $real_parent || ! $real_base ) {
		return new WP_Error( 'not_found', 'Parent directory does not exist.', [ 'status' => 404 ] );
	}

	// Parent must be the plugin dir itself or a subdirectory of it.
	if ( $real_parent !== $real_base && ! str_starts_with( $real_parent, $real_base . '/' ) ) {
		return new WP_Error( 'forbidden', 'Path escapes the plugin boundary.', [ 'status' => 403 ] );
	}

	return $real_parent . '/' . basename( $relative_path );
}

// ── Handlers ────────────────────────────────────────────────────

/**
 * Directory tree listing.
 */
function examplepress_fs_tree( WP_REST_Request $request ) {
	$slug       = $request->get_param( 'slug' );
	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

	if ( ! is_dir( $plugin_dir ) ) {
		return new WP_Error( 'not_found', 'Plugin directory does not exist.', [ 'status' => 404 ] );
	}

	return rest_ensure_response( examplepress_fs_scan_dir( $plugin_dir, $plugin_dir ) );
}

/**
 * Read a file.
 */
function examplepress_fs_read_file( WP_REST_Request $request ) {
	$slug = $request->get_param( 'slug' );
	$path = $request->get_param( 'path' );

	$resolved = examplepress_fs_resolve_path( $slug, $path );

	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	if ( ! is_file( $resolved ) ) {
		return new WP_Error( 'not_found', 'File not found.', [ 'status' => 404 ] );
	}

	$size = filesize( $resolved );

	if ( $size > 1048576 ) {
		return new WP_Error( 'too_large', 'File exceeds 1 MB limit.', [ 'status' => 413 ] );
	}

	$content = file_get_contents( $resolved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( $content === false ) {
		return new WP_Error( 'read_error', 'Could not read file.', [ 'status' => 500 ] );
	}

	if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
		return new WP_Error( 'binary_file', 'File appears to be binary.', [ 'status' => 415 ] );
	}

	return rest_ensure_response( [
		'path'     => $path,
		'content'  => $content,
		'size'     => $size,
		'language' => examplepress_fs_detect_language( $path ),
	] );
}

/**
 * Write a file.
 */
function examplepress_fs_write_file( WP_REST_Request $request ) {
	$slug    = $request->get_param( 'slug' );
	$path    = $request->get_param( 'path' );
	$content = $request->get_param( 'content' );

	$resolved = examplepress_fs_resolve_path( $slug, $path, false );

	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	// Ensure parent directory exists.
	$parent = dirname( $resolved );
	if ( ! is_dir( $parent ) ) {
		wp_mkdir_p( $parent );
	}

	$bytes = file_put_contents( $resolved, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	if ( $bytes === false ) {
		return new WP_Error( 'write_error', 'Could not write file.', [ 'status' => 500 ] );
	}

	return rest_ensure_response( [
		'success' => true,
		'path'    => $path,
		'size'    => $bytes,
	] );
}

// ── Helpers ─────────────────────────────────────────────────────

/**
 * Recursively scan a directory and return a tree structure.
 *
 * @param string $dir  Directory to scan.
 * @param string $base Base directory for relative paths.
 * @return array
 */
function examplepress_fs_scan_dir( string $dir, string $base ): array {
	$skip    = [ '.', '..', '.git', '.github', 'node_modules', 'vendor', '.DS_Store' ];
	$entries = scandir( $dir );
	$dirs    = [];
	$files   = [];

	foreach ( $entries as $entry ) {
		if ( in_array( $entry, $skip, true ) ) {
			continue;
		}

		$absolute = $dir . '/' . $entry;
		$relative = ltrim( str_replace( $base, '', $absolute ), '/' );

		if ( is_dir( $absolute ) ) {
			$dirs[] = [
				'name'     => $entry,
				'path'     => $relative,
				'type'     => 'directory',
				'children' => examplepress_fs_scan_dir( $absolute, $base ),
			];
		} else {
			$files[] = [
				'name' => $entry,
				'path' => $relative,
				'type' => 'file',
				'size' => filesize( $absolute ),
			];
		}
	}

	// Directories first, then files. Alphabetical within each group.
	usort( $dirs, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
	usort( $files, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

	return array_merge( $dirs, $files );
}

/**
 * Detect Monaco language ID from file extension.
 *
 * @param string $path File path.
 * @return string Monaco language ID.
 */
function examplepress_fs_detect_language( string $path ): string {
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

	$map = [
		'php'  => 'php',
		'js'   => 'javascript',
		'mjs'  => 'javascript',
		'ts'   => 'typescript',
		'json' => 'json',
		'css'  => 'css',
		'scss' => 'scss',
		'html' => 'html',
		'htm'  => 'html',
		'xml'  => 'xml',
		'md'   => 'markdown',
		'yaml' => 'yaml',
		'yml'  => 'yaml',
		'sh'   => 'shell',
		'bash' => 'shell',
		'sql'  => 'sql',
	];

	return $map[ $ext ] ?? 'plaintext';
}
