<?php
/**
 * Template: Docs
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-guides"  id="t-guides"  data-tab-id="guides">Guides</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-hooks"   id="t-hooks"   data-tab-id="hooks">Hooks</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-support" id="t-support" data-tab-id="support">Support</button>
		</nav>

		<div class="ep-panels">

		<!-- Guides -->
		<div class="ep-panel" id="p-guides" role="tabpanel" aria-hidden="false">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Getting Started</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">ExamplePress is the FSE theme layer for Blockstudio. It provides a router, feature registry, and Blockstudio integration. Everything else is built in your companion plugin.</p>
				<div class="ep-card-grid" id="docs-cards"></div>
			</section>
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Resolution Order</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">When the feature registry resolves a value, it checks sources in priority order. The first match wins.</p>
				<div class="ep-table" id="docs-resolution-table"></div>
			</section>
		</div>

		<!-- Hooks -->
		<div class="ep-panel" id="p-hooks" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Filter &amp; Action Reference</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Every hook the theme exposes. Use these from your companion plugin to control routing, features, and design tokens.</p>
				<div id="hooks-list"></div>
			</section>
		</div>

		<!-- Support -->
		<div class="ep-panel" id="p-support" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Support &amp; Resources</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Find help, contribute to the project, or get in touch with the team.</p>
				<div class="ep-card-grid" id="docs-support-cards"></div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
