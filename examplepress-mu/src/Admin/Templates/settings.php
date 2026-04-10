<?php
/**
 * Template: Settings
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

$ep_github_app_available = \ExamplePress\MU\Infrastructure\GitHub::appIsConfigured();
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-github" id="t-github" data-tab-id="github">GitHub</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-troy"   id="t-troy"   data-tab-id="troy">Troy</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-agent"  id="t-agent"  data-tab-id="agent">AI Agent</button>
		</nav>

		<div class="ep-panels">

		<!-- GitHub -->
		<div class="ep-panel" id="p-github" role="tabpanel" aria-hidden="false">
			<section class="ep-section" id="ep-connections-section">
				<div class="ep-section__header"><span class="ep-section__title">GitHub Connection</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Configure credentials for the automated scaffold pipeline. Without these, the "+ New App" flow scaffolds locally only.</p>
				<div class="ep-form ep-form--grid" id="ep-connections-grid">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-conn-github-org">Organization</label>
						<div class="ep-form__control">
							<input type="text" id="ep-conn-github-org" placeholder="webmultipliers" />
						</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-conn-app-template">App Template Repository</label>
						<div class="ep-form__control">
							<input type="text" id="ep-conn-app-template" placeholder="<?php echo esc_attr( \ExamplePress\MU\Infrastructure\Scaffolder::EP_DEFAULT_TEMPLATE_REPO ); ?>" />
						</div>
						<div class="ep-form__hint">GitHub template repo used when scaffolding new apps. Use your own to customize the boilerplate.</div>
					</div>
					<?php if ( $ep_github_app_available ) : ?>
					<div class="ep-form__row">
						<label class="ep-form__label">App Authorization</label>
						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--primary" id="ep-conn-github-app-btn" type="button">Install GitHub App</button>
							<span id="ep-github-app-status"></span>
						</div>
						<div class="ep-form__hint">Grants repo creation + code push on your org. No shared secrets.</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label">Or use a token instead</label>
					<?php else : ?>
					<div class="ep-form__row">
						<label class="ep-form__label">Write Access Token</label>
					<?php endif; ?>
						<div class="ep-form__control">
							<input type="password" id="ep-conn-github-pat" placeholder="github_pat_..." autocomplete="off" />
						</div>
						<div class="ep-form__hint">Fine-grained PAT. Permissions: <code>Administration</code> (R/W) + <code>Contents</code> (R/W). <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">Create token &rarr;</a></div>
					</div>
					<div class="ep-form__row">
						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--secondary" id="ep-test-github-btn" type="button">Test GitHub</button>
							<span id="ep-test-github-status"></span>
						</div>
					</div>
				</div>
				<div class="ep-toolbar">
					<button class="ep-btn ep-btn--primary" id="ep-conn-save-btn" type="button">Save Connections</button>
					<span id="ep-conn-status"></span>
				</div>
			</section>
		</div>

		<!-- Troy -->
		<div class="ep-panel" id="p-troy" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Troy Server</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Optional &mdash; connect to a Troy instance for multi-site plugin distribution.</p>
				<div class="ep-form">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-conn-troy-url">Server URL</label>
						<div class="ep-form__control">
							<input type="url" id="ep-conn-troy-url" placeholder="https://internal.repo.mustuse.com" />
						</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label">Authorization</label>
						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--primary" id="ep-conn-troy-auth-btn" type="button">Authorize with Troy</button>
							<span id="ep-troy-auth-status"></span>
						</div>
						<div class="ep-form__hint">Opens the Troy Server to create an application password automatically.</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-conn-troy-github-pat">GitHub Read Token</label>
						<div class="ep-form__control">
							<input type="password" id="ep-conn-troy-github-pat" placeholder="github_pat_..." autocomplete="off" />
						</div>
						<div class="ep-form__hint">Fine-grained PAT with <code>Contents</code> (Read). Passed to Troy for tag fetching and ZIP downloads from private repos.</div>
					</div>
					<div class="ep-form__row">
						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--secondary" id="ep-test-troy-btn" type="button">Test Troy</button>
							<span id="ep-test-troy-status"></span>
						</div>
					</div>
				</div>
				<div class="ep-toolbar">
					<button class="ep-btn ep-btn--primary" id="ep-conn-save-btn-troy" type="button">Save Connections</button>
					<span id="ep-conn-status-troy"></span>
				</div>
			</section>
		</div>

		<!-- AI Agent -->
		<div class="ep-panel" id="p-agent" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Generative UI Agent</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Configure the LLM provider that powers the "Generate with AI" flow on the Apps page. Generated apps live entirely in private GitHub repos &mdash; nothing is written to <code>wp_posts</code> or <code>wp_options</code> beyond the standard app registry.</p>
				<div class="ep-form">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-enabled">Enable Agent</label>
						<label class="ep-radio-group__label">
							<input type="checkbox" id="ep-agent-enabled" />
							<span>Boots the minimal Prism container and exposes the "Generate with AI" surfaces. Off by default.</span>
						</label>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-provider">Provider</label>
						<div class="ep-form__control">
							<select id="ep-agent-provider">
								<option value="anthropic">Anthropic Claude</option>
								<option value="openai">OpenAI</option>
							</select>
						</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-model">Model</label>
						<div class="ep-form__control">
							<select id="ep-agent-model"></select>
						</div>
						<div class="ep-form__hint">Models update when you change the provider above.</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-api-key">API Key</label>
						<div class="ep-form__control">
							<input type="password" id="ep-agent-api-key" placeholder="sk-..." autocomplete="off" />
						</div>
						<div class="ep-form__hint">Stored in <code>wp_options</code> as <code>ep_agent_api_key</code>. Restrict <code>manage_options</code> access accordingly.</div>
					</div>
					<div class="ep-form__row">
						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--secondary" id="ep-test-agent-btn" type="button">Test Agent</button>
							<button class="ep-btn ep-btn--secondary" id="ep-inspect-skills-btn" type="button">Inspect Skills</button>
							<span id="ep-test-agent-status"></span>
						</div>
						<details id="ep-skills-inspector" hidden>
							<summary>Compiled curriculum &amp; merge tags</summary>
							<div id="ep-skills-inspector-body"></div>
						</details>
					</div>
					<div class="ep-form__row">
						<span id="ep-agent-runtime-status"></span>
					</div>
				</div>
				<div class="ep-toolbar">
					<button class="ep-btn ep-btn--primary" id="ep-conn-save-btn-agent" type="button">Save Agent Settings</button>
					<span id="ep-conn-status-agent"></span>
				</div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
