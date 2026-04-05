<?php
/**
 * ExamplePress Admin — Editor Page
 *
 * In-browser code editor for companion plugins using Monaco.
 * Hidden from the sidebar menu — accessed via direct URL from the Apps table.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor page data payload.
 *
 * @param string $slug App slug from query string.
 */
function examplepress_editor_data( string $slug ): array {
	$base = [
		'themeVersion' => EXAMPLEPRESS_MU_VERSION,
		'devMode'      => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'         => 'editor',
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'appsUrl'      => examplepress_admin_page_url( 'apps' ),
	];

	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

	if ( ! $slug || ! is_dir( $plugin_dir ) ) {
		return array_merge( $base, [
			'valid' => false,
			'slug'  => $slug,
		] );
	}

	// Read app name from examplepress.json if available.
	$json_path = $plugin_dir . '/examplepress.json';
	$app_name  = $slug;

	if ( file_exists( $json_path ) ) {
		$app_json = json_decode( file_get_contents( $json_path ), true ) ?: []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$app_name = $app_json['name'] ?? $slug;
	}

	// Get GitHub data from registry.
	$record = function_exists( 'examplepress_registry_get' ) ? examplepress_registry_get( $slug ) : null;
	$github = $record['github'] ?? [];

	return array_merge( $base, [
		'valid'     => true,
		'slug'      => $slug,
		'appName'   => $app_name,
		'fsTreeUrl' => esc_url_raw( rest_url( "examplepress/v1/fs/{$slug}/tree" ) ),
		'fsFileUrl' => esc_url_raw( rest_url( "examplepress/v1/fs/{$slug}/file" ) ),
		'github'    => $github,
	] );
}

/**
 * Editor page render.
 */
function examplepress_render_editor_page(): void {
	$slug       = sanitize_title( $_GET['app'] ?? '' );
	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
	$valid      = $slug && is_dir( $plugin_dir );
	$apps_url   = examplepress_admin_page_url( 'apps' );
	?>
	<div class="ep-settings-wrapper">
	<div class="ep-editor-wrapper">

		<?php if ( ! $valid ) : ?>
			<div class="ep-editor-error">
				<h2>App not found</h2>
				<p>The plugin directory <code><?php echo esc_html( $slug ?: '(empty)' ); ?></code> does not exist.</p>
				<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-demo-btn ep-demo-btn-primary">&larr; Back to Apps</a>
			</div>
		<?php else : ?>
			<div class="ep-editor-toolbar">
				<a href="<?php echo esc_url( $apps_url ); ?>" class="ep-editor-toolbar-back">&larr; Apps</a>
				<span id="ep-editor-app-name" class="ep-editor-app-name"></span>
				<span id="ep-editor-file-path" class="ep-editor-file-path"></span>
				<span class="ep-editor-spacer"></span>
				<span id="ep-editor-status" class="ep-editor-status"></span>
				<button id="ep-editor-save" class="ep-editor-save" disabled>Save</button>
			</div>
			<div class="ep-editor-body">
				<div id="ep-editor-tree" class="ep-editor-tree"></div>
				<div id="ep-editor-container" class="ep-editor-container"></div>
			</div>
		<?php endif; ?>

	</div>
	</div>
	<?php
}
