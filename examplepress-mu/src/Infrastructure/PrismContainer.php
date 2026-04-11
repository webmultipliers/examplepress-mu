<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

use ExamplePress\MU\Config\FeatureRegistry;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Prism\Prism\Prism;
use Prism\Prism\PrismManager;

/**
 * Boots a minimal Laravel container with only the services Prism's
 * text/structured paths require.
 *
 * Public API:
 *   - boot()         registers everything; gated by the 'agent' feature
 *   - isAvailable()  was the boot successful
 *   - lastError()    failure detail for UI surfacing
 */
final class PrismContainer
{
    /**
     * Option key: unix timestamp until which the agent runtime is
     * cooldown-disabled following a boot failure. 0 / unset = not
     * disabled. The Kernel feature filter reads this to short-circuit
     * the 'agent' feature flag without reattempting boot on every
     * request while the cooldown is active.
     */
    public const DISABLED_UNTIL_OPTION = 'ep_agent_disabled_until';

    /**
     * Option key: human-readable reason for the last cooldown disable.
     * Surfaced to the Settings page so operators know *why* the agent
     * is off without having to read debug.log.
     */
    public const DISABLED_REASON_OPTION = 'ep_agent_disabled_reason';

    /**
     * Cooldown window (seconds) applied after a boot failure. Chosen
     * to balance "don't hammer a broken subsystem every request" with
     * "recover automatically from transient blips" — an hour is long
     * enough to survive a deploy window but short enough that a
     * legitimately-fixed issue clears itself within a page refresh or
     * two. Operators can force an earlier retry by re-saving agent
     * settings (which calls clearDisabled()).
     */
    private const COOLDOWN_SECONDS = 3600;

    private static bool $booted = false;
    private static bool $available = false;
    private static ?string $error = null;
    private static ?MinimalApplication $app = null;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!FeatureRegistry::enabled('agent')) {
            return;
        }

        if (!class_exists(Prism::class) || !class_exists(PrismManager::class)) {
            self::$error = 'Prism is not installed. Run composer install.';
            return;
        }

        try {
            // 0. Load the global helpers Prism reaches for (config(), app(), event()).
            //    These normally live in illuminate/foundation, which we avoid shipping.
            require_once __DIR__ . '/prism-helpers.php';

            // 1. Build the application shim and register it as the global container
            //    so the global app() / config() helpers resolve here.
            $app = new MinimalApplication();
            Container::setInstance($app);
            $app->instance('app', $app);
            $app->instance(Container::class, $app);
            $app->instance(\Illuminate\Contracts\Container\Container::class, $app);
            $app->instance(\Illuminate\Contracts\Foundation\Application::class, $app);

            // 2. Config repository — seed from our prism config file. The
            //    config() helper resolves $app['config']->get(...).
            $configFile = EXAMPLEPRESS_MU_DIR . '/config/prism.php';
            $configData = is_readable($configFile) ? (array) require $configFile : [];
            $config = new ConfigRepository(['prism' => $configData]);
            $app->instance('config', $config);
            $app->instance(\Illuminate\Contracts\Config\Repository::class, $config);

            // 3. Events dispatcher — Http\Client\Factory needs one in its constructor.
            $events = new Dispatcher($app);
            $app->instance('events', $events);
            $app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);

            // 4. HTTP client factory — backs both the Http facade and direct
            //    Http\Client\Factory injections inside Prism providers.
            $http = new HttpFactory($events);
            $app->instance('http', $http);
            $app->instance(HttpFactory::class, $http);

            // 5. Prism bindings — replicate PrismServiceProvider::register() inline
            //    without ever instantiating the provider itself.
            $app->singleton(
                PrismManager::class,
                static fn ($container): PrismManager => new PrismManager($container)
            );
            $app->alias(PrismManager::class, 'prism-manager');

            $app->singleton(Prism::class, static fn (): Prism => new Prism());
            $app->alias(Prism::class, 'prism');

            // 6. Wire up facades. Single call sets the resolver root for every
            //    Illuminate\Support\Facades\* class — including Prism's facade
            //    and Http::, App::, etc.
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($app);

            self::$app = $app;
            self::$available = true;

            // Successful boot implicitly clears any prior cooldown —
            // whatever was wrong has resolved itself.
            self::clearDisabled();
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            error_log('ExamplePress PrismContainer boot failed: ' . $e->getMessage());

            // Persist the disable across requests with a bounded cooldown
            // so the next page load doesn't retry a known-broken boot path.
            self::markDisabled($e->getMessage());
        }
    }

    /**
     * Record an agent-runtime disable with a bounded cooldown window.
     * Safe to call multiple times; the cooldown restarts from "now"
     * on each call.
     */
    public static function markDisabled(string $reason): void
    {
        if (!\function_exists('update_option')) {
            return;
        }
        \update_option(self::DISABLED_UNTIL_OPTION, time() + self::COOLDOWN_SECONDS, false);
        \update_option(self::DISABLED_REASON_OPTION, $reason, false);
    }

    /**
     * Clear a persisted agent-runtime disable. Called on successful
     * boot and on manual reset from the Settings UI (saving the agent
     * form implies operator intent to retry).
     */
    public static function clearDisabled(): void
    {
        if (!\function_exists('delete_option')) {
            return;
        }
        \delete_option(self::DISABLED_UNTIL_OPTION);
        \delete_option(self::DISABLED_REASON_OPTION);
    }

    /**
     * True if the agent runtime is currently cooldown-disabled.
     * Used by the Kernel feature filter to short-circuit.
     */
    public static function isCooldownActive(): bool
    {
        if (!\function_exists('get_option')) {
            return false;
        }
        $until = (int) \get_option(self::DISABLED_UNTIL_OPTION, 0);
        return $until > time();
    }

    /**
     * Unix timestamp remaining in the cooldown, or 0 if inactive.
     * Exposed for DataProvider / UI surfacing.
     */
    public static function cooldownUntil(): int
    {
        if (!\function_exists('get_option')) {
            return 0;
        }
        $until = (int) \get_option(self::DISABLED_UNTIL_OPTION, 0);
        return $until > time() ? $until : 0;
    }

    /**
     * Persisted failure reason from the last boot attempt, if any.
     */
    public static function disabledReason(): ?string
    {
        if (!\function_exists('get_option')) {
            return null;
        }
        $reason = \get_option(self::DISABLED_REASON_OPTION, '');
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public static function isAvailable(): bool
    {
        return self::$available;
    }

    public static function lastError(): ?string
    {
        return self::$error;
    }

    public static function app(): ?MinimalApplication
    {
        return self::$app;
    }
}
