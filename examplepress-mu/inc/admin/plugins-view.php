<?php
/**
 * Enhances the native plugins.php view with an ExamplePress tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamplePress_MU_Plugins_View {

    public static function init() {
        add_filter( 'views_plugins', [ __CLASS__, 'add_examplepress_tab' ] );
        add_filter( 'all_plugins', [ __CLASS__, 'filter_examplepress_plugins' ] );
    }

    /**
     * Identifies plugins that contain an examplepress.json manifest.
     */
    private static function is_examplepress_app( $plugin_file ) {
        $plugin_dir = dirname( $plugin_file );
        // The root plugin file might just be in the root directory
        $manifest_path = $plugin_dir === '.'
            ? WP_PLUGIN_DIR . '/examplepress.json'
            : WP_PLUGIN_DIR . '/' . $plugin_dir . '/examplepress.json';

        return file_exists( $manifest_path );
    }

    /**
     * Adds the "ExamplePress" tab to the top of the plugins screen.
     */
    public static function add_examplepress_tab( $views ) {
        $all_plugins = get_plugins();
        $ep_count    = 0;

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( self::is_examplepress_app( $plugin_file ) ) {
                $ep_count++;
            }
        }

        if ( $ep_count > 0 ) {
            $current = ( isset( $_REQUEST['plugin_status'] ) && $_REQUEST['plugin_status'] === 'examplepress' ) ? 'current' : '';
            $views['examplepress'] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                admin_url( 'plugins.php?plugin_status=examplepress' ),
                $current,
                esc_html__( 'ExamplePress Apps', 'examplepress-mu' ),
                $ep_count
            );
        }

        return $views;
    }

    /**
     * Filters the plugin table to show ONLY ExamplePress apps when the tab is clicked.
     */
    public static function filter_examplepress_plugins( $plugins ) {
        global $pagenow;

        if ( $pagenow === 'plugins.php' && isset( $_REQUEST['plugin_status'] ) && $_REQUEST['plugin_status'] === 'examplepress' ) {
            $filtered = [];
            foreach ( $plugins as $plugin_file => $plugin_data ) {
                if ( self::is_examplepress_app( $plugin_file ) ) {
                    $filtered[ $plugin_file ] = $plugin_data;
                }
            }
            return $filtered;
        }

        return $plugins;
    }
}

ExamplePress_MU_Plugins_View::init();
