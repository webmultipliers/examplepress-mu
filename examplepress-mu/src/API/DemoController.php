<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\PluginManager;

/**
 * Demo companion plugin REST API.
 *
 * Identity-only subclass of CompanionPluginController.
 */
final class DemoController extends CompanionPluginController
{
    protected static string $routePrefix   = 'demo';
    protected static string $pluginFile    = PluginManager::DEMO_PLUGIN_FILE;
    protected static string $pluginDir     = 'examplepress-demo';
    protected static string $assetName     = 'examplepress-theme-demo.zip';
    protected static string $channelOption = 'ep_demo_plugin_channel';
    protected static string $pinnedOption  = 'ep_demo_plugin_pinned';
    protected static string $throttleKey   = PluginManager::DEMO_THROTTLE_KEY;
    protected static string $label         = 'Demo';
    protected static string $repo          = '';

    protected static function install_(): true|\WP_Error
    {
        return PluginManager::installDemo(overwrite: true);
    }

    protected static function statusFor(): string
    {
        // Demo has its own status semantics (foreign-plugin detection).
        return PluginManager::getDemoStatus();
    }

    protected static function uninstallGuard(string $pluginDirAbs): ?\WP_Error
    {
        if (!PluginManager::isDemoPlugin($pluginDirAbs)) {
            return new \WP_Error(
                'demo_conflict',
                'The examplepress-demo plugin is not the ExamplePress demo. Refusing to delete.',
                ['status' => 409]
            );
        }
        return null;
    }

    protected static function fetchReleases(): ?array
    {
        static::$repo = PluginManager::demoRepo();
        return parent::fetchReleases();
    }
}
