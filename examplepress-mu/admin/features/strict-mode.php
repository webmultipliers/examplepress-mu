<?php
/**
 * Strict Mode — MU-enhanced feature subpage.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'examplepress_mu_register_admin_pages', function() {
    ExamplePress_MU_Admin_Registry::add_subpage(
        'strict-mode',
        __( 'Strict Mode', 'examplepress-mu' ),
        'examplepress_mu_render_strict_mode_page'
    );
} );

function examplepress_mu_render_strict_mode_page() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Strict Mode Policies', 'examplepress-mu' ) . '</h1>';
    echo '<p>' . esc_html__( 'Configure fleet-wide constraints and permissions.', 'examplepress-mu' ) . '</p>';
    echo '</div>';
}
