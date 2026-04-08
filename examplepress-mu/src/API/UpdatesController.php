<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\Updater;

/**
 * REST API for the unified Updates admin page.
 *
 * Exposes two update surfaces that the Updates page needs:
 *
 *   Kernel — the MU self-updater (`Infrastructure\Updater`). Reads cached
 *            remote version, schedule/throttle state, quarantine flags, and
 *            drives manual check / update / rollback / clear-quarantine.
 *
 *   Apps   — companion app updates published by AppUpdateProvider. Read-only
 *            summary + cache flush.
 *
 * The theme update surface lives in ThemeUpdateController under the
 * `/theme-update/*` namespace; this controller does NOT duplicate those
 * endpoints.
 */
final class UpdatesController
{
    private const NS = 'examplepress-mu/v1';

    public static function register(): void
    {
        // ── Kernel ──────────────────────────────────────────────────

        register_rest_route(self::NS, '/updates/kernel/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'kernelStatus'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, '/updates/kernel/check', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'kernelCheck'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, '/updates/kernel/update', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'kernelUpdate'],
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

        // ── Apps ────────────────────────────────────────────────────

        register_rest_route(self::NS, '/updates/apps/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'appsStatus'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);

        register_rest_route(self::NS, '/updates/apps/check', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'appsCheck'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('update_core') || current_user_can('manage_options');
    }

    // ── Kernel handlers ─────────────────────────────────────────────

    public static function kernelStatus(): \WP_REST_Response
    {
        return rest_ensure_response(Updater::getStatus());
    }

    public static function kernelCheck(): \WP_REST_Response|\WP_Error
    {
        $result = Updater::forceCheck();
        if (!$result['success']) {
            return new \WP_Error('ep_kernel_check_failed', $result['message'] ?? 'Check failed.', [
                'status' => 502,
                'kernel' => $result['status'],
            ]);
        }
        return rest_ensure_response($result);
    }

    public static function kernelUpdate(): \WP_REST_Response|\WP_Error
    {
        $result = Updater::forceUpdate();
        if (!$result['success']) {
            return new \WP_Error('ep_kernel_update_failed', $result['message'] ?? 'Update failed.', [
                'status' => 500,
                'kernel' => $result['status'],
            ]);
        }
        return rest_ensure_response($result);
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

    // ── Apps handlers ───────────────────────────────────────────────

    public static function appsStatus(): \WP_REST_Response
    {
        return rest_ensure_response([
            'apps' => self::shapeAppsPayload(AppUpdateProvider::getUpdateData()),
        ]);
    }

    public static function appsCheck(): \WP_REST_Response
    {
        AppUpdateProvider::flush();
        return rest_ensure_response([
            'apps' => self::shapeAppsPayload(AppUpdateProvider::getUpdateData()),
        ]);
    }

    /**
     * Normalize AppUpdateProvider's internal map into a list the JS
     * can iterate directly, stripping fields the UI doesn't need.
     *
     * @param array<string, array<string, mixed>> $raw
     * @return array<int, array<string, mixed>>
     */
    private static function shapeAppsPayload(array $raw): array
    {
        $out = [];
        foreach ($raw as $pluginFile => $row) {
            $out[] = [
                'plugin_file'      => $pluginFile,
                'slug'             => $row['slug'] ?? '',
                'name'             => $row['name'] ?? $row['slug'] ?? $pluginFile,
                'description'      => $row['description'] ?? '',
                'owner_repo'       => $row['owner_repo'] ?? '',
                'current_version'  => $row['current_version'] ?? '',
                'new_version'      => $row['new_version'] ?? '',
                'update_available' => !empty($row['update_available']),
                'html_url'         => $row['html_url'] ?? '',
                'release_url'      => $row['release_url'] ?? '',
                'changelog'        => $row['changelog'] ?? '',
            ];
        }
        return $out;
    }
}
