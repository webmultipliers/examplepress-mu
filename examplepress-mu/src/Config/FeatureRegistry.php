<?php

declare(strict_types=1);

namespace ExamplePress\MU\Config;

/**
 * Centralized feature flag system.
 *
 * Reads defaults from ConfigManager, allowing overrides via namespaced
 * filters (examplepress_mu_feature_{id}).
 */
final class FeatureRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $features = [];

    /**
     * Register a feature.
     *
     * @param string $id   Unique feature identifier (slug).
     * @param array  $args Feature arguments.
     */
    public static function register(string $id, array $args = []): void
    {
        self::$features[$id] = wp_parse_args($args, [
            'label'    => '',
            'group'    => '',
            'default'  => true,
            'options'  => [],
            'hook'     => '',
            'callback' => '',
            'type'     => 'filter',
            'priority' => 10,
            'setup'    => null,
        ]);
    }

    /**
     * Check whether a feature is enabled.
     *
     * Resolution order (highest wins):
     *   1. PHP Filter  (examplepress_mu_feature_{$id})
     *   2. examplepress.json  (features.{id})
     *   3. Registration default
     */
    public static function enabled(string $id): bool
    {
        $default = self::$features[$id]['default'] ?? true;

        $json = ConfigManager::get();
        if (isset($json['features'][$id])) {
            $val = $json['features'][$id];
            $default = is_array($val) ? ($val['enabled'] ?? $default) : (bool) $val;
        }

        return (bool) apply_filters("examplepress_mu_feature_{$id}", $default);
    }

    /**
     * Retrieve a feature-specific option value.
     *
     * Resolution order (highest wins):
     *   1. PHP Filter  (examplepress_mu_feature_{$id}_{$key})
     *   2. examplepress.json  (features.{id}.options.{key})
     *   3. Registration default
     */
    public static function option(string $id, string $key, mixed $fallback = null): mixed
    {
        $options = self::$features[$id]['options'] ?? [];
        $value = $options[$key] ?? $fallback;

        $json = ConfigManager::get();
        if (isset($json['features'][$id]) && is_array($json['features'][$id])) {
            $jsonOptions = $json['features'][$id]['options'] ?? [];
            if (array_key_exists($key, $jsonOptions)) {
                $value = $jsonOptions[$key];
            }
        }

        return apply_filters("examplepress_mu_feature_{$id}_{$key}", $value);
    }

    /**
     * Guard wrapper for complex feature setup callables.
     */
    public static function guardedSetup(string $id, callable $fn): void
    {
        if (!self::enabled($id)) {
            return;
        }
        $fn($id);
    }

    /**
     * Return all registered features.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return apply_filters('examplepress_mu_features', self::$features);
    }

    /**
     * Return features belonging to a specific group.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function byGroup(string $group): array
    {
        $features = self::all();
        return array_filter($features, static fn(array $f): bool => ($f['group'] ?? '') === $group);
    }

    /**
     * Boot all registered features.
     *
     * Hooked to after_setup_theme. Complex features (with setup callable)
     * always have their setup invoked. Simple features are only wired
     * when enabled.
     */
    public static function bootAll(): void
    {
        /**
         * Fires before core features are registered. Use this to unregister
         * or replace core features by manipulating the registry directly,
         * or to register additional features that should boot alongside core.
         */
        do_action('examplepress_mu_register_features');

        self::registerCoreFeatures();

        $features = self::all();

        foreach ($features as $id => $feature) {
            if (is_callable($feature['setup'])) {
                call_user_func($feature['setup'], $id, $feature);
                continue;
            }

            if (!self::enabled($id)) {
                continue;
            }

            if ($feature['hook'] && $feature['callback']) {
                if ('action' === $feature['type']) {
                    add_action($feature['hook'], $feature['callback'], $feature['priority']);
                } else {
                    add_filter($feature['hook'], $feature['callback'], $feature['priority']);
                }
            }
        }

        /**
         * Fires after all features have booted. Use this to register late
         * extensions that depend on the core feature wiring being in place.
         */
        do_action('examplepress_mu_features_booted');
    }

    /**
     * Register all core platform features.
     */
    private static function registerCoreFeatures(): void
    {
        // Theme Support features
        self::register('title-tag', [
            'label' => 'Title Tag',
            'group' => 'theme',
            'hook'  => 'after_setup_theme',
            'type'  => 'action',
            'callback' => static function (): void {
                add_theme_support('title-tag');
            },
        ]);

        self::register('responsive-embeds', [
            'label' => 'Responsive Embeds',
            'group' => 'theme',
            'hook'  => 'after_setup_theme',
            'type'  => 'action',
            'callback' => static function (): void {
                add_theme_support('responsive-embeds');
            },
        ]);

        self::register('post-thumbnails', [
            'label' => 'Post Thumbnails',
            'group' => 'theme',
            'hook'  => 'after_setup_theme',
            'type'  => 'action',
            'callback' => static function (): void {
                add_theme_support('post-thumbnails');
            },
        ]);

        self::register('wp-block-styles', [
            'label' => 'Block Styles',
            'group' => 'theme',
            'hook'  => 'after_setup_theme',
            'type'  => 'action',
            'callback' => static function (): void {
                add_theme_support('wp-block-styles');
            },
        ]);

        self::register('html5', [
            'label'   => 'HTML5 Support',
            'group'   => 'theme',
            'hook'    => 'after_setup_theme',
            'type'    => 'action',
            'callback' => static function (): void {
                add_theme_support('html5', [
                    'comment-list', 'comment-form', 'search-form', 'gallery', 'caption', 'style', 'script',
                ]);
            },
        ]);

        // Editor Controls
        self::register('disable-remote-block-patterns', [
            'label'   => 'Disable Remote Block Patterns',
            'group'   => 'editor',
            'default' => true,
            'hook'    => 'should_load_remote_block_patterns',
            'type'    => 'filter',
            'callback' => '__return_false',
        ]);

        self::register('disable-core-block-patterns', [
            'label'   => 'Disable Core Block Patterns',
            'group'   => 'editor',
            'default' => true,
            'hook'    => 'after_setup_theme',
            'type'    => 'action',
            'callback' => static function (): void {
                remove_theme_support('core-block-patterns');
            },
        ]);

        self::register('restrict-block-types', [
            'label'   => 'Restrict Block Types',
            'group'   => 'editor',
            'default' => false,
            'options' => ['allowed' => []],
            'setup'   => static function (string $id): void {
                if (!self::enabled($id)) {
                    return;
                }
                $allowed = self::option($id, 'allowed', []);
                if (!empty($allowed)) {
                    add_filter('allowed_block_types_all', static fn() => $allowed);
                }
            },
        ]);

        self::register('openverse', [
            'label'   => 'Openverse Integration',
            'group'   => 'editor',
            'default' => false,
            'hook'    => 'wp_openverse_enabled',
            'type'    => 'filter',
            'callback' => '__return_false',
        ]);

        // Admin Customization
        self::register('post-lock-window', [
            'label'   => 'Post Lock Window',
            'group'   => 'admin',
            'default' => true,
            'options' => ['interval' => 300],
            'setup'   => static function (string $id): void {
                if (!self::enabled($id)) {
                    return;
                }
                $interval = (int) self::option($id, 'interval', 300);
                add_filter('wp_check_post_lock_window', static fn() => $interval);
            },
        ]);

        self::register('remove-dashboard-widgets', [
            'label'   => 'Remove Dashboard Widgets',
            'group'   => 'admin',
            'default' => true,
            'setup'   => static function (string $id): void {
                if (!self::enabled($id)) {
                    return;
                }
                add_action('wp_dashboard_setup', static function (): void {
                    remove_meta_box('dashboard_primary', 'dashboard', 'side');
                    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
                    remove_action('welcome_panel', 'wp_welcome_panel');
                });
            },
        ]);

        self::register('login-branding', [
            'label'   => 'Login Branding',
            'group'   => 'admin',
            'default' => true,
            'setup'   => static function (string $id): void {
                if (!self::enabled($id)) {
                    return;
                }
                add_action('login_enqueue_scripts', static function (): void {
                    $cssPath = EXAMPLEPRESS_MU_DIR . '/assets/src/css/login.css';
                    if (file_exists($cssPath)) {
                        wp_enqueue_style(
                            'examplepress-login',
                            EXAMPLEPRESS_MU_URI . '/assets/src/css/login.css',
                            [],
                            EXAMPLEPRESS_MU_VERSION
                        );
                    }
                });
            },
        ]);

        // Design Tokens — each feature owns its own slice of theme.json.
        self::register('theme-colors', [
            'label'   => 'Theme Colors',
            'group'   => 'design',
            'default' => true,
            'options' => ['palette' => []],
            'setup'   => [self::class, 'setupColorsTokens'],
        ]);

        self::register('theme-layout', [
            'label'   => 'Theme Layout',
            'group'   => 'design',
            'default' => true,
            'options' => ['wide_size' => '1200px', 'content_size' => '800px'],
            'setup'   => [self::class, 'setupLayoutTokens'],
        ]);

        self::register('theme-typography', [
            'label'   => 'Theme Typography',
            'group'   => 'design',
            'default' => true,
            'options' => ['font_families' => [], 'font_sizes' => []],
            'setup'   => [self::class, 'setupTypographyTokens'],
        ]);

        self::register('design-strict', [
            'label'   => 'Design Strict Mode',
            'group'   => 'design',
            'default' => false,
            'setup'   => [self::class, 'setupStrictTokens'],
        ]);

        self::register('theme-spacing', [
            'label'   => 'Theme Spacing',
            'group'   => 'design',
            'default' => false,
            'options' => [],
            'setup'   => [self::class, 'setupSpacingTokens'],
        ]);

        self::register('theme-borders', [
            'label'   => 'Theme Borders',
            'group'   => 'design',
            'default' => false,
            'options' => [],
            'setup'   => [self::class, 'setupBordersTokens'],
        ]);

        self::register('theme-shadows', [
            'label'   => 'Theme Shadows',
            'group'   => 'design',
            'default' => false,
            'options' => [],
            'setup'   => [self::class, 'setupShadowsTokens'],
        ]);

        self::register('theme-global-styles', [
            'label'   => 'Global Styles',
            'group'   => 'design',
            'default' => false,
            'options' => [],
            'setup'   => [self::class, 'setupGlobalStylesTokens'],
        ]);

        // Blockstudio features
        $bsFeatures = [
            'blockstudio-assets'       => ['label' => 'Blockstudio Assets',       'options' => ['enqueue' => true]],
            'blockstudio-asset-reset'  => ['label' => 'Blockstudio Asset Reset',  'options' => []],
            'blockstudio-minify'       => ['label' => 'Blockstudio Minify',       'options' => []],
            'blockstudio-scss'         => ['label' => 'Blockstudio SCSS',         'options' => []],
            'blockstudio-tailwind'     => ['label' => 'Blockstudio Tailwind',     'options' => []],
            'blockstudio-editor'       => ['label' => 'Blockstudio Editor',       'options' => []],
            'blockstudio-block-editor' => ['label' => 'Blockstudio Block Editor', 'options' => []],
            'blockstudio-ai-context'   => ['label' => 'Blockstudio AI Context',   'options' => ['enabled' => false]],
            'blockstudio-block-tags'   => ['label' => 'Blockstudio Block Tags',   'options' => []],
            'blockstudio-dev'          => ['label' => 'Blockstudio Dev Tools',    'options' => []],
            'blockstudio-users'        => ['label' => 'Blockstudio Users',        'options' => []],
        ];

        foreach ($bsFeatures as $bsId => $bsArgs) {
            self::register($bsId, array_merge($bsArgs, [
                'group'   => 'blockstudio',
                'default' => false,
                // IIFE factory: capture $bsId by value into a fresh closure
                // per iteration so the setup callable carries its own id
                // regardless of loop-variable scoping surprises or future
                // refactors that turn the loop variable into a reference.
                'setup'   => self::makeBlockstudioSetup($bsId),
            ]));
        }
    }

    /**
     * Build a setup closure for a single Blockstudio feature with $bsId
     * captured by value via a higher-order factory.
     */
    private static function makeBlockstudioSetup(string $bsId): \Closure
    {
        static $bsFilterMap = [
            'blockstudio-assets'       => 'blockstudio/settings/assets',
            'blockstudio-asset-reset'  => 'blockstudio/settings/assetReset',
            'blockstudio-minify'       => 'blockstudio/settings/minify',
            'blockstudio-scss'         => 'blockstudio/settings/scss',
            'blockstudio-tailwind'     => 'blockstudio/settings/tailwind',
            'blockstudio-editor'       => 'blockstudio/settings/editor',
            'blockstudio-block-editor' => 'blockstudio/settings/blockEditor',
            'blockstudio-ai-context'   => 'blockstudio/settings/aiContext',
            'blockstudio-block-tags'   => 'blockstudio/settings/blockTags',
            'blockstudio-dev'          => 'blockstudio/settings/dev',
            'blockstudio-users'        => 'blockstudio/settings/users',
        ];

        $filter = $bsFilterMap[$bsId] ?? null;

        return static function (string $id) use ($bsId, $filter): void {
            if (!self::enabled($id)) {
                return;
            }
            if (!$filter) {
                return;
            }
            add_filter($filter, static function ($value) use ($bsId) {
                $json = ConfigManager::get();
                $opts = $json['features'][$bsId]['options'] ?? [];
                return !empty($opts) ? $opts : $value;
            });
        };
    }

    /**
     * Merge a data slice into wp_theme_json_data_theme.
     *
     * Each per-token-group setup* method hands its slice to this helper so
     * each design feature stays independent. The filter is added once per
     * call; WordPress gathers all contributions before theme.json is built.
     *
     * @param callable(): array $sliceBuilder Returns ['settings' => ..., 'styles' => ...].
     */
    private static function applyThemeJsonSlice(callable $sliceBuilder): void
    {
        add_filter('wp_theme_json_data_theme', static function ($themeJson) use ($sliceBuilder) {
            $slice = $sliceBuilder();
            if (empty($slice)) {
                return $themeJson;
            }

            $data = [];
            if (!empty($slice['settings'])) {
                $data['settings'] = $slice['settings'];
            }
            if (!empty($slice['styles'])) {
                $data['styles'] = $slice['styles'];
            }

            if (!empty($data)) {
                $data['version'] = 2;
                $themeJson->update_with($data);
            }

            return $themeJson;
        });
    }

    public static function setupColorsTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            $palette = self::option('theme-colors', 'palette', []);
            if (empty($palette)) {
                return [];
            }
            return ['settings' => ['color' => ['palette' => $palette]]];
        });
    }

    public static function setupLayoutTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            return [
                'settings' => [
                    'layout' => [
                        'wideSize'    => self::option('theme-layout', 'wide_size', '1200px'),
                        'contentSize' => self::option('theme-layout', 'content_size', '800px'),
                    ],
                ],
            ];
        });
    }

    public static function setupTypographyTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            $typography = [];

            $fontFamilies = self::option('theme-typography', 'font_families', []);
            if (!empty($fontFamilies)) {
                $typography['fontFamilies'] = $fontFamilies;
            }
            $fontSizes = self::option('theme-typography', 'font_sizes', []);
            if (!empty($fontSizes)) {
                $typography['fontSizes'] = $fontSizes;
            }

            $typoFlags = ['fluid', 'line_height', 'text_columns', 'writing_mode', 'drop_cap', 'default_font_sizes'];
            foreach ($typoFlags as $flag) {
                $val = self::option('theme-typography', $flag);
                if ($val !== null) {
                    $camelKey = lcfirst(str_replace('_', '', ucwords($flag, '_')));
                    $typography[$camelKey] = $val;
                }
            }

            if (empty($typography)) {
                return [];
            }
            return ['settings' => ['typography' => $typography]];
        });
    }

    public static function setupStrictTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static fn(): array => [
            'settings' => [
                'color'      => [
                    'custom'         => false,
                    'customGradient' => false,
                ],
                'typography' => ['customFontSize' => false],
                'spacing'    => ['customSpacingSize' => false],
            ],
        ]);
    }

    public static function setupSpacingTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            $spacing = [];

            $spacingSizes = self::option('theme-spacing', 'spacing_sizes', []);
            if (!empty($spacingSizes)) {
                $spacing['spacingSizes'] = $spacingSizes;
            }
            foreach (['spacing_scale' => 'spacingScale', 'block_gap' => 'blockGap', 'units' => 'units'] as $opt => $key) {
                $val = self::option('theme-spacing', $opt);
                if ($val !== null) {
                    $spacing[$key] = $val;
                }
            }

            if (empty($spacing)) {
                return [];
            }
            return ['settings' => ['spacing' => $spacing]];
        });
    }

    public static function setupBordersTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            $settings = [];

            $radiusSizes = self::option('theme-borders', 'radius_sizes', []);
            if (!empty($radiusSizes)) {
                $settings['custom']['border']['radiusSizes'] = $radiusSizes;
            }
            foreach (['color', 'radius', 'style', 'width'] as $key) {
                $val = self::option('theme-borders', $key);
                if ($val !== null) {
                    $settings['border'][$key] = $val;
                }
            }

            if (empty($settings)) {
                return [];
            }
            return ['settings' => $settings];
        });
    }

    public static function setupShadowsTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            $shadow = [];

            $presets = self::option('theme-shadows', 'presets', []);
            if (!empty($presets)) {
                $shadow['presets'] = $presets;
            }
            $defaultPresets = self::option('theme-shadows', 'default_presets');
            if ($defaultPresets !== null) {
                $shadow['defaultPresets'] = $defaultPresets;
            }

            if (empty($shadow)) {
                return [];
            }
            return ['settings' => ['shadow' => $shadow]];
        });
    }

    public static function setupGlobalStylesTokens(string $_id): void
    {
        self::applyThemeJsonSlice(static function (): array {
            $styles = [];

            foreach (['background', 'text'] as $key) {
                $val = self::option('theme-global-styles', $key);
                if ($val !== null) {
                    $styles['color'][$key] = $val;
                }
            }
            $fontFamily = self::option('theme-global-styles', 'font_family');
            if ($fontFamily !== null) {
                $styles['typography']['fontFamily'] = $fontFamily;
            }
            $fontSize = self::option('theme-global-styles', 'font_size');
            if ($fontSize !== null) {
                $styles['typography']['fontSize'] = $fontSize;
            }
            $padding = self::option('theme-global-styles', 'padding');
            if ($padding !== null) {
                $styles['spacing']['padding'] = $padding;
            }

            if (empty($styles)) {
                return [];
            }
            return ['styles' => $styles];
        });
    }
}
