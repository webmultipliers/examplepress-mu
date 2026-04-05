/**
 * Dashboard — Updater companion plugin management.
 *
 * Sections:
 *   1. Plugin install/uninstall (status badge + actions)
 *   2. Check for updates (flush cache + force re-check)
 *   3. Channel & version pinning settings
 */
import { log } from '../lib/logger.js';

export function renderUpdater(data) {
	const panel = document.getElementById('ep-updater-panel');
	if (!panel || !data.updater) return;

	let status = data.updater.status || 'not-installed';

	const badge   = document.getElementById('ep-updater-badge');
	const message = document.getElementById('ep-updater-message');
	const actions = document.getElementById('ep-updater-actions');

	// ── Plugin install/uninstall ─────────────────────────────────────

	function renderPlugin() {
		const badgeMap = {
			'not-installed': { cls: 'badge-off',  lbl: 'Not Installed' },
			'installed':     { cls: 'badge-warn', lbl: 'Installed' },
			'active':        { cls: 'badge-on',   lbl: 'Active' },
		};
		const b = badgeMap[status] || badgeMap['not-installed'];
		badge.className = `ep-badge ${b.cls}`;
		badge.innerHTML = `<span class="ep-dot"></span>${b.lbl}`;

		const messages = {
			'not-installed': 'The updater plugin is not installed. Click Install to download it from GitHub and activate it.',
			'installed':     'The updater plugin is installed but not active.',
			'active':        'The updater plugin is running. Theme updates will be checked automatically via GitHub Releases.',
		};
		message.textContent = messages[status] || '';

		let html = '';
		if (status === 'not-installed') {
			html += '<button class="ep-demo-btn ep-demo-btn-primary" id="ep-updater-install">Install &amp; Activate</button>';
		} else if (status === 'installed') {
			html += '<button class="ep-demo-btn ep-demo-btn-primary" id="ep-updater-install">Activate</button>';
			html += '<button class="ep-demo-btn ep-demo-btn-danger" id="ep-updater-uninstall">Remove</button>';
		} else if (status === 'active') {
			html += '<button class="ep-demo-btn ep-demo-btn-danger" id="ep-updater-uninstall">Remove Plugin</button>';
		}
		actions.innerHTML = html;

		bindInstallBtn();
		bindUninstallBtn();
		toggleSections();
	}

	function toggleSections() {
		const checkSection    = document.getElementById('ep-updater-check-section');
		const settingsSection = document.getElementById('ep-updater-settings-section');
		const show = status === 'active';
		if (checkSection)    checkSection.style.display    = show ? '' : 'none';
		if (settingsSection) settingsSection.style.display = show ? '' : 'none';
	}

	function bindInstallBtn() {
		const btn = document.getElementById('ep-updater-install');
		if (!btn) return;
		btn.addEventListener('click', async () => {
			btn.disabled = true;
			btn.textContent = 'Installing...';
			log.info('[ExamplePress] Updater install started');
			try {
				const res = await fetch(data.updaterInstallUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				});
				const result = await res.json();
				if (res.ok && result.success) {
					status = result.status;
					renderPlugin();
					if (status === 'active') initSettings();
				} else {
					message.textContent = result.message || result.data?.message || 'Install failed.';
					btn.disabled = false;
					btn.textContent = 'Retry Install';
				}
			} catch (err) {
				message.textContent = 'Network error: ' + err.message;
				btn.disabled = false;
				btn.textContent = 'Retry Install';
			}
		});
	}

	function bindUninstallBtn() {
		const btn = document.getElementById('ep-updater-uninstall');
		if (!btn) return;
		btn.addEventListener('click', async () => {
			btn.disabled = true;
			btn.textContent = 'Removing...';
			try {
				const res = await fetch(data.updaterUninstallUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				});
				const result = await res.json();
				if (res.ok && result.success) {
					status = result.status;
					renderPlugin();
				} else {
					message.textContent = result.message || result.data?.message || 'Uninstall failed.';
					btn.disabled = false;
					btn.textContent = 'Retry Remove';
				}
			} catch (err) {
				message.textContent = 'Network error: ' + err.message;
				btn.disabled = false;
				btn.textContent = 'Retry Remove';
			}
		});
	}

	// ── Check for updates ────────────────────────────────────────────

	function initCheckNow() {
		const btn          = document.getElementById('ep-updater-check-btn');
		const curVerBadge  = document.getElementById('ep-updater-current-ver');
		const targetRow    = document.getElementById('ep-updater-target-row');
		const targetBadge  = document.getElementById('ep-updater-target-ver');
		const availRow     = document.getElementById('ep-updater-available-row');
		const availBadge   = document.getElementById('ep-updater-available-ver');
		const checkMsg     = document.getElementById('ep-updater-check-message');
		if (!btn) return;

		// Show installed version from initial payload.
		if (data.updater.current_version) {
			curVerBadge.textContent = data.updater.current_version;
		}

		btn.addEventListener('click', async () => {
			btn.disabled = true;
			btn.textContent = 'Checking...';
			checkMsg.textContent = 'Checking GitHub releases against your channel/pin settings...';
			targetRow.style.display = 'none';
			availRow.style.display = 'none';

			try {
				const res = await fetch(data.updaterCheckUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				});
				const result = await res.json();

				if (res.ok && result.success) {
					curVerBadge.textContent = result.current_version || '—';

					// Show the resolved target based on channel/pin.
					if (result.target) {
						targetRow.style.display = '';
						const tag = result.target.prerelease ? ' (pre-release)' : '';
						targetBadge.textContent = result.target.version + tag;
					}

					if (result.available) {
						availRow.style.display = '';
						const isPinned = result.settings?.pinned_version;

						availBadge.textContent = result.available.version;
						availBadge.className = 'ep-badge badge-warn';

						if (isPinned) {
							checkMsg.textContent = `Pinned to ${result.available.version} — installed version differs.`;
						} else {
							checkMsg.textContent = `Update available: ${result.available.version}.`;
						}

						showUpdateButton(result.current_version, result.available.version);
					} else {
						availRow.style.display = 'none';
						hideUpdateButton();
						const time = new Date(result.checked_at).toLocaleString();
						const ch = result.settings?.pinned_version
							? `pinned to ${result.settings.pinned_version}`
							: `channel: ${result.settings?.channel || 'stable'}`;
						checkMsg.textContent = `Up to date (${ch}). Checked at ${time}.`;
					}
				} else {
					checkMsg.textContent = result.message || result.data?.message || 'Check failed.';
				}
			} catch (err) {
				checkMsg.textContent = 'Network error: ' + err.message;
			}

			btn.disabled = false;
			btn.textContent = 'Check Now';
		});
	}

	// ── Update Now ───────────────────────────────────────────────────

	function showUpdateButton(fromVersion, toVersion) {
		const btn = document.getElementById('ep-updater-update-btn');
		if (!btn) return;
		btn.style.display = '';
		btn.disabled = false;
		btn.textContent = `Update to ${toVersion}`;

		// Remove old listeners by cloning.
		const fresh = btn.cloneNode(true);
		btn.parentNode.replaceChild(fresh, btn);

		fresh.addEventListener('click', async () => {
			const checkMsg = document.getElementById('ep-updater-check-message');
			const curVerBadge = document.getElementById('ep-updater-current-ver');
			fresh.disabled = true;
			fresh.textContent = 'Updating...';
			checkMsg.textContent = `Downloading and installing ${toVersion}...`;

			try {
				const res = await fetch(data.updaterUpdateUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
					body: JSON.stringify({ from_version: fromVersion }),
				});
				const result = await res.json();

				if (res.ok && result.success) {
					curVerBadge.textContent = result.new_version || toVersion;
					checkMsg.textContent = result.message;
					fresh.style.display = 'none';

					const availRow = document.getElementById('ep-updater-available-row');
					if (availRow) availRow.style.display = 'none';

					// Update plugin status badge too.
					status = result.status || 'active';
					renderPlugin();

					log.info(`[ExamplePress] Updater plugin updated: ${fromVersion} → ${result.new_version}`);
				} else {
					const msg = result.message || result.data?.message || 'Update failed.';
					checkMsg.textContent = msg;
					fresh.disabled = false;
					fresh.textContent = 'Retry Update';
					log.error(`[ExamplePress] Updater update error: ${msg}`);
				}
			} catch (err) {
				checkMsg.textContent = 'Network error: ' + err.message;
				fresh.disabled = false;
				fresh.textContent = 'Retry Update';
			}
		});
	}

	function hideUpdateButton() {
		const btn = document.getElementById('ep-updater-update-btn');
		if (btn) btn.style.display = 'none';
	}

	// ── Settings (channel + pin) ─────────────────────────────────────

	async function initSettings() {
		const channelSelect = document.getElementById('ep-updater-channel');
		const pinSelect     = document.getElementById('ep-updater-pin');
		const saveBtn       = document.getElementById('ep-updater-save-settings');
		const settingsMsg   = document.getElementById('ep-updater-settings-message');
		if (!channelSelect || !pinSelect || !saveBtn) return;

		// Load current settings.
		try {
			const res = await fetch(data.updaterSettingsUrl, {
				headers: { 'X-WP-Nonce': data.nonce },
			});
			const result = await res.json();
			if (result.channel) channelSelect.value = result.channel;

			// Load releases for the pin dropdown.
			await loadReleases(pinSelect, result.pinned_version || '');
		} catch (err) {
			log.error('[ExamplePress] Failed to load updater settings: ' + err.message);
		}

		// Save handler.
		saveBtn.addEventListener('click', async () => {
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving...';
			settingsMsg.style.display = 'none';

			try {
				const res = await fetch(data.updaterSettingsUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
					body: JSON.stringify({
						channel: channelSelect.value,
						pinned_version: pinSelect.value,
					}),
				});
				const result = await res.json();
				settingsMsg.style.display = '';
				settingsMsg.textContent = result.message || (result.success ? 'Saved.' : 'Save failed.');
			} catch (err) {
				settingsMsg.style.display = '';
				settingsMsg.textContent = 'Network error: ' + err.message;
			}

			saveBtn.disabled = false;
			saveBtn.textContent = 'Save Settings';
		});
	}

	async function loadReleases(pinSelect, currentPin) {
		try {
			const res = await fetch(data.updaterReleasesUrl, {
				headers: { 'X-WP-Nonce': data.nonce },
			});
			const result = await res.json();

			if (!result.success || !result.releases) return;

			// Keep the "Latest" default option, append releases.
			result.releases.forEach(r => {
				const opt  = document.createElement('option');
				opt.value  = r.version;
				const label = r.prerelease ? `${r.version} (pre-release)` : r.version;
				const date  = r.date ? ` — ${new Date(r.date).toLocaleDateString()}` : '';
				opt.textContent = `${label}${date}`;
				pinSelect.appendChild(opt);
			});

			if (currentPin) {
				pinSelect.value = currentPin;
			}
		} catch (err) {
			log.error('[ExamplePress] Failed to load releases: ' + err.message);
		}
	}

	// ── Init ─────────────────────────────────────────────────────────

	renderPlugin();
	initCheckNow();
	if (status === 'active') initSettings();
}
