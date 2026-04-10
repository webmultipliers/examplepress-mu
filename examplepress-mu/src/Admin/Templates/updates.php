<?php
/**
 * Template: Updates
 *
 * Two-tab update surface:
 *   - Theme  : ExamplePress theme update lifecycle (ThemeUpdateProvider).
 *   - Kernel : Read-only MU self-updater status + recovery actions.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true"  aria-controls="p-theme-update"  id="t-theme-update"  data-tab-id="theme-update">Theme</button>
			<button class="ep-tab" role="tab" aria-selected="false" aria-controls="p-kernel-update" id="t-kernel-update" data-tab-id="kernel-update">Kernel</button>
		</nav>

		<div class="ep-panels">

		<!-- Toast notices (shared across all tabs) -->
		<div id="ep-updates-notices"></div>

		<!-- TAB 1 — Theme -->
		<div class="ep-panel" id="p-theme-update" role="tabpanel" aria-hidden="false">

			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">ExamplePress Theme</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">The ExamplePress theme is updated directly from GitHub Releases by the MU kernel. No companion plugin required &mdash; the kernel lives in <code>mu-plugins</code> and survives theme upgrades natively.</p>

				<div id="ep-theme-update-panel">
					<!-- Skeleton — replaced by JS once data loads -->
					<div id="ep-theme-update-skeleton" class="ep-empty">
						<p>Loading update status...</p>
					</div>

					<div id="ep-theme-update-body" hidden>
						<div class="ep-stat-grid" id="ep-theme-update-stats">
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-theme-update-current-ver">&mdash;</div>
								<div class="ep-stat__label">Installed</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-theme-update-latest-ver">&mdash;</div>
								<div class="ep-stat__label">Latest</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value"><span class="ep-badge" id="ep-theme-update-status-badge">&mdash;</span></div>
								<div class="ep-stat__label">Status</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-theme-update-last-checked">Never</div>
								<div class="ep-stat__label">Last checked</div>
							</div>
						</div>

						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--primary" id="ep-theme-update-check-btn">Check Now</button>
							<button class="ep-btn ep-btn--primary" id="ep-theme-update-install-btn" hidden>Install Update</button>
							<button class="ep-btn ep-btn--secondary" id="ep-theme-update-reinstall-btn">Reinstall Current</button>
						</div>

						<div class="ep-progress" id="ep-theme-update-progress" hidden>
							<div class="ep-progress__track">
								<div class="ep-progress__bar"></div>
							</div>
							<div class="ep-progress__message"></div>
						</div>
					</div>
				</div>
			</section>

			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Channel &amp; Pinning</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Choose which release channel to follow. Optionally pin to a specific version to stop automatic upgrades past that release.</p>

				<div class="ep-form">
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-theme-update-channel">Channel</label>
						<div class="ep-form__control">
							<select id="ep-theme-update-channel">
								<option value="stable">Stable (released versions)</option>
								<option value="development">Development (pre-release builds)</option>
							</select>
						</div>
						<div class="ep-form__hint" id="ep-theme-update-channel-source"></div>
					</div>
					<div class="ep-form__row">
						<label class="ep-form__label" for="ep-theme-update-pin">Pin to Version</label>
						<div class="ep-form__control">
							<select id="ep-theme-update-pin">
								<option value="">Latest (no pin)</option>
							</select>
						</div>
						<div class="ep-form__hint">When pinned, the theme will not offer versions newer than the pinned release.</div>
					</div>
					<div class="ep-notice ep-notice--warning" id="ep-theme-update-pin-notice" hidden></div>
				</div>
			</section>

			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">Release History</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">Browse every GitHub release. Click a release to expand its notes.</p>

				<div id="ep-theme-update-releases-skeleton" class="ep-empty">
					<p>Loading releases...</p>
				</div>
				<ul id="ep-theme-update-releases" hidden></ul>
			</section>
		</div>

		<!-- TAB 2 — Kernel -->
		<div class="ep-panel" id="p-kernel-update" role="tabpanel" aria-hidden="true">

			<section class="ep-section">
				<div class="ep-section__header"><span class="ep-section__title">ExamplePress MU Kernel</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">The MU kernel self-updates from its own GitHub releases on a WP-Cron schedule. This is intentionally cron-driven &mdash; the kernel cannot meaningfully install itself from inside the running request. The controls below are limited to read-only status plus the two recovery actions (rollback to the previous on-disk snapshot, clear quarantine state).</p>

				<div id="ep-kernel-update-panel">
					<div id="ep-kernel-update-skeleton" class="ep-empty">
						<p>Loading kernel status...</p>
					</div>

					<div id="ep-kernel-update-body" hidden>
						<div class="ep-stat-grid" id="ep-kernel-update-stats">
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-kernel-update-current-ver">&mdash;</div>
								<div class="ep-stat__label">Installed</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-kernel-update-remote-ver">&mdash;</div>
								<div class="ep-stat__label">Latest known</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value"><span class="ep-badge" id="ep-kernel-update-status-badge">&mdash;</span></div>
								<div class="ep-stat__label">Status</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-kernel-update-last-fetched">Never</div>
								<div class="ep-stat__label">Last fetched (cron)</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-kernel-update-next-scheduled">&mdash;</div>
								<div class="ep-stat__label">Next scheduled (cron)</div>
							</div>
							<div class="ep-stat">
								<div class="ep-stat__value" id="ep-kernel-update-previous">&mdash;</div>
								<div class="ep-stat__label">Previous snapshot</div>
							</div>
						</div>

						<div class="ep-btn-group">
							<button class="ep-btn ep-btn--secondary" id="ep-kernel-update-rollback-btn" hidden>Rollback to Previous</button>
						</div>

						<div class="ep-progress" id="ep-kernel-update-progress" hidden>
							<div class="ep-progress__track">
								<div class="ep-progress__bar"></div>
							</div>
							<div class="ep-progress__message"></div>
						</div>
					</div>
				</div>
			</section>

			<section class="ep-section" id="ep-kernel-update-quarantine-section" hidden>
				<div class="ep-section__header"><span class="ep-section__title">Quarantine</span><div class="ep-section__line"></div></div>
				<p class="ep-section__desc">The loader has detected repeated boot failures and quarantined the kernel. Clear this state after you've remediated the underlying problem.</p>

				<div class="ep-notice ep-notice--error" id="ep-kernel-update-quarantine-notice"></div>
				<div class="ep-btn-group">
					<button class="ep-btn ep-btn--danger" id="ep-kernel-update-clear-quarantine-btn">Clear Quarantine State</button>
				</div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
