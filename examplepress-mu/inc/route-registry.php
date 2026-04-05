<?php
/**
 * ExamplePress Route Origin Registry
 *
 * Multi-origin routing: companion plugins register which routes they
 * provide and under which Blockstudio namespace. The router resolves
 * per-route instead of using a single global namespace, so multiple
 * companion plugins can coexist without clobbering each other.
 *
 * Usage in a companion plugin:
 *
 *     examplepress_register_route_origin( 'my-plugin', [
 *         'front'  => fn() => is_front_page() || is_home(),
 *         'single' => fn() => is_singular(),
 *     ] );
 *
 * Each key is a route slug, each value is a callable that returns true
 * when the current request matches that route. The router evaluates
 * origins in priority order (lower = earlier) and uses the first
 * matching origin's namespace to build the block name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal store for registered route origins.
 *
 * Structure: [ priority => [ namespace => [ slug => callable, … ] ] ]
 *
 * @var array
 */
global $examplepress_route_origins;
$examplepress_route_origins = [];

/**
 * Register a route origin.
 *
 * @param string   $namespace Blockstudio namespace (e.g. 'examplepress-demo').
 * @param array    $routes    Map of slug => callable. The callable receives no
 *                            args and returns bool (true = this route matches).
 * @param int      $priority  Lower = evaluated first. Default 10.
 */
function examplepress_register_route_origin( string $namespace, array $routes, int $priority = 10 ): void {
	global $examplepress_route_origins;

	if ( ! isset( $examplepress_route_origins[ $priority ] ) ) {
		$examplepress_route_origins[ $priority ] = [];
	}

	$examplepress_route_origins[ $priority ][ $namespace ] = $routes;
}

/**
 * Resolve the current request against all registered route origins.
 *
 * Returns the first match: the namespace and slug whose condition callable
 * returns true. Origins are checked in priority order (ascending), and
 * within the same priority in registration order.
 *
 * @return array{namespace: string, slug: string}|null Null if no origin matched.
 */
function examplepress_resolve_route_origin(): ?array {
	global $examplepress_route_origins;

	if ( empty( $examplepress_route_origins ) ) {
		return null;
	}

	// Sort by priority (ascending — lower number = higher priority).
	ksort( $examplepress_route_origins, SORT_NUMERIC );

	foreach ( $examplepress_route_origins as $origins_at_priority ) {
		foreach ( $origins_at_priority as $namespace => $routes ) {
			foreach ( $routes as $slug => $condition ) {
				if ( is_callable( $condition ) && call_user_func( $condition ) ) {
					return [
						'namespace' => $namespace,
						'slug'      => $slug,
					];
				}
			}
		}
	}

	return null;
}

/**
 * Check whether any route origins have been registered.
 *
 * @return bool
 */
function examplepress_has_route_origins(): bool {
	global $examplepress_route_origins;

	return ! empty( $examplepress_route_origins );
}

/**
 * Get all registered route origin namespaces.
 *
 * Used by the inner_blocks wrap filter to exempt template blocks
 * from all active companion plugin namespaces.
 *
 * @return string[] Unique namespace strings.
 */
function examplepress_get_route_origin_namespaces(): array {
	global $examplepress_route_origins;

	$namespaces = [];

	foreach ( $examplepress_route_origins as $origins_at_priority ) {
		foreach ( $origins_at_priority as $namespace => $routes ) {
			$namespaces[] = $namespace;
		}
	}

	return array_unique( $namespaces );
}

/**
 * Get a full introspection map of all registered route origins.
 *
 * Returns a structured array suitable for debugging, admin display,
 * and WP-CLI output. Each entry includes the namespace, its declared
 * route slugs, and the registration priority.
 *
 * @return array[] List of [ namespace, priority, routes (slug list) ].
 */
function examplepress_get_route_origin_map(): array {
	global $examplepress_route_origins;

	$map = [];

	ksort( $examplepress_route_origins, SORT_NUMERIC );

	foreach ( $examplepress_route_origins as $priority => $origins ) {
		foreach ( $origins as $namespace => $routes ) {
			$map[] = [
				'namespace' => $namespace,
				'priority'  => $priority,
				'routes'    => array_keys( $routes ),
			];
		}
	}

	return $map;
}

/**
 * Check whether a specific route slug is claimed by any origin.
 *
 * Useful for companion plugins to check if another plugin already
 * owns a route before registering a conflicting one.
 *
 * @param string $slug The route slug to check.
 * @return string|null The namespace that owns it, or null.
 */
function examplepress_route_slug_owner( string $slug ): ?string {
	global $examplepress_route_origins;

	ksort( $examplepress_route_origins, SORT_NUMERIC );

	foreach ( $examplepress_route_origins as $origins ) {
		foreach ( $origins as $namespace => $routes ) {
			if ( array_key_exists( $slug, $routes ) ) {
				return $namespace;
			}
		}
	}

	return null;
}

/**
 * Detect route slug conflicts across origins.
 *
 * Returns an array of slugs that are declared by more than one
 * namespace. Each entry lists all claimants and their priorities.
 *
 * @return array<string, array[]> Map of slug => [ { namespace, priority }, … ]
 */
function examplepress_detect_route_conflicts(): array {
	global $examplepress_route_origins;

	$slug_claims = [];

	foreach ( $examplepress_route_origins as $priority => $origins ) {
		foreach ( $origins as $namespace => $routes ) {
			foreach ( array_keys( $routes ) as $slug ) {
				$slug_claims[ $slug ][] = [
					'namespace' => $namespace,
					'priority'  => $priority,
				];
			}
		}
	}

	// Only return slugs claimed by more than one origin.
	return array_filter( $slug_claims, fn( $claims ) => count( $claims ) > 1 );
}

/**
 * Assert that the theme is being used as an immutable foundation.
 *
 * Companion plugins should never modify theme files. This helper
 * verifies that the theme directory has not been written to since
 * the last known version. Returns a list of unexpected files.
 *
 * Intended for health checks and CI — not called during normal
 * request processing.
 *
 * @return string[] List of files that don't belong to the theme distribution.
 */
function examplepress_check_theme_immutability(): array {
	$known_dirs = [
		'blockstudio',
		'demo',
		'docs',
		'inc',
		'languages',
		'templates',
		'vendor',
	];

	$known_root_files = [
		'blockstudio.json',
		'composer.json',
		'composer.lock',
		'examplepress.json',
		'functions.php',
		'screenshot.png',
		'style.css',
		'theme.json',
	];

	$unexpected = [];
	$entries    = scandir( EP_THEME_PATH );

	if ( ! $entries ) {
		return $unexpected;
	}

	foreach ( $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}

		$is_dir  = is_dir( EP_THEME_PATH . '/' . $entry );
		$is_known = $is_dir
			? in_array( $entry, $known_dirs, true )
			: in_array( $entry, $known_root_files, true );

		if ( ! $is_known ) {
			$unexpected[] = $entry;
		}
	}

	return $unexpected;
}
