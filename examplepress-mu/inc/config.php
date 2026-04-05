<?php
/**
 * ExamplePress Configuration
 *
 * Reads and caches examplepress.json. Normalises the "design" shorthand
 * into feature-option overrides so the registry can resolve them
 * transparently via the standard resolution order:
 *
 *   PHP Filter  >  examplepress.json  >  Registration Default
 */

/**
 * Return the parsed examplepress.json configuration.
 *
 * The result is cached for the lifetime of the request.
 */
function examplepress_get_config() {
	static $config = null;

	if ( $config !== null ) {
		return $config;
	}

	$path = EP_THEME_PATH . '/examplepress.json';

	if ( ! file_exists( $path ) ) {
		$config = [];
		return $config;
	}

	$data   = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$config = is_array( $data ) ? $data : [];

	$config = examplepress_normalise_design_config( $config );
	$config = examplepress_normalise_blockstudio_config( $config );
	$config = examplepress_normalise_dependencies_config( $config );

	return $config;
}

/**
 * Map "design" shorthand keys into their corresponding feature options.
 *
 * design.colors                    → features.theme-colors.options.palette
 * design.layout.wideSize           → features.theme-layout.options.wide_size
 * design.layout.contentSize        → features.theme-layout.options.content_size
 * design.typography.fontFamilies   → features.theme-typography.options.font_families
 * design.typography.fontSizes      → features.theme-typography.options.font_sizes
 * design.strict                    → features.design-strict.enabled
 */
function examplepress_normalise_design_config( array $config ) {
	$design = $config['design'] ?? [];

	if ( empty( $design ) ) {
		return $config;
	}

	$features = $config['features'] ?? [];

	// Colors → theme-colors.options.palette
	if ( isset( $design['colors'] ) ) {
		$features['theme-colors']['options']['palette'] = $design['colors'];
	}

	// Layout → theme-layout.options
	if ( isset( $design['layout']['wideSize'] ) ) {
		$features['theme-layout']['options']['wide_size'] = $design['layout']['wideSize'];
	}
	if ( isset( $design['layout']['contentSize'] ) ) {
		$features['theme-layout']['options']['content_size'] = $design['layout']['contentSize'];
	}

	// Typography → theme-typography.options
	if ( isset( $design['typography']['fontFamilies'] ) ) {
		$features['theme-typography']['options']['font_families'] = $design['typography']['fontFamilies'];
	}
	if ( isset( $design['typography']['fontSizes'] ) ) {
		$features['theme-typography']['options']['font_sizes'] = $design['typography']['fontSizes'];
	}

	// Strict mode → design-strict.enabled
	if ( ! empty( $design['strict'] ) ) {
		$features['design-strict']['enabled'] = true;
	}

	// Spacing → theme-spacing.options
	if ( isset( $design['spacing'] ) ) {
		$spacing = $design['spacing'];
		if ( isset( $spacing['spacingSizes'] ) ) {
			$features['theme-spacing']['options']['spacing_sizes'] = $spacing['spacingSizes'];
		}
		if ( isset( $spacing['spacingScale'] ) ) {
			$features['theme-spacing']['options']['spacing_scale'] = $spacing['spacingScale'];
		}
		if ( isset( $spacing['blockGap'] ) ) {
			$features['theme-spacing']['options']['block_gap'] = $spacing['blockGap'];
		}
		if ( isset( $spacing['units'] ) ) {
			$features['theme-spacing']['options']['units'] = $spacing['units'];
		}
	}

	// Borders → theme-borders.options
	if ( isset( $design['borders'] ) ) {
		$borders = $design['borders'];
		if ( isset( $borders['radiusSizes'] ) ) {
			$features['theme-borders']['options']['radius_sizes'] = $borders['radiusSizes'];
		}
		foreach ( [ 'color', 'radius', 'style', 'width' ] as $key ) {
			if ( isset( $borders[ $key ] ) ) {
				$features['theme-borders']['options'][ $key ] = $borders[ $key ];
			}
		}
	}

	// Shadows → theme-shadows.options
	if ( isset( $design['shadows'] ) ) {
		$shadows = $design['shadows'];
		if ( isset( $shadows['presets'] ) ) {
			$features['theme-shadows']['options']['presets'] = $shadows['presets'];
		}
		if ( isset( $shadows['defaultPresets'] ) ) {
			$features['theme-shadows']['options']['default_presets'] = $shadows['defaultPresets'];
		}
	}

	// Global styles → theme-global-styles.options
	if ( isset( $design['globalStyles'] ) ) {
		$gs = $design['globalStyles'];
		$features['theme-global-styles']['enabled'] = true;
		foreach ( [ 'background', 'text' ] as $key ) {
			if ( isset( $gs[ $key ] ) ) {
				$features['theme-global-styles']['options'][ $key ] = $gs[ $key ];
			}
		}
		if ( isset( $gs['fontFamily'] ) ) {
			$features['theme-global-styles']['options']['font_family'] = $gs['fontFamily'];
		}
		if ( isset( $gs['fontSize'] ) ) {
			$features['theme-global-styles']['options']['font_size'] = $gs['fontSize'];
		}
		if ( isset( $gs['padding'] ) ) {
			$features['theme-global-styles']['options']['padding'] = $gs['padding'];
		}
	}

	// Extended typography flags → theme-typography.options
	if ( isset( $design['typography'] ) ) {
		$typo_flags = [
			'fluid'            => 'fluid',
			'lineHeight'       => 'line_height',
			'textColumns'      => 'text_columns',
			'writingMode'      => 'writing_mode',
			'dropCap'          => 'drop_cap',
			'defaultFontSizes' => 'default_font_sizes',
		];
		foreach ( $typo_flags as $json_key => $option_key ) {
			if ( isset( $design['typography'][ $json_key ] ) ) {
				$features['theme-typography']['options'][ $option_key ] = $design['typography'][ $json_key ];
			}
		}
	}

	$config['features'] = $features;

	return $config;
}

/**
 * Map "blockstudio" shorthand keys into their corresponding feature options.
 *
 * blockstudio.assets.enqueue              → features.blockstudio-assets.options.enqueue
 * blockstudio.assetReset.enabled          → features.blockstudio-asset-reset.options.enabled
 * blockstudio.assetReset.fullWidth        → features.blockstudio-asset-reset.options.full_width
 * blockstudio.minify.css / .js            → features.blockstudio-minify.options.css / .js
 * blockstudio.scss.scss / .scssFiles      → features.blockstudio-scss.options.scss / .scss_files
 * blockstudio.tailwind.enabled / .config  → features.blockstudio-tailwind.options.enabled / .config
 * blockstudio.editor.*                    → features.blockstudio-editor.options.*
 * blockstudio.blockEditor.*               → features.blockstudio-block-editor.options.*
 * blockstudio.aiContext                   → features.blockstudio-ai-context.options.enabled
 * blockstudio.blockTags.*                 → features.blockstudio-block-tags.options.*
 * blockstudio.dev.*                       → features.blockstudio-dev.options.*
 * blockstudio.users.*                     → features.blockstudio-users.options.*
 */
function examplepress_normalise_blockstudio_config( array $config ) {
	$bs = $config['blockstudio'] ?? [];

	if ( empty( $bs ) ) {
		return $config;
	}

	$features = $config['features'] ?? [];

	// Helper: enable a Blockstudio feature and set its options.
	$set = function ( $feature_id, $options ) use ( &$features ) {
		$features[ $feature_id ]['enabled'] = true;
		foreach ( $options as $key => $value ) {
			$features[ $feature_id ]['options'][ $key ] = $value;
		}
	};

	// Assets.
	if ( isset( $bs['assets'] ) ) {
		$set( 'blockstudio-assets', [
			'enqueue' => $bs['assets']['enqueue'] ?? true,
		] );
	}

	// Asset Reset.
	if ( isset( $bs['assetReset'] ) ) {
		$opts = [];
		if ( isset( $bs['assetReset']['enabled'] ) ) {
			$opts['enabled'] = $bs['assetReset']['enabled'];
		}
		if ( isset( $bs['assetReset']['fullWidth'] ) ) {
			$opts['full_width'] = $bs['assetReset']['fullWidth'];
		}
		$set( 'blockstudio-asset-reset', $opts );
	}

	// Minify.
	if ( isset( $bs['minify'] ) ) {
		$opts = [];
		if ( isset( $bs['minify']['css'] ) ) {
			$opts['css'] = $bs['minify']['css'];
		}
		if ( isset( $bs['minify']['js'] ) ) {
			$opts['js'] = $bs['minify']['js'];
		}
		$set( 'blockstudio-minify', $opts );
	}

	// SCSS.
	if ( isset( $bs['scss'] ) ) {
		$opts = [];
		if ( isset( $bs['scss']['scss'] ) ) {
			$opts['scss'] = $bs['scss']['scss'];
		}
		if ( isset( $bs['scss']['scssFiles'] ) ) {
			$opts['scss_files'] = $bs['scss']['scssFiles'];
		}
		$set( 'blockstudio-scss', $opts );
	}

	// Tailwind.
	if ( isset( $bs['tailwind'] ) ) {
		$opts = [];
		if ( isset( $bs['tailwind']['enabled'] ) ) {
			$opts['enabled'] = $bs['tailwind']['enabled'];
		}
		if ( isset( $bs['tailwind']['config'] ) ) {
			$opts['config'] = $bs['tailwind']['config'];
		}
		$set( 'blockstudio-tailwind', $opts );
	}

	// Editor.
	if ( isset( $bs['editor'] ) ) {
		$opts = [];
		if ( isset( $bs['editor']['formatOnSave'] ) ) {
			$opts['format_on_save'] = $bs['editor']['formatOnSave'];
		}
		if ( isset( $bs['editor']['assets'] ) ) {
			$opts['assets'] = $bs['editor']['assets'];
		}
		if ( isset( $bs['editor']['markup'] ) ) {
			$opts['markup'] = $bs['editor']['markup'];
		}
		$set( 'blockstudio-editor', $opts );
	}

	// Block Editor.
	if ( isset( $bs['blockEditor'] ) ) {
		$opts = [];
		if ( isset( $bs['blockEditor']['disableLoading'] ) ) {
			$opts['disable_loading'] = $bs['blockEditor']['disableLoading'];
		}
		if ( isset( $bs['blockEditor']['cssClasses'] ) ) {
			$opts['css_classes'] = $bs['blockEditor']['cssClasses'];
		}
		if ( isset( $bs['blockEditor']['cssVariables'] ) ) {
			$opts['css_variables'] = $bs['blockEditor']['cssVariables'];
		}
		$set( 'blockstudio-block-editor', $opts );
	}

	// AI Context — accepts boolean shorthand or object.
	if ( isset( $bs['aiContext'] ) ) {
		$val = $bs['aiContext'];
		$set( 'blockstudio-ai-context', [
			'enabled' => is_bool( $val ) ? $val : ( $val['enabled'] ?? false ),
		] );
	}

	// Block Tags.
	if ( isset( $bs['blockTags'] ) ) {
		$opts = [];
		if ( isset( $bs['blockTags']['enabled'] ) ) {
			$opts['enabled'] = $bs['blockTags']['enabled'];
		}
		if ( isset( $bs['blockTags']['allow'] ) ) {
			$opts['allow'] = $bs['blockTags']['allow'];
		}
		if ( isset( $bs['blockTags']['deny'] ) ) {
			$opts['deny'] = $bs['blockTags']['deny'];
		}
		$set( 'blockstudio-block-tags', $opts );
	}

	// Dev Tools.
	if ( isset( $bs['dev'] ) ) {
		$opts = [];
		if ( isset( $bs['dev']['grab'] ) ) {
			$opts['grab'] = $bs['dev']['grab'];
		}
		if ( isset( $bs['dev']['perf'] ) ) {
			$opts['perf'] = $bs['dev']['perf'];
		}
		if ( isset( $bs['dev']['canvas'] ) ) {
			$opts['canvas'] = $bs['dev']['canvas'];
		}
		if ( isset( $bs['dev']['canvasAdminBar'] ) ) {
			$opts['canvas_admin_bar'] = $bs['dev']['canvasAdminBar'];
		}
		$set( 'blockstudio-dev', $opts );
	}

	// Users.
	if ( isset( $bs['users'] ) ) {
		$opts = [];
		if ( isset( $bs['users']['ids'] ) ) {
			$opts['ids'] = $bs['users']['ids'];
		}
		if ( isset( $bs['users']['roles'] ) ) {
			$opts['roles'] = $bs['users']['roles'];
		}
		$set( 'blockstudio-users', $opts );
	}

	$config['features'] = $features;

	return $config;
}

/**
 * Normalise dependency config.
 *
 * Ensures config['dependencies'] is a flat indexed array.
 */
function examplepress_normalise_dependencies_config( array $config ) {
	$raw = $config['dependencies'] ?? [];
	$config['dependencies'] = is_array( $raw ) ? array_values( $raw ) : [];
	return $config;
}


