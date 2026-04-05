<?php

declare(strict_types=1);

namespace ExamplePress\MU\Infrastructure;

use ExamplePress\MU\Config\ConfigManager;
use ExamplePress\MU\Config\FeatureRegistry;

/**
 * WP-CLI integration.
 *
 * CRITICAL FIX: The `wp examplepress init` command generates the
 * examplepress.json in the Current Working Directory (via getcwd()),
 * NOT the theme folder. Only outputs app-specific boilerplate
 * (routing, troy) — no design/feature boilerplate.
 */
final class CliCommand
{
    public static function register(): void
    {
        \WP_CLI::add_command('examplepress', self::class);
    }

    /**
     * Generate a starter examplepress.json file.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Overwrite an existing examplepress.json file.
     *
     * ## EXAMPLES
     *
     *     wp examplepress init
     *     wp examplepress init --force
     *
     * @param array<int, string>    $args      Positional arguments.
     * @param array<string, string> $assocArgs Named arguments.
     */
    public function init(array $args, array $assocArgs): void
    {
        // CRITICAL FIX: Write to CWD, not the theme folder.
        $cwd = getcwd();
        if (!$cwd) {
            \WP_CLI::error('Could not determine the current working directory.');
            return;
        }

        $path = $cwd . '/examplepress.json';

        if (file_exists($path) && empty($assocArgs['force'])) {
            \WP_CLI::error('examplepress.json already exists. Use --force to overwrite.');
            return;
        }

        // App-specific boilerplate only — no design/feature boilerplate.
        $config = [
            '$schema'  => 'https://www.examplepress.com/schema/app',
            'name'     => basename($cwd),
            'slug'     => basename($cwd),
            'description' => 'An ExamplePress companion plugin.',
            'version'  => '0.1.0',
            'routing'  => [
                'priority' => 10,
                'routes'   => [],
            ],
            'troy'     => [
                'server_url' => '',
                'repo'       => '',
                'repo_id'    => '',
            ],
            'dependencies' => [],
        ];

        file_put_contents(
            $path,
            wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        \WP_CLI::success("Generated examplepress.json at {$path}");
    }
}
