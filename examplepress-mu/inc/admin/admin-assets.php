<?php
/**
 * Vite-aware asset enqueue for ExamplePress admin subpages.
 *
 * In development (EP_VITE_DEV constant truthy), loads modules from
 * Vite's dev server at localhost:5173 with HMR support.
 *
 * In production, reads dist/.vite/manifest.json to resolve
 * content-hashed filenames produced by `npm run build`.
 */

/**
 * Enqueue a Vite entry point's JS and CSS for a given admin subpage.
 *
 * @param string $entry  Entry name matching the Vite config input key
 *                       (e.g. 'dashboard', 'theme', 'build', 'reference').
 */
function examplepress_vite_enqueue( string $entry ) {
	$dev = defined( 'EP_VITE_DEV' ) && EP_VITE_DEV;

	// Google Fonts (shared across all subpages).
	$fonts_url = apply_filters(
		'examplepress_settings_fonts_url',
		'https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500;600&display=swap'
	);

	if ( $fonts_url ) {
		wp_enqueue_style( 'ep-settings-fonts', $fonts_url, [], null );
	}

	if ( $dev ) {
		// Development: load from Vite dev server.
		wp_enqueue_script(
			'ep-vite-client',
			'http://localhost:5173/@vite/client',
			[],
			null,
			true
		);
		wp_enqueue_script(
			"ep-{$entry}",
			"http://localhost:5173/assets/src/{$entry}/main.js",
			[ 'ep-vite-client' ],
			null,
			true
		);
		return;
	}

	// Production: read from manifest.
	$manifest_path = EXAMPLEPRESS_MU_DIR . '/dist/.vite/manifest.json';
	$manifest      = json_decode( file_get_contents( $manifest_path ), true );
	$key           = "assets/src/{$entry}/main.js";
	$asset         = $manifest[ $key ] ?? null;

	if ( ! $asset ) {
		return;
	}

	$font_deps = $fonts_url ? [ 'ep-settings-fonts' ] : [];

	// Collect all CSS from the entry and its shared chunk imports.
	$css_files = $asset['css'] ?? [];
	foreach ( $asset['imports'] ?? [] as $import_key ) {
		$import = $manifest[ $import_key ] ?? null;
		if ( $import && ! empty( $import['css'] ) ) {
			$css_files = array_merge( $css_files, $import['css'] );
		}
	}
	$css_files = array_unique( $css_files );

	foreach ( $css_files as $css_file ) {
		$handle = 'ep-' . $entry . '-' . md5( $css_file );
		wp_enqueue_style(
			$handle,
			EXAMPLEPRESS_MU_URI . '/dist/' . $css_file,
			$font_deps,
			null
		);
	}

	// Enqueue the JS entry point.
	wp_enqueue_script(
		"ep-{$entry}",
		EXAMPLEPRESS_MU_URI . '/dist/' . $asset['file'],
		[],
		null,
		true
	);
}

/**
 * Add type="module" to Vite-managed scripts.
 */
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	$vite_handles = [
		'ep-vite-client',
		'ep-apps',
		'ep-theme',
		'ep-navigation',
		'ep-dependencies',
		'ep-library',
		'ep-settings',
		'ep-notifications',
		'ep-system',
		'ep-docs',
		'ep-editor',
	];
	if ( in_array( $handle, $vite_handles, true ) ) {
		$tag = str_replace( '<script ', '<script type="module" ', $tag );
	}
	return $tag;
}, 10, 2 );
