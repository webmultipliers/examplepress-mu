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
use ExamplePress\MU\Infrastructure\Updater;

/**
 * Localizes the initial JSON payload (window.ExamplePressData) into the DOM.
 * Each page ID maps to a structured data array that the Vite JS entry
 * point consumes.
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
            'updates'        => array_merge($base, self::updatesData()),
            'theme'          => array_merge($base, self::themeData()),
            'navigation'     => array_merge($base, self::navigationData()),
            'dependencies'   => array_merge($base, self::dependenciesData()),
            'library'        => $base,
            'settings'       => array_merge($base, self::settingsData()),
            'notifications'  => array_merge($base, self::notificationsData()),
            'system'         => array_merge($base, self::systemData()),
            'docs'           => array_merge($base, self::docsData()),
            'proposer'       => array_merge($base, self::proposerData()),
            'skills'         => array_merge($base, self::skillsData()),
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
     * Apps page: companion app management, scaffold, demo.
     *
     * @return array<string, mixed>
     */
    private static function appsData(): array
    {
        $apps = AppDiscovery::scan();

        // Generate per-app destroy nonces so the JS can confirm deletions.
        // Also tag each app with its supports_ai_iteration flag, read
        // straight from the on-disk manifest, so the UI can decide
        // whether to render the chat panel or the eject lock.
        $destroyNonces = [];
        foreach ($apps as &$app) {
            $destroyNonces[$app['slug']] = wp_create_nonce('ep_destroy_' . $app['slug']);

            // Use the on-disk directory name ($app['id']), NOT the
            // manifest-declared slug. An app whose manifest slug differs
            // from its directory name would otherwise silently fail the
            // is_readable() check and flip supports_ai_iteration to
            // false for no reason. Same correctness bug we fixed in
            // DependencyManager.
            $pluginDirName = (string) ($app['id'] ?? '');
            $supports      = false;
            if ($pluginDirName !== ''
                && \ExamplePress\MU\Infrastructure\Helpers::isSafeRelativePath($pluginDirName)
                && !str_contains($pluginDirName, '/')
            ) {
                $manifestPath = WP_PLUGIN_DIR . '/' . $pluginDirName . '/examplepress.json';
                if (is_readable($manifestPath)) {
                    $manifest = json_decode((string) file_get_contents($manifestPath), true);
                    if (is_array($manifest) && !empty($manifest['supports_ai_iteration'])) {
                        $supports = true;
                    }
                }
            }
            $app['supports_ai_iteration'] = $supports;
        }
        unset($app);

        $agentEnabled = \ExamplePress\MU\Config\FeatureRegistry::enabled('agent');

        return [
            'apps'                => $apps,
            'destroyNonces'       => $destroyNonces,
            'appsBaseUrl'         => esc_url_raw(rest_url('examplepress-mu/v1/apps')),
            'appsScaffoldUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/apps/scaffold')),
            'proposerUrl'         => MenuManager::pageUrl('proposer', ['app' => '__SLUG__']),
            'troyCloudUrl'        => get_option('ep_troy_server_url', ''),
            'adminUrl'            => esc_url(admin_url()),
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
            'agent'               => [
                'enabled'        => $agentEnabled,
                'ready'          => \ExamplePress\MU\Infrastructure\PrismContainer::isAvailable(),
                'configured'     => (bool) get_option('ep_agent_api_key', ''),
                'provider'       => (string) get_option('ep_agent_provider', 'anthropic'),
                'model'          => (string) get_option('ep_agent_model', 'claude-sonnet-4-6'),
                'error'          => \ExamplePress\MU\Infrastructure\PrismContainer::lastError(),
                // Persistent cooldown state — non-zero timestamp means
                // the runtime is disabled until then following a prior
                // boot failure; 0 means no active cooldown. Surfaced so
                // the Settings UI can explain why the agent is off
                // instead of silently appearing unavailable.
                'cooldownUntil'  => \ExamplePress\MU\Infrastructure\PrismContainer::cooldownUntil(),
                'cooldownReason' => \ExamplePress\MU\Infrastructure\PrismContainer::disabledReason(),
            ],
            'agentGenerateUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/agent/generate')),
            'agentIterateUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/agent/iterate/__SLUG__')),
            'agentRepairUrl'      => esc_url_raw(rest_url('examplepress-mu/v1/agent/repair/__SLUG__')),
            'agentEjectUrl'       => esc_url_raw(rest_url('examplepress-mu/v1/agent/eject/__SLUG__')),
            'agentJobUrl'         => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs/__ID__')),
            'agentJobsUrl'        => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs')),
            'agentJobsForSlugUrl' => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs/by-slug/__SLUG__')),
            'agentDraftsUrl'      => esc_url_raw(rest_url('examplepress-mu/v1/agent/drafts')),
            'agentDraftUrl'       => esc_url_raw(rest_url('examplepress-mu/v1/agent/drafts/__SLUG__')),
            'agentDraftCommitUrl' => esc_url_raw(rest_url('examplepress-mu/v1/agent/drafts/__SLUG__/commit')),
            'agentDrafts'         => \ExamplePress\MU\Infrastructure\AppRegistry::listDraftsPending(),
            'agentJobCommitUrl'   => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs/__ID__/commit')),
            'agentJobDiscardUrl'  => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs/__ID__/discard')),
            'agentJobRetryUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs/__ID__/retry')),
            'agentJobFileUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/agent/jobs/__ID__/file')),
            'agentProvidersUrl'   => esc_url_raw(rest_url('examplepress-mu/v1/agent/providers')),
            'agentTestUrl'        => esc_url_raw(rest_url('examplepress-mu/v1/agent/test')),
        ];
    }

    /**
     * Updates page: ExamplePress theme update manager.
     *
     * @return array<string, mixed>
     */
    private static function updatesData(): array
    {
        // The initial payload is intentionally LIGHTWEIGHT. The JS bootstraps
        // each tab from these URLs and calls /status on first render, so we
        // do NOT want to trigger synchronous GitHub fetches during the page
        // render itself — that would block the page for 10+ seconds on a
        // cold cache and fail outright on a rate-limit. The renderers seed
        // from null and pull fresh data via REST after DOMContentLoaded.

        return [
            // Theme update surface — null seed; JS calls /theme-update/status.
            'themeUpdate'             => null,
            'themeUpdateStatusUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/status')),
            'themeUpdateCheckUrl'     => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/check')),
            'themeUpdateChannelUrl'   => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/channel')),
            'themeUpdatePinUrl'       => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/pin')),
            'themeUpdateInstallUrl'   => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/install')),
            'themeUpdateReinstallUrl' => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/reinstall')),
            'themeUpdateReleasesUrl'  => esc_url_raw(rest_url('examplepress-mu/v1/theme-update/releases')),

            // Kernel self-update surface. The kernel updater is intentionally
            // cron-driven and there are NO manual check/install endpoints —
            // only read-only status + the two recovery actions (rollback,
            // clear quarantine) which move you AWAY from a broken kernel.
            // getStatus() is option-and-transient only, no network, so it's
            // safe to seed inline.
            'kernelUpdate'                   => Updater::getStatus(),
            'kernelUpdateStatusUrl'          => esc_url_raw(rest_url('examplepress-mu/v1/updates/kernel/status')),
            'kernelUpdateRollbackUrl'        => esc_url_raw(rest_url('examplepress-mu/v1/updates/kernel/rollback')),
            'kernelUpdateClearQuarantineUrl' => esc_url_raw(rest_url('examplepress-mu/v1/updates/kernel/clear-quarantine')),
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
    /**
     * Skills page: agent skill curriculum browser. Loads each skill
     * file via SkillRegistry, applies merge tags, and ships the
     * resolved markdown to the JS bundle for client-side rendering.
     *
     * @return array<string, mixed>
     */
    private static function skillsData(): array
    {
        $files = \ExamplePress\MU\Agent\SkillRegistry::collectFiles();
        $skills = [];

        foreach ($files as $relPath => $absPath) {
            if (!is_readable($absPath)) {
                continue;
            }
            $raw = @file_get_contents($absPath);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $body = \ExamplePress\MU\Agent\MergeTags::apply($raw);

            // Extract a human title from the first H1, falling back to filename.
            $title = '';
            if (preg_match('/^#\s+(.+)$/m', $body, $m)) {
                $title = trim($m[1]);
            }
            if ($title === '') {
                $title = ucwords(str_replace(['-', '_'], ' ', preg_replace('/\.md$/', '', $relPath) ?? $relPath));
            }

            $id = preg_replace('/[^a-z0-9-]+/i', '-', strtolower(preg_replace('/\.md$/', '', $relPath) ?? $relPath));

            $skills[] = [
                'id'    => $id,
                'name'  => $relPath,
                'title' => $title,
                'bytes' => strlen($body),
                'body'  => $body,
            ];
        }

        return [
            'skills' => $skills,
            'tags'   => \ExamplePress\MU\Agent\MergeTags::all(),
        ];
    }

    private static function proposerData(): array
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

        return array_merge($base, [
            'valid'         => true,
            'slug'          => $slug,
            'appName'       => $appName,
            'editorBaseUrl' => esc_url_raw(rest_url("examplepress-mu/v1/editor/{$slug}")),
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
        $wpVer       = get_bloginfo('version');
        $phpVer      = phpversion();
        $hasAutoload = \ExamplePress\MU\Infrastructure\ThemeManifest::hasFile('vendor/autoload.php');
        $memory      = (string) ini_get('memory_limit');

        $hasConfig = \ExamplePress\MU\Infrastructure\ThemeManifest::hasFile('examplepress.json');
        $hasTheme  = \ExamplePress\MU\Infrastructure\ThemeManifest::hasFile('theme.json');
        $hasBs     = \ExamplePress\MU\Infrastructure\ThemeManifest::hasFile('blockstudio.json');

        $themeJson = \ExamplePress\MU\Infrastructure\ThemeManifest::themeJson();

        $indexContent = \ExamplePress\MU\Infrastructure\ThemeManifest::indexTemplate();
        $routerOnly   = $indexContent === '<!-- wp:examplepress-theme/router /-->';

        $bsActive = class_exists('Blockstudio\\Build');

        $payload = [
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

        /** Filter the platform health payload before localization. */
        return (array) apply_filters('examplepress_mu_data_health', $payload);
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
                'detail' => (string) preg_replace('#^https?://#', '', rtrim($troyUrl, '/')),
                'req'    => 'Configured',
                'status' => 'pass',
            ];

            // Cast explicitly — legacy deployments or a plugin conflict
            // could leave non-string data in this option, which would
            // make the "Stored"/"Not authorized" label render oddly
            // (e.g. "Array") instead of the intended pass/warn flag.
            $troyAuth    = (string) get_option('ep_troy_credentials', '');
            $hasTroyAuth = $troyAuth !== '';

            $checks[] = [
                'name'   => 'Troy Credentials',
                'detail' => $hasTroyAuth ? 'Stored' : 'Not authorized',
                'req'    => 'Authorized',
                'status' => $hasTroyAuth ? 'pass' : 'warn',
                'note'   => !$hasTroyAuth ? 'Click "Authorize with Troy" in the Connections tab' : '',
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
        $originMap             = RouteRegistry::map();
        $conflicts             = RouteRegistry::detectConflicts();
        $registrationConflicts = RouteRegistry::registrationConflicts();
        $apps                  = AppDiscovery::scan();

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

            // Drift: manifest routing.routes vs actually-registered slugs.
            // An entry that appears in the manifest but not in the registry
            // is a "declared but not wired" drift; the reverse is
            // "registered but undeclared" drift. Both are non-blocking
            // warnings but worth surfacing because they usually indicate
            // stale metadata or a forgotten register_route_origin call.
            $drift = ['declared_but_unregistered' => [], 'registered_but_undeclared' => []];
            if ($matchedApp) {
                $manifestSlugs = is_array($routeMeta) ? array_keys($routeMeta) : [];
                $drift['declared_but_unregistered'] = array_values(array_diff($manifestSlugs, $slugs));
                $drift['registered_but_undeclared'] = array_values(array_diff($slugs, $manifestSlugs));
            }

            $origins[] = [
                'id'        => $matchedApp ? $matchedApp['slug'] : sanitize_title($ns),
                'name'      => $matchedApp ? $matchedApp['name'] : $ns,
                'namespace' => $ns,
                'priority'  => $priority,
                'active'    => $matchedApp ? $matchedApp['active'] : true,
                'routes'    => $routes,
                'drift'     => $drift,
            ];
        }

        $hasOrigins = RouteRegistry::hasOrigins();
        $resolved   = \ExamplePress\MU\Infrastructure\Router::resolveRoute();

        return [
            'origins'                => $origins,
            'conflicts'              => $conflicts,
            'registration_conflicts' => $registrationConflicts,
            'mode'                   => $hasOrigins ? 'registry' : 'implicit',
            'resolved'               => $resolved,
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

        /** Filter the block registry payload before localization. */
        return (array) apply_filters('examplepress_mu_data_blocks', $blocks);
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
        $manifest = \ExamplePress\MU\Infrastructure\ThemeManifest::class;

        $files = [];
        foreach (['examplepress.json', 'theme.json', 'blockstudio.json'] as $name) {
            if (!$manifest::hasFile($name)) {
                continue;
            }
            $decoded = $manifest::readJson($name);
            // readJson returns [] for missing OR invalid JSON. Preserve
            // the original behavior of only including files we could
            // actually parse — use hasFile+readJson rather than defaulting.
            $files[$name] = $decoded;
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

        /** Filter the features payload before localization. */
        return (array) apply_filters('examplepress_mu_data_features', $result);
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
        return (array) apply_filters('examplepress_mu_feature_details', $details);
    }

    // ── Docs & Hooks ─────────────────────────────────────────────

    /**
     * Documentation links for the admin Docs tab.
     *
     * Delegates the data layer to DocsProvider (kernel defaults + config
     * overrides + filter) so this method only shapes the UI payload.
     *
     * @return array<int, array<string, string>>
     */
    private static function getDocs(): array
    {
        $docs = \ExamplePress\MU\Infrastructure\DocsProvider::get();

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
            ['name' => 'examplepress_mu_resolved_origin',         'type' => 'filter', 'desc' => 'Filter the registry-resolved route origin (namespace + slug) before dispatch.'],
            ['name' => 'examplepress_route_data',                 'type' => 'filter', 'desc' => 'Enrich the data payload passed to template blocks via bs_block(). (Theme hook)'],
            ['name' => 'examplepress_route_resolved',             'type' => 'action', 'desc' => 'Fires after route resolution, before dispatch. (Theme hook)'],
            ['name' => 'examplepress_mu_template_prefix',         'type' => 'filter', 'desc' => 'Override the template block prefix. Default: "template".'],
            ['name' => 'examplepress_mu_template_block_name',     'type' => 'filter', 'desc' => 'Override the fully assembled block name before dispatch.'],
            ['name' => 'examplepress_mu_template_repo',           'type' => 'filter', 'desc' => 'Override the GitHub template repository used for scaffolding new apps.'],
            ['name' => 'examplepress_mu_feature_{id}',            'type' => 'filter', 'desc' => 'Toggle any registered feature on or off. Highest priority override.'],
            ['name' => 'examplepress_mu_feature_{id}_{key}',      'type' => 'filter', 'desc' => 'Override a specific option value for a feature.'],
            ['name' => 'examplepress_mu_features',                'type' => 'filter', 'desc' => 'Filter the entire feature registry array.'],
            ['name' => 'examplepress_mu_register_features',       'type' => 'action', 'desc' => 'Fired before core features are registered — inject or replace early.'],
            ['name' => 'examplepress_mu_features_booted',         'type' => 'action', 'desc' => 'Fired after every feature has been wired by bootAll().'],
            ['name' => 'examplepress_mu_config_raw',              'type' => 'filter', 'desc' => 'Filter the merged MU + theme config before normalization.'],
            ['name' => 'examplepress_mu_config',                  'type' => 'filter', 'desc' => 'Filter the final normalized config (cached after first call).'],
            ['name' => 'examplepress_mu_theme_repo',             'type' => 'filter', 'desc' => 'Override the GitHub repo used for ExamplePress theme updates. Default: webmultipliers/examplepress-theme.'],
            ['name' => 'examplepress_mu_theme_update_channel',   'type' => 'filter', 'desc' => 'Override the resolved theme update channel (stable|development). Highest priority.'],
            ['name' => 'examplepress_mu_theme_manifest_url',     'type' => 'filter', 'desc' => 'Override the updates.json manifest URL per channel.'],
            ['name' => 'examplepress_mu_theme_variant',          'type' => 'filter', 'desc' => 'Pick a specific package variant from the manifest (default: "full").'],
            ['name' => 'examplepress_mu_should_update_now',      'type' => 'filter', 'desc' => 'Per-cron-tick gate on the kernel self-updater. Return false to skip — useful for quiet hours or release freezes.'],
            ['name' => 'examplepress_mu_demo_repo',               'type' => 'filter', 'desc' => 'Override the GitHub repo used for the demo companion plugin.'],
            ['name' => 'examplepress_mu_enforce_permalinks',      'type' => 'filter', 'desc' => 'Opt out of /%postname%/ enforcement.'],
            ['name' => 'examplepress_mu_disallow_file_edit',      'type' => 'filter', 'desc' => 'Opt out of the DISALLOW_FILE_EDIT define.'],
            ['name' => 'examplepress_mu_stripped_capabilities',   'type' => 'filter', 'desc' => 'Provide a list of capabilities to strip via user_has_cap.'],
            ['name' => 'examplepress_mu_permalink_structure',     'type' => 'filter', 'desc' => 'Override the enforced permalink structure value.'],
            ['name' => 'examplepress_mu_managed_options',         'type' => 'filter', 'desc' => 'Lock specific wp_options to managed values.'],
            ['name' => 'examplepress_mu_bypass_editor_guard',     'type' => 'filter', 'desc' => 'Bypass the FSE EditorGuard programmatically.'],
            ['name' => 'examplepress_mu_validate_app',            'type' => 'filter', 'desc' => 'Final accept/reject decision for an ExamplePress app manifest.'],
            ['name' => 'examplepress_mu_banned_permissions',      'type' => 'filter', 'desc' => 'List of permissions that disqualify a manifest.'],
            ['name' => 'examplepress_mu_app_scan_excludes',       'type' => 'filter', 'desc' => 'Plugin-directory entries to skip during app discovery.'],
            ['name' => 'examplepress_mu_discovered_apps',         'type' => 'filter', 'desc' => 'Final discovered app list from AppDiscovery::scan().'],
            ['name' => 'examplepress_mu_apps_merged',             'type' => 'filter', 'desc' => 'Final merged registry/filesystem app list.'],
            ['name' => 'examplepress_mu_apps_query_limit',        'type' => 'filter', 'desc' => 'Cap on the ep_app CPT query (default: 500).'],
            ['name' => 'examplepress_mu_data_health',             'type' => 'filter', 'desc' => 'Health payload before localization to window.ExamplePressData.'],
            ['name' => 'examplepress_mu_data_features',           'type' => 'filter', 'desc' => 'Features payload before localization.'],
            ['name' => 'examplepress_mu_data_blocks',             'type' => 'filter', 'desc' => 'Block-registry payload before localization.'],
            ['name' => 'examplepress_mu_core_page',               'type' => 'filter', 'desc' => 'Override an individual core admin page definition (return null to suppress).'],
            ['name' => 'examplepress_mu_admin_pages',             'type' => 'filter', 'desc' => 'Modify the final merged admin page registry.'],
            ['name' => 'examplepress_mu_register_admin_pages',    'type' => 'action', 'desc' => 'Register additional admin pages from companion plugins.'],
            ['name' => 'examplepress_mu_can_edit_app_files',      'type' => 'filter', 'desc' => 'Control filesystem editor write access.'],
            ['name' => 'examplepress_mu_github_inline_tree_threshold', 'type' => 'filter', 'desc' => 'Inline-content size threshold for GitHub::pushScaffold (default: 1 MB).'],
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
            'agent'            => [
                'enabled'  => \ExamplePress\MU\Config\FeatureRegistry::enabled('agent'),
                'provider' => (string) get_option('ep_agent_provider', 'anthropic'),
                'model'    => (string) get_option('ep_agent_model', 'claude-sonnet-4-6'),
                'hasKey'   => (bool) get_option('ep_agent_api_key', ''),
                'ready'    => \ExamplePress\MU\Infrastructure\PrismContainer::isAvailable(),
                'error'    => \ExamplePress\MU\Infrastructure\PrismContainer::lastError(),
                'testUrl'      => esc_url_raw(rest_url('examplepress-mu/v1/agent/test')),
                'providersUrl' => esc_url_raw(rest_url('examplepress-mu/v1/agent/providers')),
                'skillsUrl'    => esc_url_raw(rest_url('examplepress-mu/v1/agent/skills')),
            ],
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

        if (has_filter("examplepress_mu_feature_{$id}")) {
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

        $tag = "examplepress_mu_feature_{$id}";

        if (empty($wp_filter[$tag])) {
            return '';
        }

        $callbacks = $wp_filter[$tag]->callbacks ?? [];
        if (!is_array($callbacks)) {
            return '';
        }

        foreach ($callbacks as $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook) {
                $fn = is_array($hook) ? ($hook['function'] ?? null) : null;

                if (is_string($fn)) {
                    return $fn . '()';
                }

                if (is_array($fn) && count($fn) === 2) {
                    $class = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
                    $method = is_string($fn[1]) ? $fn[1] : '?';
                    return $class . '::' . $method . '()';
                }

                if ($fn instanceof \Closure) {
                    // ReflectionFunction can throw on exotic closures
                    // (e.g. a closure bound to a scope that has since
                    // been torn down). Failing this diagnostic method
                    // should never break a page render, so catch and
                    // fall back to the generic "closure" label.
                    try {
                        $ref  = new \ReflectionFunction($fn);
                        $file = $ref->getFileName();
                        if (is_string($file) && $file !== '') {
                            return basename($file) . ':' . $ref->getStartLine();
                        }
                        return 'closure';
                    } catch (\Throwable $e) {
                        return 'closure';
                    }
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
