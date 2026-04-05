<?php

declare(strict_types=1);

namespace ExamplePress\MU\Admin;

use ExamplePress\MU\Config\ConfigManager;
use ExamplePress\MU\Config\FeatureRegistry;
use ExamplePress\MU\Config\DependencyManager;
use ExamplePress\MU\Infrastructure\AppDiscovery;
use ExamplePress\MU\Infrastructure\AppRegistry;
use ExamplePress\MU\Infrastructure\Notifications;
use ExamplePress\MU\Infrastructure\PluginManager;
use ExamplePress\MU\Infrastructure\RouteRegistry;

/**
 * Localizes the initial JSON payload (window.ExamplePressData) into the DOM.
 *
 * Ported from inc/admin/settings-data.php and per-page data functions in
 * inc/admin/pages/*.php. Each page ID maps to a structured data array that
 * the Vite JS entry point consumes.
 */
final class DataProvider
{
    /**
     * Inject the ExamplePressData payload for a page via wp_add_inline_script.
     */
    public static function localize(string $pageId): void
    {
        $data = self::forPage($pageId);
        $handle = 'ep-' . $pageId;

        wp_add_inline_script(
            $handle,
            'window.ExamplePressData = ' . wp_json_encode($data) . ';',
            'before'
        );
    }

    /**
     * Return the data payload for a specific admin page.
     *
     * @return array<string, mixed>
     */
    public static function forPage(string $pageId): array
    {
        $base = self::basePayload($pageId);

        return match ($pageId) {
            'apps'           => array_merge($base, self::appsData()),
            'theme'          => array_merge($base, self::themeData()),
            'navigation'     => array_merge($base, self::navigationData()),
            'dependencies'   => array_merge($base, self::dependenciesData()),
            'library'        => $base,
            'settings'       => array_merge($base, self::settingsData()),
            'notifications'  => array_merge($base, self::notificationsData()),
            'system'         => array_merge($base, self::systemData()),
            'docs'           => array_merge($base, self::docsData()),
            'editor'         => array_merge($base, self::editorData()),
            default          => $base,
        };
    }

    // ── Base Payload ─────────────────────────────────────────────

    /**
     * Common fields shared by every admin page.
     *
     * @return array<string, mixed>
     */
    private static function basePayload(string $pageId): array
    {
        return [
            'themeVersion' => EXAMPLEPRESS_MU_VERSION,
            'devMode'      => defined('EP_DEV_MODE') && EP_DEV_MODE,
            'page'         => $pageId,
            'nonce'        => wp_create_nonce('wp_rest'),
        ];
    }

    // ── Per-Page Data ────────────────────────────────────────────

    /**
     * Apps page: companion app management, scaffold, updater, demo.
     *
     * @return array<string, mixed>
     */
    private static function appsData(): array
    {
        return [
            'apps'                => AppDiscovery::scan(),
            'appsBaseUrl'         => esc_url_raw(rest_url('examplepress-mu/v1/apps')),
            'appsScaffoldUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/apps/scaffold')),
            'editorUrl'           => MenuManager::pageUrl('editor', ['app' => '__SLUG__']),
            'troyCloudUrl'        => get_option('ep_troy_server_url', ''),
            'adminUrl'            => esc_url(admin_url()),
            'updater'             => [
                'status'          => PluginManager::getUpdaterStatus(),
                'current_version' => PluginManager::getUpdaterPluginVersion(),
            ],
            'updaterInstallUrl'   => esc_url_raw(rest_url('examplepress-mu/v1/updater/install')),
            'updaterUninstallUrl' => esc_url_raw(rest_url('examplepress-mu/v1/updater/uninstall')),
            'updaterCheckUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/updater/check')),
            'updaterUpdateUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/updater/update')),
            'updaterSettingsUrl'  => esc_url_raw(rest_url('examplepress-mu/v1/updater/settings')),
            'updaterReleasesUrl'  => esc_url_raw(rest_url('examplepress-mu/v1/updater/releases')),
            'demo'                => [
                'status'          => PluginManager::getDemoStatus(),
                'current_version' => PluginManager::getDemoPluginVersion(),
            ],
            'demoInstallUrl'      => esc_url_raw(rest_url('examplepress-mu/v1/demo/install')),
            'demoUninstallUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/demo/uninstall')),
            'demoCheckUrl'        => esc_url_raw(rest_url('examplepress-mu/v1/demo/check')),
            'demoUpdateUrl'       => esc_url_raw(rest_url('examplepress-mu/v1/demo/update')),
            'demoSettingsUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/demo/settings')),
            'demoReleasesUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/demo/releases')),
        ];
    }

    /**
     * Theme page: design tokens — colors, layout, typography, sizes.
     *
     * @return array<string, mixed>
     */
    private static function themeData(): array
    {
        return [
            'colors' => (array) self::featureOption('theme-colors', 'palette', []),
            'layout' => [
                'wideSize'    => (string) self::featureOption('theme-layout', 'wide_size', '1200px'),
                'contentSize' => (string) self::featureOption('theme-layout', 'content_size', '800px'),
            ],
            'fonts' => self::getFonts(),
            'sizes' => self::getSizes(),
        ];
    }

    /**
     * Navigation page: menus and registered locations.
     *
     * @return array<string, mixed>
     */
    private static function navigationData(): array
    {
        return [
            'navigation' => self::getNavigationData(),
        ];
    }

    /**
     * Dependencies page: required and recommended plugins.
     *
     * @return array<string, mixed>
     */
    private static function dependenciesData(): array
    {
        return [
            'dependencies' => DependencyManager::resolve(),
        ];
    }

    /**
     * Settings page: GitHub and Troy connection settings.
     *
     * @return array<string, mixed>
     */
    private static function settingsData(): array
    {
        return [
            'connectionsUrl' => esc_url_raw(rest_url('examplepress-mu/v1/settings/connections')),
            'connections'    => self::getConnectionState(),
        ];
    }

    /**
     * Notifications page: active and archived notifications.
     *
     * @return array<string, mixed>
     */
    private static function notificationsData(): array
    {
        return [
            'notifications' => Notifications::gather(),
            'archived'      => Notifications::getArchived(),
            'restUrl'       => esc_url_raw(rest_url('examplepress-mu/v1/notifications/archive')),
        ];
    }

    /**
     * System page: health checks, features, routes, blocks, config.
     *
     * @return array<string, mixed>
     */
    private static function systemData(): array
    {
        return [
            'healthChecks'   => self::getHealth(),
            'features'       => self::getFeatures(),
            'featureDetails' => self::getFeatureDetails(),
            'blocks'         => self::getBlockRegistry(),
            'routeTopology'  => self::getRouteTopology(),
            'configFiles'    => self::getConfigFiles(),
        ];
    }

    /**
     * Docs page: guides, hook reference, support resources.
     *
     * @return array<string, mixed>
     */
    private static function docsData(): array
    {
        return [
            'docs'  => self::getDocs(),
            'hooks' => self::getHookReference(),
        ];
    }

    /**
     * Editor page: in-browser code editor for companion plugins.
     *
     * @return array<string, mixed>
     */
    private static function editorData(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $slug = sanitize_title(wp_unslash($_GET['app'] ?? ''));

        $base = [
            'appsUrl' => MenuManager::pageUrl('apps'),
        ];

        $pluginDir = WP_PLUGIN_DIR . '/' . $slug;

        if (!$slug || !is_dir($pluginDir)) {
            return array_merge($base, [
                'valid' => false,
                'slug'  => $slug,
            ]);
        }

        $jsonPath = $pluginDir . '/examplepress.json';
        $appName  = $slug;

        if (file_exists($jsonPath)) {
            $appJson = json_decode((string) file_get_contents($jsonPath), true) ?: [];
            $appName = $appJson['name'] ?? $slug;
        }

        $record = AppRegistry::getPost($slug);
        $github = [];
        if ($record !== null) {
            $github = (array) get_post_meta($record->ID, '_ep_github', true);
        }

        return array_merge($base, [
            'valid'     => true,
            'slug'      => $slug,
            'appName'   => $appName,
            'fsTreeUrl' => esc_url_raw(rest_url("examplepress/v1/fs/{$slug}/tree")),
            'fsFileUrl' => esc_url_raw(rest_url("examplepress/v1/fs/{$slug}/file")),
            'github'    => $github,
        ]);
    }

    // ── Health Checks ────────────────────────────────────────────

    /**
     * Run health checks against the current environment.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function getHealth(): array
    {
        $wpVer      = get_bloginfo('version');
        $phpVer     = phpversion();
        $hasAutoload = file_exists(EP_THEME_PATH . '/vendor/autoload.php');
        $memory     = (string) ini_get('memory_limit');

        $hasConfig = file_exists(EP_THEME_PATH . '/examplepress.json');
        $hasTheme  = file_exists(EP_THEME_PATH . '/theme.json');
        $hasBs     = file_exists(EP_THEME_PATH . '/blockstudio.json');

        $themeJson = $hasTheme
            ? (json_decode((string) file_get_contents(EP_THEME_PATH . '/theme.json'), true) ?? [])
            : [];

        $indexContent = file_exists(EP_THEME_PATH . '/templates/index.html')
            ? trim((string) file_get_contents(EP_THEME_PATH . '/templates/index.html'))
            : '';
        $routerOnly = $indexContent === '<!-- wp:examplepress-theme/router /-->';

        $bsActive = class_exists('Blockstudio\\Build');

        return [
            'env' => [
                ['name' => 'WordPress Version',  'detail' => $wpVer,                                        'req' => "\u{2265} 6.9",  'status' => version_compare($wpVer, '6.9', '>=') ? 'pass' : 'fail'],
                ['name' => 'PHP Version',         'detail' => $phpVer,                                       'req' => "\u{2265} 8.4",  'status' => version_compare($phpVer, '8.4', '>=') ? 'pass' : 'fail'],
                ['name' => 'Blockstudio',         'detail' => $bsActive ? 'Active' : 'Not detected',        'req' => 'Active',        'status' => $bsActive ? 'pass' : 'fail'],
                ['name' => 'Composer Autoload',   'detail' => $hasAutoload ? 'Loaded' : 'Missing',          'req' => 'File exists',   'status' => $hasAutoload ? 'pass' : 'warn'],
                ['name' => 'Memory Limit',        'detail' => $memory,                                       'req' => "\u{2265} 128M", 'status' => wp_convert_hr_to_bytes($memory) >= 134217728 ? 'pass' : 'warn'],
            ],
            'theme' => [
                ['name' => 'examplepress.json',   'detail' => $hasConfig ? 'Found and valid' : 'Not found', 'req' => 'File exists',          'status' => $hasConfig ? 'pass' : 'warn'],
                ['name' => 'theme.json',           'detail' => $hasTheme ? 'Version ' . ($themeJson['version'] ?? '?') : 'Not found', 'req' => "Version \u{2265} 3", 'status' => ($themeJson['version'] ?? 0) >= 3 ? 'pass' : 'fail'],
                ['name' => 'blockstudio.json',     'detail' => $hasBs ? 'Found and valid' : 'Not found',    'req' => 'File exists',          'status' => $hasBs ? 'pass' : 'warn'],
                ['name' => 'templates/index.html', 'detail' => $routerOnly ? 'Router block only' : 'Non-standard', 'req' => 'Single router block', 'status' => $routerOnly ? 'pass' : 'warn'],
            ],
            'router'      => self::getRouterHealth(),
            'security'    => self::getSecurityHealth(),
            'connections'  => self::getConnectionHealth(),
        ];
    }

    /**
     * Router-specific health checks -- multi-origin aware.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getRouterHealth(): array
    {
        $checks     = [];
        $hasOrigins = RouteRegistry::hasOrigins();

        if ($hasOrigins) {
            $namespaces = RouteRegistry::namespaces();
            $resolved   = RouteRegistry::resolve();

            $checks[] = [
                'name'   => 'Route Origins',
                'detail' => count($namespaces) . ' registered',
                'req'    => 'At least one',
                'status' => 'pass',
                'note'   => implode(', ', $namespaces),
            ];

            if ($resolved !== null) {
                $checks[] = [
                    'name'   => 'Resolved Origin',
                    'detail' => $resolved['namespace'] . ' → ' . $resolved['slug'],
                    'req'    => 'Non-empty',
                    'status' => 'pass',
                ];
            }
        } else {
            $checks[] = [
                'name'   => 'Route Origins',
                'detail' => 'None registered',
                'req'    => 'At least one',
                'status' => 'warn',
                'note'   => 'No companion plugin has registered route origins. Use RouteRegistry::register().',
            ];
        }

        $checks[] = [
            'name'   => 'Template Prefix',
            'detail' => \ExamplePress\MU\Infrastructure\Router::templatePrefix(),
            'req'    => 'Non-empty string',
            'status' => 'pass',
        ];

        return $checks;
    }

    /**
     * Security health checks.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getSecurityHealth(): array
    {
        return [
            [
                'name'   => 'Platform Kernel',
                'detail' => defined('EXAMPLEPRESS_MU_VERSION') ? 'Active (v' . EXAMPLEPRESS_MU_VERSION . ')' : 'Missing',
                'req'    => 'Required',
                'status' => defined('EXAMPLEPRESS_MU_VERSION') ? 'pass' : 'fail',
            ],
            ['name' => 'REST Template Guard',       'detail' => 'Enforced by MU Kernel', 'req' => 'Enabled', 'status' => 'pass'],
            ['name' => 'Template Resolution Guard',  'detail' => 'Enforced by MU Kernel', 'req' => 'Enabled', 'status' => 'pass'],
            ['name' => 'Editor Redirect Guard',      'detail' => 'Enforced by MU Kernel', 'req' => 'Enabled', 'status' => 'pass'],
            [
                'name'   => 'Block Type Restriction',
                'detail' => FeatureRegistry::enabled('restrict-block-types') ? 'Active' : 'Disabled',
                'req'    => 'Opt-in',
                'status' => 'info',
                'note'   => FeatureRegistry::enabled('restrict-block-types') ? '' : 'Not active — companion plugin can enable',
            ],
        ];
    }

    /**
     * Connection health checks -- GitHub App and Troy Server status.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getConnectionHealth(): array
    {
        $checks = [];

        $githubAppConfigured = \ExamplePress\MU\Infrastructure\GitHub::appIsConfigured();
        $githubAppInstalled  = \ExamplePress\MU\Infrastructure\GitHub::appIsInstalled();

        $checks[] = [
            'name'   => 'GitHub App',
            'detail' => $githubAppInstalled ? 'Installed' : ($githubAppConfigured ? 'Configured but not installed' : 'Not configured'),
            'req'    => 'Installed',
            'status' => $githubAppInstalled ? 'pass' : 'warn',
            'note'   => !$githubAppConfigured ? 'Install the GitHub App from the Connections tab for one-click scaffolding' : '',
        ];

        $hasWriteToken = (bool) \ExamplePress\MU\Infrastructure\GitHub::writeToken();

        $checks[] = [
            'name'   => 'GitHub Write Token',
            'detail' => $hasWriteToken ? 'Available' : 'Not available',
            'req'    => 'Available',
            'status' => $hasWriteToken ? 'pass' : 'warn',
            'note'   => !$hasWriteToken ? 'Required for scaffolding — install GitHub App or configure a PAT' : '',
        ];

        $troyUrl = (string) get_option('ep_troy_server_url', '');

        if ($troyUrl) {
            $checks[] = [
                'name'   => 'Troy Server URL',
                'detail' => preg_replace('#^https?://#', '', rtrim($troyUrl, '/')),
                'req'    => 'Configured',
                'status' => 'pass',
            ];

            $troyAuth = get_option('ep_troy_credentials', '');

            $checks[] = [
                'name'   => 'Troy Credentials',
                'detail' => $troyAuth ? 'Stored' : 'Not authorized',
                'req'    => 'Authorized',
                'status' => $troyAuth ? 'pass' : 'warn',
                'note'   => !$troyAuth ? 'Click "Authorize with Troy" in the Connections tab' : '',
            ];
        }

        return $checks;
    }

    // ── Route Topology ───────────────────────────────────────────

    /**
     * Assemble the full route topology for the admin Route Visualizer.
     *
     * @return array<string, mixed>
     */
    private static function getRouteTopology(): array
    {
        $originMap = RouteRegistry::map();
        $conflicts = RouteRegistry::detectConflicts();
        $apps      = AppDiscovery::scan();

        $appsBySlug = [];
        foreach ($apps as $app) {
            $appsBySlug[$app['slug']] = $app;
        }

        $origins = [];
        foreach ($originMap as $entry) {
            $ns       = $entry['namespace'];
            $priority = $entry['priority'];
            $slugs    = $entry['routes'];

            $matchedApp = $appsBySlug[$ns] ?? null;

            $routeMeta = ($matchedApp && !empty($matchedApp['routing']['routes']))
                ? $matchedApp['routing']['routes']
                : [];

            $routes = [];
            foreach ($slugs as $slug) {
                $meta = $routeMeta[$slug] ?? [];
                $routes[$slug] = [
                    'condition' => $meta['condition'] ?? '',
                    'urls'      => $meta['urls'] ?? [],
                    'desc'      => $meta['desc'] ?? '',
                ];
            }

            $origins[] = [
                'id'        => $matchedApp ? $matchedApp['slug'] : sanitize_title($ns),
                'name'      => $matchedApp ? $matchedApp['name'] : $ns,
                'namespace' => $ns,
                'priority'  => $priority,
                'active'    => $matchedApp ? $matchedApp['active'] : true,
                'routes'    => $routes,
            ];
        }

        $hasOrigins = RouteRegistry::hasOrigins();
        $resolved   = \ExamplePress\MU\Infrastructure\Router::resolveRoute();

        return [
            'origins'   => $origins,
            'conflicts' => $conflicts,
            'mode'      => $hasOrigins ? 'registry' : 'legacy',
            'resolved'  => $resolved,
        ];
    }

    // ── Block Registry ───────────────────────────────────────────

    /**
     * Discover all Blockstudio blocks from the WP block registry.
     *
     * @return array<int, array<string, string>>
     */
    private static function getBlockRegistry(): array
    {
        $blocks    = [];
        $registry  = \WP_Block_Type_Registry::get_instance();
        $registered = $registry->get_all_registered();

        foreach ($registered as $name => $block) {
            if (empty($block->blockstudio)) {
                continue;
            }

            $blocks[] = [
                'name'   => $name,
                'title'  => $block->title ?? $name,
                'cat'    => $block->category ?? 'uncategorized',
                'source' => str_starts_with($name, 'examplepress-theme/') ? 'theme' : 'plugin',
                'type'   => str_contains($name, '/router') ? 'system' : (str_contains($name, '/template-') ? 'template' : 'block'),
            ];
        }

        return $blocks;
    }

    // ── Navigation Data ──────────────────────────────────────────

    /**
     * Gather navigation menus and registered locations.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function getNavigationData(): array
    {
        $menus      = wp_get_nav_menus();
        $locations  = get_nav_menu_locations();
        $registered = get_registered_nav_menus();

        $menuData = [];
        foreach ($menus as $menu) {
            $items    = wp_get_nav_menu_items($menu->term_id) ?: [];
            $menuLocs = [];
            foreach ($locations as $locSlug => $menuId) {
                if ((int) $menuId === (int) $menu->term_id && isset($registered[$locSlug])) {
                    $menuLocs[] = $registered[$locSlug];
                }
            }

            $flatItems = [];
            foreach ($items as $item) {
                $flatItems[] = [
                    'id'     => (int) $item->ID,
                    'title'  => $item->title,
                    'url'    => $item->url,
                    'type'   => $item->type,
                    'parent' => (int) $item->menu_item_parent,
                ];
            }

            $menuData[] = [
                'id'        => (int) $menu->term_id,
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'count'     => count($items),
                'locations' => $menuLocs,
                'items'     => $flatItems,
            ];
        }

        $locationData = [];
        foreach ($registered as $slug => $name) {
            $assignedMenu = '';
            if (isset($locations[$slug]) && $locations[$slug]) {
                foreach ($menus as $menu) {
                    if ((int) $menu->term_id === (int) $locations[$slug]) {
                        $assignedMenu = $menu->name;
                        break;
                    }
                }
            }
            $locationData[] = [
                'slug'     => $slug,
                'name'     => $name,
                'assigned' => $assignedMenu,
            ];
        }

        return [
            'menus'     => $menuData,
            'locations' => $locationData,
        ];
    }

    // ── Config Files ─────────────────────────────────────────────

    /**
     * Read the raw configuration files for the Config viewer.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function getConfigFiles(): array
    {
        $files = [];
        $paths = [
            'examplepress.json' => EP_THEME_PATH . '/examplepress.json',
            'theme.json'        => EP_THEME_PATH . '/theme.json',
            'blockstudio.json'  => EP_THEME_PATH . '/blockstudio.json',
        ];

        foreach ($paths as $name => $path) {
            if (file_exists($path)) {
                $files[$name] = json_decode((string) file_get_contents($path), true) ?? [];
            }
        }

        return $files;
    }

    // ── Features ─────────────────────────────────────────────────

    /**
     * Build the features array grouped by UI category.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function getFeatures(): array
    {
        $features = FeatureRegistry::all();

        $categories = [
            'theme-support' => ['title-tag', 'responsive-embeds', 'post-thumbnails', 'wp-block-styles', 'html5'],
            'editor'        => ['disable-remote-block-patterns', 'disable-core-block-patterns', 'restrict-block-types', 'openverse', 'post-lock-window'],
            'admin'         => ['remove-dashboard-widgets', 'login-branding'],
            'design'        => ['theme-colors', 'theme-layout', 'theme-typography', 'design-strict'],
        ];

        $optDisplay = [
            'html5'                => ['features', static fn($v): string => implode(', ', (array) $v)],
            'post-lock-window'     => ['duration', static fn($v): string => $v . 's'],
            'restrict-block-types' => ['types', static fn($v): string => implode(', ', (array) $v)],
        ];

        $result = [];
        foreach ($categories as $cat => $ids) {
            $result[$cat] = [];
            foreach ($ids as $id) {
                if (!isset($features[$id])) {
                    continue;
                }

                $src  = self::detectFeatureSource($id);
                $item = [
                    'id'   => $id,
                    'name' => $features[$id]['label'],
                    'on'   => FeatureRegistry::enabled($id),
                    'src'  => $src,
                ];

                if ($src === 'php') {
                    $origin = self::getFilterOrigin($id);
                    if ($origin) {
                        $item['srcDetail'] = $origin;
                    }
                }

                if (isset($optDisplay[$id])) {
                    [$key, $formatter] = $optDisplay[$id];
                    $val = self::featureOption($id, $key, null);
                    if ($val !== null) {
                        $item['opts'] = $key . ' → ' . $formatter($val);
                    }
                }

                $result[$cat][] = $item;
            }
        }

        return $result;
    }

    /**
     * Static feature detail descriptions, technical notes, and override examples.
     *
     * @return array<string, array<string, string>>
     */
    private static function getFeatureDetails(): array
    {
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

        /** @var array<string, array<string, string>> */
        return (array) apply_filters('examplepress_feature_details', $details);
    }

    // ── Docs & Hooks ─────────────────────────────────────────────

    /**
     * Documentation links -- read from examplepress.json docs section.
     *
     * @return array<int, array<string, string>>
     */
    private static function getDocs(): array
    {
        $config = ConfigManager::get();
        $docs   = $config['docs'] ?? [];

        if (empty($docs)) {
            return [];
        }

        return array_map(static function (array $doc): array {
            $url   = $doc['url'] ?? '';
            $label = 'Read docs';

            if ($url) {
                $host = wp_parse_url($url, PHP_URL_HOST) ?? '';
                if ($host && !str_contains((string) $host, 'github.com')) {
                    $label = str_replace('www.', '', (string) $host);
                }
            }

            return [
                'eyebrow' => $doc['category'] ?? '',
                'title'   => $doc['title'] ?? '',
                'desc'    => $doc['description'] ?? '',
                'link'    => $url,
                'label'   => $label,
            ];
        }, $docs);
    }

    /**
     * Static hook reference.
     *
     * @return array<int, array<string, string>>
     */
    private static function getHookReference(): array
    {
        return [
            ['name' => 'examplepress_resolved_origin',      'type' => 'filter', 'desc' => 'Filter the registry-resolved route origin (namespace + slug) before dispatch.'],
            ['name' => 'examplepress_route_data',           'type' => 'filter', 'desc' => 'Enrich the data payload passed to template blocks via bs_block().'],
            ['name' => 'examplepress_route_resolved',       'type' => 'action', 'desc' => 'Fires after route resolution, before dispatch. Set up route-specific state here.'],
            ['name' => 'examplepress_template_prefix',      'type' => 'filter', 'desc' => 'Override the template block prefix. Default: "template".'],
            ['name' => 'examplepress_template_block_name',  'type' => 'filter', 'desc' => 'Override the fully assembled block name before dispatch.'],
            ['name' => 'examplepress_template_repo',        'type' => 'filter', 'desc' => 'Override the GitHub template repository used for scaffolding new apps.'],
            ['name' => 'examplepress_allowed_block_types',  'type' => 'filter', 'desc' => 'Allowlist of block types when restrict-block-types feature is enabled.'],
            ['name' => 'examplepress_feature_{id}',         'type' => 'filter', 'desc' => 'Toggle any registered feature on or off. Highest priority override.'],
            ['name' => 'examplepress_feature_{id}_{key}',   'type' => 'filter', 'desc' => 'Override a specific option value for a feature.'],
            ['name' => 'examplepress_features',             'type' => 'filter', 'desc' => 'Filter the entire feature registry array. Use for bulk modifications.'],
        ];
    }

    // ── Connection State ─────────────────────────────────────────

    /**
     * Current connection state for GitHub and Troy integrations.
     *
     * @return array<string, mixed>
     */
    private static function getConnectionState(): array
    {
        return [
            'hasGithubPat'     => (bool) get_option('ep_github_pat', ''),
            'hasGithubApp'     => \ExamplePress\MU\Infrastructure\GitHub::appIsInstalled(),
            'githubAppAvail'   => \ExamplePress\MU\Infrastructure\GitHub::appIsConfigured(),
            'githubAppSlug'    => defined('EP_GITHUB_APP_SLUG') ? EP_GITHUB_APP_SLUG : '',
            'githubOrg'        => get_option('ep_github_org', 'webmultipliers'),
            'appTemplateRepo'  => get_option('ep_app_template_repo', \ExamplePress\MU\Infrastructure\Scaffolder::EP_DEFAULT_TEMPLATE_REPO),
            'hasTroyUrl'       => (bool) get_option('ep_troy_server_url', ''),
            'hasTroyCreds'     => (bool) get_option('ep_troy_credentials', ''),
            'hasTroyGithubPat' => (bool) get_option('ep_troy_github_pat', ''),
            'troyServerUrl'    => get_option('ep_troy_server_url', ''),
            'testGithubUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/settings/test-github')),
            'testTroyUrl'      => esc_url_raw(rest_url('examplepress-mu/v1/settings/test-troy')),
        ];
    }

    // ── Typography Helpers ───────────────────────────────────────

    /**
     * Get font families from the feature registry, falling back to system defaults.
     *
     * @return array<int, array<string, string>>
     */
    private static function getFonts(): array
    {
        $families = (array) self::featureOption('theme-typography', 'font_families', []);

        if (!empty($families)) {
            return array_map(static fn(array $f): array => [
                'name'  => $f['name'] ?? '',
                'slug'  => $f['slug'] ?? '',
                'stack' => $f['fontFamily'] ?? '',
            ], $families);
        }

        return [
            ['name' => 'System',    'slug' => 'system', 'stack' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"],
            ['name' => 'Monospace', 'slug' => 'mono',   'stack' => "'JetBrains Mono', ui-monospace, monospace"],
            ['name' => 'Serif',     'slug' => 'serif',  'stack' => "'Instrument Serif', Georgia, serif"],
        ];
    }

    /**
     * Get font sizes from the feature registry, falling back to system defaults.
     *
     * @return array<int, array<string, string>>
     */
    private static function getSizes(): array
    {
        $sizes = (array) self::featureOption('theme-typography', 'font_sizes', []);

        if (!empty($sizes)) {
            return $sizes;
        }

        return [
            ['slug' => 'sm',  'size' => '0.875rem', 'name' => 'Small'],
            ['slug' => 'md',  'size' => '1rem',     'name' => 'Medium'],
            ['slug' => 'lg',  'size' => '1.25rem',  'name' => 'Large'],
            ['slug' => 'xl',  'size' => '1.5rem',   'name' => 'Extra Large'],
            ['slug' => '2xl', 'size' => '2rem',     'name' => '2X Large'],
        ];
    }

    // ── Feature Source Detection ─────────────────────────────────

    /**
     * Detect whether a feature's resolved value comes from a PHP filter,
     * the JSON config, or the registration default.
     */
    private static function detectFeatureSource(string $id): string
    {
        $config = ConfigManager::get();

        if (has_filter("examplepress_feature_{$id}")) {
            return 'php';
        }

        if (isset($config['features'][$id])) {
            return 'json';
        }

        return 'default';
    }

    /**
     * Inspect $wp_filter to identify which function or class hooked a
     * feature filter. Returns a human-readable origin string.
     */
    private static function getFilterOrigin(string $id): string
    {
        global $wp_filter;

        $tag = "examplepress_feature_{$id}";

        if (empty($wp_filter[$tag])) {
            return '';
        }

        $callbacks = $wp_filter[$tag]->callbacks ?? [];

        foreach ($callbacks as $hooks) {
            foreach ($hooks as $hook) {
                $fn = $hook['function'] ?? null;

                if (is_string($fn)) {
                    return $fn . '()';
                }

                if (is_array($fn) && count($fn) === 2) {
                    $class = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
                    return $class . '::' . $fn[1] . '()';
                }

                if ($fn instanceof \Closure) {
                    $ref = new \ReflectionFunction($fn);
                    return basename((string) $ref->getFileName()) . ':' . $ref->getStartLine();
                }
            }
        }

        return '';
    }

    /**
     * Shorthand to get a feature option value via FeatureRegistry.
     */
    private static function featureOption(string $id, string $key, mixed $default = null): mixed
    {
        return FeatureRegistry::option($id, $key, $default);
    }
}
