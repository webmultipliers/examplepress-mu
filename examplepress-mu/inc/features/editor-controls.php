<?php
/**
 * Editor & Content Control Features
 *
 * Block pattern toggles, block type restrictions, and
 * Openverse media category.
 */

examplepress_register_feature( 'disable-remote-block-patterns', [
	'label'    => 'Disable Remote Block Patterns',
	'group'    => 'editor',
	'default'  => true,
	'hook'     => 'should_load_remote_block_patterns',
	'callback' => '__return_false',
] );

examplepress_register_feature( 'disable-core-block-patterns', [
	'label'   => 'Disable Core Block Patterns',
	'group'   => 'editor',
	'default' => true,
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function () {
			add_action( 'init', function () {
				remove_theme_support( 'core-block-patterns' );
			} );
		} );
	},
] );

examplepress_register_feature( 'restrict-block-types', [
	'label'   => 'Restrict Block Types',
	'group'   => 'editor',
	'default' => false,
	'options' => [
		'types' => [
			'core/heading',
			'core/paragraph',
			'core/image',
			'core/list',
			'core/list-item',
		],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			add_filter( 'allowed_block_types_all', function ( $allowed_block_types, $block_editor_context ) use ( $id ) {
				if ( ! empty( $block_editor_context->post ) && $block_editor_context->post->post_type === 'wp_template' ) {
					return $allowed_block_types;
				}
				$types = (array) examplepress_feature_option( $id, 'types', [] );
				return apply_filters( 'examplepress_allowed_block_types', $types, $block_editor_context );
			}, 10, 2 );
		} );
	},
] );

examplepress_register_feature( 'openverse', [
	'label'   => 'Openverse Media Category',
	'group'   => 'editor',
	'default' => true,
	'setup'   => function ( $id ) {
		add_filter( 'block_editor_settings_all', function ( $settings ) use ( $id ) {
			$settings['enableOpenverseMediaCategory'] = examplepress_feature_enabled( $id );
			return $settings;
		} );
	},
] );
