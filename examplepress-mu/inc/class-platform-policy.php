<?php
/**
 * ExamplePress MU — Platform Policy Engine
 *
 * Enforces fleet-wide policies: strips dangerous capabilities globally,
 * enforces permalink structures, locks specific wp_options, and disables
 * 404 redirect guessing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Platform_Policy {

    /**
     * Capabilities completely stripped from the site by the platform.
     */
    const STRIPPED_CAPS = [
        'edit_themes',
        'install_themes',
        'switch_themes',
        'delete_themes',
        'install_plugins',
        'delete_plugins',
        'update_core',
    ];

    public static function init() {
        // 1. Enforce Permalink Structure globally
        add_filter( 'pre_option_permalink_structure', [ __CLASS__, 'enforce_permalinks' ] );

        // 2. Global Capability Stripping
        add_filter( 'user_has_cap', [ __CLASS__, 'strip_capabilities' ], 999, 4 );

        // Hard disable file editing
        if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
            define( 'DISALLOW_FILE_EDIT', true );
        }

        // 3. Managed Options
        $managed_options = apply_filters( 'examplepress_mu_managed_options', [] );
        foreach ( $managed_options as $option => $value ) {
            add_filter( "pre_option_{$option}", function() use ( $value ) {
                return $value;
            } );
        }

        // 4. Disable 404 Redirect Guessing
        if ( apply_filters( 'examplepress_mu_disable_redirect_guess_404', true ) ) {
            add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
        }
    }

    public static function enforce_permalinks( $value ) {
        // Enforce a consistent /%postname%/ structure
        return apply_filters( 'examplepress_mu_permalink_structure', '/%postname%/' );
    }

    public static function strip_capabilities( $allcaps, $caps, $args, $user ) {
        foreach ( self::STRIPPED_CAPS as $cap ) {
            if ( isset( $allcaps[ $cap ] ) ) {
                $allcaps[ $cap ] = false;
            }
        }
        return $allcaps;
    }
}
