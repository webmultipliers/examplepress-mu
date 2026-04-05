<?php

declare(strict_types=1);

namespace ExamplePress\MU\Config;

/**
 * Reads and caches the platform configuration from examplepress.json.
 *
 * CRITICAL FIX: Reads from the MU plugin directory (__DIR__/../../examplepress.json),
 * NOT from get_template_directory(). The MU plugin's examplepress.json is the
 * absolute source of truth since the theme was gutted in v1.2.0.
 */
final class ConfigManager
{
    private static ?array $config = null;

    /**
     * Return the parsed, merged examplepress.json configuration.
     * Cached for the lifetime of the request.
     *
     * The MU plugin's examplepress.json is the infrastructure baseline
     * (features, dependencies, updater). The theme's examplepress.json
     * supplies design tokens (colors, typography, layout) and Blockstudio
     * settings. Theme values win via deep merge so the theme retains full
     * control over the visual design system.
     */
    public static function get(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        // 1. MU plugin baseline (infrastructure, features, dependencies).
        $muPath = dirname(__DIR__, 2) . '/examplepress.json';
        $muData = [];
        if (file_exists($muPath)) {
            $decoded = json_decode((string) file_get_contents($muPath), true);
            $muData = is_array($decoded) ? $decoded : [];
        }

        // 2. Theme layer (design tokens, blockstudio, overrides).
        $themePath = (defined('EP_THEME_PATH') ? EP_THEME_PATH : get_template_directory())
            . '/examplepress.json';
        $themeData = [];
        if (file_exists($themePath)) {
            $decoded = json_decode((string) file_get_contents($themePath), true);
            $themeData = is_array($decoded) ? $decoded : [];
        }

        // Deep merge: theme wins so it controls design tokens.
        self::$config = array_replace_recursive($muData, $themeData);

        self::$config = self::normaliseDesign(self::$config);
        self::$config = self::normaliseBlockstudio(self::$config);
        self::$config = self::normaliseDependencies(self::$config);

        return self::$config;
    }

    /**
     * Force-reload the configuration on next access.
     */
    public static function reset(): void
    {
        self::$config = null;
    }

    /**
     * Map "design" shorthand keys into their corresponding feature options.
     */
    private static function normaliseDesign(array $config): array
    {
        $design = $config['design'] ?? [];

        if (empty($design)) {
            return $config;
        }

        $features = $config['features'] ?? [];

        if (isset($design['colors'])) {
            $features['theme-colors']['options']['palette'] = $design['colors'];
        }

        if (isset($design['layout']['wideSize'])) {
            $features['theme-layout']['options']['wide_size'] = $design['layout']['wideSize'];
        }
        if (isset($design['layout']['contentSize'])) {
            $features['theme-layout']['options']['content_size'] = $design['layout']['contentSize'];
        }

        if (isset($design['typography']['fontFamilies'])) {
            $features['theme-typography']['options']['font_families'] = $design['typography']['fontFamilies'];
        }
        if (isset($design['typography']['fontSizes'])) {
            $features['theme-typography']['options']['font_sizes'] = $design['typography']['fontSizes'];
        }

        if (!empty($design['strict'])) {
            $features['design-strict']['enabled'] = true;
        }

        if (isset($design['spacing'])) {
            $spacing = $design['spacing'];
            if (isset($spacing['spacingSizes'])) {
                $features['theme-spacing']['options']['spacing_sizes'] = $spacing['spacingSizes'];
            }
            if (isset($spacing['spacingScale'])) {
                $features['theme-spacing']['options']['spacing_scale'] = $spacing['spacingScale'];
            }
            if (isset($spacing['blockGap'])) {
                $features['theme-spacing']['options']['block_gap'] = $spacing['blockGap'];
            }
            if (isset($spacing['units'])) {
                $features['theme-spacing']['options']['units'] = $spacing['units'];
            }
        }

        if (isset($design['borders'])) {
            $borders = $design['borders'];
            if (isset($borders['radiusSizes'])) {
                $features['theme-borders']['options']['radius_sizes'] = $borders['radiusSizes'];
            }
            foreach (['color', 'radius', 'style', 'width'] as $key) {
                if (isset($borders[$key])) {
                    $features['theme-borders']['options'][$key] = $borders[$key];
                }
            }
        }

        if (isset($design['shadows'])) {
            $shadows = $design['shadows'];
            if (isset($shadows['presets'])) {
                $features['theme-shadows']['options']['presets'] = $shadows['presets'];
            }
            if (isset($shadows['defaultPresets'])) {
                $features['theme-shadows']['options']['default_presets'] = $shadows['defaultPresets'];
            }
        }

        if (isset($design['globalStyles'])) {
            $gs = $design['globalStyles'];
            $features['theme-global-styles']['enabled'] = true;
            foreach (['background', 'text'] as $key) {
                if (isset($gs[$key])) {
                    $features['theme-global-styles']['options'][$key] = $gs[$key];
                }
            }
            if (isset($gs['fontFamily'])) {
                $features['theme-global-styles']['options']['font_family'] = $gs['fontFamily'];
            }
            if (isset($gs['fontSize'])) {
                $features['theme-global-styles']['options']['font_size'] = $gs['fontSize'];
            }
            if (isset($gs['padding'])) {
                $features['theme-global-styles']['options']['padding'] = $gs['padding'];
            }
        }

        if (isset($design['typography'])) {
            $typoFlags = [
                'fluid'            => 'fluid',
                'lineHeight'       => 'line_height',
                'textColumns'      => 'text_columns',
                'writingMode'      => 'writing_mode',
                'dropCap'          => 'drop_cap',
                'defaultFontSizes' => 'default_font_sizes',
            ];
            foreach ($typoFlags as $jsonKey => $optionKey) {
                if (isset($design['typography'][$jsonKey])) {
                    $features['theme-typography']['options'][$optionKey] = $design['typography'][$jsonKey];
                }
            }
        }

        $config['features'] = $features;

        return $config;
    }

    /**
     * Map "blockstudio" shorthand keys into their corresponding feature options.
     */
    private static function normaliseBlockstudio(array $config): array
    {
        $bs = $config['blockstudio'] ?? [];

        if (empty($bs)) {
            return $config;
        }

        $features = $config['features'] ?? [];

        $set = static function (string $featureId, array $options) use (&$features): void {
            $features[$featureId]['enabled'] = true;
            foreach ($options as $key => $value) {
                $features[$featureId]['options'][$key] = $value;
            }
        };

        if (isset($bs['assets'])) {
            $set('blockstudio-assets', ['enqueue' => $bs['assets']['enqueue'] ?? true]);
        }

        if (isset($bs['assetReset'])) {
            $opts = [];
            if (isset($bs['assetReset']['enabled'])) {
                $opts['enabled'] = $bs['assetReset']['enabled'];
            }
            if (isset($bs['assetReset']['fullWidth'])) {
                $opts['full_width'] = $bs['assetReset']['fullWidth'];
            }
            $set('blockstudio-asset-reset', $opts);
        }

        if (isset($bs['minify'])) {
            $opts = [];
            if (isset($bs['minify']['css'])) {
                $opts['css'] = $bs['minify']['css'];
            }
            if (isset($bs['minify']['js'])) {
                $opts['js'] = $bs['minify']['js'];
            }
            $set('blockstudio-minify', $opts);
        }

        if (isset($bs['scss'])) {
            $opts = [];
            if (isset($bs['scss']['scss'])) {
                $opts['scss'] = $bs['scss']['scss'];
            }
            if (isset($bs['scss']['scssFiles'])) {
                $opts['scss_files'] = $bs['scss']['scssFiles'];
            }
            $set('blockstudio-scss', $opts);
        }

        if (isset($bs['tailwind'])) {
            $opts = [];
            if (isset($bs['tailwind']['enabled'])) {
                $opts['enabled'] = $bs['tailwind']['enabled'];
            }
            if (isset($bs['tailwind']['config'])) {
                $opts['config'] = $bs['tailwind']['config'];
            }
            $set('blockstudio-tailwind', $opts);
        }

        if (isset($bs['editor'])) {
            $opts = [];
            if (isset($bs['editor']['formatOnSave'])) {
                $opts['format_on_save'] = $bs['editor']['formatOnSave'];
            }
            if (isset($bs['editor']['assets'])) {
                $opts['assets'] = $bs['editor']['assets'];
            }
            if (isset($bs['editor']['markup'])) {
                $opts['markup'] = $bs['editor']['markup'];
            }
            $set('blockstudio-editor', $opts);
        }

        if (isset($bs['blockEditor'])) {
            $opts = [];
            if (isset($bs['blockEditor']['disableLoading'])) {
                $opts['disable_loading'] = $bs['blockEditor']['disableLoading'];
            }
            if (isset($bs['blockEditor']['cssClasses'])) {
                $opts['css_classes'] = $bs['blockEditor']['cssClasses'];
            }
            if (isset($bs['blockEditor']['cssVariables'])) {
                $opts['css_variables'] = $bs['blockEditor']['cssVariables'];
            }
            $set('blockstudio-block-editor', $opts);
        }

        if (isset($bs['aiContext'])) {
            $val = $bs['aiContext'];
            $set('blockstudio-ai-context', [
                'enabled' => is_bool($val) ? $val : ($val['enabled'] ?? false),
            ]);
        }

        if (isset($bs['blockTags'])) {
            $opts = [];
            if (isset($bs['blockTags']['enabled'])) {
                $opts['enabled'] = $bs['blockTags']['enabled'];
            }
            if (isset($bs['blockTags']['allow'])) {
                $opts['allow'] = $bs['blockTags']['allow'];
            }
            if (isset($bs['blockTags']['deny'])) {
                $opts['deny'] = $bs['blockTags']['deny'];
            }
            $set('blockstudio-block-tags', $opts);
        }

        if (isset($bs['dev'])) {
            $opts = [];
            if (isset($bs['dev']['grab'])) {
                $opts['grab'] = $bs['dev']['grab'];
            }
            if (isset($bs['dev']['perf'])) {
                $opts['perf'] = $bs['dev']['perf'];
            }
            if (isset($bs['dev']['canvas'])) {
                $opts['canvas'] = $bs['dev']['canvas'];
            }
            if (isset($bs['dev']['canvasAdminBar'])) {
                $opts['canvas_admin_bar'] = $bs['dev']['canvasAdminBar'];
            }
            $set('blockstudio-dev', $opts);
        }

        if (isset($bs['users'])) {
            $opts = [];
            if (isset($bs['users']['ids'])) {
                $opts['ids'] = $bs['users']['ids'];
            }
            if (isset($bs['users']['roles'])) {
                $opts['roles'] = $bs['users']['roles'];
            }
            $set('blockstudio-users', $opts);
        }

        $config['features'] = $features;

        return $config;
    }

    /**
     * Normalise dependency config to a flat indexed array.
     */
    private static function normaliseDependencies(array $config): array
    {
        $raw = $config['dependencies'] ?? [];
        $config['dependencies'] = is_array($raw) ? array_values($raw) : [];
        return $config;
    }
}
