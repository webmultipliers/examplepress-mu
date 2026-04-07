<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

use ExamplePress\MU\Config\DependencyManager;

/**
 * Notification Hub — aggregates system warnings into structured notifications.
 */
final class Notifications
{
    public static function registerRoutes(): void
    {
        register_rest_route('examplepress-mu/v1', '/notifications/archive', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleArchive'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => 'Notification ID to archive or restore.',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn($value): bool => is_string($value) && strlen($value) > 0 && strlen($value) <= 200,
                ],
                'action' => [
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => 'Action to perform.',
                    'enum'              => ['archive', 'restore'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public static function handleArchive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = sanitize_text_field($request->get_param('id'));
        $action = sanitize_text_field($request->get_param('action'));

        if (empty($id) || !in_array($action, ['archive', 'restore'], true)) {
            return new \WP_Error('invalid_request', 'Missing id or invalid action.', ['status' => 400]);
        }

        $userId = get_current_user_id();
        $archived = get_user_meta($userId, 'ep_archived_notifications', true);
        $archived = is_array($archived) ? $archived : [];

        if ($action === 'archive' && !in_array($id, $archived, true)) {
            $archived[] = $id;
        } elseif ($action === 'restore') {
            $archived = array_values(array_diff($archived, [$id]));
        }

        update_user_meta($userId, 'ep_archived_notifications', $archived);

        return rest_ensure_response(['success' => true, 'archived' => $archived]);
    }

    /**
     * Get the current user's archived notification IDs.
     *
     * @return string[]
     */
    public static function getArchived(): array
    {
        $meta = get_user_meta(get_current_user_id(), 'ep_archived_notifications', true);
        return is_array($meta) ? $meta : [];
    }

    /**
     * Gather all active system notifications.
     *
     * @return array<int, array{id: string, type: string, title: string, message: string}>
     */
    public static function gather(): array
    {
        $notifications = [];

        // 1. Missing required dependencies.
        $deps = DependencyManager::resolve();
        foreach ($deps as $dep) {
            if (($dep['tier'] ?? '') !== 'required') {
                continue;
            }
            if ($dep['status'] === 'active') {
                continue;
            }

            $msg = sprintf(
                '%s is required but %s.',
                $dep['name'],
                $dep['status'] === 'installed' ? 'not activated' : 'not installed'
            );
            if ($dep['status'] === 'fallback') {
                $msg = sprintf(
                    '%s is using the free alternative (%s). The full version is recommended.',
                    $dep['name'],
                    $dep['fallback']['slug'] ?? ''
                );
            }
            if ($dep['status'] === 'outdated') {
                $msg = sprintf(
                    '%s %s is installed but version %s or newer is required. Update the plugin to match the version pinned by ExamplePress.',
                    $dep['name'],
                    $dep['installedVersion'] ?? '?',
                    $dep['requiredVersion'] ?? '?'
                );
            }

            $title = $dep['status'] === 'outdated' ? 'Outdated Dependency' : 'Missing Dependency';
            $type = ($dep['status'] === 'fallback') ? 'warn' : 'error';

            $notifications[] = [
                'id'      => 'dep_' . $dep['slug'],
                'type'    => $type,
                'title'   => $title,
                'message' => $msg,
            ];
        }

        // 2. Developer mode warning.
        if (defined('EP_DEV_MODE') && EP_DEV_MODE) {
            $notifications[] = [
                'id'      => 'dev_mode',
                'type'    => 'warn',
                'title'   => 'Developer Mode Active',
                'message' => 'All template guards are bypassed. Remove EP_DEV_MODE from wp-config.php before deploying to production.',
            ];
        }

        // 3. No route origins registered.
        if (!RouteRegistry::hasOrigins()) {
            $notifications[] = [
                'id'      => 'no_route_origins',
                'type'    => 'info',
                'title'   => 'No Routing Configured',
                'message' => 'No companion plugin has registered route origins. Use \\ExamplePress\\MU\\Infrastructure\\RouteRegistry::register() in your companion plugin.',
            ];
        }

        return $notifications;
    }
}
