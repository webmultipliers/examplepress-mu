<?php
/**
 * ExamplePress Admin — System Page
 *
 * Health checks, feature registry, routes, and blocks.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System page data payload.
 */
function examplepress_system_data(): array {
	return [
		'themeVersion'   => EXAMPLEPRESS_MU_VERSION,
		'devMode'        => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'           => 'system',
		'healthChecks'   => examplepress_settings_get_health(),
		'features'       => examplepress_settings_get_features(),
		'featureDetails' => examplepress_settings_get_feature_details(),
		'blocks'         => examplepress_settings_get_blocks(),
		'routeTopology'  => examplepress_settings_get_route_topology(),
		'configFiles'    => examplepress_settings_get_config_files(),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * System page render.
 */
function examplepress_render_system_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php
			examplepress_render_page_header(
				$is_dev,
				'<button class="ep-copy-report" id="ep-copy-report">Copy System Report</button>'
			);
			?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-health"   id="t-health"   data-tab-id="health">Health</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-features"  id="t-features" data-tab-id="features">Features<span class="ep-tab-count"></span></button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-routes"    id="t-routes"   data-tab-id="routes">Routes<span class="ep-tab-count"></span></button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-blocks"    id="t-blocks"   data-tab-id="blocks">Blocks<span class="ep-tab-count"></span></button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-config"    id="t-config"   data-tab-id="config">Config</button>
			</nav>

			<div class="ep-panels">

			<!-- Health -->
			<div class="ep-panel" id="p-health" role="tabpanel" aria-hidden="false">
				<section class="ep-section">
					<div class="ep-health-summary" id="health-summary"></div>
				</section>
				<section class="ep-section">
					<div class="ep-datatable-search">
						<input type="text" id="ep-health-search" placeholder="Search health checks..." aria-label="Search health checks" />
					</div>
				</section>
				<section class="ep-section ep-collapsible" data-health-section="env">
					<div class="ep-section-header ep-collapsible-header">
						<button class="ep-collapse-toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-collapse-icon"></span></button>
						<span class="ep-section-title">Environment Checks</span><div class="ep-section-line"></div>
					</div>
					<div class="ep-collapsible-body">
						<div class="ep-table" id="tbl-health-env"></div>
					</div>
				</section>
				<section class="ep-section ep-collapsible" data-health-section="theme">
					<div class="ep-section-header ep-collapsible-header">
						<button class="ep-collapse-toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-collapse-icon"></span></button>
						<span class="ep-section-title">Theme Integrity</span><div class="ep-section-line"></div>
					</div>
					<div class="ep-collapsible-body">
						<div class="ep-table" id="tbl-health-theme"></div>
					</div>
				</section>
				<section class="ep-section ep-collapsible" data-health-section="router">
					<div class="ep-section-header ep-collapsible-header">
						<button class="ep-collapse-toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-collapse-icon"></span></button>
						<span class="ep-section-title">Router Health</span><div class="ep-section-line"></div>
					</div>
					<div class="ep-collapsible-body">
						<div class="ep-table" id="tbl-health-router"></div>
					</div>
				</section>
				<section class="ep-section ep-collapsible" data-health-section="security">
					<div class="ep-section-header ep-collapsible-header">
						<button class="ep-collapse-toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-collapse-icon"></span></button>
						<span class="ep-section-title">Security &amp; Platform</span><div class="ep-section-line"></div>
					</div>
					<div class="ep-collapsible-body">
						<div class="ep-table" id="tbl-health-security"></div>
					</div>
				</section>
				<section class="ep-section ep-collapsible" data-health-section="connections">
					<div class="ep-section-header ep-collapsible-header">
						<button class="ep-collapse-toggle" aria-expanded="true" aria-label="Toggle section"><span class="ep-collapse-icon"></span></button>
						<span class="ep-section-title">Connections</span><div class="ep-section-line"></div>
					</div>
					<div class="ep-collapsible-body">
						<div class="ep-table" id="tbl-health-connections"></div>
					</div>
				</section>
			</div>

			<!-- Features -->
			<div class="ep-panel" id="p-features" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Feature Registry</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">All registered features &mdash; theme support, editor controls, admin tweaks, and design tokens. Click any row for details.</p>
					<div id="tbl-features"></div>
				</section>
			</div>

			<!-- Routes -->
			<div class="ep-panel" id="p-routes" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-doc-section-title">Route Aggregator</div>
					<div class="ep-section-desc">
						Visualizing the dispatch topology across all registered companion apps.
						Each app declares route slugs with condition closures and a priority.
						The router evaluates origins in priority order and dispatches the first match.
					</div>
				</section>
				<section class="ep-section">
					<div id="ep-routes-stats" class="ep-overview-grid" style="grid-template-columns: repeat(4, 1fr);"></div>
				</section>
				<section class="ep-section">
					<div id="ep-routes-filters" class="ep-routes-filters"></div>
					<div id="ep-routes-view-toggle" class="ep-routes-view-toggle"></div>
					<div id="ep-routes-content"></div>
				</section>
			</div>

			<!-- Blocks -->
			<div class="ep-panel" id="p-blocks" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Block Registry</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">All Blockstudio blocks discovered in the active theme and companion plugins, grouped by namespace. Template blocks are blocks the router can dispatch to.</p>
					<div class="ep-table" id="tbl-blocks"></div>
				</section>
			</div>

			<!-- Config -->
			<div class="ep-panel" id="p-config" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Configuration Files</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Explore the configuration files that drive the theme. All values are read-only — edit the files directly in your project.</p>
					<div class="ep-config-tabs" id="config-switcher"></div>
					<div id="config-viewer"></div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>

		<?php examplepress_render_detail_modal(); ?>

	</div>
	<?php
}
