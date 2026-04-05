<?php
/**
 * Blockstudio Integration Features
 *
 * Bridge Blockstudio runtime settings into the ExamplePress feature
 * registry. Each feature owns a logical group of Blockstudio config
 * and writes back via the `blockstudio/settings/{path}` filter pattern.
 *
 * When a feature is disabled, Blockstudio falls back to its own
 * blockstudio.json or built-in defaults. When enabled, ExamplePress
 * takes ownership of those settings through filters.
 */

// ── Assets ───────────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-assets', [
	'label'   => 'Blockstudio Assets',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'enqueue' => true,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/assets/enqueue', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'enqueue', true );
			} );
		} );
	},
] );

examplepress_register_feature( 'blockstudio-asset-reset', [
	'label'   => 'Blockstudio Asset Reset',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'enabled'    => false,
		'full_width' => [],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/assets/reset/enabled', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'enabled', false );
			} );

			$full_width = (array) examplepress_feature_option( $id, 'full_width', [] );
			if ( ! empty( $full_width ) ) {
				add_filter( 'blockstudio/settings/assets/reset/fullWidth', function () use ( $full_width ) {
					return $full_width;
				} );
			}
		} );
	},
] );

examplepress_register_feature( 'blockstudio-minify', [
	'label'   => 'Blockstudio Minification',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'css' => false,
		'js'  => false,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/assets/minify/css', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'css', false );
			} );
			add_filter( 'blockstudio/settings/assets/minify/js', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'js', false );
			} );
		} );
	},
] );

examplepress_register_feature( 'blockstudio-scss', [
	'label'   => 'Blockstudio SCSS Processing',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'scss'       => false,
		'scss_files' => true,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/assets/process/scss', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'scss', false );
			} );
			add_filter( 'blockstudio/settings/assets/process/scssFiles', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'scss_files', true );
			} );
		} );
	},
] );

// ── Tailwind ─────────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-tailwind', [
	'label'   => 'Blockstudio Tailwind',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'enabled' => false,
		'config'  => '',
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/tailwind/enabled', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'enabled', false );
			} );

			$config = examplepress_feature_option( $id, 'config', '' );
			if ( $config ) {
				add_filter( 'blockstudio/settings/tailwind/config', function () use ( $config ) {
					return $config;
				} );
			}
		} );
	},
] );

// ── Editor ───────────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-editor', [
	'label'   => 'Blockstudio Editor Settings',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'format_on_save' => false,
		'assets'         => [],
		'markup'         => false,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/editor/formatOnSave', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'format_on_save', false );
			} );

			$assets = (array) examplepress_feature_option( $id, 'assets', [] );
			if ( ! empty( $assets ) ) {
				add_filter( 'blockstudio/settings/editor/assets', function () use ( $assets ) {
					return $assets;
				} );
			}

			$markup = examplepress_feature_option( $id, 'markup', false );
			if ( $markup !== false ) {
				add_filter( 'blockstudio/settings/editor/markup', function () use ( $markup ) {
					return $markup;
				} );
			}
		} );
	},
] );

// ── Block Editor ─────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-block-editor', [
	'label'   => 'Blockstudio Block Editor',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'disable_loading' => false,
		'css_classes'     => [],
		'css_variables'   => [],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/blockEditor/disableLoading', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'disable_loading', false );
			} );

			$css_classes = (array) examplepress_feature_option( $id, 'css_classes', [] );
			if ( ! empty( $css_classes ) ) {
				add_filter( 'blockstudio/settings/blockEditor/cssClasses', function () use ( $css_classes ) {
					return $css_classes;
				} );
			}

			$css_variables = (array) examplepress_feature_option( $id, 'css_variables', [] );
			if ( ! empty( $css_variables ) ) {
				add_filter( 'blockstudio/settings/blockEditor/cssVariables', function () use ( $css_variables ) {
					return $css_variables;
				} );
			}
		} );
	},
] );

// ── AI Context ───────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-ai-context', [
	'label'   => 'Blockstudio AI Context Generation',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'enabled' => false,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/ai/enableContextGeneration', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'enabled', false );
			} );
		} );
	},
] );

// ── Block Tags ───────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-block-tags', [
	'label'   => 'Blockstudio Block Tags',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'enabled' => false,
		'allow'   => [],
		'deny'    => [],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/blockTags/enabled', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'enabled', false );
			} );

			$allow = (array) examplepress_feature_option( $id, 'allow', [] );
			if ( ! empty( $allow ) ) {
				add_filter( 'blockstudio/settings/blockTags/allow', function () use ( $allow ) {
					return $allow;
				} );
			}

			$deny = (array) examplepress_feature_option( $id, 'deny', [] );
			if ( ! empty( $deny ) ) {
				add_filter( 'blockstudio/settings/blockTags/deny', function () use ( $deny ) {
					return $deny;
				} );
			}
		} );
	},
] );

// ── Dev Tools ────────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-dev', [
	'label'   => 'Blockstudio Dev Tools',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'grab'             => false,
		'perf'             => false,
		'canvas'           => false,
		'canvas_admin_bar' => true,
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'blockstudio/settings/dev/grab/enabled', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'grab', false );
			} );
			add_filter( 'blockstudio/settings/dev/perf', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'perf', false );
			} );
			add_filter( 'blockstudio/settings/dev/canvas/enabled', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'canvas', false );
			} );
			add_filter( 'blockstudio/settings/dev/canvas/adminBar', function () use ( $id ) {
				return (bool) examplepress_feature_option( $id, 'canvas_admin_bar', true );
			} );
		} );
	},
] );

// ── Users ────────────────────────────────────────────────────────────

examplepress_register_feature( 'blockstudio-users', [
	'label'   => 'Blockstudio User Restrictions',
	'group'   => 'blockstudio',
	'default' => false,
	'options' => [
		'ids'   => [],
		'roles' => [],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			$ids = (array) examplepress_feature_option( $id, 'ids', [] );
			if ( ! empty( $ids ) ) {
				add_filter( 'blockstudio/settings/users/ids', function () use ( $ids ) {
					return $ids;
				} );
			}

			$roles = (array) examplepress_feature_option( $id, 'roles', [] );
			if ( ! empty( $roles ) ) {
				add_filter( 'blockstudio/settings/users/roles', function () use ( $roles ) {
					return $roles;
				} );
			}
		} );
	},
] );
