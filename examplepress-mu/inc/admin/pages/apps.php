<?php
/**
 * ExamplePress Admin — Apps Page
 *
 * Companion app management: scaffold, connect, health, and demo.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apps page data payload.
 */
function examplepress_apps_data(): array {
	return [
		'themeVersion'      => EXAMPLEPRESS_MU_VERSION,
		'devMode'           => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'              => 'apps',
		'apps'              => function_exists( 'examplepress_get_apps' ) ? examplepress_get_apps() : [],
		'appsBaseUrl'       => esc_url_raw( rest_url( 'examplepress/v1/apps' ) ),
		'appsScaffoldUrl'   => esc_url_raw( rest_url( 'examplepress/v1/apps/scaffold' ) ),
		'editorUrl'         => examplepress_admin_page_url( 'editor', [ 'app' => '__SLUG__' ] ),
		'troyCloudUrl'      => function_exists( 'examplepress_get_troy_cloud_url' ) ? examplepress_get_troy_cloud_url() : '',
		'adminUrl'          => esc_url( admin_url() ),
		'updater'              => [
			'status'          => function_exists( 'examplepress_get_updater_status' ) ? examplepress_get_updater_status() : 'not-installed',
			'current_version' => function_exists( 'examplepress_get_updater_plugin_version' ) ? examplepress_get_updater_plugin_version() : null,
		],
		'updaterInstallUrl'    => esc_url_raw( rest_url( 'examplepress/v1/updater/install' ) ),
		'updaterUninstallUrl'  => esc_url_raw( rest_url( 'examplepress/v1/updater/uninstall' ) ),
		'updaterCheckUrl'      => esc_url_raw( rest_url( 'examplepress/v1/updater/check' ) ),
		'updaterUpdateUrl'     => esc_url_raw( rest_url( 'examplepress/v1/updater/update' ) ),
		'updaterSettingsUrl'   => esc_url_raw( rest_url( 'examplepress/v1/updater/settings' ) ),
		'updaterReleasesUrl'   => esc_url_raw( rest_url( 'examplepress/v1/updater/releases' ) ),
		'demo'              => [
			'status'          => function_exists( 'examplepress_get_demo_status' ) ? examplepress_get_demo_status() : 'not-installed',
			'current_version' => function_exists( 'examplepress_get_demo_plugin_version' ) ? examplepress_get_demo_plugin_version() : null,
		],
		'demoInstallUrl'    => esc_url_raw( rest_url( 'examplepress/v1/demo/install' ) ),
		'demoUninstallUrl'  => esc_url_raw( rest_url( 'examplepress/v1/demo/uninstall' ) ),
		'demoCheckUrl'      => esc_url_raw( rest_url( 'examplepress/v1/demo/check' ) ),
		'demoUpdateUrl'     => esc_url_raw( rest_url( 'examplepress/v1/demo/update' ) ),
		'demoSettingsUrl'   => esc_url_raw( rest_url( 'examplepress/v1/demo/settings' ) ),
		'demoReleasesUrl'   => esc_url_raw( rest_url( 'examplepress/v1/demo/releases' ) ),
		'nonce'             => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * Render the app modals (scaffold, troy, codespace).
 */
function examplepress_render_app_modals(): void {
	?>
	<!-- App Scaffold Modal -->
	<div class="ep-modal-overlay" id="ep-apps-scaffold-modal" style="display:none">
		<div class="ep-modal" style="max-width:480px">
			<div class="ep-modal-header">
				<div>
					<span class="ep-modal-title">New App</span>
					<span class="ep-modal-id">Scaffolds a plugin in <code>wp-content/plugins/</code></span>
				</div>
				<button class="ep-modal-close" data-modal="ep-apps-scaffold-modal">&times;</button>
			</div>
			<div class="ep-modal-body">
				<div class="ep-build-field">
					<label class="ep-build-label" for="ep-apps-scaffold-name">App Name</label>
					<input type="text" class="ep-build-input" id="ep-apps-scaffold-name" placeholder="e.g., ExamplePress Analytics" />
				</div>
				<div class="ep-build-field">
					<label class="ep-build-label" for="ep-apps-scaffold-desc">Description</label>
					<input type="text" class="ep-build-input" id="ep-apps-scaffold-desc" placeholder="What does this app do?" />
				</div>
				<div class="ep-scaffold-steps" id="ep-scaffold-steps"></div>
				<div id="ep-apps-scaffold-error" class="ep-build-error" style="display:none"></div>
				<div id="ep-apps-scaffold-warnings" class="ep-scaffold-warnings" style="display:none"></div>
			</div>
			<div class="ep-apps-modal-foot">
				<button class="ep-apps-btn ep-apps-btn-cancel" data-modal="ep-apps-scaffold-modal">Cancel</button>
				<button class="ep-apps-btn ep-apps-btn-primary" id="ep-apps-scaffold-submit">Create App</button>
			</div>
		</div>
	</div>

	<!-- Troy Connect Modal -->
	<div class="ep-modal-overlay" id="ep-apps-troy-modal" style="display:none">
		<div class="ep-modal" style="max-width:440px">
			<div class="ep-modal-header">
				<div>
					<span class="ep-modal-title">Manage App</span>
					<span class="ep-modal-id">Connect to Troy for repo provisioning and automatic updates</span>
				</div>
				<button class="ep-modal-close" data-modal="ep-apps-troy-modal">&times;</button>
			</div>
			<div class="ep-modal-body">
				<div class="ep-apps-troy-info">
					<div class="ep-apps-troy-info-label">Troy &mdash; Decentralized Directory</div>
					<div class="ep-apps-troy-info-sub">Tagged GitHub releases will serve updates automatically after initialization.</div>
				</div>
				<div class="ep-apps-radio-group">
					<label class="ep-apps-radio-label">
						<input type="radio" name="ep-apps-troy-target" value="cloud" checked />
						Troy Cloud (hosted)
					</label>
					<label class="ep-apps-radio-label">
						<input type="radio" name="ep-apps-troy-target" value="custom" />
						Self-hosted Troy instance
					</label>
					<input type="url" class="ep-build-input" id="ep-apps-troy-custom-url" placeholder="https://troy.yourdomain.com" style="display:none; margin-top:4px;" />
				</div>
				<div id="ep-apps-troy-error" class="ep-build-error" style="display:none"></div>
				<input type="hidden" id="ep-apps-troy-target-slug" />
			</div>
			<div class="ep-apps-modal-foot">
				<button class="ep-apps-btn ep-apps-btn-cancel" data-modal="ep-apps-troy-modal">Cancel</button>
				<button class="ep-apps-btn ep-apps-btn-troy" id="ep-apps-troy-submit">Connect to Troy &rarr;</button>
			</div>
		</div>
	</div>

	<!-- Codespace Modal -->
	<div class="ep-modal-overlay" id="ep-apps-codespace-modal" style="display:none">
		<div class="ep-modal" style="max-width:440px">
			<div class="ep-modal-body" style="padding:24px 20px;text-align:center;">
				<svg width="40" height="40" viewBox="0 0 98 96" xmlns="http://www.w3.org/2000/svg" style="margin-bottom:12px"><path fill-rule="evenodd" clip-rule="evenodd" d="M48.854 0C21.839 0 0 22 0 49.217c0 21.756 13.993 40.172 33.405 46.69 2.427.49 3.316-1.059 3.316-2.362 0-1.141-.08-5.052-.08-9.127-13.59 2.934-16.42-5.867-16.42-5.867-2.184-5.704-5.42-7.17-5.42-7.17-4.448-3.015.324-3.015.324-3.015 4.934.326 7.523 5.052 7.523 5.052 4.367 7.496 11.404 5.378 14.235 4.074.404-3.178 1.699-5.378 3.074-6.6-10.839-1.141-22.243-5.378-22.243-24.283 0-5.378 1.94-9.778 5.014-13.2-.485-1.222-2.184-6.275.486-13.038 0 0 4.125-1.304 13.426 5.052a46.97 46.97 0 0 1 12.214-1.63c4.125 0 8.33.571 12.213 1.63 9.302-6.356 13.427-5.052 13.427-5.052 2.67 6.763.97 11.816.485 13.038 3.155 3.422 5.015 7.822 5.015 13.2 0 18.905-11.404 23.06-22.324 24.283 1.78 1.548 3.316 4.481 3.316 9.126 0 6.6-.08 11.897-.08 13.526 0 1.304.89 2.853 3.316 2.364 19.412-6.52 33.405-24.935 33.405-46.691C97.707 22 75.788 0 48.854 0z" fill="#24292f"/></svg>
				<h2 style="margin:0 0 6px;font-size:17px;font-weight:600;">Launching Codespace</h2>
				<p style="color:#646970;font-size:13px;margin:0 0 14px;">You'll be redirected to GitHub with the repo pre-selected.</p>
				<div class="ep-apps-codespace-url" id="ep-apps-codespace-url"></div>
				<button class="ep-apps-btn ep-apps-btn-cancel" data-modal="ep-apps-codespace-modal">Close</button>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Apps page render.
 */
function examplepress_render_apps_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php examplepress_render_page_header( $is_dev ); ?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-apps" id="t-apps" data-tab-id="apps">Apps</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-updater" id="t-updater" data-tab-id="updater">Updates</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-demo" id="t-demo" data-tab-id="demo">Demo</button>
			</nav>

			<div class="ep-panels">

			<!-- Apps -->
			<div class="ep-panel" id="p-apps" role="tabpanel" aria-hidden="false">

				<!-- Workflow Explanation -->
				<section class="ep-section">
					<div class="ep-apps-workflow">
						<div class="ep-apps-workflow-step">
							<div class="ep-apps-step-num">1</div>
							<div class="ep-apps-step-title">Scaffold App</div>
							<p class="ep-apps-step-desc">Create a new local plugin directory inheriting ExamplePress design standards.</p>
							<span class="ep-apps-step-sys ep-apps-sys-wp">Local WordPress</span>
						</div>
						<div class="ep-apps-workflow-step">
							<div class="ep-apps-step-num">2</div>
							<div class="ep-apps-step-title">Push to GitHub</div>
							<p class="ep-apps-step-desc">Create a GitHub repo from the template, replace placeholders, and tag the initial release.</p>
							<span class="ep-apps-step-sys ep-apps-sys-gh">GitHub</span>
						</div>
						<div class="ep-apps-workflow-step">
							<div class="ep-apps-step-num">3</div>
							<div class="ep-apps-step-title">Edit Code</div>
							<p class="ep-apps-step-desc">Launch a GitHub Codespace to write code without local dev environments.</p>
							<span class="ep-apps-step-sys ep-apps-sys-codespace">Codespaces</span>
						</div>
						<div class="ep-apps-workflow-step">
							<div class="ep-apps-step-num">4</div>
							<div class="ep-apps-step-title">Ship Updates</div>
							<p class="ep-apps-step-desc">Tag a GitHub Release. WordPress checks GitHub directly and installs the update natively.</p>
							<span class="ep-apps-step-sys ep-apps-sys-gh">GitHub Releases &rarr; WP Update</span>
						</div>
					</div>
				</section>

				<!-- Platform Stats -->
				<section class="ep-section">
					<div class="ep-apps-stats" id="ep-apps-stats"></div>
				</section>

				<!-- Toolbar -->
				<section class="ep-section">
					<button class="ep-build-submit" id="ep-apps-new-btn">
						<span class="ep-build-submit-label">+ New App</span>
					</button>
				</section>

				<!-- Apps Table -->
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Apps</span><div class="ep-section-line"></div></div>
					<div id="ep-apps-table"></div>
				</section>

			</div>

			<!-- Updates -->
			<div class="ep-panel" id="p-updater" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Theme Update Plugin</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">The ExamplePress theme relies on a companion plugin to check for updates via GitHub Releases. This plugin lives outside the theme directory so it persists across theme upgrades. Install it here, and it becomes a locked dependency &mdash; it cannot be deactivated while ExamplePress is the active theme.</p>

					<div class="ep-demo-panel" id="ep-updater-panel">
						<div class="ep-demo-status">
							<div class="ep-demo-status-label">Status</div>
							<span class="ep-badge" id="ep-updater-badge"></span>
						</div>
						<p class="ep-demo-message" id="ep-updater-message"></p>
						<div class="ep-demo-actions" id="ep-updater-actions"></div>
					</div>
				</section>

				<!-- Update Check -->
				<section class="ep-section" id="ep-updater-check-section">
					<div class="ep-section-header"><span class="ep-section-title">Check for Plugin Updates</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Check GitHub for a newer version of the updater plugin. The theme is responsible for keeping its companion up to date.</p>

					<div class="ep-demo-panel">
						<div class="ep-demo-status">
							<div class="ep-demo-status-label">Installed</div>
							<span class="ep-badge badge-on" id="ep-updater-current-ver">&mdash;</span>
						</div>
						<div class="ep-demo-status" id="ep-updater-target-row" style="display:none;margin-top:8px;">
							<div class="ep-demo-status-label">Target</div>
							<span class="ep-badge badge-on" id="ep-updater-target-ver"></span>
						</div>
						<div class="ep-demo-status" id="ep-updater-available-row" style="display:none;margin-top:8px;">
							<div class="ep-demo-status-label">Action Needed</div>
							<span class="ep-badge badge-warn" id="ep-updater-available-ver"></span>
						</div>
						<p class="ep-demo-message" id="ep-updater-check-message">Click below to check GitHub releases against your channel and pin settings.</p>
						<div class="ep-demo-actions">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-updater-check-btn">Check Now</button>
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-updater-update-btn" style="display:none;">Update Now</button>
						</div>
					</div>
				</section>

				<!-- Channel & Pin Settings -->
				<section class="ep-section" id="ep-updater-settings-section">
					<div class="ep-section-header"><span class="ep-section-title">Plugin Channel &amp; Pinning</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Choose which release channel to follow for the updater plugin. Optionally pin to a specific version to prevent upgrades past that release.</p>

					<div class="ep-demo-panel">
						<div class="ep-build-field">
							<label class="ep-build-label" for="ep-updater-channel">Channel</label>
							<select class="ep-build-input" id="ep-updater-channel" style="max-width:260px;">
								<option value="stable">Stable (main branch releases only)</option>
								<option value="prerelease">Pre-release (includes development builds)</option>
							</select>
						</div>
						<div class="ep-build-field" style="margin-top:12px;">
							<label class="ep-build-label" for="ep-updater-pin">Pin to Version</label>
							<select class="ep-build-input" id="ep-updater-pin" style="max-width:260px;">
								<option value="">Latest (no pin)</option>
							</select>
							<p class="ep-section-desc" style="margin-top:4px;font-size:12px;">When pinned, the updater will not offer versions newer than the pinned release.</p>
						</div>
						<div class="ep-demo-actions" style="margin-top:12px;">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-updater-save-settings">Save Settings</button>
						</div>
						<p class="ep-demo-message" id="ep-updater-settings-message" style="display:none;"></p>
					</div>
				</section>
			</div>

			<!-- Demo -->
			<div class="ep-panel" id="p-demo" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Demo Companion Plugin</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Install the demo companion plugin to see the routing contract in action. The demo claims its own namespace, defines a routing cascade, and renders distinct template blocks. Inspect the source, then remove it when you're ready to scaffold your own.</p>

					<div class="ep-demo-panel" id="ep-demo-panel">
						<div class="ep-demo-status">
							<div class="ep-demo-status-label">Status</div>
							<span class="ep-badge" id="ep-demo-badge"></span>
						</div>
						<p class="ep-demo-message" id="ep-demo-message"></p>
						<div class="ep-demo-actions" id="ep-demo-actions"></div>
					</div>
				</section>

				<!-- Check for Plugin Updates -->
				<section class="ep-section" id="ep-demo-check-section">
					<div class="ep-section-header"><span class="ep-section-title">Check for Plugin Updates</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Check GitHub for a newer version of the demo plugin.</p>

					<div class="ep-demo-panel">
						<div class="ep-demo-status">
							<div class="ep-demo-status-label">Installed</div>
							<span class="ep-badge badge-on" id="ep-demo-current-ver">&mdash;</span>
						</div>
						<div class="ep-demo-status" id="ep-demo-target-row" style="display:none;margin-top:8px;">
							<div class="ep-demo-status-label">Target</div>
							<span class="ep-badge badge-on" id="ep-demo-target-ver"></span>
						</div>
						<div class="ep-demo-status" id="ep-demo-available-row" style="display:none;margin-top:8px;">
							<div class="ep-demo-status-label">Action Needed</div>
							<span class="ep-badge badge-warn" id="ep-demo-available-ver"></span>
						</div>
						<p class="ep-demo-message" id="ep-demo-check-message">Click below to check GitHub releases against your channel and pin settings.</p>
						<div class="ep-demo-actions">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-demo-check-btn">Check Now</button>
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-demo-update-btn" style="display:none;">Update Now</button>
						</div>
					</div>
				</section>

				<!-- Plugin Channel & Pinning -->
				<section class="ep-section" id="ep-demo-settings-section">
					<div class="ep-section-header"><span class="ep-section-title">Plugin Channel &amp; Pinning</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Choose which release channel to follow for the demo plugin. Optionally pin to a specific version.</p>

					<div class="ep-demo-panel">
						<div class="ep-build-field">
							<label class="ep-build-label" for="ep-demo-channel">Channel</label>
							<select class="ep-build-input" id="ep-demo-channel" style="max-width:260px;">
								<option value="stable">Stable (main branch releases only)</option>
								<option value="prerelease">Pre-release (includes development builds)</option>
							</select>
						</div>
						<div class="ep-build-field" style="margin-top:12px;">
							<label class="ep-build-label" for="ep-demo-pin">Pin to Version</label>
							<select class="ep-build-input" id="ep-demo-pin" style="max-width:260px;">
								<option value="">Latest (no pin)</option>
							</select>
							<p class="ep-section-desc" style="margin-top:4px;font-size:12px;">When pinned, the updater will not offer versions newer than the pinned release.</p>
						</div>
						<div class="ep-demo-actions" style="margin-top:12px;">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-demo-save-settings">Save Settings</button>
						</div>
						<p class="ep-demo-message" id="ep-demo-settings-message" style="display:none;"></p>
					</div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>

		<?php examplepress_render_app_modals(); ?>

	</div>
	<?php
}
