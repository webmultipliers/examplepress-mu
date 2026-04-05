<?php
/**
 * ExamplePress MU — WP-CLI Commands
 *
 * Ported from the theme's inc/cli.php. Provides scaffolding commands
 * for theme configuration. Only loaded when WP-CLI is active.
 *
 * @package ExamplePress_MU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command( 'examplepress', 'ExamplePress_CLI' );

/**
 * Manage ExamplePress theme configuration.
 */
class ExamplePress_CLI {

	/**
	 * Generate a starter examplepress.json file.
	 *
	 * Creates the configuration file pre-populated with all registration
	 * defaults, the current design tokens, and the schema URL.
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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function init( $args, $assoc_args ) {
		if ( ! function_exists( 'examplepress_get_features' ) || ! function_exists( 'examplepress_feature_option' ) ) {
			WP_CLI::error( 'Theme functions not loaded.' );
		}

		$path = get_template_directory() . '/examplepress.json';

		if ( file_exists( $path ) && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( 'examplepress.json already exists. Use --force to overwrite.' );
		}

		$features = examplepress_get_features();
		$defaults = [];
		foreach ( $features as $id => $feature ) {
			$defaults[ $id ] = $feature['default'];
		}

		$config = [
			'$schema'  => 'https://www.examplepress.com/schema/examplepress-theme',
			'features' => $defaults,
			'design'   => [
				'colors'     => (array) examplepress_feature_option( 'theme-colors', 'palette', [] ),
				'layout'     => [
					'wideSize'    => (string) examplepress_feature_option( 'theme-layout', 'wide_size', '1200px' ),
					'contentSize' => (string) examplepress_feature_option( 'theme-layout', 'content_size', '800px' ),
				],
				'typography' => [
					'fontFamilies' => (array) examplepress_feature_option( 'theme-typography', 'font_families', [] ),
					'fontSizes'    => (array) examplepress_feature_option( 'theme-typography', 'font_sizes', [] ),
				],
			],
			'updater'  => [
				'github_repo'     => 'webmultipliers/examplepress-theme',
				'asset_filename'  => 'examplepress-theme.zip',
				'theme_slug'      => 'examplepress-theme',
				'default_channel' => 'stable',
				'requires_wp'     => '6.9',
				'requires_php'    => '8.4',
			],
			'routing'  => [
				'priority' => 10,
			],
			'docs'     => [
				[ 'title' => 'Companion Plugin Guide', 'description' => 'Build your first companion plugin: hook the router, register blocks, and take ownership of the frontend.', 'url' => 'https://github.com/webmultipliers/examplepress-theme/blob/development/docs/companion-plugin.md', 'category' => 'Start Here' ],
				[ 'title' => 'Router & Routing',       'description' => 'How the single-entry-point router works, the filter chain, and routing cascade.', 'url' => 'https://github.com/webmultipliers/examplepress-theme/blob/development/docs/routing.md',          'category' => 'Architecture' ],
				[ 'title' => 'Guard System',           'description' => 'Template lockdown layers and how to disable guards for development.',              'url' => 'https://github.com/webmultipliers/examplepress-theme/blob/development/docs/guards.md',           'category' => 'Architecture' ],
				[ 'title' => 'Configuration Guide',    'description' => 'JSON files, design tokens, strict mode, and the config loading pipeline.',         'url' => 'https://github.com/webmultipliers/examplepress-theme/blob/development/docs/configuration.md',    'category' => 'Configuration' ],
				[ 'title' => 'Feature Registry API',   'description' => 'Register features, check state, read options, and the filterable flag system.',    'url' => 'https://github.com/webmultipliers/examplepress-theme/blob/development/docs/feature-registry.md', 'category' => 'Configuration' ],
				[ 'title' => 'Blockstudio',            'description' => 'Block registration, fields, rendering, and hooks.',                                'url' => 'https://blockstudio.dev/documentation/',                                                         'category' => 'Blockstudio' ],
			],
			'dependencies' => [
				[
					'slug'            => 'blockstudio',
					'name'            => 'Blockstudio',
					'tier'            => 'required',
					'pricing'         => 'free',
					'cloud_dependent' => false,
					'check_type'      => 'class',
					'check_target'    => 'Blockstudio\\Build',
					'source'          => [ 'type' => 'direct', 'url' => 'https://github.com/flavor/flavor' ],
				],
			],
		];

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path,
			wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		WP_CLI::success( "Generated examplepress.json at {$path}" );
	}
}
