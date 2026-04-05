<?php
/**
 * ExamplePress Settings Data Helpers
 *
 * Functions that gather data for admin page payloads. These are called
 * by the per-page data functions in inc/admin/pages/. The menu
 * registration and rendering has moved to the admin page registry
 * (inc/admin/admin-registry.php) and per-page files.
 */

// ── Data Helpers ───────────────────────────────────────────────────
//
// The functions below are called by per-page data gatherers in
// inc/admin/pages/*.php. They are not meant to be called directly.

/**
 * Assemble the full route topology for the admin Route Visualizer.
 *
 * Joins the route-origin registry (namespaces + slugs + priorities)
 * with app discovery data (names, metadata) and route annotations
 * from each app's examplepress.json.
 *
 * @return array Topology data for window.ExamplePressData.routeTopology.
 */
function examplepress_settings_get_route_topology(): array {
	$origin_map = examplepress_get_route_origin_map();
	$conflicts  = examplepress_detect_route_conflicts();
	$apps       = function_exists( 'examplepress_get_apps' ) ? examplepress_get_apps() : [];

	// Index apps by slug for join attempts.
	$apps_by_slug = [];
	foreach ( $apps as $app ) {
		$apps_by_slug[ $app['slug'] ] = $app;
	}

	$origins = [];
	foreach ( $origin_map as $entry ) {
		$ns       = $entry['namespace'];
		$priority = $entry['priority'];
		$slugs    = $entry['routes'];

		// Attempt to match namespace to a discovered app.
		$matched_app = $apps_by_slug[ $ns ] ?? null;

		// Build route entries enriched with JSON metadata if available.
		$route_meta = ( $matched_app && ! empty( $matched_app['routing']['routes'] ) )
			? $matched_app['routing']['routes']
			: [];

		$routes = [];
		foreach ( $slugs as $slug ) {
			$meta = $route_meta[ $slug ] ?? [];
			$routes[ $slug ] = [
				'condition' => $meta['condition'] ?? '',
				'urls'      => $meta['urls'] ?? [],
				'desc'      => $meta['desc'] ?? '',
			];
		}

		$origins[] = [
			'id'        => $matched_app ? $matched_app['slug'] : sanitize_title( $ns ),
			'name'      => $matched_app ? $matched_app['name'] : $ns,
			'namespace' => $ns,
			'priority'  => $priority,
			'active'    => $matched_app ? $matched_app['active'] : true,
			'routes'    => $routes,
		];
	}

	return [
		'origins'   => $origins,
		'conflicts' => $conflicts,
		'mode'      => examplepress_has_route_origins() ? 'registry' : 'legacy',
		'resolved'  => examplepress_resolve_route(),
	];
}

/**
 * Detect whether a feature's resolved value comes from a PHP filter,
 * the JSON config, or the registration default.
 */
function examplepress_settings_detect_source( $id ) {
	$config = examplepress_get_config();

	if ( has_filter( "examplepress_feature_{$id}" ) ) {
		return 'php';
	}
	if ( isset( $config['features'][ $id ] ) ) {
		return 'json';
	}
	return 'default';
}

/**
 * Inspect $wp_filter to identify which function or class hooked a
 * feature filter. Returns a human-readable origin string.
 */
function examplepress_settings_get_filter_origin( $id ) {
	global $wp_filter;

	$tag = "examplepress_feature_{$id}";
	if ( empty( $wp_filter[ $tag ] ) ) {
		return '';
	}

	$callbacks = $wp_filter[ $tag ]->callbacks ?? [];
	foreach ( $callbacks as $hooks ) {
		foreach ( $hooks as $hook ) {
			$fn = $hook['function'] ?? null;
			if ( is_string( $fn ) ) {
				return $fn . '()';
			}
			if ( is_array( $fn ) && count( $fn ) === 2 ) {
				$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				return $class . '::' . $fn[1] . '()';
			}
			if ( $fn instanceof Closure ) {
				$ref = new ReflectionFunction( $fn );
				return basename( $ref->getFileName() ) . ':' . $ref->getStartLine();
			}
		}
	}
	return '';
}

/**
 * Build the features array grouped by UI category.
 */
function examplepress_settings_get_features() {
	$features = examplepress_get_features();

	$categories = [
		'theme-support' => [ 'title-tag', 'responsive-embeds', 'post-thumbnails', 'wp-block-styles', 'html5' ],
		'editor'        => [ 'disable-remote-block-patterns', 'disable-core-block-patterns', 'restrict-block-types', 'openverse', 'post-lock-window' ],
		'admin'         => [ 'remove-dashboard-widgets', 'login-branding' ],
		'design'        => [ 'theme-colors', 'theme-layout', 'theme-typography', 'design-strict' ],
	];

	$opt_display = [
		'html5'                => [ 'features', fn( $v ) => implode( ', ', (array) $v ) ],
		'post-lock-window'     => [ 'duration', fn( $v ) => $v . 's' ],
		'restrict-block-types' => [ 'types', fn( $v ) => implode( ', ', (array) $v ) ],
	];

	$result = [];
	foreach ( $categories as $cat => $ids ) {
		$result[ $cat ] = [];
		foreach ( $ids as $id ) {
			if ( ! isset( $features[ $id ] ) ) {
				continue;
			}
			$src  = examplepress_settings_detect_source( $id );
			$item = [
				'id'   => $id,
				'name' => $features[ $id ]['label'],
				'on'   => examplepress_feature_enabled( $id ),
				'src'  => $src,
			];
			if ( $src === 'php' ) {
				$origin = examplepress_settings_get_filter_origin( $id );
				if ( $origin ) {
					$item['srcDetail'] = $origin;
				}
			}
			if ( isset( $opt_display[ $id ] ) ) {
				[ $key, $formatter ] = $opt_display[ $id ];
				$val = examplepress_feature_option( $id, $key, null );
				if ( $val !== null ) {
					$item['opts'] = $key . ' → ' . $formatter( $val );
				}
			}
			$result[ $cat ][] = $item;
		}
	}
	return $result;
}

/**
 * Get font families from the feature registry, falling back to system defaults.
 */
function examplepress_settings_get_fonts() {
	$families = (array) examplepress_feature_option( 'theme-typography', 'font_families', [] );
	if ( ! empty( $families ) ) {
		return array_map( function ( $f ) {
			return [
				'name'  => $f['name'] ?? '',
				'slug'  => $f['slug'] ?? '',
				'stack' => $f['fontFamily'] ?? '',
			];
		}, $families );
	}
	return [
		[ 'name' => 'System',    'slug' => 'system', 'stack' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" ],
		[ 'name' => 'Monospace', 'slug' => 'mono',   'stack' => "'JetBrains Mono', ui-monospace, monospace" ],
		[ 'name' => 'Serif',    'slug' => 'serif',   'stack' => "'Instrument Serif', Georgia, serif" ],
	];
}

/**
 * Get font sizes from the feature registry, falling back to system defaults.
 */
function examplepress_settings_get_sizes() {
	$sizes = (array) examplepress_feature_option( 'theme-typography', 'font_sizes', [] );
	if ( ! empty( $sizes ) ) {
		return $sizes;
	}
	return [
		[ 'slug' => 'sm',  'size' => '0.875rem', 'name' => 'Small' ],
		[ 'slug' => 'md',  'size' => '1rem',     'name' => 'Medium' ],
		[ 'slug' => 'lg',  'size' => '1.25rem',  'name' => 'Large' ],
		[ 'slug' => 'xl',  'size' => '1.5rem',   'name' => 'Extra Large' ],
		[ 'slug' => '2xl', 'size' => '2rem',      'name' => '2X Large' ],
	];
}

/**
 * Discover all Blockstudio blocks from the WP block registry.
 */
function examplepress_settings_get_blocks() {
	$blocks     = [];
	$registry   = WP_Block_Type_Registry::get_instance();
	$registered = $registry->get_all_registered();

	foreach ( $registered as $name => $block ) {
		if ( empty( $block->blockstudio ) ) {
			continue;
		}

		$blocks[] = [
			'name'   => $name,
			'title'  => $block->title ?? $name,
			'cat'    => $block->category ?? 'uncategorized',
			'source' => str_starts_with( $name, 'examplepress-theme/' ) ? 'theme' : 'plugin',
			'type'   => str_contains( $name, '/router' ) ? 'system' : ( str_contains( $name, '/template-' ) ? 'template' : 'block' ),
		];
	}

	return $blocks;
}

/**
 * Read the raw configuration files for the Config viewer.
 */
function examplepress_settings_get_config_files() {
	$files = [];
	$paths = [
		'examplepress.json' => EP_THEME_PATH . '/examplepress.json',
		'theme.json'        => EP_THEME_PATH . '/theme.json',
		'blockstudio.json'  => EP_THEME_PATH . '/blockstudio.json',
	];

	foreach ( $paths as $name => $path ) {
		if ( file_exists( $path ) ) {
			$files[ $name ] = json_decode( file_get_contents( $path ), true ) ?? []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	return $files;
}

/**
 * Run health checks against the current environment.
 */
function examplepress_settings_get_health() {
	$wp_ver       = get_bloginfo( 'version' );
	$php_ver      = phpversion();
	$has_autoload = file_exists( EP_THEME_PATH . '/vendor/autoload.php' );
	$memory       = ini_get( 'memory_limit' );

	$has_config = file_exists( EP_THEME_PATH . '/examplepress.json' );
	$has_theme  = file_exists( EP_THEME_PATH . '/theme.json' );
	$has_bs     = file_exists( EP_THEME_PATH . '/blockstudio.json' );

	$theme_json = $has_theme
		? ( json_decode( file_get_contents( EP_THEME_PATH . '/theme.json' ), true ) ?? [] ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		: [];

	$index_content = file_exists( EP_THEME_PATH . '/templates/index.html' )
		? trim( file_get_contents( EP_THEME_PATH . '/templates/index.html' ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		: '';
	$router_only   = $index_content === '<!-- wp:examplepress-theme/router /-->';

	$bs_active = class_exists( 'Blockstudio\\Build' );

	return [
		'env'      => [
			[ 'name' => 'WordPress Version',  'detail' => $wp_ver,                                          'req' => "\u{2265} 6.9",   'status' => version_compare( $wp_ver, '6.9', '>=' ) ? 'pass' : 'fail' ],
			[ 'name' => 'PHP Version',         'detail' => $php_ver,                                         'req' => "\u{2265} 8.4",   'status' => version_compare( $php_ver, '8.4', '>=' ) ? 'pass' : 'fail' ],
			[ 'name' => 'Blockstudio',         'detail' => $bs_active ? 'Active' : 'Not detected',           'req' => 'Active',         'status' => $bs_active ? 'pass' : 'fail' ],
			[ 'name' => 'Composer Autoload',   'detail' => $has_autoload ? 'Loaded' : 'Missing',             'req' => 'File exists',    'status' => $has_autoload ? 'pass' : 'warn' ],
			[ 'name' => 'Memory Limit',        'detail' => $memory,                                          'req' => "\u{2265} 128M",  'status' => wp_convert_hr_to_bytes( $memory ) >= 134217728 ? 'pass' : 'warn' ],
		],
		'theme'    => [
			[ 'name' => 'examplepress.json',   'detail' => $has_config ? 'Found and valid' : 'Not found',    'req' => 'File exists',           'status' => $has_config ? 'pass' : 'warn' ],
			[ 'name' => 'theme.json',           'detail' => $has_theme ? 'Version ' . ( $theme_json['version'] ?? '?' ) : 'Not found', 'req' => "Version \u{2265} 3", 'status' => ( $theme_json['version'] ?? 0 ) >= 3 ? 'pass' : 'fail' ],
			[ 'name' => 'blockstudio.json',     'detail' => $has_bs ? 'Found and valid' : 'Not found',       'req' => 'File exists',           'status' => $has_bs ? 'pass' : 'warn' ],
			[ 'name' => 'templates/index.html', 'detail' => $router_only ? 'Router block only' : 'Non-standard', 'req' => 'Single router block', 'status' => $router_only ? 'pass' : 'warn' ],
		],
		'router'   => examplepress_settings_get_router_health(),
		'security' => [
			[ 'name' => 'Platform Kernel', 'detail' => defined('EXAMPLEPRESS_MU_VERSION') ? 'Active (v' . EXAMPLEPRESS_MU_VERSION . ')' : 'Missing', 'req' => 'Required', 'status' => defined('EXAMPLEPRESS_MU_VERSION') ? 'pass' : 'fail' ],
			[ 'name' => 'REST Template Guard', 'detail' => 'Enforced by MU Kernel', 'req' => 'Enabled', 'status' => 'pass' ],
			[ 'name' => 'Template Resolution Guard', 'detail' => 'Enforced by MU Kernel', 'req' => 'Enabled', 'status' => 'pass' ],
			[ 'name' => 'Editor Redirect Guard', 'detail' => 'Enforced by MU Kernel', 'req' => 'Enabled', 'status' => 'pass' ],
			[
				'name'   => 'Block Type Restriction',
				'detail' => examplepress_feature_enabled( 'restrict-block-types' ) ? 'Active' : 'Disabled',
				'req'    => 'Opt-in',
				'status' => 'info',
				'note'   => examplepress_feature_enabled( 'restrict-block-types' ) ? '' : 'Not active — companion plugin can enable',
			],
		],
		'connections' => examplepress_settings_get_connection_health(),
	];
}

/**
 * Router-specific health checks — multi-origin aware.
 */
function examplepress_settings_get_router_health() {
	$checks      = [];
	$has_origins = examplepress_has_route_origins();

	if ( $has_origins ) {
		$namespaces = examplepress_get_route_origin_namespaces();
		$resolved   = examplepress_resolve_route();

		$checks[] = [
			'name'   => 'Route Origins',
			'detail' => count( $namespaces ) . ' registered',
			'req'    => 'At least one',
			'status' => 'pass',
			'note'   => implode( ', ', $namespaces ),
		];
		$checks[] = [
			'name'   => 'Resolved Origin',
			'detail' => $resolved['namespace'] . ' → ' . $resolved['slug'],
			'req'    => 'Non-empty',
			'status' => 'pass',
		];
	} else {
		$checks[] = [
			'name'   => 'Route Origins',
			'detail' => 'None registered',
			'req'    => 'At least one',
			'status' => 'warn',
			'note'   => 'No companion plugin has registered route origins. Use examplepress_register_route_origin().',
		];
	}

	$checks[] = [ 'name' => 'Template Prefix', 'detail' => examplepress_get_template_prefix(), 'req' => 'Non-empty string', 'status' => 'pass' ];

	return $checks;
}

/**
 * Connection health checks — GitHub App and Troy Server status.
 */
function examplepress_settings_get_connection_health() {
	$checks = [];

	// GitHub App configuration.
	$github_app_configured = function_exists( 'examplepress_github_app_is_configured' )
		&& examplepress_github_app_is_configured();
	$github_app_installed  = $github_app_configured
		&& function_exists( 'examplepress_github_app_is_installed' )
		&& examplepress_github_app_is_installed();

	$checks[] = [
		'name'   => 'GitHub App',
		'detail' => $github_app_installed ? 'Installed' : ( $github_app_configured ? 'Configured but not installed' : 'Not configured' ),
		'req'    => 'Installed',
		'status' => $github_app_installed ? 'pass' : ( $github_app_configured ? 'warn' : 'warn' ),
		'note'   => ! $github_app_configured ? 'Install the GitHub App from the Connections tab for one-click scaffolding' : '',
	];

	// GitHub write token (PAT or App).
	$has_write_token = function_exists( 'examplepress_github_get_write_token' )
		&& (bool) examplepress_github_get_write_token();

	$checks[] = [
		'name'   => 'GitHub Write Token',
		'detail' => $has_write_token ? 'Available' : 'Not available',
		'req'    => 'Available',
		'status' => $has_write_token ? 'pass' : 'warn',
		'note'   => ! $has_write_token ? 'Required for scaffolding — install GitHub App or configure a PAT' : '',
	];

	// Troy Server (only shown when configured — Troy is opt-in).
	$troy_url = get_option( 'ep_troy_server_url', '' );

	if ( $troy_url ) {
		$checks[] = [
			'name'   => 'Troy Server URL',
			'detail' => preg_replace( '#^https?://#', '', rtrim( $troy_url, '/' ) ),
			'req'    => 'Configured',
			'status' => 'pass',
		];

		$troy_auth = get_option( 'ep_troy_credentials', '' );

		$checks[] = [
			'name'   => 'Troy Credentials',
			'detail' => $troy_auth ? 'Stored' : 'Not authorized',
			'req'    => 'Authorized',
			'status' => $troy_auth ? 'pass' : 'warn',
			'note'   => ! $troy_auth ? 'Click "Authorize with Troy" in the Connections tab' : '',
		];
	}

	return $checks;
}

/**
 * Documentation links — read from examplepress.json docs section.
 */
function examplepress_settings_get_docs() {
	$config = examplepress_get_config();
	$docs   = $config['docs'] ?? [];

	if ( empty( $docs ) ) {
		return [];
	}

	return array_map( function ( $doc ) {
		$url   = $doc['url'] ?? '';
		$label = 'Read docs';

		if ( $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST ) ?? '';
			if ( $host && ! str_contains( $host, 'github.com' ) ) {
				$label = str_replace( 'www.', '', $host );
			}
		}

		return [
			'eyebrow' => $doc['category'] ?? '',
			'title'   => $doc['title'] ?? '',
			'desc'    => $doc['description'] ?? '',
			'link'    => $url,
			'label'   => $label,
		];
	}, $docs );
}

/**
 * Static hook reference.
 */
function examplepress_settings_get_hooks() {
	return [
		[ 'name' => 'examplepress_resolved_origin',      'type' => 'filter', 'desc' => 'Filter the registry-resolved route origin (namespace + slug) before dispatch.' ],
		[ 'name' => 'examplepress_route_data',           'type' => 'filter', 'desc' => 'Enrich the data payload passed to template blocks via bs_block().' ],
		[ 'name' => 'examplepress_route_resolved',       'type' => 'action', 'desc' => 'Fires after route resolution, before dispatch. Set up route-specific state here.' ],
		[ 'name' => 'examplepress_template_prefix',      'type' => 'filter', 'desc' => 'Override the template block prefix. Default: "template".' ],
		[ 'name' => 'examplepress_template_block_name',  'type' => 'filter', 'desc' => 'Override the fully assembled block name before dispatch.' ],
		[ 'name' => 'examplepress_template_repo',        'type' => 'filter', 'desc' => 'Override the GitHub template repository used for scaffolding new apps.' ],
		[ 'name' => 'examplepress_allowed_block_types',  'type' => 'filter', 'desc' => 'Allowlist of block types when restrict-block-types feature is enabled.' ],
		[ 'name' => 'examplepress_feature_{id}',         'type' => 'filter', 'desc' => 'Toggle any registered feature on or off. Highest priority override.' ],
		[ 'name' => 'examplepress_feature_{id}_{key}',   'type' => 'filter', 'desc' => 'Override a specific option value for a feature.' ],
		[ 'name' => 'examplepress_features',             'type' => 'filter', 'desc' => 'Filter the entire feature registry array. Use for bulk modifications.' ],
	];
}

/**
 * Gather navigation menus and registered locations.
 */
function examplepress_settings_get_navigation() {
	$menus     = wp_get_nav_menus();
	$locations = get_nav_menu_locations();
	$registered = get_registered_nav_menus();

	$menu_data = [];
	foreach ( $menus as $menu ) {
		$items       = wp_get_nav_menu_items( $menu->term_id ) ?: [];
		$menu_locs   = [];
		foreach ( $locations as $loc_slug => $menu_id ) {
			if ( (int) $menu_id === (int) $menu->term_id && isset( $registered[ $loc_slug ] ) ) {
				$menu_locs[] = $registered[ $loc_slug ];
			}
		}
		$flat_items = [];
		foreach ( $items as $item ) {
			$flat_items[] = [
				'id'     => (int) $item->ID,
				'title'  => $item->title,
				'url'    => $item->url,
				'type'   => $item->type,
				'parent' => (int) $item->menu_item_parent,
			];
		}
		$menu_data[] = [
			'id'        => (int) $menu->term_id,
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'count'     => count( $items ),
			'locations' => $menu_locs,
			'items'     => $flat_items,
		];
	}

	$location_data = [];
	foreach ( $registered as $slug => $name ) {
		$assigned_menu = '';
		if ( isset( $locations[ $slug ] ) && $locations[ $slug ] ) {
			foreach ( $menus as $menu ) {
				if ( (int) $menu->term_id === (int) $locations[ $slug ] ) {
					$assigned_menu = $menu->name;
					break;
				}
			}
		}
		$location_data[] = [
			'slug'     => $slug,
			'name'     => $name,
			'assigned' => $assigned_menu,
		];
	}

	return [
		'menus'     => $menu_data,
		'locations' => $location_data,
	];
}

/**
 * Static feature detail descriptions, technical notes, and override examples.
 */
function examplepress_settings_get_feature_details() {
	$details = [
		'title-tag' => [
			'description' => 'Adds the document title tag to the HTML head, letting WordPress manage the page title dynamically.',
			'technical'   => 'Calls add_theme_support(\'title-tag\') during after_setup_theme.',
			'override'    => "add_filter( 'examplepress_feature_title-tag', '__return_false' );",
		],
		'responsive-embeds' => [
			'description' => 'Enables responsive wrappers around oEmbed content so videos and iframes scale correctly on all screen sizes.',
			'technical'   => 'Calls add_theme_support(\'responsive-embeds\') during after_setup_theme.',
			'override'    => "add_filter( 'examplepress_feature_responsive-embeds', '__return_false' );",
		],
		'post-thumbnails' => [
			'description' => 'Enables featured image support for posts and pages.',
			'technical'   => 'Calls add_theme_support(\'post-thumbnails\') during after_setup_theme.',
			'override'    => "add_filter( 'examplepress_feature_post-thumbnails', '__return_false' );",
		],
		'wp-block-styles' => [
			'description' => 'Loads the default block stylesheets provided by WordPress core.',
			'technical'   => 'Calls add_theme_support(\'wp-block-styles\') during after_setup_theme.',
			'override'    => "add_filter( 'examplepress_feature_wp-block-styles', '__return_false' );",
		],
		'html5' => [
			'description' => 'Outputs semantic HTML5 markup for search forms, comment forms, comment lists, gallery, and caption elements.',
			'technical'   => 'Calls add_theme_support(\'html5\', [...]) with the configured feature list during after_setup_theme.',
			'override'    => "add_filter( 'examplepress_feature_html5_features', function () {\n    return [ 'search-form', 'comment-form' ];\n} );",
		],
		'disable-remote-block-patterns' => [
			'description' => 'Prevents WordPress from fetching block patterns from the remote pattern directory, keeping the inserter focused on project patterns.',
			'technical'   => 'Calls remove_theme_support(\'core-block-patterns\') and sets the should_load_remote_block_patterns option to false.',
			'override'    => "add_filter( 'examplepress_feature_disable-remote-block-patterns', '__return_false' );",
		],
		'disable-core-block-patterns' => [
			'description' => 'Removes the core block patterns bundled with WordPress, leaving only custom-registered patterns.',
			'technical'   => 'Calls remove_theme_support(\'core-block-patterns\') during after_setup_theme.',
			'override'    => "add_filter( 'examplepress_feature_disable-core-block-patterns', '__return_false' );",
		],
		'restrict-block-types' => [
			'description' => 'Limits which block types are available in the editor to a defined allowlist. Opt-in — disabled by default.',
			'technical'   => 'Hooks allowed_block_types_all and returns only the configured types array. Falls back to the examplepress_allowed_block_types filter.',
			'override'    => "add_filter( 'examplepress_feature_restrict-block-types', '__return_true' );\nadd_filter( 'examplepress_feature_restrict-block-types_types', function () {\n    return [ 'core/paragraph', 'core/heading', 'core/image' ];\n} );",
		],
		'openverse' => [
			'description' => 'Controls visibility of the Openverse free media library in the block editor media inserter.',
			'technical'   => 'Hooks the block_editor_settings_all filter and sets enableOpenverseMediaCategory.',
			'override'    => "add_filter( 'examplepress_feature_openverse', '__return_true' );",
		],
		'post-lock-window' => [
			'description' => 'Sets the duration (in seconds) for the post lock heartbeat interval, controlling how often the editor checks for concurrent editing.',
			'technical'   => 'Hooks wp_check_post_lock_window and returns the configured duration value.',
			'override'    => "add_filter( 'examplepress_feature_post-lock-window_duration', function () {\n    return 300;\n} );",
		],
		'remove-dashboard-widgets' => [
			'description' => 'Removes the default WordPress dashboard widgets (Quick Draft, Activity, Events & News, Site Health) for a cleaner admin experience.',
			'technical'   => 'Hooks wp_dashboard_setup and calls remove_meta_box for each default widget.',
			'override'    => "add_filter( 'examplepress_feature_remove-dashboard-widgets', '__return_false' );",
		],
		'login-branding' => [
			'description' => 'Applies custom CSS to the WordPress login page, replacing the default WordPress logo with theme branding.',
			'technical'   => 'Hooks login_enqueue_scripts to inject custom styles on the login page.',
			'override'    => "add_filter( 'examplepress_feature_login-branding', '__return_false' );",
		],
		'theme-colors' => [
			'description' => 'Injects a colour palette into theme.json at runtime. Prefer using the design.colors shorthand in examplepress.json.',
			'technical'   => 'Hooks wp_theme_json_data_theme and merges the palette array into settings.color.palette.',
			'override'    => "add_filter( 'examplepress_feature_theme-colors', '__return_false' );",
		],
		'theme-layout' => [
			'description' => 'Injects global layout dimensions (wideSize, contentSize) into theme.json at runtime.',
			'technical'   => 'Hooks wp_theme_json_data_theme and merges layout values into settings.layout.',
			'override'    => "add_filter( 'examplepress_feature_theme-layout_wide_size', function () {\n    return '1400px';\n} );",
		],
		'theme-typography' => [
			'description' => 'Injects font families and size presets into theme.json at runtime.',
			'technical'   => 'Hooks wp_theme_json_data_theme and merges typography arrays into settings.typography.',
			'override'    => "add_filter( 'examplepress_feature_theme-typography', '__return_false' );",
		],
		'design-strict' => [
			'description' => 'Locks down all appearance tools in the block editor — disables custom colours, font sizes, spacing, and other visual controls.',
			'technical'   => 'Hooks wp_theme_json_data_theme and forces appearanceTools to false, disabling all editor appearance panels.',
			'override'    => "// Enable via examplepress.json: \"design\": { \"strict\": true }\nadd_filter( 'examplepress_feature_design-strict', '__return_true' );",
		],
	];

	return (array) apply_filters( 'examplepress_feature_details', $details );
}

// ── Legacy Render (removed) ──────────────────────────────────────
// The monolithic render function has been replaced by per-page render
// functions in inc/admin/pages/. See admin-registry.php for the menu
// builder that wires them up.
