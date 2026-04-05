<?php
/**
 * ExamplePress Admin — Settings Page
 *
 * GitHub and Troy connection settings.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection state helper.
 *
 * Returns current connection status for GitHub and Troy integrations.
 */
function examplepress_apps_connection_state(): array {
	return [
		'hasGithubPat'     => (bool) get_option( 'ep_github_pat', '' ),
		'hasGithubApp'     => function_exists( 'examplepress_github_app_is_installed' ) && examplepress_github_app_is_installed(),
		'githubAppAvail'   => function_exists( 'examplepress_github_app_is_configured' ) && examplepress_github_app_is_configured(),
		'githubAppSlug'    => function_exists( 'examplepress_get_github_app_slug' ) ? examplepress_get_github_app_slug() : '',
		'githubOrg'        => get_option( 'ep_github_org', 'webmultipliers' ),
		'appTemplateRepo'  => get_option( 'ep_app_template_repo', defined( 'EP_DEFAULT_TEMPLATE_REPO' ) ? EP_DEFAULT_TEMPLATE_REPO : '' ),
		'hasTroyUrl'       => (bool) get_option( 'ep_troy_server_url', '' ),
		'hasTroyCreds'     => (bool) get_option( 'ep_troy_credentials', '' ),
		'hasTroyGithubPat' => (bool) get_option( 'ep_troy_github_pat', '' ),
		'troyServerUrl'    => get_option( 'ep_troy_server_url', '' ),
		'testGithubUrl'    => esc_url_raw( rest_url( 'examplepress/v1/settings/test-github' ) ),
		'testTroyUrl'      => esc_url_raw( rest_url( 'examplepress/v1/settings/test-troy' ) ),
	];
}

/**
 * Settings page data payload.
 */
function examplepress_settings_page_data(): array {
	return [
		'themeVersion'   => EXAMPLEPRESS_MU_VERSION,
		'devMode'        => defined( 'EP_DEV_MODE' ) && EP_DEV_MODE,
		'page'           => 'settings',
		'connectionsUrl' => esc_url_raw( rest_url( 'examplepress/v1/settings/connections' ) ),
		'connections'    => examplepress_apps_connection_state(),
		'nonce'          => wp_create_nonce( 'wp_rest' ),
	];
}

/**
 * Settings page render.
 */
function examplepress_render_settings_page(): void {
	$is_dev = defined( 'EP_DEV_MODE' ) && EP_DEV_MODE;
	$ep_github_app_available = function_exists( 'examplepress_github_app_is_configured' ) && examplepress_github_app_is_configured();
	?>
	<div class="ep-settings-wrapper">
		<div class="ep-settings">

			<?php examplepress_render_page_header( $is_dev ); ?>

			<div class="ep-layout">
			<nav class="ep-tabs" role="tablist">
				<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-github" id="t-github" data-tab-id="github">GitHub</button>
				<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-troy"   id="t-troy"   data-tab-id="troy">Troy</button>
			</nav>

			<div class="ep-panels">

			<!-- GitHub -->
			<div class="ep-panel" id="p-github" role="tabpanel" aria-hidden="false">
				<section class="ep-section" id="ep-connections-section">
					<div class="ep-section-header"><span class="ep-section-title">GitHub Connection</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Configure credentials for the automated scaffold pipeline. Without these, the "+ New App" flow scaffolds locally only.</p>
					<div class="ep-connections-grid" id="ep-connections-grid">
						<div class="ep-conn-group">
							<div class="ep-conn-field">
								<label class="ep-build-label" for="ep-conn-github-org">Organization</label>
								<input type="text" id="ep-conn-github-org" placeholder="webmultipliers" />
							</div>
							<div class="ep-conn-field">
								<label class="ep-build-label" for="ep-conn-app-template">App Template Repository</label>
								<input type="text" id="ep-conn-app-template" placeholder="<?php echo esc_attr( defined( 'EP_DEFAULT_TEMPLATE_REPO' ) ? EP_DEFAULT_TEMPLATE_REPO : '' ); ?>" />
								<span class="ep-build-hint">GitHub template repo used when scaffolding new apps. Use your own to customize the boilerplate.</span>
							</div>
							<?php if ( $ep_github_app_available ) : ?>
							<div class="ep-conn-field">
								<label class="ep-build-label">App Authorization</label>
								<div class="ep-troy-auth-row">
									<button class="ep-demo-btn ep-demo-btn-primary" id="ep-conn-github-app-btn" type="button">Install GitHub App</button>
									<span class="ep-troy-auth-status" id="ep-github-app-status"></span>
								</div>
								<span class="ep-build-hint">Grants repo creation + code push on your org. No shared secrets.</span>
							</div>
							<div class="ep-conn-field ep-conn-field-separator">
								<label class="ep-build-label">Or use a token instead</label>
							<?php else : ?>
							<div class="ep-conn-field">
								<label class="ep-build-label">Write Access Token</label>
							<?php endif; ?>
								<input type="password" id="ep-conn-github-pat" placeholder="github_pat_..." autocomplete="off" />
								<span class="ep-build-hint">Fine-grained PAT. Permissions: <code>Administration</code> (R/W) + <code>Contents</code> (R/W). <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">Create token &rarr;</a></span>
							</div>
							<div class="ep-conn-field">
								<div class="ep-troy-auth-row">
									<button class="ep-demo-btn" id="ep-test-github-btn" type="button">Test GitHub</button>
									<span class="ep-troy-auth-status" id="ep-test-github-status"></span>
								</div>
							</div>
						</div>
					</div>
					<div class="ep-conn-actions">
						<button class="ep-build-submit" id="ep-conn-save-btn" type="button">Save Connections</button>
						<span class="ep-conn-status" id="ep-conn-status"></span>
					</div>
				</section>
			</div>

			<!-- Troy -->
			<div class="ep-panel" id="p-troy" role="tabpanel" aria-hidden="true">
				<section class="ep-section">
					<div class="ep-section-header"><span class="ep-section-title">Troy Server</span><div class="ep-section-line"></div></div>
					<p class="ep-section-desc">Optional &mdash; connect to a Troy instance for multi-site plugin distribution.</p>
					<div class="ep-connections-grid">
						<div class="ep-conn-group">
							<div class="ep-conn-field">
								<label class="ep-build-label" for="ep-conn-troy-url">Server URL</label>
								<input type="url" id="ep-conn-troy-url" placeholder="https://internal.repo.mustuse.com" />
							</div>
							<div class="ep-conn-field">
								<label class="ep-build-label">Authorization</label>
								<div class="ep-troy-auth-row">
									<button class="ep-demo-btn ep-demo-btn-primary" id="ep-conn-troy-auth-btn" type="button">Authorize with Troy</button>
									<span class="ep-troy-auth-status" id="ep-troy-auth-status"></span>
								</div>
								<span class="ep-build-hint">Opens the Troy Server to create an application password automatically.</span>
							</div>
							<div class="ep-conn-field">
								<label class="ep-build-label" for="ep-conn-troy-github-pat">GitHub Read Token</label>
								<input type="password" id="ep-conn-troy-github-pat" placeholder="github_pat_..." autocomplete="off" />
								<span class="ep-build-hint">Fine-grained PAT with <code>Contents</code> (Read). Passed to Troy for tag fetching and ZIP downloads from private repos.</span>
							</div>
							<div class="ep-conn-field">
								<div class="ep-troy-auth-row">
									<button class="ep-demo-btn" id="ep-test-troy-btn" type="button">Test Troy</button>
									<span class="ep-troy-auth-status" id="ep-test-troy-status"></span>
								</div>
							</div>
						</div>
					</div>
					<div class="ep-conn-actions">
						<button class="ep-build-submit" id="ep-conn-save-btn-troy" type="button">Save Connections</button>
						<span class="ep-conn-status" id="ep-conn-status-troy"></span>
					</div>
				</section>
			</div>

			</div><!-- /.ep-panels -->
			</div><!-- /.ep-layout -->

		</div>
	</div>
	<?php
}
