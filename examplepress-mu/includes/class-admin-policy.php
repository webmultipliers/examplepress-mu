<?php
/**
 * ExamplePress MU — Admin Policy
 *
 * Fleet-wide admin customizations: dashboard widget cleanup
 * and post-lock window override.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Admin_Policy {

    public static function init() {
        // 1. Remove default dashboard widgets
        add_action( 'wp_dashboard_setup', [ __CLASS__, 'remove_dashboard_widgets' ] );

        // 2. Custom post-lock window (default 30 seconds)
        add_filter( 'wp_check_post_lock_window', [ __CLASS__, 'post_lock_window' ] );
    }

    public static function remove_dashboard_widgets() {
        if ( ! apply_filters( 'examplepress_mu_remove_dashboard_widgets', true ) ) {
            return;
        }

        remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
        remove_action( 'welcome_panel', 'wp_welcome_panel' );
        remove_meta_box( 'wc_admin_dashboard_setup', 'dashboard', 'normal' );
    }

    public static function post_lock_window( $interval ) {
        return apply_filters( 'examplepress_mu_post_lock_duration', 30 );
    }
}
