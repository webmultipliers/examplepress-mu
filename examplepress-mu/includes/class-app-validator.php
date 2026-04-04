<?php
/**
 * ExamplePress MU — App Validator (Zero-Trust Plugin Governance)
 *
 * Scans active standard plugins for an `examplepress.json` manifest. Plugins that
 * declare themselves as ExamplePress apps (via the `Theme: examplepress-theme` plugin
 * header) but fail validation are dynamically removed from the active plugins array
 * for the current request — effectively neutralizing them before they load.
 *
 * Validation rules:
 *  1. The plugin must contain a valid `examplepress.json` at its root.
 *  2. The manifest must include required fields: name, slug.
 *  3. The manifest must not request any banned permissions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_App_Validator {

    /**
     * Permissions that an ExamplePress app may never request.
     */
    const BANNED_PERMISSIONS = [
        'manage_options',
        'edit_themes',
        'edit_plugins',
        'install_plugins',
        'install_themes',
        'switch_themes',
        'delete_plugins',
        'delete_themes',
        'update_core',
        'update_plugins',
        'update_themes',
    ];

    /**
     * Cache of validation results for the current request, keyed by plugin basename.
     */
    private static $cache = [];

    /**
     * Register the governance filter.
     */
    public static function init() {
        // Filter the active plugins list before WordPress loads them.
        add_filter( 'option_active_plugins', [ __CLASS__, 'filter_active_plugins' ] );
    }

    /**
     * Filter the list of active plugins, removing any that claim to be ExamplePress
     * apps but fail validation.
     *
     * @param array $plugins List of active plugin basenames.
     * @return array Filtered list.
     */
    public static function filter_active_plugins( $plugins ) {
        if ( ! is_array( $plugins ) ) {
            return $plugins;
        }

        return array_values( array_filter( $plugins, [ __CLASS__, 'validate_plugin' ] ) );
    }

    /**
     * Validate a single plugin. Returns true if the plugin should remain active.
     *
     * Non-ExamplePress plugins always pass (we only govern our own ecosystem).
     *
     * @param string $plugin_basename  e.g. "my-app/my-app.php"
     * @return bool
     */
    private static function validate_plugin( $plugin_basename ) {
        if ( isset( self::$cache[ $plugin_basename ] ) ) {
            return self::$cache[ $plugin_basename ];
        }

        $plugin_dir  = WP_PLUGIN_DIR . '/' . dirname( $plugin_basename );
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_basename;

        // If the plugin file doesn't exist, let WordPress handle the error.
        if ( ! file_exists( $plugin_file ) ) {
            self::$cache[ $plugin_basename ] = true;
            return true;
        }

        // Check if this plugin declares itself as an ExamplePress app via the
        // "Theme: examplepress-theme" plugin header.
        $headers = get_file_data( $plugin_file, [ 'Theme' => 'Theme' ] );

        if ( empty( $headers['Theme'] ) || 'examplepress-theme' !== $headers['Theme'] ) {
            // Not an ExamplePress app — allow it through without governance.
            self::$cache[ $plugin_basename ] = true;
            return true;
        }

        // --- This is a declared ExamplePress app. Validate it. ---

        $manifest_path = $plugin_dir . '/examplepress.json';

        // Rule 1: Manifest must exist and be valid JSON.
        if ( ! file_exists( $manifest_path ) ) {
            self::reject( $plugin_basename, 'Missing examplepress.json manifest.' );
            return false;
        }

        $manifest = json_decode( file_get_contents( $manifest_path ), true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $manifest ) ) {
            self::reject( $plugin_basename, 'Invalid JSON in examplepress.json.' );
            return false;
        }

        // Rule 2: Required fields.
        if ( empty( $manifest['name'] ) || empty( $manifest['slug'] ) ) {
            self::reject( $plugin_basename, 'Manifest missing required fields (name, slug).' );
            return false;
        }

        // Rule 3: No banned permissions.
        $requested = $manifest['permissions'] ?? [];
        if ( is_array( $requested ) ) {
            $banned_found = array_intersect( $requested, self::BANNED_PERMISSIONS );
            if ( ! empty( $banned_found ) ) {
                self::reject(
                    $plugin_basename,
                    'Manifest requests banned permissions: ' . implode( ', ', $banned_found )
                );
                return false;
            }
        }

        self::$cache[ $plugin_basename ] = true;
        return true;
    }

    /**
     * Log a rejection and cache the result.
     *
     * @param string $plugin_basename
     * @param string $reason
     */
    private static function reject( $plugin_basename, $reason ) {
        self::$cache[ $plugin_basename ] = false;
        error_log( sprintf(
            'ExamplePress MU App Validator: Deactivated "%s" — %s',
            $plugin_basename,
            $reason
        ) );
    }
}
