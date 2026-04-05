<?php

declare(strict_types=1);

namespace ExamplePress\MU;

use ExamplePress\MU\Config\ConfigManager;
use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Config\DependencyManager;
use ExamplePress\MU\Governance\PlatformPolicy;
use ExamplePress\MU\Governance\AppValidator;
use ExamplePress\MU\Governance\EditorGuard;
use ExamplePress\MU\Governance\AdminPolicy;
use ExamplePress\MU\Governance\EditorPolicy;
use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\Scaffolder;
use ExamplePress\MU\Infrastructure\Updater;
use ExamplePress\MU\Infrastructure\CliCommand;
use ExamplePress\MU\Infrastructure\RouteRegistry;
use ExamplePress\MU\Infrastructure\Router;
use ExamplePress\MU\Infrastructure\GitHub;
use ExamplePress\MU\Infrastructure\PluginManager;
use ExamplePress\MU\Infrastructure\Helpers;
use ExamplePress\MU\Infrastructure\Notifications;
use ExamplePress\MU\API\AgentController;
use ExamplePress\MU\API\AppsController;
use ExamplePress\MU\API\ConnectionsController;
use ExamplePress\MU\API\DemoController;
use ExamplePress\MU\API\UpdaterController;
use ExamplePress\MU\API\FilesystemController;
use ExamplePress\MU\Admin\MenuManager;
use ExamplePress\MU\Admin\AssetManager;
use ExamplePress\MU\Admin\PageController;
use ExamplePress\MU\Admin\DataProvider;

final class Kernel
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // ── Governance (non-toggleable, MU-enforced) ────────────
        PlatformPolicy::init();
        AppValidator::init();
        EditorGuard::init();

        // ── Updater (WP-Cron based) ────────────────────────────
        Updater::init();

        // ── Infrastructure ──────────────────────────────────────
        AppRegistry::init();
        PluginManager::init();

        // ── REST API Controllers ────────────────────────────────
        add_action('rest_api_init', [AgentController::class, 'register']);
        add_action('rest_api_init', [AppsController::class, 'register']);
        add_action('rest_api_init', [ConnectionsController::class, 'register']);
        add_action('rest_api_init', [DemoController::class, 'register']);
        add_action('rest_api_init', [UpdaterController::class, 'register']);
        add_action('rest_api_init', [FilesystemController::class, 'register']);
        add_action('rest_api_init', [Notifications::class, 'registerRoutes']);

        // ── Features ────────────────────────────────────────────
        add_action('after_setup_theme', [FeatureRegistry::class, 'bootAll']);

        // ── Admin ───────────────────────────────────────────────
        if (is_admin()) {
            MenuManager::init();
            AssetManager::init();
            PageController::init();
            ConnectionsController::initCallbacks();

            // Theme immutability — warn if unexpected files land in the theme.
            add_action('admin_notices', static function (): void {
                $unexpected = RouteRegistry::checkThemeImmutability();
                if (empty($unexpected)) {
                    return;
                }
                printf(
                    '<div class="notice notice-warning"><p><strong>ExamplePress:</strong> '
                    . 'Unexpected files detected in the theme directory: <code>%s</code>. '
                    . 'The theme is an immutable foundation — add custom templates in a companion plugin instead.</p></div>',
                    esc_html(implode(', ', $unexpected))
                );
            });
        }

        // ── WP-CLI ──────────────────────────────────────────────
        if (defined('WP_CLI') && \WP_CLI) {
            CliCommand::register();
        }
    }
}
