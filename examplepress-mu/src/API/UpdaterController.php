<?php

declare(strict_types=1);

namespace ExamplePress\MU\API;

use ExamplePress\MU\Infrastructure\PluginManager;

/**
 * Updater companion plugin REST API.
 *
 * Identity-only subclass of CompanionPluginController; all install /
 * uninstall / check / settings / update / releases logic is inherited.
 */
final class UpdaterController extends CompanionPluginController
{
    protected static string $routePrefix   = 'updater';
    protected static string $pluginFile    = PluginManager::UPDATER_PLUGIN_FILE;
    protected static string $pluginDir     = 'examplepress-theme-update';
    protected static string $assetName     = 'examplepress-theme-update.zip';
    protected static string $channelOption = 'ep_updater_plugin_channel';
    protected static string $pinnedOption  = 'ep_updater_plugin_pinned';
    protected static string $throttleKey   = PluginManager::UPDATER_THROTTLE_KEY;
    protected static string $label         = 'Updater';
    protected static string $repo          = '';

    protected static function install_(): true|\WP_Error
    {
        return PluginManager::installUpdater(overwrite: true);
    }

    protected static function fetchReleases(): ?array
    {
        // Resolve repo lazily so the filter applies at request time.
        static::$repo = PluginManager::updaterRepo();
        return parent::fetchReleases();
    }
}
