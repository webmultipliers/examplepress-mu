<?php
/**
 * Template: Updates
 * ExamplePress theme update manager. Rendered by PageController::render().
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
?>

		<nav class="ep-tabs" role="tablist">
			<button class="ep-tab" role="tab" aria-selected="true" aria-controls="p-theme-update" id="t-theme-update" data-tab-id="theme-update">Theme</button>
		</nav>

		<div class="ep-panels">

		<!-- Theme -->
		<div class="ep-panel" id="p-theme-update" role="tabpanel" aria-hidden="false">
			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">ExamplePress Theme</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">The ExamplePress theme is updated directly from GitHub Releases by the MU kernel. No companion plugin is required &mdash; the kernel lives in <code>mu-plugins</code> and survives theme upgrades natively.</p>

				<div class="ep-demo-panel" id="ep-theme-update-panel">
					<div class="ep-demo-status">
						<div class="ep-demo-status-label">Installed</div>
						<span class="ep-badge badge-on" id="ep-theme-update-current-ver">&mdash;</span>
					</div>
					<div class="ep-demo-status" id="ep-theme-update-latest-row" style="display:none;margin-top:8px;">
						<div class="ep-demo-status-label">Latest</div>
						<span class="ep-badge badge-on" id="ep-theme-update-latest-ver"></span>
					</div>
					<div class="ep-demo-status" id="ep-theme-update-available-row" style="display:none;margin-top:8px;">
						<div class="ep-demo-status-label">Update Available</div>
						<span class="ep-badge badge-warn" id="ep-theme-update-available-ver"></span>
					</div>
					<p class="ep-demo-message" id="ep-theme-update-message"></p>
					<div class="ep-demo-actions">
						<button class="ep-demo-btn ep-demo-btn-primary" id="ep-theme-update-check-btn">Check Now</button>
						<button class="ep-demo-btn ep-demo-btn-primary" id="ep-theme-update-install-btn" style="display:none;">Install Update</button>
						<button class="ep-demo-btn" id="ep-theme-update-reinstall-btn">Reinstall Current</button>
					</div>
				</div>
			</section>

			<section class="ep-section">
				<div class="ep-section-header"><span class="ep-section-title">Channel &amp; Pinning</span><div class="ep-section-line"></div></div>
				<p class="ep-section-desc">Choose which release channel to follow for the theme. Optionally pin to a specific version to stop automatic upgrades past that release.</p>

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
					<p class="ep-demo-message" id="ep-theme-update-settings-message" style="display:none;"></p>
				</div>
			</section>
		</div>

		</div><!-- /.ep-panels -->
