<?php
/**
 * Template: System
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-health"   id="t-health"   data-tab-id="health">Health</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-features"  id="t-features" data-tab-id="features">Features<span class="ep-tab__count"></span></button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-routes"    id="t-routes"   data-tab-id="routes">Routes<span class="ep-tab__count"></span></button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-blocks"    id="t-blocks"   data-tab-id="blocks">Blocks<span class="ep-tab__count"></span></button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-config"    id="t-config"   data-tab-id="config">Config</button>
		</nav>

		<div class="ep-panels">

		<!-- Health -->
		<div class="ep-panel" id="p-health" role="tabpanel" aria-hidden="false">
			<section class="ep-section">
				<div class="ep-stat-grid" id="health-summary"></div>
			</section>
			<section class="ep-section">
				<div class="ep-toolbar ep-toolbar--between">
					<div class="ep-datatable__search">
						<input type="text" id="ep-health-search" placeholder="Search health checks..." aria-label="Search health checks" />
					</div>
					<button type="button" class="ep-btn ep-btn--secondary" id="ep-copy-report" title="Copy the full ExamplePress data payload as JSON for support diagnostics">Copy System Report</button>
				</div>
			</section>
			<section class="ep-section ep-section--collapsible" data-health-section="env">
				<div class="ep-section__header">
					<button class="ep-section__toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-section__toggle-icon"></span></button>
					<span class="ep-section__title">Environment Checks</span><div class="ep-section__line"></div>
				</div>
				<div class="ep-section__body">
					<div id="tbl-health-env"></div>
				</div>
			</section>
			<section class="ep-section ep-section--collapsible" data-health-section="theme">
				<div class="ep-section__header">
					<button class="ep-section__toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-section__toggle-icon"></span></button>
					<span class="ep-section__title">Theme Integrity</span><div class="ep-section__line"></div>
				</div>
				<div class="ep-section__body">
					<div id="tbl-health-theme"></div>
				</div>
			</section>
			<section class="ep-section ep-section--collapsible" data-health-section="router">
				<div class="ep-section__header">
					<button class="ep-section__toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-section__toggle-icon"></span></button>
					<span class="ep-section__title">Router Health</span><div class="ep-section__line"></div>
				</div>
				<div class="ep-section__body">
					<div id="tbl-health-router"></div>
				</div>
			</section>
			<section class="ep-section ep-section--collapsible" data-health-section="security">
				<div class="ep-section__header">
					<button class="ep-section__toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-section__toggle-icon"></span></button>
					<span class="ep-section__title">Security &amp; Platform</span><div class="ep-section__line"></div>
				</div>
				<div class="ep-section__body">
					<div id="tbl-health-security"></div>
				</div>
			</section>
			<section class="ep-section ep-section--collapsible" data-health-section="connections">
				<div class="ep-section__header">
					<button class="ep-section__toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-section__toggle-icon"></span></button>
					<span class="ep-section__title">Connections</span><div class="ep-section__line"></div>
				</div>
				<div class="ep-section__body">
					<div id="tbl-health-connections"></div>
				</div>
			</section>
		</div>

		<!-- Features -->
		<div class="ep-panel" id="p-features" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Feature Registry</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">All registered features &mdash; theme support, editor controls, admin tweaks, and design tokens. Click any row for details.</p>
				<div id="tbl-features"></div>
			</section>
		</div>

		<!-- Routes -->
		<div class="ep-panel" id="p-routes" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Route Aggregator</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">
					Visualizing the dispatch topology across all registered companion apps.
					Each app declares route slugs with condition closures and a priority.
					The router evaluates origins in priority order and dispatches the first match.
				</p>
			</section>
			<section class="ep-section">
				<div id="ep-routes-stats" class="ep-stat-grid"></div>
			</section>
			<section class="ep-section">
				<div id="ep-routes-filters"></div>
				<div id="ep-routes-view-toggle"></div>
				<div id="ep-routes-content"></div>
			</section>
		</div>

		<!-- Blocks -->
		<div class="ep-panel" id="p-blocks" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Block Registry</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">All Blockstudio blocks discovered in the active theme and companion plugins, grouped by namespace. Template blocks are blocks the router can dispatch to.</p>
				<div id="tbl-blocks"></div>
			</section>
		</div>

		<!-- Config -->
		<div class="ep-panel" id="p-config" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Configuration Files</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Explore the configuration files that drive the theme. All values are read-only — edit the files directly in your project.</p>
				<div id="config-switcher"></div>
				<div id="config-viewer"></div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
