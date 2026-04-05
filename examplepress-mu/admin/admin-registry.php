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
     *
     * Creates only the top-level menu shell. The theme's admin-registry
     * attaches its own subpages and fires examplepress_register_admin_pages
     * to let companion plugins add theirs.
     */
    public static function register_menus() {
        // Create the top-level menu. The theme populates subpages.
        add_menu_page(
            __( 'ExamplePress', 'examplepress-mu' ),
            'ExamplePress',
            'manage_options',
            EP_ADMIN_MENU_SLUG,
            [ __CLASS__, 'render_main_page' ],
            'dashicons-layout',
            55
        );

        // Attach any MU-registered subpages (kernel features).
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
