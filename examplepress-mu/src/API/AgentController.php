<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

/**
 * Agent REST API — lightweight endpoint for querying active ExamplePress apps.
 */
final class AgentController
{
    public static function register(): void
    {
        register_rest_route('examplepress-mu/v1', '/apps', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'listApps'],
            'permission_callback' => function (): bool {
                return current_user_can('manage_options');
            },
        ]);
    }

    public static function listApps(): \WP_REST_Response
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = get_option('active_plugins', []);
        $payload = [];

        foreach ($active_plugins as $plugin_file) {
            $plugin_dir    = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
            $manifest_path = $plugin_dir . '/examplepress.json';

            if (!file_exists($manifest_path)) {
                continue;
            }

            $manifest = json_decode(file_get_contents($manifest_path), true);

            if (!$manifest) {
                continue;
            }

            $payload[] = [
                'slug'        => $manifest['slug'] ?? '',
                'name'        => $manifest['name'] ?? '',
                'version'     => $manifest['version'] ?? '',
                'permissions' => $manifest['permissions'] ?? [],
                'status'      => 'validated_and_active',
            ];
        }

        return rest_ensure_response([
            'kernel_version' => EXAMPLEPRESS_MU_VERSION,
            'active_apps'    => $payload,
        ]);
    }
}
