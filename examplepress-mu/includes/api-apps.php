<?php
/**
 * ExamplePress MU — Agent REST API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function() {
    register_rest_route( 'examplepress-mu/v1', '/apps', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        },
        'callback'            => function() {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $active_plugins = get_option( 'active_plugins', [] );
            $payload = [];

            foreach ( $active_plugins as $plugin_file ) {
                $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
                $manifest_path = $plugin_dir . '/examplepress.json';

                if ( file_exists( $manifest_path ) ) {
                    $manifest = json_decode( file_get_contents( $manifest_path ), true );
                    if ( $manifest ) {
                        $payload[] = [
                            'slug'        => $manifest['slug'] ?? '',
                            'name'        => $manifest['name'] ?? '',
                            'version'     => $manifest['version'] ?? '',
                            'permissions' => $manifest['permissions'] ?? [],
                            'status'      => 'validated_and_active'
                        ];
                    }
                }
            }

            return rest_ensure_response( [
                'kernel_version' => EXAMPLEPRESS_MU_VERSION,
                'active_apps'    => $payload,
            ] );
        }
    ] );
} );
