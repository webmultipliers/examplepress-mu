<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;

/**
 * Tiny shim that satisfies Illuminate\Contracts\Foundation\Application
 * for Prism's PrismManager constructor — without dragging in
 * illuminate/foundation (and its symfony/console + http-foundation chain).
 *
 * Inherits real bind/make/singleton/alias from Illuminate\Container\Container.
 * Every Foundation contract method is a stub returning sane defaults; Prism
 * never calls them in the text/structured paths we use (verified Phase 1).
 */
final class MinimalApplication extends Container implements ApplicationContract
{
    /** @var array<int,callable> */
    private array $terminatingCallbacks = [];

    public function version(): string
    {
        return '12.99.99-examplepress-shim';
    }

    public function basePath($path = ''): string
    {
        return EXAMPLEPRESS_MU_DIR . ($path !== '' ? '/' . ltrim((string) $path, '/') : '');
    }

    public function bootstrapPath($path = ''): string
    {
        return $this->basePath('bootstrap' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function configPath($path = ''): string
    {
        return $this->basePath('config' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function databasePath($path = ''): string
    {
        return $this->basePath('database' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function langPath($path = ''): string
    {
        return $this->basePath('lang' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function publicPath($path = ''): string
    {
        return $this->basePath('public' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function resourcePath($path = ''): string
    {
        return $this->basePath('resources' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function storagePath($path = ''): string
    {
        return $this->basePath('storage' . ($path !== '' ? '/' . ltrim((string) $path, '/') : ''));
    }

    public function environment(...$environments): string|bool
    {
        if (empty($environments)) {
            return 'production';
        }
        return in_array('production', is_array($environments[0]) ? $environments[0] : $environments, true);
    }

    public function runningInConsole(): bool
    {
        return defined('WP_CLI') && constant('WP_CLI');
    }

    public function runningUnitTests(): bool
    {
        return false;
    }

    public function hasDebugModeEnabled(): bool
    {
        return defined('WP_DEBUG') && constant('WP_DEBUG');
    }

    public function maintenanceMode(): mixed
    {
        return null;
    }

    public function isDownForMaintenance(): bool
    {
        return false;
    }

    public function registerConfiguredProviders(): void
    {
        // no-op
    }

    public function register($provider, $force = false): mixed
    {
        // no-op; Prism's service provider is bypassed entirely.
        return $provider;
    }

    public function registerDeferredProvider($provider, $service = null): void
    {
        // no-op
    }

    public function resolveProvider($provider): mixed
    {
        return null;
    }

    public function boot(): void
    {
        // no-op
    }

    public function booting($callback): void
    {
        // no-op
    }

    public function booted($callback): void
    {
        // no-op
    }

    public function bootstrapWith(array $bootstrappers): void
    {
        // no-op
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function getNamespace(): string
    {
        return 'ExamplePress\\MU\\';
    }

    public function getProviders($provider): array
    {
        return [];
    }

    public function hasBeenBootstrapped(): bool
    {
        return true;
    }

    public function loadDeferredProviders(): void
    {
        // no-op
    }

    public function setLocale($locale): void
    {
        // no-op
    }

    public function shouldSkipMiddleware(): bool
    {
        return true;
    }

    public function terminating($callback): self
    {
        $this->terminatingCallbacks[] = $callback;
        return $this;
    }

    public function terminate(): void
    {
        foreach ($this->terminatingCallbacks as $cb) {
            if (is_callable($cb)) {
                $cb();
            }
        }
        $this->terminatingCallbacks = [];
    }
}
