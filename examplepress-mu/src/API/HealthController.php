<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\PrismContainer;
use ExamplePress\MU\Infrastructure\RouteRegistry;
use ExamplePress\MU\Infrastructure\Router;
use ExamplePress\MU\Infrastructure\ThemeUpdateProvider;
use ExamplePress\MU\Infrastructure\Updater;

/**
 * Aggregate health endpoint — one REST call returns a punch list of every
 * subsystem's state so the admin dashboard doesn't have to make five
 * separate round trips to paint the initial screen.
 *
 * GET /wp-json/examplepress-mu/v1/health
 *
 * This controller intentionally does zero filesystem I/O and zero network
 * calls of its own — it delegates to each subsystem's existing status
 * helpers. Every field in the response is derivable from data the kernel
 * has already computed for other code paths.
 */
final class HealthController
{
    public static function register(): void
    {
        register_rest_route('examplepress-mu/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getHealth'],
            'permission_callback' => [self::class, 'permissionCheck'],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @return \WP_REST_Response
     */
    public static function getHealth(): \WP_REST_Response
    {
        $kernelStatus = self::kernelStatus();
        $updatesStatus = self::updatesStatus();
        $routingStatus = self::routingStatus();
        $appsStatus    = self::appsStatus();
        $agentStatus   = self::agentStatus();

        // Aggregate severity. Order matters: error > warning > ok.
        $severity = 'ok';

        $isError = $kernelStatus['quarantined']
            || $kernelStatus['boot_attempts_elevated']
            || $updatesStatus['theme']['kernel_api_block'] !== null;

        $isWarning = $routingStatus['conflicts_count'] > 0
            || $routingStatus['registration_conflicts_count'] > 0
            || $updatesStatus['kernel']['coldstart_error'] !== ''
            || $agentStatus['cooldown_active'];

        if ($isError) {
            $severity = 'error';
        } elseif ($isWarning) {
            $severity = 'warning';
        }

        return rest_ensure_response([
            'status'  => $severity,
            'kernel'  => $kernelStatus,
            'updates' => $updatesStatus,
            'routing' => $routingStatus,
            'apps'    => $appsStatus,
            'agent'   => $agentStatus,
            'time'    => time(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function kernelStatus(): array
    {
        $updaterStatus = Updater::getStatus();

        return [
            'version'                => defined('EXAMPLEPRESS_MU_VERSION') ? EXAMPLEPRESS_MU_VERSION : '0.0.0',
            'api_version'            => (class_exists(Router::class) && defined(Router::class . '::API_VERSION'))
                ? (int) Router::API_VERSION
                : 0,
            'quarantined'            => !empty($updaterStatus['quarantined']),
            'boot_attempts'          => (int) ($updaterStatus['boot_attempts'] ?? 0),
            'boot_attempts_elevated' => (int) ($updaterStatus['boot_attempts'] ?? 0) >= 3,
            'previous_available'     => !empty($updaterStatus['previous_version_available']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function updatesStatus(): array
    {
        $kernel = Updater::getStatus();
        $theme  = ThemeUpdateProvider::getStatus();

        return [
            'kernel' => [
                'current_version'   => (string) ($kernel['current_version'] ?? ''),
                'remote_version'    => $kernel['remote_version'] ?? null,
                'update_available'  => !empty($kernel['update_available']),
                'repo'              => (string) ($kernel['repo'] ?? ''),
                'repo_source'       => (string) ($kernel['repo_source'] ?? 'default'),
                'coldstart_error'   => (string) ($kernel['coldstart_error'] ?? ''),
            ],
            'theme' => [
                'current_version'     => $theme['current_version'] ?? null,
                'latest_version'      => $theme['latest_version'] ?? null,
                'update_available'    => !empty($theme['update_available']),
                'channel'             => (string) ($theme['channel'] ?? ''),
                'repo'                => (string) ($theme['repo'] ?? ''),
                'repo_source'         => (string) ($theme['repo_source'] ?? 'default'),
                'kernel_api'          => (int) ($theme['kernel_api'] ?? 0),
                'manifest_kernel_api' => $theme['manifest_kernel_api'] ?? null,
                'kernel_api_block'    => $theme['kernel_api_block'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function routingStatus(): array
    {
        return [
            'has_origins'                  => RouteRegistry::hasOrigins(),
            'origins_count'                => count(RouteRegistry::map()),
            'conflicts_count'              => count(RouteRegistry::detectConflicts()),
            'registration_conflicts_count' => count(RouteRegistry::registrationConflicts()),
            'namespaces'                   => RouteRegistry::namespaces(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function appsStatus(): array
    {
        $apps = AppDiscovery::scan();
        $active = 0;
        $connected = 0;
        foreach ($apps as $app) {
            if (!empty($app['active'])) {
                $active++;
            }
            if (($app['status'] ?? '') === 'connected') {
                $connected++;
            }
        }
        return [
            'total'     => count($apps),
            'active'    => $active,
            'connected' => $connected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function agentStatus(): array
    {
        $enabled = FeatureRegistry::enabled('agent');

        return [
            'enabled'             => $enabled,
            'container_available' => class_exists(PrismContainer::class)
                ? PrismContainer::isAvailable()
                : false,
            'cooldown_active'     => class_exists(PrismContainer::class)
                ? PrismContainer::isCooldownActive()
                : false,
            'last_error'          => class_exists(PrismContainer::class)
                ? PrismContainer::lastError()
                : null,
        ];
    }
}
