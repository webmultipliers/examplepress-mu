<?php
/**
 * Template: Updates
 *
 * Unified update surface for every channel the MU owns:
 *   - Theme  : ExamplePress theme (ThemeUpdateProvider)
 *   - Kernel : MU self-updater    (Infrastructure\Updater)
 *   - Apps   : Companion apps     (AppUpdateProvider)
 *
 * Rendered by PageController::render(). All dynamic content is populated
 * by the Vite entry at assets/src/updates/main.js against the payload in
 * DataProvider::updatesData().
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-theme-update"  id="t-theme-update"  data-tab-id="theme-update">Theme</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-kernel-update" id="t-kernel-update" data-tab-id="kernel-update">Kernel</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-apps-update"   id="t-apps-update"   data-tab-id="apps-update">Apps</button>
		</nav>

		<div class="ep-panels ep-updates-page">

		<!-- Toast notices (shared across all tabs) -->
		<div class="ep-updates-notices" id="ep-updates-notices"></div>

		<!-- ═══════════════════════════════════════════════════════════════
		     TAB 1 — Theme
		     ═══════════════════════════════════════════════════════════════ -->
		<div class="ep-panel" id="p-theme-update" role="tabpanel" aria-hidden="false">

			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">ExamplePress Theme</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">The ExamplePress theme is updated directly from GitHub Releases by the MU kernel. No companion plugin required &mdash; the kernel lives in <code>mu-plugins</code> and survives theme upgrades natively.</p>

				<div class="ep-demo-panel" id="ep-theme-update-panel">
					<!-- Skeleton — replaced by JS once data loads -->
					<div class="ep-updates-skeleton" id="ep-theme-update-skeleton">
						<span></span><span></span><span></span>
					</div>

					<div class="ep-updates-body" id="ep-theme-update-body" hidden>
						<div class="ep-updates-grid">
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Installed</div>
								<div class="ep-updates-value" id="ep-theme-update-current-ver">&mdash;</div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Latest</div>
								<div class="ep-updates-value" id="ep-theme-update-latest-ver">&mdash;</div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Status</div>
								<div><span class="ep-updates-badge" id="ep-theme-update-status-badge">&mdash;</span></div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Last checked</div>
								<div class="ep-updates-value" id="ep-theme-update-last-checked">Never</div>
							</div>
						</div>

						<div class="ep-demo-actions" style="margin-top:16px;">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-theme-update-check-btn">Check Now</button>
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-theme-update-install-btn" hidden>Install Update</button>
							<button class="ep-demo-btn" id="ep-theme-update-reinstall-btn">Reinstall Current</button>
						</div>

						<div class="ep-updates-progress" id="ep-theme-update-progress" hidden>
							<div class="ep-updates-progress-bar"></div>
							<p class="ep-updates-progress-message"></p>
						</div>
					</div>
				</div>
			</section>

			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">Channel &amp; Pinning</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">Choose which release channel to follow. Optionally pin to a specific version to stop automatic upgrades past that release.</p>

				<div class="ep-demo-panel">
					<div class="ep-build-field">
						<label class="ep-build-label" for="ep-theme-update-channel">Channel</label>
						<select class="ep-build-input" id="ep-theme-update-channel" style="max-width:260px;">
							<option value="stable">Stable (released versions)</option>
							<option value="development">Development (pre-release builds)</option>
						</select>
						<p class="ep-section-desc" id="ep-theme-update-channel-source" style="margin-top:4px;font-size:12px;"></p>
					</div>
					<div class="ep-build-field" style="margin-top:12px;">
						<label class="ep-build-label" for="ep-theme-update-pin">Pin to Version</label>
						<select class="ep-build-input" id="ep-theme-update-pin" style="max-width:260px;">
							<option value="">Latest (no pin)</option>
						</select>
						<p class="ep-section-desc" style="margin-top:4px;font-size:12px;">When pinned, the theme will not offer versions newer than the pinned release.</p>
					</div>
					<div class="ep-updates-pin-notice" id="ep-theme-update-pin-notice" hidden></div>
				</div>
			</section>

			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">Release History</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">Browse every GitHub release. Click a release to expand its notes.</p>

				<div class="ep-demo-panel">
					<div class="ep-updates-skeleton" id="ep-theme-update-releases-skeleton">
						<span></span><span></span><span></span><span></span>
					</div>
					<ul class="ep-updates-release-list" id="ep-theme-update-releases" hidden></ul>
				</div>
			</section>
		</div>

		<!-- ═══════════════════════════════════════════════════════════════
		     TAB 2 — Kernel
		     ═══════════════════════════════════════════════════════════════ -->
		<div class="ep-panel" id="p-kernel-update" role="tabpanel" aria-hidden="true">

			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">ExamplePress MU Kernel</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">The MU kernel self-updates from its own GitHub releases on a WP-Cron schedule. These controls expose the state of the cron check and let you force a check, install, or rollback manually.</p>

				<div class="ep-demo-panel" id="ep-kernel-update-panel">
					<div class="ep-updates-skeleton" id="ep-kernel-update-skeleton">
						<span></span><span></span><span></span>
					</div>

					<div class="ep-updates-body" id="ep-kernel-update-body" hidden>
						<div class="ep-updates-grid">
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Installed</div>
								<div class="ep-updates-value" id="ep-kernel-update-current-ver">&mdash;</div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Latest known</div>
								<div class="ep-updates-value" id="ep-kernel-update-remote-ver">&mdash;</div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Status</div>
								<div><span class="ep-updates-badge" id="ep-kernel-update-status-badge">&mdash;</span></div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Last fetched</div>
								<div class="ep-updates-value" id="ep-kernel-update-last-fetched">Never</div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Next scheduled</div>
								<div class="ep-updates-value" id="ep-kernel-update-next-scheduled">&mdash;</div>
							</div>
							<div class="ep-updates-cell">
								<div class="ep-updates-label">Previous snapshot</div>
								<div class="ep-updates-value" id="ep-kernel-update-previous">&mdash;</div>
							</div>
						</div>

						<div class="ep-demo-actions" style="margin-top:16px;">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-kernel-update-check-btn">Check Now</button>
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-kernel-update-install-btn" hidden>Install Update</button>
							<button class="ep-demo-btn" id="ep-kernel-update-rollback-btn" hidden>Rollback to Previous</button>
						</div>

						<div class="ep-updates-progress" id="ep-kernel-update-progress" hidden>
							<div class="ep-updates-progress-bar"></div>
							<p class="ep-updates-progress-message"></p>
						</div>
					</div>
				</div>
			</section>

			<section class="ep-section" id="ep-kernel-update-quarantine-section" hidden>
				<div class="ep-section-header"><span class="ep-section-title">Quarantine</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">The loader has detected repeated boot failures and quarantined the kernel. Clear this state after you've remediated the underlying problem (e.g. by rolling back to the previous version, manually restoring files, or deploying a new loader).</p>

				<div class="ep-demo-panel">
					<div class="ep-updates-pin-notice" id="ep-kernel-update-quarantine-notice"></div>
					<div class="ep-demo-actions" style="margin-top:12px;">
						<button class="ep-demo-btn ep-demo-btn-danger" id="ep-kernel-update-clear-quarantine-btn">Clear Quarantine State</button>
					</div>
				</div>
			</section>
		</div>

		<!-- ═══════════════════════════════════════════════════════════════
		     TAB 3 — Apps
		     ═══════════════════════════════════════════════════════════════ -->
		<div class="ep-panel" id="p-apps-update" role="tabpanel" aria-hidden="true">

			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">Companion Apps</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">ExamplePress companion apps publish update records into WordPress's native <strong>Dashboard &rarr; Updates</strong> screen via <code>AppUpdateProvider</code>. This tab summarises what the provider currently knows; applying an update happens on the standard <a href="#" id="ep-apps-update-plugins-link">Plugins screen</a>.</p>

				<div class="ep-demo-panel">
					<div class="ep-updates-skeleton" id="ep-apps-update-skeleton">
						<span></span><span></span><span></span>
					</div>

					<div id="ep-apps-update-body" hidden>
						<div class="ep-demo-actions" style="margin-bottom:12px;">
							<button class="ep-demo-btn ep-demo-btn-primary" id="ep-apps-update-refresh-btn">Refresh</button>
						</div>
						<div id="ep-apps-update-empty" class="ep-updates-empty" hidden>No companion apps with GitHub repos are currently installed.</div>
						<div class="ep-updates-apps-table" id="ep-apps-update-table"></div>
					</div>
				</div>
			</section>
		</div>

		</div><!-- /.ep-panels.ep-updates-page -->
