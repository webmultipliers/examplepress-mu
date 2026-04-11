<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\Updater;

/**
 * REST API for the Updates admin page — kernel status + recovery actions.
 *
 * The kernel updater is intentionally cron-driven (`Infrastructure\Updater`).
 * Manual install from inside the running kernel is a footgun: the page
 * response comes from the in-memory code, partial failures
 * leave the operator debugging from a half-broken kernel. So this
 * controller exposes ONLY:
 *
 *   GET  /updates/kernel/status            Read-only state for the UI.
 *   POST /updates/kernel/rollback          Promote `examplepress-mu.previous/`.
 *   POST /updates/kernel/clear-quarantine  Clear the loader's fatal-loop counter.
 *   POST /updates/apps/check-now           Force a companion-app update refresh.
 *
 * Both kernel POST routes are RECOVERY actions — they move you AWAY from
 * a broken kernel toward a known-good state.
 *
 * The /updates/apps/check-now route is a READ action — it triggers the
 * same code path the twicedaily cron runs, so operators don't have to
 * wait for the next scheduled tick to see fresh update data.
 *
 * Theme updates live in ThemeUpdateController under /theme-update/*.
 * Companion app updates publish into the native WordPress Plugins screen
 * via AppUpdateProvider — they intentionally have no install/activate
 * surface here, since WordPress's native plugin update UI handles that.
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

        register_rest_route(self::NS, '/updates/apps/check-now', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'appsCheckNow'],
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

    /**
     * POST /updates/apps/check-now
     *
     * Force-refresh the companion-app update cache. Runs the same code
     * path as the twicedaily background cron, but bypasses the cron/CLI
     * guard so an authenticated admin can refresh on demand instead of
     * waiting up to 12 hours for the next scheduled tick.
     *
     * The refresh is synchronous relative to the REST call — the caller
     * will block until all companion-app repos have been queried. That's
     * intentional: this endpoint is explicitly opt-in, and the caller
     * already accepted a loading state by clicking the button. It is
     * NOT the same surface as the update-badge injection path, which
     * remains strictly cache-read (never blocks admin renders).
     */
    public static function appsCheckNow(): \WP_REST_Response
    {
        AppUpdateProvider::checkNow();

        return rest_ensure_response([
            'success'    => true,
            'refreshed'  => true,
            'message'    => 'Companion app update cache refreshed.',
            'checked_at' => time(),
        ]);
    }
}
