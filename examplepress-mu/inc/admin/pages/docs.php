<?php
/**
 * ExamplePress Admin — Docs Page
 *
 * Guides, hook reference, and support resources.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Docs page data payload.
 */
function examplepress_docs_data(): array {
	return [
		'themeVersion' => EXAMPLEPRESS_MU_VERSION,
		'devMode'      => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'         => 'docs',
		'docs'         => examplepress_settings_get_docs(),
		'hooks'        => examplepress_settings_get_hooks(),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * Docs page render.
 */
function examplepress_render_docs_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php examplepress_render_page_header( $is_dev ); ?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-guides"  id="t-guides"  data-tab-id="guides">Guides</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-hooks"   id="t-hooks"   data-tab-id="hooks">Hooks</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-support" id="t-support" data-tab-id="support">Support</button>
			</nav>

			<div class="ep-panels">

			<!-- Guides -->
			<div class="ep-panel" id="p-guides" role="tabpanel" aria-hidden="false">
				<section class="ep-section">
					<div class="ep-doc-section-title">Getting Started</div>
					<div class="ep-doc-section-desc">ExamplePress is the FSE theme layer for Blockstudio. It provides a router, feature registry, and Blockstudio integration. Everything else is built in your companion plugin.</div>
					<div class="ep-docs-grid" id="docs-cards"></div>
				</section>
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Resolution Order</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">When the feature registry resolves a value, it checks sources in priority order. The first match wins.</p>
					<div class="ep-table">
						<div class="ep-row ep-row-head ep-cols-3">
							<div class="ep-th">Priority</div><div class="ep-th">Source</div><div class="ep-th">Mechanism</div>
						</div>
						<div class="ep-row ep-cols-3">
							<div class="ep-td-label"><span class="ep-name">1 — Highest</span></div>
							<div><span class="ep-src src-php">PHP filter</span></div>
							<div><span class="ep-id">add_filter()</span></div>
						</div>
						<div class="ep-row ep-cols-3">
							<div class="ep-td-label"><span class="ep-name">2</span></div>
							<div><span class="ep-src src-json">JSON</span></div>
							<div><span class="ep-id">examplepress.json</span></div>
						</div>
						<div class="ep-row ep-cols-3">
							<div class="ep-td-label"><span class="ep-name">3 — Lowest</span></div>
							<div><span class="ep-src">default</span></div>
							<div><span class="ep-id">register_feature()</span></div>
						</div>
					</div>
				</section>
			</div>

			<!-- Hooks -->
			<div class="ep-panel" id="p-hooks" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Filter &amp; Action Reference</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Every hook the theme exposes. Use these from your companion plugin to control routing, features, and design tokens.</p>
					<div class="ep-hooks-list" id="hooks-list"></div>
				</section>
			</div>

			<!-- Support -->
			<div class="ep-panel" id="p-support" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-doc-section-title">Support &amp; Resources</div>
					<div class="ep-doc-section-desc">Find help, contribute to the project, or get in touch with the team.</div>
					<div class="ep-support-grid">
						<a class="ep-overview-card" href="https://github.com/flavor/flavor" target="_blank" rel="noopener">
							<div class="ep-overview-card-title">Blockstudio</div>
							<p class="ep-overview-card-desc">The parent framework that ExamplePress extends. Browse the source, file issues, and follow development.</p>
						</a>
						<div class="ep-overview-card">
							<div class="ep-overview-card-title">ExamplePress Support</div>
							<p class="ep-overview-card-desc">Dedicated support channel for ExamplePress users. Ask questions, report bugs, and share feedback.</p>
							<span class="ep-badge badge-info"><span class="ep-dot"></span>Coming Soon</span>
						</div>
						<a class="ep-overview-card" href="https://github.com/webmultipliers/examplepress-theme" target="_blank" rel="noopener">
							<div class="ep-overview-card-title">Contribute</div>
							<p class="ep-overview-card-desc">Open a pull request, suggest a feature, or help improve the documentation. All contributions are welcome.</p>
						</a>
						<a class="ep-overview-card" href="https://examplepress.com/contact" target="_blank" rel="noopener">
							<div class="ep-overview-card-title">Get In Touch</div>
							<p class="ep-overview-card-desc">Reach the ExamplePress team directly for partnerships, custom development, or general inquiries.</p>
						</a>
					</div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>

		<?php examplepress_render_detail_modal(); ?>

	</div>
	<?php
}
