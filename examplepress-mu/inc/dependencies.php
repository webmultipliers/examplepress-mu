<?php
/**
 * ExamplePress MU — Dependency Checker
 *
 * Resolves the status of declared dependencies from examplepress.json.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve status for all declared dependencies.
 *
 * Returns the full dependency array enriched with runtime status.
 */
function examplepress_get_dependencies() {
    $config = examplepress_get_config();
    $deps   = $config['dependencies'] ?? [];

    if ( empty( $deps ) ) {
        return [];
    }

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $installed = get_plugins();
    $active    = array_map( 'plugin_basename', wp_get_active_and_valid_plugins() );
    $result    = [];

    foreach ( $deps as $dep ) {
        $slug       = $dep['slug'] ?? '';
        $check_type = $dep['check_type'] ?? 'plugin';
        $target     = $dep['check_target'] ?? $slug;

        if ( empty( $slug ) ) {
            continue;
        }

        $source_type = $dep['source']['type'] ?? 'wporg';
        $source_url  = $dep['source']['url'] ?? '';
        $url         = $source_url;
        if ( ! $url && $source_type === 'wporg' ) {
            $url = "https://wordpress.org/plugins/{$slug}/";
        }

        $item = [
            'slug'      => $slug,
            'name'      => $dep['name'] ?? $slug,
            'tier'      => $dep['tier'] ?? 'optional',
            'pricing'   => $dep['pricing'] ?? 'free',
            'cloud'     => ! empty( $dep['cloud_dependent'] ),
            'source'    => $source_type,
            'url'       => $url,
            'checkType' => $check_type,
            'status'    => 'missing',
        ];

        if ( $check_type === 'class' ) {
            $item['status'] = class_exists( $target ) ? 'active' : 'missing';
        } elseif ( $check_type === 'function' ) {
            $item['status'] = function_exists( $target ) ? 'active' : 'missing';
        } else {
            foreach ( $installed as $file => $data ) {
                if ( str_starts_with( $file, $target . '/' ) || $file === $target . '.php' ) {
                    $item['name']   = $data['Name'];
                    $item['status'] = in_array( $file, $active, true ) ? 'active' : 'installed';
                    if ( ! empty( $data['PluginURI'] ) && ! $source_url ) {
                        $item['url'] = $data['PluginURI'];
                    }
                    break;
                }
            }
        }

        $fallback_slug = $dep['fallback_slug'] ?? '';
        if ( $fallback_slug && $item['status'] !== 'active' ) {
            $fb_status = 'missing';
            foreach ( $installed as $file => $data ) {
                if ( str_starts_with( $file, $fallback_slug . '/' ) || $file === $fallback_slug . '.php' ) {
                    $fb_status = in_array( $file, $active, true ) ? 'active' : 'installed';
                    break;
                }
            }
            $item['fallback'] = [
                'slug'   => $fallback_slug,
                'status' => $fb_status,
            ];
            if ( $item['status'] === 'missing' && $fb_status === 'active' ) {
                $item['status'] = 'fallback';
            }
        }

        $result[] = $item;
    }

    return $result;
}
