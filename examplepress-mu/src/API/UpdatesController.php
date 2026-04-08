<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\Updater;

/**
 * REST API for the Updates admin page — kernel status + recovery actions.
 *
 * The kernel updater is intentionally cron-driven (`Infrastructure\Updater`).
 * Manual install from inside the running kernel is a footgun: the page
 * response comes from the old code already in memory, partial failures
 * leave the operator debugging from a half-broken kernel. So this
 * controller exposes ONLY:
 *
 *   GET  /updates/kernel/status            Read-only state for the UI.
 *   POST /updates/kernel/rollback          Promote `examplepress-mu.previous/`.
 *   POST /updates/kernel/clear-quarantine  Clear the loader's fatal-loop counter.
 *
 * Both POST routes are RECOVERY actions — they move you AWAY from a
 * broken kernel toward a known-good state.
 *
 * Theme updates live in ThemeUpdateController under /theme-update/*.
 * Companion app updates publish into the native WordPress Plugins screen
 * via AppUpdateProvider — they intentionally have no surface here.
 */
final class UpdatesController
{
    private const NS = 'examplepress-mu/v1';

    public static function register(): void
    {
        register_rest_route(self::NS, '/updates/kernel/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'kernelStatus'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, '/updates/kernel/rollback', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'kernelRollback'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, '/updates/kernel/clear-quarantine', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'kernelClearQuarantine'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('update_core') || current_user_can('manage_options');
    }

    public static function kernelStatus(): \WP_REST_Response
    {
        return rest_ensure_response(Updater::getStatus());
    }

    public static function kernelRollback(): \WP_REST_Response|\WP_Error
    {
        $result = Updater::rollbackToPrevious();
        if (!$result['success']) {
            return new \WP_Error('ep_kernel_rollback_failed', $result['message'] ?? 'Rollback failed.', [
                'status' => 409,
                'kernel' => $result['status'],
            ]);
        }
        return rest_ensure_response($result);
    }

    public static function kernelClearQuarantine(): \WP_REST_Response
    {
        return rest_ensure_response(Updater::clearQuarantine());
    }
}
