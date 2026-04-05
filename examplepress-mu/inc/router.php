<?php
/**
 * ExamplePress Routing Helpers
 *
 * Centralised routing API used by the Blockstudio router block
 * to resolve the current request to a template block.
 *
 * Companion plugins register route origins via the registry
 * (examplepress_register_route_origin). The router evaluates all
 * registered origins by priority and dispatches to the first match.
 * When no origin matches, the default route 'get-started' is used
 * under the theme's own namespace.
 */

/**
 * Resolve the current route via the route origin registry.
 *
 * Returns an array with 'namespace' and 'slug' keys.
 *
 * @return array{namespace: string, slug: string}
 */
function examplepress_resolve_route(): array {
	$origin = examplepress_resolve_route_origin();

	if ( $origin ) {
		/**
		 * Filters the registry-resolved route before dispatch.
		 *
		 * @param array $origin { namespace: string, slug: string }
		 */
		return apply_filters( 'examplepress_resolved_origin', $origin );
	}

	// No origin matched — use theme default.
	return [
		'namespace' => 'examplepress-theme',
		'slug'      => 'get-started',
	];
}

/**
 * Get the template block prefix.
 *
 * @return string Prefix. Defaults to 'template'.
 */
function examplepress_get_template_prefix() {
	return apply_filters( 'examplepress_template_prefix', 'template' );
}

/**
 * Build the fully-qualified block name for a template slug.
 *
 * @param string $slug     Template slug (e.g. 'front').
 * @param string $prefix   Template prefix (e.g. 'template').
 * @param string $theme_ns Theme namespace (e.g. 'examplepress-core').
 * @return string Full block name (e.g. 'examplepress-core/template-front').
 */
function examplepress_get_template_block_name( $slug, $prefix, $theme_ns ) {
	return apply_filters(
		'examplepress_template_block_name',
		sprintf( '%s/%s-%s', $theme_ns, $prefix, $slug ),
		$slug,
		$prefix,
		$theme_ns
	);
}
