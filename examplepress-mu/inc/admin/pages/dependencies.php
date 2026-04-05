<?php
/**
 * ExamplePress Admin — Dependencies Page
 *
 * Required and recommended plugins, packages, and libraries.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependencies page data payload.
 */
function examplepress_dependencies_data(): array {
	return [
		'themeVersion'  => EXAMPLEPRESS_MU_VERSION,
		'devMode'       => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'          => 'dependencies',
		'dependencies'  => function_exists( 'examplepress_get_dependencies' ) ? examplepress_get_dependencies() : [],
		'nonce'         => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * Dependencies page render.
 */
function examplepress_render_dependencies_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php examplepress_render_page_header( $is_dev ); ?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-required"    id="t-required"    data-tab-id="required">Required</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-recommended" id="t-recommended" data-tab-id="recommended">Recommended</button>
			</nav>

			<div class="ep-panels">

			<!-- Required -->
			<div class="ep-panel" id="p-required" role="tabpanel" aria-hidden="false">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Dependency Directory</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Plugins, Composer packages, and libraries declared in examplepress.json. Detected via plugin registry, class_exists, or function_exists.</p>
					<div class="ep-table" id="deps-required"></div>
				</section>
			</div>

			<!-- Recommended -->
			<div class="ep-panel" id="p-recommended" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Recommended</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Optional packages and plugins that enhance the ExamplePress experience. These are not required but provide additional functionality.</p>
					<div class="ep-table" id="deps-recommended"></div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>

		<?php examplepress_render_detail_modal(); ?>

	</div>
	<?php
}
