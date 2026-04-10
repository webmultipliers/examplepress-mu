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
				<div class="ep-section__header"><span class="ep-section__title">Color Palette</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Resolved from examplepress.json — design.colors. Translated to theme.json settings.color.palette at runtime.</p>
				<div id="colors-grid"></div>
			</section>
		</div>

		<!-- Layout -->
		<div class="ep-panel" id="p-layout" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Layout</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Content and wide size constraints from the design configuration.</p>
				<div id="layout-visual"></div>
			</section>
		</div>

		<!-- Typography -->
		<div class="ep-panel" id="p-typography" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Typography</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Font families registered via the design configuration.</p>
				<div id="type-stack"></div>
			</section>
		</div>

		<!-- Size Scale -->
		<div class="ep-panel" id="p-sizes" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Size Scale</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Font size tokens from the design configuration.</p>
				<div id="size-scale"></div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
