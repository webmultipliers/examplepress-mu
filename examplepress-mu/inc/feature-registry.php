<?php
/**
 * ExamplePress Feature Registry
 *
 * Centralised system for registering, querying, and booting
 * filterable feature flags.
 *
 * - Register features with examplepress_register_feature().
 * - Check state with examplepress_feature_enabled() (filterable via
 *   "examplepress_feature_{$id}").
 * - Read per-feature options with examplepress_feature_option() (filterable
 *   via "examplepress_feature_{$id}_{$key}").
 * - Boot all features with examplepress_boot_features().
 */

global $examplepress_features;
$examplepress_features = [];

/**
 * Register a feature.
 *
 * @param string $id   Unique feature identifier (slug).
 * @param array  $args {
 *     @type string   $label    Human-readable label.
 *     @type bool     $default  Whether the feature is enabled by default.
 *     @type array    $options  Feature-specific key/value pairs.
 *     @type string   $hook     Target hook (simple features only).
 *     @type callable $callback Hook callback (simple features only).
 *     @type string   $type     'filter' or 'action'. Default 'filter'.
 *     @type int      $priority Hook priority. Default 10.
 *     @type callable $setup    Custom boot callable for complex features.
 *                              Receives ($id, $feature).
 * }
 */
function examplepress_register_feature( $id, $args = [] ) {
	global $examplepress_features;

	$examplepress_features[ $id ] = wp_parse_args( $args, [
		'label'    => '',
		'group'    => '',
		'default'  => true,
		'options'  => [],
		'hook'     => '',
		'callback' => '',
		'type'     => 'filter',
		'priority' => 10,
		'setup'    => NULL,
	] );
}

/**
 * Check whether a feature is enabled.
 *
 * Resolution order (highest wins):
 *   1. PHP Filter  (`examplepress_feature_{$id}`)
 *   2. examplepress.json  (`features.{id}` — bool or object with `enabled`)
 *   3. Registration default
 */
function examplepress_feature_enabled( $id ) {
	global $examplepress_features;

	$default = $examplepress_features[ $id ]['default'] ?? true;

	$json = examplepress_get_config();
	if ( isset( $json['features'][ $id ] ) ) {
		$val     = $json['features'][ $id ];
		$default = is_array( $val ) ? ( $val['enabled'] ?? $default ) : (bool) $val;
	}

	return (bool) apply_filters( "examplepress_feature_{$id}", $default );
}

/**
 * Retrieve a feature-specific option value.
 *
 * Resolution order (highest wins):
 *   1. PHP Filter  (`examplepress_feature_{$id}_{$key}`)
 *   2. examplepress.json  (`features.{id}.options.{key}`)
 *   3. Registration default
 */
function examplepress_feature_option( $id, $key, $fallback = NULL ) {
	global $examplepress_features;

	$options = $examplepress_features[ $id ]['options'] ?? [];
	$value   = $options[ $key ] ?? $fallback;

	$json = examplepress_get_config();
	if ( isset( $json['features'][ $id ] ) && is_array( $json['features'][ $id ] ) ) {
		$json_options = $json['features'][ $id ]['options'] ?? [];
		if ( array_key_exists( $key, $json_options ) ) {
			$value = $json_options[ $key ];
		}
	}

	return apply_filters( "examplepress_feature_{$id}_{$key}", $value );
}

/**
 * Guard wrapper for complex feature setup callables.
 *
 * Checks whether the feature is enabled before invoking the callback,
 * eliminating the duplicated `if ( ! examplepress_feature_enabled() )`
 * preamble in every setup callable.
 *
 * @param string   $id Feature identifier.
 * @param callable $fn Callback to invoke when the feature is enabled.
 *                     Receives ($id).
 */
function examplepress_guarded_setup( $id, callable $fn ) {
	if ( ! examplepress_feature_enabled( $id ) ) {
		return;
	}
	$fn( $id );
}

/**
 * Return every registered feature.
 *
 * Filterable via `examplepress_features`.
 */
function examplepress_get_features() {
	global $examplepress_features;

	return apply_filters( 'examplepress_features', $examplepress_features );
}

/**
 * Return features belonging to a specific group.
 *
 * @param string $group Group slug (e.g. 'design', 'editor', 'blockstudio').
 * @return array Associative array of feature_id => feature_args.
 */
function examplepress_get_features_by_group( $group ) {
	$features = examplepress_get_features();

	return array_filter( $features, fn( $f ) => ( $f['group'] ?? '' ) === $group );
}

/**
 * Boot all registered features.
 *
 * Complex features (those with a `setup` callable) always have their
 * setup invoked — the callable is responsible for checking enabled
 * state internally when appropriate.
 *
 * Simple features (hook + callback, no setup) are only wired when
 * the feature is enabled.
 */
function examplepress_boot_features() {
	$features = examplepress_get_features();

	foreach ( $features as $id => $feature ) {
		if ( is_callable( $feature['setup'] ) ) {
			call_user_func( $feature['setup'], $id, $feature );
			continue;
		}

		if ( ! examplepress_feature_enabled( $id ) ) {
			continue;
		}

		if ( $feature['hook'] && $feature['callback'] ) {
			if ( 'action' === $feature['type'] ) {
				add_action( $feature['hook'], $feature['callback'], $feature['priority'] );
			} else {
				add_filter( $feature['hook'], $feature['callback'], $feature['priority'] );
			}
		}
	}
}
