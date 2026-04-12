<?php

declare(strict_types=1);

namespace ExamplePress\MU;

use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Governance\PlatformPolicy;
use ExamplePress\MU\Governance\AppValidator;
use ExamplePress\MU\Governance\EditorGuard;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\Updater;
use ExamplePress\MU\Infrastructure\CliCommand;
use ExamplePress\MU\Infrastructure\RouteRegistry;
use ExamplePress\MU\Infrastructure\Router;
use ExamplePress\MU\Infrastructure\PluginManager;
use ExamplePress\MU\Infrastructure\Notifications;
use ExamplePress\MU\Infrastructure\AppUpdateProvider;
use ExamplePress\MU\Infrastructure\ThemeUpdateProvider;
use ExamplePress\MU\Infrastructure\PrismContainer;
use ExamplePress\MU\Agent\GenerationJob;
use ExamplePress\MU\API\AgentController;
use ExamplePress\MU\API\AppsController;
use ExamplePress\MU\API\ConnectionsController;
use ExamplePress\MU\API\DemoController;
use ExamplePress\MU\API\HealthController;
use ExamplePress\MU\API\ThemeUpdateController;
use ExamplePress\MU\API\UpdatesController;
use ExamplePress\MU\Editor\RepoController;
use ExamplePress\MU\Admin\MenuManager;
use ExamplePress\MU\Admin\AssetManager;
use ExamplePress\MU\Admin\PageController;

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
        // These are hard failures: governance / routing must boot or the
        // platform contract is broken. Let exceptions propagate so the
        // fatal-loop counter trips and the loader can quarantine or roll
        // back a genuinely-broken kernel.
        PlatformPolicy::init();
        AppValidator::init();
        EditorGuard::init();

        // ── Core routing ────────────────────────────────────────
        // AppRegistry (CPT registration) and Router (Blockstudio filter +
        // route helper function definition) are also hard requirements.
        AppRegistry::init();
        Router::init();

        // ── Non-critical subsystems ─────────────────────────────
        // Updater, AppUpdateProvider, ThemeUpdateProvider, PluginManager
        // are "nice to have" at boot time. A failure in any one of them
        // (missing cron, bad transient, GitHub API shape change, etc.)
        // should log and be skipped — not burn a fatal-loop attempt that
        // pushes the loader toward quarantine. Each gets its own guarded
        // init so one subsystem's failure can't suppress later subsystems.
        foreach ([
            'Updater'             => [Updater::class, 'init'],
            'PluginManager'       => [PluginManager::class, 'init'],
            'AppUpdateProvider'   => [AppUpdateProvider::class, 'init'],
            'ThemeUpdateProvider' => [ThemeUpdateProvider::class, 'init'],
        ] as $label => $callable) {
            try {
                $callable();
            } catch (\Throwable $e) {
                error_log("ExamplePress: {$label}::init failed (caught): " . $e->getMessage());
            }
        }

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
        add_action('rest_api_init', [HealthController::class, 'register']);

        // ── Features ────────────────────────────────────────────
        add_action('after_setup_theme', [FeatureRegistry::class, 'bootAll']);

        // ── Agent feature toggle ─────────────────
        // Surface the ep_agent_enabled option through the feature
        // filter so the settings UI can flip the flag without a
        // code deploy. Site-level filters can still override.
        //
        // The cooldown check also short-circuits the flag when a
        // prior PrismContainer::boot() failure is still within its
        // retry window (PrismContainer::COOLDOWN_SECONDS, currently
        // 1 hour). This replaces the old pattern of re-attempting
        // boot on every request and logging the same failure each
        // time. A successful boot or a manual Settings save clears
        // the cooldown.
        add_filter('examplepress_mu_feature_agent', static function ($enabled) {
            if (PrismContainer::isCooldownActive()) {
                return false;
            }
            $opt = \get_option('ep_agent_enabled', null);
            return $opt === null ? $enabled : (bool) $opt;
        }, 5);

        // ── Agent runtime ────────────────────────
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
                // Persist the failure so the next request's feature filter
                // short-circuits without retrying the broken boot path.
                // PrismContainer's own inner catch also writes this on
                // exceptions from within its try block — this outer
                // catch covers the pre-try guards (missing classes,
                // require of prism-helpers.php failing) that would
                // otherwise skip the inner handler.
                PrismContainer::markDisabled($e->getMessage());
            }
        }, 20);

        add_action(GenerationJob::HOOK_GENERATE, static function (string $jobId): void {
            try {
                GenerationJob::handleGenerate($jobId);
            } catch (\Throwable $e) {
                error_log('ExamplePress: GenerationJob::handleGenerate failed (caught): ' . $e->getMessage());
            }
        });

        add_action(GenerationJob::HOOK_COMMIT, static function (string $jobId): void {
            try {
                GenerationJob::handleCommit($jobId);
            } catch (\Throwable $e) {
                error_log('ExamplePress: GenerationJob::handleCommit failed (caught): ' . $e->getMessage());
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
