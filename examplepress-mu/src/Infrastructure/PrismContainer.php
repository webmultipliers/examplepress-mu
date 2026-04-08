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
 * Boots a hand-rolled Laravel container with the absolute minimum
 * services Prism's text/structured paths require — no Acorn, no
 * illuminate/foundation, no symfony/console.
 *
 * Public API matches the old AcornBridge so callers don't change:
 *   - boot()         registers everything; gated by the 'agent' feature
 *   - isAvailable()  was the boot successful
 *   - lastError()    failure detail for UI surfacing
 */
final class PrismContainer
{
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
        } catch (\Throwable $e) {
            self::$error = $e->getMessage();
            error_log('ExamplePress PrismContainer boot failed: ' . $e->getMessage());

            // Auto-disable the agent feature for this request so the admin
            // UI does not advertise capabilities we cannot fulfill.
            add_filter('examplepress_mu_feature_agent', '__return_false', PHP_INT_MAX);
        }
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
