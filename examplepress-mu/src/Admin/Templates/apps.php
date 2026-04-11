<?php
/**
 * Template: Apps
 * Included by PageController::render() — outputs the HTML skeleton
 * that the Vite JS entry point binds to.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-apps" id="t-apps" data-tab-id="apps">Apps</button>
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
				<button class="ep-btn ep-btn--primary ep-btn--purple" id="ep-apps-generate-btn" hidden title="Generate a new app with AI">
					<span>✨ Generate with AI</span>
				</button>
				<button class="ep-btn ep-btn--primary ep-btn--disabled" id="ep-apps-generate-btn-disabled" hidden title="Configure provider + API key in Settings → AI Agent">
					<span>✨ Generate with AI (configure first)</span>
				</button>
				<button class="ep-btn ep-btn--secondary" id="ep-apps-new-btn">
					<span>+ New App (manual scaffold)</span>
				</button>
				<button class="ep-btn ep-btn--secondary" id="ep-apps-jobs-btn" hidden title="View AI generation history">
					Jobs &amp; History
				</button>
				<div id="ep-agent-runtime-warning" class="ep-form__error" style="display:none"></div>
			</section>

			<!-- Pending Drafts (AI generations awaiting review or push) -->
			<section class="ep-section" id="ep-agent-drafts-section" style="display:none">
				<div class="ep-section__header"><span class="ep-section__title">Pending Drafts</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">AI-generated apps stashed as drafts. Resume to review or repair, push to commit to GitHub, or discard to throw away.</p>
				<div id="ep-agent-drafts-list"></div>
			</section>

			<!-- Apps Table -->
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Apps</span><div class="ep-section__line"></div></div>
				<div id="ep-apps-table"></div>
			</section>

		</div>

		<!-- Demo -->
		<div class="ep-panel" id="p-demo" role="tabpanel" aria-hidden="true">
			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Demo Companion Plugin</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Install the demo companion plugin to see the routing contract in action. The demo claims its own namespace, defines a routing cascade, and renders distinct template blocks. Inspect the source, then remove it when you're ready to scaffold your own.</p>

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
				<div class="ep-section__header"><span class="ep-section__title">Check for Plugin Updates</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Check GitHub for a newer version of the demo plugin.</p>

				<div class="ep-demo-panel">
					<div class="ep-demo-status">
						<div class="ep-demo-status-label">Installed</div>
						<span class="ep-badge ep-badge--success" id="ep-demo-current-ver">&mdash;</span>
					</div>
					<div class="ep-demo-status" id="ep-demo-target-row" style="display:none">
						<div class="ep-demo-status-label">Target</div>
						<span class="ep-badge ep-badge--success" id="ep-demo-target-ver"></span>
					</div>
					<div class="ep-demo-status" id="ep-demo-available-row" style="display:none">
						<div class="ep-demo-status-label">Action Needed</div>
						<span class="ep-badge ep-badge--warning" id="ep-demo-available-ver"></span>
					</div>
					<p class="ep-demo-message" id="ep-demo-check-message">Click below to check GitHub releases against your channel and pin settings.</p>
					<div class="ep-demo-actions">
						<button class="ep-btn ep-btn--primary" id="ep-demo-check-btn">Check Now</button>
						<button class="ep-btn ep-btn--primary" id="ep-demo-update-btn" style="display:none">Update Now</button>
					</div>
				</div>
			</section>

			<!-- Plugin Channel & Pinning -->
			<section class="ep-section" id="ep-demo-settings-section">
				<div class="ep-section__header"><span class="ep-section__title">Plugin Channel &amp; Pinning</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Choose which release channel to follow for the demo plugin. Optionally pin to a specific version.</p>

				<div class="ep-demo-panel">
					<div class="ep-form">
						<div class="ep-form__row">
							<label class="ep-form__label" for="ep-demo-channel">Channel</label>
							<select id="ep-demo-channel">
								<option value="stable">Stable (main branch releases only)</option>
								<option value="prerelease">Pre-release (includes development builds)</option>
							</select>
						</div>
						<div class="ep-form__row">
							<label class="ep-form__label" for="ep-demo-pin">Pin to Version</label>
							<select id="ep-demo-pin">
								<option value="">Latest (no pin)</option>
							</select>
							<p class="ep-form__hint">When pinned, the updater will not offer versions newer than the pinned release.</p>
						</div>
					</div>
					<div class="ep-demo-actions">
						<button class="ep-btn ep-btn--primary" id="ep-demo-save-settings">Save Settings</button>
					</div>
					<p class="ep-demo-message" id="ep-demo-settings-message" style="display:none"></p>
				</div>
			</section>
		</div>

		</div><!-- /.ep-panels -->

	<!-- App Scaffold Modal -->
	<div class="ep-modal__overlay" id="ep-apps-scaffold-modal" style="display:none">
		<div class="ep-modal ep-modal--sm">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">New App</span>
					<span class="ep-modal__subtitle">Scaffolds a plugin in <code>wp-content/plugins/</code></span>
				</div>
				<button class="ep-modal__close" data-modal="ep-apps-scaffold-modal">&times;</button>
			</div>
			<div class="ep-modal__body">
				<div class="ep-form">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-apps-scaffold-name">App Name</label>
						<input type="text" id="ep-apps-scaffold-name" placeholder="e.g., ExamplePress Analytics" />
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-apps-scaffold-desc">Description</label>
						<input type="text" id="ep-apps-scaffold-desc" placeholder="What does this app do?" />
					</div>
				</div>
				<div class="ep-scaffold-steps" id="ep-scaffold-steps"></div>
				<div id="ep-apps-scaffold-error" class="ep-form__error" style="display:none"></div>
				<div id="ep-apps-scaffold-warnings" class="ep-scaffold-warnings" style="display:none"></div>
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-apps-scaffold-modal">Cancel</button>
				<button class="ep-btn ep-btn--primary" id="ep-apps-scaffold-submit">Create App</button>
			</div>
		</div>
	</div>

	<!-- Troy Connect Modal -->
	<div class="ep-modal__overlay" id="ep-apps-troy-modal" style="display:none">
		<div class="ep-modal ep-modal--sm">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">Manage App</span>
					<span class="ep-modal__subtitle">Connect to Troy for repo provisioning and automatic updates</span>
				</div>
				<button class="ep-modal__close" data-modal="ep-apps-troy-modal">&times;</button>
			</div>
			<div class="ep-modal__body">
				<div class="ep-apps-troy-info">
					<div class="ep-apps-troy-info-label">Troy &mdash; Decentralized Directory</div>
					<div class="ep-apps-troy-info-sub">Tagged GitHub releases will serve updates automatically after initialization.</div>
				</div>
				<div class="ep-radio-group">
					<label class="ep-radio-group__label">
						<input type="radio" name="ep-apps-troy-target" value="cloud" checked />
						Troy Cloud (hosted)
					</label>
					<label class="ep-radio-group__label">
						<input type="radio" name="ep-apps-troy-target" value="custom" />
						Self-hosted Troy instance
					</label>
					<input type="url" id="ep-apps-troy-custom-url" placeholder="https://troy.yourdomain.com" style="display:none" />
				</div>
				<div id="ep-apps-troy-error" class="ep-form__error" style="display:none"></div>
				<input type="hidden" id="ep-apps-troy-target-slug" />
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-apps-troy-modal">Cancel</button>
				<button class="ep-btn ep-btn--purple" id="ep-apps-troy-submit">Connect to Troy &rarr;</button>
			</div>
		</div>
	</div>

	<!-- Generate-with-AI Modal (new app) -->
	<div class="ep-modal__overlay" id="ep-agent-modal" style="display:none">
		<div class="ep-modal ep-modal--lg">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">Generate App with AI</span>
					<span class="ep-modal__subtitle">Describe what you want — the agent drafts, validates, then waits for your review before pushing.</span>
				</div>
				<button class="ep-modal__close" data-modal="ep-agent-modal">&times;</button>
			</div>
			<div class="ep-modal__body">
				<div class="ep-form">
					<div class="ep-form__row ep-form__row--inline">
						<div class="ep-form__row">
							<label class="ep-form__label" for="ep-agent-app-name">App Name</label>
							<input id="ep-agent-app-name" type="text" placeholder="e.g., Team Directory" />
						</div>
						<div class="ep-form__row">
							<label class="ep-form__label" for="ep-agent-app-slug">Slug</label>
							<input id="ep-agent-app-slug" type="text" placeholder="e.g., team-directory" />
						</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-app-description">Description</label>
						<input id="ep-agent-app-description" type="text" placeholder="Short description of the app" />
					</div>
					<div class="ep-form__row">
						<div class="ep-form__label-row">
							<label class="ep-form__label" for="ep-agent-prompt">Prompt</label>
							<span id="ep-agent-model-badge" class="ep-badge"></span>
						</div>
						<textarea id="ep-agent-prompt" rows="4" placeholder="Describe what you want the app to do — layout, features, data sources, etc."></textarea>
					</div>
				</div>
				<div class="ep-scaffold-steps" id="ep-agent-steps"></div>
				<div id="ep-agent-error" class="ep-form__error" style="display:none"></div>

				<!-- Draft preview (revealed when status === 'drafted') -->
				<div id="ep-agent-draft-preview" class="ep-agent-draft-preview" style="display:none">
					<div class="ep-agent-draft-preview__header">
						<strong class="ep-agent-draft-preview__title">Draft ready for review</strong>
						<span class="ep-modal__subtitle" id="ep-agent-draft-summary"></span>
					</div>
					<div id="ep-agent-draft-files" class="ep-agent-draft-files"></div>
					<div id="ep-agent-draft-file-viewer" class="ep-agent-file-viewer" style="display:none"></div>
				</div>
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-agent-modal" id="ep-agent-cancel-btn">Cancel</button>
				<button class="ep-btn ep-btn--danger" id="ep-agent-discard-btn" style="display:none">Discard draft</button>
				<button class="ep-btn" id="ep-agent-retry-btn" style="display:none">Retry</button>
				<button class="ep-btn ep-btn--primary" id="ep-agent-submit">Generate Draft</button>
				<button class="ep-btn ep-btn--primary ep-btn--success" id="ep-agent-commit-btn" style="display:none">Push to GitHub</button>
			</div>
		</div>
	</div>

	<!-- Iterate-with-AI Modal (chat thread) -->
	<div class="ep-modal__overlay" id="ep-agent-iterate-modal" style="display:none">
		<div class="ep-modal ep-modal--lg">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">✨ Iterate with AI</span>
					<span class="ep-modal__subtitle" id="ep-agent-iterate-slug"></span>
				</div>
				<button class="ep-modal__close" data-modal="ep-agent-iterate-modal">&times;</button>
			</div>
			<div class="ep-modal__body ep-modal__body--flush">
				<!-- Chat thread (newest at bottom) -->
				<div id="ep-agent-chat-thread" class="ep-agent-chat-thread"></div>

				<!-- Composer -->
				<div class="ep-agent-composer">
					<div class="ep-form">
						<div class="ep-form__row">
							<div class="ep-form__label-row">
								<label class="ep-form__label" for="ep-agent-iterate-prompt">Change request</label>
								<span id="ep-agent-iterate-model-badge" class="ep-badge"></span>
							</div>
							<textarea id="ep-agent-iterate-prompt" rows="3" placeholder="e.g., Make the header text red and add a search bar above the grid."></textarea>
							<div id="ep-agent-iterate-context-hint" class="ep-form__hint"></div>
						</div>
					</div>
					<div class="ep-scaffold-steps" id="ep-agent-iterate-steps"></div>
					<div id="ep-agent-iterate-error" class="ep-form__error" style="display:none"></div>

					<!-- Draft preview for iterations -->
					<div id="ep-agent-iterate-draft-preview" class="ep-agent-draft-preview" style="display:none">
						<div class="ep-agent-draft-preview__header">
							<strong class="ep-agent-draft-preview__title">Draft ready for review</strong>
							<span class="ep-modal__subtitle" id="ep-agent-iterate-draft-summary"></span>
						</div>
						<div id="ep-agent-iterate-draft-files" class="ep-agent-draft-files"></div>
					</div>
				</div>
				<input type="hidden" id="ep-agent-iterate-target-slug" />
				<input type="hidden" id="ep-agent-iterate-current-job-id" />
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--danger" id="ep-agent-iterate-eject-btn">Eject…</button>
				<button class="ep-btn ep-btn--secondary" data-modal="ep-agent-iterate-modal">Close</button>
				<button class="ep-btn ep-btn--danger" id="ep-agent-iterate-discard-btn" style="display:none">Discard draft</button>
				<button class="ep-btn ep-btn--primary" id="ep-agent-iterate-submit">Send</button>
				<button class="ep-btn ep-btn--primary ep-btn--success" id="ep-agent-iterate-commit-btn" style="display:none">Push to GitHub</button>
			</div>
		</div>
	</div>

	<!-- Repair Modal (surgical fix for a reported error) -->
	<div class="ep-modal__overlay" id="ep-agent-repair-modal" style="display:none">
		<div class="ep-modal ep-modal--lg">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">🛠 Repair with AI</span>
					<span class="ep-modal__subtitle" id="ep-agent-repair-slug"></span>
				</div>
				<button class="ep-modal__close" data-modal="ep-agent-repair-modal">&times;</button>
			</div>
			<div class="ep-modal__body">
				<p class="ep-section__desc">
					Paste the exact error message from PHP error log, browser, or WordPress activation screen. The agent will produce a <strong>surgical fix</strong> — modifying only the files necessary to resolve this error, preserving everything else byte-identical.
				</p>
				<div class="ep-form">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-repair-error">Error message <span class="ep-form__required">*</span></label>
						<textarea id="ep-agent-repair-error" rows="4" placeholder="Fatal error: Uncaught Error: Call to undefined function..."></textarea>
					</div>
					<div class="ep-form__row ep-form__row--inline">
						<div class="ep-form__row">
							<label class="ep-form__label" for="ep-agent-repair-file">File (optional)</label>
							<input type="text" id="ep-agent-repair-file" placeholder="app/templates/front/index.php" />
						</div>
						<div class="ep-form__row">
							<label class="ep-form__label" for="ep-agent-repair-line">Line (optional)</label>
							<input type="number" id="ep-agent-repair-line" placeholder="42" />
						</div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-repair-prompt">Additional notes (optional)</label>
						<textarea id="ep-agent-repair-prompt" rows="2" placeholder="The variable should default to an empty array when no posts are found."></textarea>
					</div>
				</div>

				<div class="ep-scaffold-steps" id="ep-agent-repair-steps"></div>
				<div id="ep-agent-repair-error-msg" class="ep-form__error" style="display:none"></div>

				<!-- Draft preview with change summary -->
				<div id="ep-agent-repair-draft-preview" class="ep-agent-draft-preview" style="display:none">
					<div class="ep-agent-draft-preview__header">
						<strong class="ep-agent-draft-preview__title">Surgical fix ready for review</strong>
						<span class="ep-modal__subtitle" id="ep-agent-repair-draft-summary"></span>
					</div>
					<div id="ep-agent-repair-change-badges" class="ep-agent-change-badges"></div>
					<div id="ep-agent-repair-draft-files" class="ep-agent-draft-files"></div>
				</div>
				<input type="hidden" id="ep-agent-repair-target-slug" />
				<input type="hidden" id="ep-agent-repair-current-job-id" />
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-agent-repair-modal">Cancel</button>
				<button class="ep-btn ep-btn--danger" id="ep-agent-repair-discard-btn" style="display:none">Discard draft</button>
				<button class="ep-btn ep-btn--primary" id="ep-agent-repair-submit">Diagnose &amp; Draft Fix</button>
				<button class="ep-btn ep-btn--primary ep-btn--success" id="ep-agent-repair-commit-btn" style="display:none">Push fix to GitHub</button>
			</div>
		</div>
	</div>

	<!-- Eject Confirmation Modal (separate, hardened) -->
	<div class="ep-modal__overlay" id="ep-agent-eject-modal" style="display:none">
		<div class="ep-modal ep-modal--sm">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title ep-modal__title--danger">⚠ Eject to Developer Mode</span>
					<span class="ep-modal__subtitle">This is irreversible from the UI.</span>
				</div>
				<button class="ep-modal__close" data-modal="ep-agent-eject-modal">&times;</button>
			</div>
			<div class="ep-modal__body">
				<p class="ep-section__desc">Ejecting <strong id="ep-agent-eject-slug-display"></strong> will:</p>
				<ul class="ep-list">
					<li>Set <code>supports_ai_iteration: false</code> in the manifest</li>
					<li>Commit the change and tag a new patch release</li>
					<li>Permanently lock the AI chat interface for this app</li>
					<li>Hand the GitHub repo to developer-mode-only workflows</li>
				</ul>
				<div class="ep-form">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-agent-eject-confirm">Type the app slug to confirm</label>
						<input type="text" id="ep-agent-eject-confirm" autocomplete="off" />
					</div>
				</div>
				<div id="ep-agent-eject-error" class="ep-form__error" style="display:none"></div>
				<input type="hidden" id="ep-agent-eject-target-slug" />
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-agent-eject-modal">Cancel</button>
				<button class="ep-btn ep-btn--primary ep-btn--danger" id="ep-agent-eject-confirm-btn" disabled>Eject</button>
			</div>
		</div>
	</div>

	<!-- Jobs & History Modal -->
	<div class="ep-modal__overlay" id="ep-agent-jobs-modal" style="display:none">
		<div class="ep-modal ep-modal--lg">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">AI Generation Jobs</span>
					<span class="ep-modal__subtitle">Recent generations and iterations across all apps</span>
				</div>
				<button class="ep-modal__close" data-modal="ep-agent-jobs-modal">&times;</button>
			</div>
			<div class="ep-modal__body ep-modal__body--flush">
				<div id="ep-agent-jobs-list" class="ep-agent-jobs-list"></div>
			</div>
			<div class="ep-modal__footer">
				<button class="ep-btn ep-btn--secondary" data-modal="ep-agent-jobs-modal">Close</button>
			</div>
		</div>
	</div>

	<!-- Review Draft Modal -->
	<div class="ep-modal__overlay" id="ep-agent-review-modal" style="display:none">
		<div class="ep-modal ep-modal--xl">
			<div class="ep-modal__header">
				<div>
					<span class="ep-modal__title">Review Draft</span>
					<span class="ep-modal__subtitle" id="ep-agent-review-slug"></span>
				</div>
				<button class="ep-modal__close" data-modal="ep-agent-review-modal">&times;</button>
			</div>
			<div class="ep-modal__body ep-modal__body--flush ep-modal__body--split">
				<div id="ep-agent-review-file-list" class="ep-agent-review-sidebar"></div>
				<div id="ep-agent-review-file-contents" class="ep-agent-file-viewer"></div>
			</div>
			<div class="ep-modal__footer">
				<span id="ep-agent-review-summary" class="ep-modal__footer-note"></span>
				<button class="ep-btn ep-btn--secondary" data-modal="ep-agent-review-modal">Close</button>
				<button class="ep-btn ep-btn--primary ep-btn--success" id="ep-agent-review-push-btn">Push to GitHub</button>
			</div>
		</div>
	</div>

	<!-- Codespace Modal -->
	<div class="ep-modal__overlay" id="ep-apps-codespace-modal" style="display:none">
		<div class="ep-modal ep-modal--sm">
			<div class="ep-modal__body ep-modal__body--centered">
				<svg width="40" height="40" viewBox="0 0 98 96" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M48.854 0C21.839 0 0 22 0 49.217c0 21.756 13.993 40.172 33.405 46.69 2.427.49 3.316-1.059 3.316-2.362 0-1.141-.08-5.052-.08-9.127-13.59 2.934-16.42-5.867-16.42-5.867-2.184-5.704-5.42-7.17-5.42-7.17-4.448-3.015.324-3.015.324-3.015 4.934.326 7.523 5.052 7.523 5.052 4.367 7.496 11.404 5.378 14.235 4.074.404-3.178 1.699-5.378 3.074-6.6-10.839-1.141-22.243-5.378-22.243-24.283 0-5.378 1.94-9.778 5.014-13.2-.485-1.222-2.184-6.275.486-13.038 0 0 4.125-1.304 13.426 5.052a46.97 46.97 0 0 1 12.214-1.63c4.125 0 8.33.571 12.213 1.63 9.302-6.356 13.427-5.052 13.427-5.052 2.67 6.763.97 11.816.485 13.038 3.155 3.422 5.015 7.822 5.015 13.2 0 18.905-11.404 23.06-22.324 24.283 1.78 1.548 3.316 4.481 3.316 9.126 0 6.6-.08 11.897-.08 13.526 0 1.304.89 2.853 3.316 2.364 19.412-6.52 33.405-24.935 33.405-46.691C97.707 22 75.788 0 48.854 0z" fill="#24292f"/></svg>
				<h2 class="ep-modal__heading">Launching Codespace</h2>
				<p class="ep-section__desc">You'll be redirected to GitHub with the repo pre-selected.</p>
				<div class="ep-apps-codespace-url" id="ep-apps-codespace-url"></div>
				<button class="ep-btn ep-btn--secondary" data-modal="ep-apps-codespace-modal">Close</button>
			</div>
		</div>
	</div>
