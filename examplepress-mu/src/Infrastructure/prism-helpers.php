<?php

/**
 * Tiny replacements for the Laravel global helpers Prism reaches for.
 *
 * Ship our own because the real ones live in illuminate/foundation —
 * the package we explicitly avoid pulling in.
 *
 * Loaded by PrismContainer::boot() AFTER the container is wired up.
 * Each helper is guarded with function_exists so we never collide with
 * a real Laravel runtime if one happens to be loaded by another plugin.
 */

declare(strict_types=1);

if (! function_exists('config')) {
    /**
     * Resolve a value from the Prism container's config repository.
     *
     * @param string|array<string,mixed>|null $key
     * @param mixed                            $default
     */
    function config($key = null, $default = null)
    {
        $container = \Illuminate\Container\Container::getInstance();

        if ($key === null) {
            return $container->make('config');
        }

        if (is_array($key)) {
            return $container->make('config')->set($key);
        }

        return $container->make('config')->get($key, $default);
    }
}

if (! function_exists('app')) {
    /**
     * Resolve a binding from the Prism container.
     *
     * @param string|null         $abstract
     * @param array<string,mixed> $parameters
     */
    function app($abstract = null, array $parameters = [])
    {
        $container = \Illuminate\Container\Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract, $parameters);
    }
}

if (! function_exists('resolve')) {
    /**
     * Resolve a binding from the Prism container. Identical to app() but
     * is the spelling Prism's ConfiguresProviders concern reaches for.
     *
     * @param string              $abstract
     * @param array<string,mixed> $parameters
     */
    function resolve($abstract, array $parameters = [])
    {
        return \Illuminate\Container\Container::getInstance()->make($abstract, $parameters);
    }
}

if (! function_exists('event')) {
    /**
     * Fire an event through the Prism container's dispatcher.
     * Prism only uses this in BroadcastAdapter, which we never touch,
     * but ship a no-op-safe shim so any future code path doesn't fatal.
     */
    function event(...$args)
    {
        $container = \Illuminate\Container\Container::getInstance();
        if (! $container->bound('events')) {
            return null;
        }
        return $container->make('events')->dispatch(...$args);
    }
}
