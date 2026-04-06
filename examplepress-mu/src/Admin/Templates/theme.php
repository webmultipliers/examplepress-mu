<?php
/**
 * Template: Theme
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

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
