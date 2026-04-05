<?php
/**
 * ExamplePress Admin — Theme Page
 *
 * Design tokens: colors, layout, typography, and size scale.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme page data payload.
 */
function examplepress_theme_data(): array {
	return [
		'themeVersion' => EXAMPLEPRESS_MU_VERSION,
		'devMode'      => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'         => 'theme',
		'colors'       => (array) examplepress_feature_option( 'theme-colors', 'palette', [] ),
		'layout'       => [
			'wideSize'    => (string) examplepress_feature_option( 'theme-layout', 'wide_size', '1200px' ),
			'contentSize' => (string) examplepress_feature_option( 'theme-layout', 'content_size', '800px' ),
		],
		'fonts'        => examplepress_settings_get_fonts(),
		'sizes'        => examplepress_settings_get_sizes(),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * Theme page render.
 */
function examplepress_render_theme_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php examplepress_render_page_header( $is_dev ); ?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-colors"     id="t-colors"     data-tab-id="colors">Color Palette</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-layout"     id="t-layout"     data-tab-id="layout">Layout</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-typography" id="t-typography" data-tab-id="typography">Typography</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-sizes"      id="t-sizes"      data-tab-id="sizes">Size Scale</button>
			</nav>

			<div class="ep-panels">

			<!-- Color Palette -->
			<div class="ep-panel" id="p-colors" role="tabpanel" aria-hidden="false">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Color Palette</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Resolved from examplepress.json — design.colors. Translated to theme.json settings.color.palette at runtime.</p>
					<div class="ep-color-grid" id="colors-grid"></div>
				</section>
			</div>

			<!-- Layout -->
			<div class="ep-panel" id="p-layout" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Layout</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Content and wide size constraints from the design configuration.</p>
					<div class="ep-layout-preview" id="layout-visual"></div>
				</section>
			</div>

			<!-- Typography -->
			<div class="ep-panel" id="p-typography" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Typography</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Font families registered via the design configuration.</p>
					<div class="ep-type-stack" id="type-stack"></div>
				</section>
			</div>

			<!-- Size Scale -->
			<div class="ep-panel" id="p-sizes" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Size Scale</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Font size tokens from the design configuration.</p>
					<div class="ep-size-scale" id="size-scale"></div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>
	</div>
	<?php
}
