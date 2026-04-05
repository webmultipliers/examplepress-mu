<?php
/**
 * Extensible Admin Registry for ExamplePress MU.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Admin_Registry {

    private static $subpages = [];

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
    }

    /**
     * API for adding a subpage to the ExamplePress menu.
     */
    public static function add_subpage( $slug, $title, $callback, $capability = 'manage_options' ) {
        self::$subpages[ $slug ] = [
            'title'      => $title,
            'callback'   => $callback,
            'capability' => $capability,
        ];
    }

    /**
     * Builds the menu structure in WordPress.
     */
    public static function register_menus() {
        // 1. Create the Main Top-Level Menu
        add_menu_page(
            __( 'ExamplePress Settings', 'examplepress-mu' ),
            'ExamplePress',
            'manage_options',
            EP_ADMIN_MENU_SLUG, // Use the shared constant
            [ __CLASS__, 'render_main_page' ],
            'dashicons-layout',
            55
        );

        // Rename the first subpage to "Dashboard"
        add_submenu_page(
            EP_ADMIN_MENU_SLUG,
            __( 'Dashboard', 'examplepress-mu' ),
            __( 'Dashboard', 'examplepress-mu' ),
            'manage_options',
            EP_ADMIN_MENU_SLUG,
            [ __CLASS__, 'render_main_page' ]
        );

        // 2. Fire the unified action to let the Theme and Companion Apps register subpages
        do_action( 'examplepress_register_admin_pages' );

        // 3. Loop through MU-registered subpages and attach them
        foreach ( self::$subpages as $slug => $page ) {
            add_submenu_page(
                EP_ADMIN_MENU_SLUG,
                $page['title'],
                $page['title'],
                $page['capability'],
                EP_ADMIN_MENU_SLUG . '-' . $slug,
                $page['callback']
            );
        }
    }

    /**
     * Callback for the main ExamplePress page.
     */
    public static function render_main_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'ExamplePress Kernel', 'examplepress-mu' ) . '</h1>';
        echo '<p>' . esc_html__( 'Platform governance and routing engine active.', 'examplepress-mu' ) . '</p>';
        echo '</div>';
    }
}

ExamplePress_MU_Admin_Registry::init();
