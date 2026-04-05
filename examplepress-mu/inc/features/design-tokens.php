<?php
/**
 * Design Token Features
 *
 * Inject colours, layout dimensions, and typography into WordPress
 * theme.json at runtime via the wp_theme_json_data_theme filter.
 * Values are resolved through the standard feature-option chain
 * (PHP filter > examplepress.json > registration default).
 */

examplepress_register_feature( 'theme-colors', [
	'label'   => 'Theme Color Palette',
	'group'   => 'design',
	'default' => true,
	'options' => [
		'palette' => [
			[ 'name' => 'Primary',     'slug' => 'primary',          'color' => '#a3122e' ],
			[ 'name' => 'Secondary',   'slug' => 'secondary',        'color' => '#205375' ],
			[ 'name' => 'Background',  'slug' => 'bg',               'color' => '#f4f6fb' ],
			[ 'name' => 'Separator',   'slug' => 'separator',        'color' => '#cfd8dc' ],
			[ 'name' => 'Dark',        'slug' => 'dark',             'color' => '#181a20' ],
			[ 'name' => 'Light',       'slug' => 'light',            'color' => '#f9fafb' ],
			[ 'name' => 'Black',       'slug' => 'black',            'color' => '#1b1b1b' ],
			[ 'name' => 'Danger',      'slug' => 'danger',           'color' => '#e63946' ],
			[ 'name' => 'Info',        'slug' => 'info',             'color' => '#3a86ff' ],
			[ 'name' => 'Success',     'slug' => 'success',          'color' => '#43aa8b' ],
			[ 'name' => 'Warning',     'slug' => 'warning',          'color' => '#ffd166' ],
			[ 'name' => 'Content',     'slug' => 'text-content',     'color' => '#23272f' ],
			[ 'name' => 'Highlight',   'slug' => 'text-highlight',   'color' => '#a3122e' ],
			[ 'name' => 'Muted',       'slug' => 'text-muted',       'color' => '#7b8a8b' ],
			[ 'name' => 'Placeholder', 'slug' => 'text-placeholder', 'color' => '#b0bec5' ],
		],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data                                 = $theme_json->get_data();
				$data['version']                      = 3;
				$data['settings']['color']['palette'] = (array) examplepress_feature_option( $id, 'palette', [] );
				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );

examplepress_register_feature( 'theme-layout', [
	'label'   => 'Theme Layout Dimensions',
	'group'   => 'design',
	'default' => true,
	'options' => [
		'wide_size'    => '1200px',
		'content_size' => '800px',
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data            = $theme_json->get_data();
				$data['version'] = 3;

				$data['settings']['layout']['wideSize']    = (string) examplepress_feature_option( $id, 'wide_size', '1200px' );
				$data['settings']['layout']['contentSize'] = (string) examplepress_feature_option( $id, 'content_size', '800px' );

				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );

examplepress_register_feature( 'theme-typography', [
	'label'   => 'Theme Typography',
	'group'   => 'design',
	'default' => true,
	'options' => [
		'font_families'      => [],
		'font_sizes'         => [],
		'fluid'              => false,
		'line_height'        => false,
		'text_columns'       => false,
		'writing_mode'       => false,
		'drop_cap'           => true,
		'default_font_sizes' => true,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data            = $theme_json->get_data();
				$data['version'] = 3;

				$families = (array) examplepress_feature_option( $id, 'font_families', [] );
				if ( ! empty( $families ) ) {
					$data['settings']['typography']['fontFamilies'] = $families;
				}

				$sizes = (array) examplepress_feature_option( $id, 'font_sizes', [] );
				if ( ! empty( $sizes ) ) {
					$data['settings']['typography']['fontSizes'] = $sizes;
				}

				// Typography capability flags.
				$bool_flags = [
					'fluid'              => 'fluid',
					'line_height'        => 'lineHeight',
					'text_columns'       => 'textColumns',
					'writing_mode'       => 'writingMode',
					'drop_cap'           => 'dropCap',
					'default_font_sizes' => 'defaultFontSizes',
				];

				foreach ( $bool_flags as $option_key => $json_key ) {
					$val = examplepress_feature_option( $id, $option_key, null );
					if ( $val !== null ) {
						$data['settings']['typography'][ $json_key ] = (bool) $val;
					}
				}

				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );

examplepress_register_feature( 'design-strict', [
	'label'   => 'Strict Design Mode',
	'group'   => 'design',
	'default' => false,
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function () {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) {
				$data                                = $theme_json->get_data();
				$data['version']                     = 3;
				$data['settings']['appearanceTools'] = false;
				return $theme_json->update_with( $data );
			}, 99 );
		} );
	},
] );

// ── Phase 1: New design token features ───────────────────────────────

examplepress_register_feature( 'theme-spacing', [
	'label'   => 'Theme Spacing',
	'group'   => 'design',
	'default' => true,
	'options' => [
		'spacing_sizes' => [],
		'spacing_scale' => [],
		'block_gap'     => true,
		'units'         => [ 'px', 'em', 'rem', 'vh', 'vw', '%' ],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data            = $theme_json->get_data();
				$data['version'] = 3;

				$sizes = (array) examplepress_feature_option( $id, 'spacing_sizes', [] );
				if ( ! empty( $sizes ) ) {
					$data['settings']['spacing']['spacingSizes'] = $sizes;
				}

				$scale = (array) examplepress_feature_option( $id, 'spacing_scale', [] );
				if ( ! empty( $scale ) ) {
					$data['settings']['spacing']['spacingScale'] = $scale;
				}

				$data['settings']['spacing']['blockGap'] = (bool) examplepress_feature_option( $id, 'block_gap', true );
				$data['settings']['spacing']['units']    = (array) examplepress_feature_option( $id, 'units', [ 'px', 'em', 'rem', 'vh', 'vw', '%' ] );

				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );

examplepress_register_feature( 'theme-borders', [
	'label'   => 'Theme Border Controls',
	'group'   => 'design',
	'default' => true,
	'options' => [
		'radius_sizes' => [],
		'color'        => true,
		'radius'       => true,
		'style'        => true,
		'width'        => true,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data            = $theme_json->get_data();
				$data['version'] = 3;

				$radius_sizes = (array) examplepress_feature_option( $id, 'radius_sizes', [] );
				if ( ! empty( $radius_sizes ) ) {
					$data['settings']['border']['radiusSizes'] = $radius_sizes;
				}

				$data['settings']['border']['color']  = (bool) examplepress_feature_option( $id, 'color', true );
				$data['settings']['border']['radius'] = (bool) examplepress_feature_option( $id, 'radius', true );
				$data['settings']['border']['style']  = (bool) examplepress_feature_option( $id, 'style', true );
				$data['settings']['border']['width']  = (bool) examplepress_feature_option( $id, 'width', true );

				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );

examplepress_register_feature( 'theme-shadows', [
	'label'   => 'Theme Shadow Presets',
	'group'   => 'design',
	'default' => true,
	'options' => [
		'presets'         => [],
		'default_presets' => true,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data            = $theme_json->get_data();
				$data['version'] = 3;

				$presets = (array) examplepress_feature_option( $id, 'presets', [] );
				if ( ! empty( $presets ) ) {
					$data['settings']['shadow']['presets'] = $presets;
				}

				$data['settings']['shadow']['defaultPresets'] = (bool) examplepress_feature_option( $id, 'default_presets', true );

				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );

examplepress_register_feature( 'theme-global-styles', [
	'label'   => 'Theme Global Styles',
	'group'   => 'design',
	'default' => false,
	'options' => [
		'background'  => '',
		'text'        => '',
		'font_family' => '',
		'font_size'   => '',
		'padding'     => [],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) use ( $id ) {
				$data            = $theme_json->get_data();
				$data['version'] = 3;

				$styles = [];

				$bg = examplepress_feature_option( $id, 'background', '' );
				if ( $bg ) {
					$styles['color']['background'] = $bg;
				}

				$text = examplepress_feature_option( $id, 'text', '' );
				if ( $text ) {
					$styles['color']['text'] = $text;
				}

				$font_family = examplepress_feature_option( $id, 'font_family', '' );
				if ( $font_family ) {
					$styles['typography']['fontFamily'] = $font_family;
				}

				$font_size = examplepress_feature_option( $id, 'font_size', '' );
				if ( $font_size ) {
					$styles['typography']['fontSize'] = $font_size;
				}

				$padding = (array) examplepress_feature_option( $id, 'padding', [] );
				if ( ! empty( $padding ) ) {
					$styles['spacing']['padding'] = $padding;
				}

				if ( ! empty( $styles ) ) {
					$data['styles'] = array_replace_recursive( $data['styles'] ?? [], $styles );
				}

				return $theme_json->update_with( $data );
			}, 39 );
		} );
	},
] );
