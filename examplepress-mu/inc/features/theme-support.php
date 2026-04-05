<?php
/**
 * Theme Support Features
 *
 * Core WordPress theme supports: title-tag, responsive-embeds,
 * post-thumbnails, wp-block-styles, html5.
 */

examplepress_register_feature( 'title-tag', [
	'label'   => 'Title Tag',
	'group'   => 'theme',
	'default' => true,
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function () {
			add_theme_support( 'title-tag' );
		} );
	},
] );

examplepress_register_feature( 'responsive-embeds', [
	'label'   => 'Responsive Embeds',
	'group'   => 'theme',
	'default' => true,
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function () {
			add_theme_support( 'responsive-embeds' );
		} );
	},
] );

examplepress_register_feature( 'post-thumbnails', [
	'label'   => 'Post Thumbnails',
	'group'   => 'theme',
	'default' => true,
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function () {
			add_theme_support( 'post-thumbnails' );
		} );
	},
] );

examplepress_register_feature( 'wp-block-styles', [
	'label'   => 'Block Styles',
	'group'   => 'theme',
	'default' => true,
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function () {
			add_theme_support( 'wp-block-styles' );
		} );
	},
] );

examplepress_register_feature( 'html5', [
	'label'   => 'HTML5 Markup',
	'group'   => 'theme',
	'default' => true,
	'options' => [
		'features' => [
			'caption',
			'comment-form',
			'comment-list',
			'gallery',
			'search-form',
			'script',
			'style',
		],
	],
	'setup'   => function ( $id ) {
		examplepress_guarded_setup( $id, function ( $id ) {
			$features = (array) examplepress_feature_option( $id, 'features', [] );
			if ( ! empty( $features ) ) {
				add_theme_support( 'html5', $features );
			}
		} );
	},
] );
