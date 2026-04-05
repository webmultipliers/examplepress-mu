<?php
/**
 * ExamplePress MU — App Discovery
 *
 * Scans installed plugins for examplepress.json to discover and track
 * apps (companion plugins) that extend the ExamplePress theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Discover all ExamplePress apps by scanning plugin directories
 * for examplepress.json files.
 *
 * @return array List of app data arrays.
 */
function examplepress_get_apps(): array {
    $plugins_dir = WP_PLUGIN_DIR;

    if ( ! is_dir( $plugins_dir ) ) {
        return [];
    }

    $apps    = [];
    $entries = scandir( $plugins_dir );

    if ( ! $entries ) {
        return [];
    }

    foreach ( $entries as $entry ) {
        if ( $entry === '.' || $entry === '..' ) {
            continue;
        }

        $plugin_path = $plugins_dir . '/' . $entry;

        if ( ! is_dir( $plugin_path ) ) {
            continue;
        }

        $json_path = $plugin_path . '/examplepress.json';

        if ( ! file_exists( $json_path ) ) {
            continue;
        }

        $app = examplepress_parse_app( $entry, $json_path, $plugin_path );

        if ( $app ) {
            $apps[] = $app;
        }
    }

    return $apps;
}

/**
 * Parse a single app from its examplepress.json and plugin headers.
 *
 * @param string $slug        Plugin directory name.
 * @param string $json_path   Path to examplepress.json.
 * @param string $plugin_path Plugin directory path.
 * @return array|null App data or null if invalid.
 */
function examplepress_parse_app( string $slug, string $json_path, string $plugin_path ): ?array {
    $raw = file_get_contents( $json_path );

    if ( ! $raw ) {
        return null;
    }

    $config = json_decode( $raw, true );

    if ( ! is_array( $config ) ) {
        return null;
    }

    // Find the main plugin file.
    $plugin_file = examplepress_find_plugin_file( $slug, $plugin_path );

    if ( ! $plugin_file ) {
        return null;
    }

    // Read plugin headers.
    $headers = get_file_data( $plugin_path . '/' . $plugin_file, [
        'name'        => 'Plugin Name',
        'description' => 'Description',
        'version'     => 'Version',
        'theme'       => 'Theme',
        'troy'        => 'Troy',
    ] );

    // Verify this plugin declares Theme: examplepress-theme.
    if ( empty( $headers['theme'] ) || $headers['theme'] !== 'examplepress-theme' ) {
        return null;
    }

    // Extract Troy connection data from JSON.
    $troy = $config['troy'] ?? [];
    $troy_server = $troy['server_url'] ?? '';
    $troy_repo   = $troy['repo'] ?? '';
    $troy_repo_id = $troy['repo_id'] ?? '';

    $is_connected = ! empty( $troy_server ) && ! empty( $troy_repo );

    // Mismatch detection.
    $mismatches = [];
    $header_troy = $headers['troy'] ?? '';

    if ( $is_connected && empty( $header_troy ) ) {
        $mismatches[] = 'troy_header_missing';
    } elseif ( $is_connected && $header_troy !== $troy_server ) {
        $mismatches[] = 'troy_header_mismatch';
    }

    $relative_file = $slug . '/' . $plugin_file;

    // Extract routing config.
    $routing    = $config['routing'] ?? [];
    $priority   = (int) ( $routing['priority'] ?? 10 );
    $route_meta = $routing['routes'] ?? [];

    return [
        'id'          => $slug,
        'name'        => $config['name'] ?? $headers['name'] ?? $slug,
        'slug'        => $config['slug'] ?? $slug,
        'description' => $config['description'] ?? $headers['description'] ?? '',
        'version'     => $config['version'] ?? $headers['version'] ?? '0.0.0',
        'status'      => $is_connected ? 'connected' : 'disconnected',
        'active'      => is_plugin_active( $relative_file ),
        'routing'     => [
            'priority' => $priority,
            'routes'   => $route_meta,
        ],
        'troy'        => [
            'server_url' => $troy_server,
            'repo'       => $troy_repo,
            'repo_id'    => $troy_repo_id,
        ],
        'plugin_file' => $relative_file,
        'mismatches'  => $mismatches,
    ];
}

/**
 * Find the main plugin PHP file in a plugin directory.
 *
 * @param string $slug        Plugin directory name.
 * @param string $plugin_path Plugin directory path.
 * @return string|null Filename relative to plugin directory, or null.
 */
function examplepress_find_plugin_file( string $slug, string $plugin_path ): ?string {
    // Prefer {slug}.php.
    if ( file_exists( $plugin_path . '/' . $slug . '.php' ) ) {
        return $slug . '.php';
    }

    // Scan for a file with Plugin Name header.
    $files = glob( $plugin_path . '/*.php' );

    if ( ! $files ) {
        return null;
    }

    foreach ( $files as $file ) {
        $data = get_file_data( $file, [ 'name' => 'Plugin Name' ] );
        if ( ! empty( $data['name'] ) ) {
            return basename( $file );
        }
    }

    return null;
}

/**
 * Slugify a plugin name for directory/file naming.
 *
 * @param string $name Human-readable name.
 * @return string Slug.
 */
function examplepress_slugify_app_name( string $name ): string {
    $slug = strtolower( $name );
    $slug = preg_replace( '/[^a-z0-9\s-]/', '', $slug );
    $slug = preg_replace( '/[\s-]+/', '-', $slug );
    $slug = trim( $slug, '-' );
    return $slug;
}
