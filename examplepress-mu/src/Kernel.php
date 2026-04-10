<?php

declare(strict_types=1);

namespace ExamplePress\MU;

use ExamplePress\MU\Config\ConfigManager;
use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Config\DependencyManager;
use ExamplePress\MU\Governance\PlatformPolicy;
use ExamplePress\MU\Governance\AppValidator;
use ExamplePress\MU\Governance\EditorGuard;
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
use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\ThemeUpdateProvider;
use ExamplePress\MU\Infrastructure\PrismContainer;
use ExamplePress\MU\Agent\GenerationJob;
use ExamplePress\MU\API\AgentController;
use ExamplePress\MU\API\AppsController;
use ExamplePress\MU\API\ConnectionsController;
use ExamplePress\MU\API\DemoController;
use ExamplePress\MU\API\ThemeUpdateController;
use ExamplePress\MU\API\UpdatesController;
use ExamplePress\MU\Editor\RepoController;
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
        AppUpdateProvider::init();
        ThemeUpdateProvider::init();
        Router::init();

        // ── REST API Controllers ────────────────────────────────
        // AppsController owns the canonical /apps route (AppRegistry-backed).
        add_action('rest_api_init', [AppsController::class, 'register']);
        add_action('rest_api_init', [ConnectionsController::class, 'register']);
        add_action('rest_api_init', [DemoController::class, 'register']);
        add_action('rest_api_init', [ThemeUpdateController::class, 'register']);
        add_action('rest_api_init', [UpdatesController::class, 'register']);
        add_action('rest_api_init', [RepoController::class, 'register']);
        add_action('rest_api_init', [Notifications::class, 'registerRoutes']);
        add_action('rest_api_init', [AgentController::class, 'register']);

        // ── Features ────────────────────────────────────────────
        add_action('after_setup_theme', [FeatureRegistry::class, 'bootAll']);

        // ── Generative UI Agent feature toggle ─────────────────
        // Surface the ep_agent_enabled option through the feature
        // filter so the settings UI can flip the flag without a
        // code deploy. Site-level filters can still override.
        add_filter('examplepress_mu_feature_agent', static function ($enabled) {
            $opt = \get_option('ep_agent_enabled', null);
            return $opt === null ? $enabled : (bool) $opt;
        }, 5);

        // ── Generative UI Agent runtime ────────────────────────
        // PrismContainer must boot AFTER FeatureRegistry::bootAll() so the
        // 'agent' feature flag is registered. Action Scheduler hook
        // is registered unconditionally — the handler short-circuits
        // gracefully when the feature is off.
        //
        // Both invocations are wrapped in defensive closures so that ANY
        // runtime failure inside the agent stack (missing vendor, parse
        // error in a Prism file, autoload miss, etc.) cannot fatal the
        // request. A failed boot logs and increments NO counter — the
        // kernel itself remains booted and the rest of the admin UI keeps
        // working. Without this guard, an agent runtime issue could push
        // the loader past its fatal-loop threshold and trigger quarantine.
        add_action('after_setup_theme', static function (): void {
            try {
                PrismContainer::boot();
            } catch (\Throwable $e) {
                error_log('ExamplePress: PrismContainer boot failed (caught): ' . $e->getMessage());
                add_filter('examplepress_mu_feature_agent', '__return_false', PHP_INT_MAX);
            }
        }, 20);

        add_action(GenerationJob::HOOK, static function (string $jobId): void {
            try {
                GenerationJob::handle($jobId);
            } catch (\Throwable $e) {
                error_log('ExamplePress: GenerationJob handler failed (caught): ' . $e->getMessage());
            }
        });

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
