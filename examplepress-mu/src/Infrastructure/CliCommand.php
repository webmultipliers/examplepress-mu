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
        // Write to CWD, not the theme folder.
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

        // Derive name + slug from the directory name. The slug must match
        // AppValidator's /^[a-z0-9-]+$/ pattern — route it through
        // sanitize_title so an uppercase or spaced directory name
        // ("My App") doesn't produce a manifest that fails validation
        // on the first scan.
        $dirName = basename($cwd);
        $slug    = function_exists('sanitize_title') ? sanitize_title($dirName) : strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $dirName));
        if ($slug === '') {
            \WP_CLI::error('Could not derive a valid slug from the directory name. Pass an explicit --slug or rename the directory.');
            return;
        }

        // App-specific boilerplate only — no design/feature boilerplate.
        // The $schema key points at the app manifest schema shipped by
        // the kernel. The relative path is resolved by IDEs that load
        // json-schema references from the containing file's directory
        // (VS Code, PhpStorm) — for apps installed under wp-content/plugins
        // it won't actually resolve, but it's the canonical URL to migrate
        // to once the schema is published publicly.
        $config = [
            '$schema'      => 'https://www.examplepress.com/schema/examplepress-app',
            'name'         => $dirName,
            'slug'         => $slug,
            'description'  => 'An ExamplePress companion plugin.',
            'version'      => '0.1.0',
            'routing'      => [
                'priority' => 10,
                'routes'   => [],
            ],
            'troy'         => [
                'server_url' => '',
                'repo'       => '',
                'repo_id'    => '',
            ],
            'dependencies' => [],
        ];

        $json = wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            \WP_CLI::error('Could not encode the manifest as JSON: ' . json_last_error_msg());
            return;
        }

        $bytes = @file_put_contents($path, $json . "\n");
        if ($bytes === false) {
            \WP_CLI::error("Could not write examplepress.json to {$path}. Check directory permissions.");
            return;
        }

        \WP_CLI::success("Generated examplepress.json at {$path}");
    }
}
