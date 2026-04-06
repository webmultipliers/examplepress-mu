<?php
/**
 * Template: Dependencies
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

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
