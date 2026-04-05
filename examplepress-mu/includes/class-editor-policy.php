<?php
/**
 * ExamplePress MU — Editor Policy
 *
 * Fleet-wide block editor governance: disables remote and core block
 * patterns, restricts available block types, and controls the
 * Openverse media category.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Editor_Policy {

    public static function init() {
        // 1. Disable remote block patterns
        add_filter( 'should_load_remote_block_patterns', [ __CLASS__, 'disable_remote_patterns' ] );

        // 2. Disable core block patterns
        add_action( 'init', [ __CLASS__, 'disable_core_patterns' ] );

        // 3. Restrict block types (opt-in via filter)
        add_filter( 'allowed_block_types_all', [ __CLASS__, 'restrict_block_types' ], 10, 2 );

        // 4. Openverse media category control
        add_filter( 'block_editor_settings_all', [ __CLASS__, 'openverse_setting' ] );
    }

    public static function disable_remote_patterns( $should_load ) {
        if ( apply_filters( 'examplepress_mu_disable_remote_block_patterns', true ) ) {
            return false;
        }
        return $should_load;
    }

    public static function disable_core_patterns() {
        if ( apply_filters( 'examplepress_mu_disable_core_block_patterns', true ) ) {
            remove_theme_support( 'core-block-patterns' );
        }
    }

    /**
     * Restrict allowed block types when a whitelist is provided.
     *
     * Returns all block types by default. To enable restrictions, filter
     * `examplepress_mu_allowed_block_types` to return an array of block names.
     */
    public static function restrict_block_types( $allowed_block_types, $block_editor_context ) {
        // Never restrict template editing
        if ( ! empty( $block_editor_context->post ) && $block_editor_context->post->post_type === 'wp_template' ) {
            return $allowed_block_types;
        }

        $types = apply_filters( 'examplepress_mu_allowed_block_types', null, $block_editor_context );

        if ( is_array( $types ) ) {
            return $types;
        }

        return $allowed_block_types;
    }

    public static function openverse_setting( $settings ) {
        $settings['enableOpenverseMediaCategory'] = apply_filters( 'examplepress_mu_enable_openverse', true );
        return $settings;
    }
}
