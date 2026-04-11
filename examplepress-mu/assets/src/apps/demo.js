/**
 * Dashboard — Demo companion plugin management.
 *
 * Sections:
 *   1. Plugin install/uninstall (status badge + actions)
 *   2. Check for updates (flush cache + force re-check)
 *   3. Channel & version pinning settings
 */
import { esc } from '../lib/dom.js';
import { log } from '../lib/logger.js';

export function renderDemo(data) {
	const panel = document.getElementById('ep-demo-panel');
	if (!panel || !data.demo) return;

	let status = data.demo.status || 'not-installed';

	const badge   = document.getElementById('ep-demo-badge');
	const message = document.getElementById('ep-demo-message');
	const actions = document.getElementById('ep-demo-actions');

	// ── Plugin install/uninstall ─────────────────────────────────────

	function renderPlugin() {
		const badgeMap = {
			'not-installed': { cls: 'ep-badge--pending',  lbl: 'Not Installed' },
			'installed':     { cls: 'ep-badge--warning', lbl: 'Installed' },
			'active':        { cls: 'ep-badge--success',   lbl: 'Active' },
			'foreign':       { cls: 'ep-badge--danger',  lbl: 'Conflict' },
		};
		const b = badgeMap[status] || badgeMap['not-installed'];
		badge.className = `ep-badge ${b.cls}`;
		badge.innerHTML = `<span class="ep-dot"></span>${b.lbl}`;

		const messages = {
			'not-installed': 'The demo plugin is not installed. Click Install to download it from GitHub and activate it.',
			'installed':     'The demo plugin is installed but not active.',
			'active':        'The demo companion plugin is running. Visit the frontend to see the routing contract in action.',
			'foreign':       'A plugin named examplepress-demo exists but is not the ExamplePress demo. Remove it manually before installing.',
		};
		message.textContent = messages[status] || '';

		let html = '';
		if (status === 'not-installed') {
			html += '<button class="ep-btn ep-btn--primary" id="ep-demo-install">Install &amp; Activate</button>';
		} else if (status === 'installed') {
			html += '<button class="ep-btn ep-btn--primary" id="ep-demo-install">Activate</button>';
			html += '<button class="ep-btn ep-btn--danger" id="ep-demo-uninstall">Remove</button>';
		} else if (status === 'active') {
			html += `<a class="ep-btn ep-btn--primary" href="${esc(window.location.origin)}" target="_blank" rel="noopener">View Frontend &rarr;</a>`;
			html += '<button class="ep-btn ep-btn--danger" id="ep-demo-uninstall">Remove Demo</button>';
		}
		actions.innerHTML = html;

		bindInstallBtn();
		bindUninstallBtn();
		toggleSections();
	}

	function toggleSections() {
		const checkSection    = document.getElementById('ep-demo-check-section');
		const settingsSection = document.getElementById('ep-demo-settings-section');
		// Show check section when installed or active so users can see
		// available versions and check for updates before/after install.
		const showCheck    = status === 'active' || status === 'installed';
		// Show settings (channel/pin) always — users should be able to
		// configure which version to install before they install it.
		const showSettings = true;
		if (checkSection)    checkSection.style.display    = showCheck ? '' : 'none';
		if (settingsSection) settingsSection.style.display = showSettings ? '' : 'none';
	}

	function bindInstallBtn() {
		const btn = document.getElementById('ep-demo-install');
		if (!btn) return;
		btn.addEventListener('click', async () => {
			btn.disabled = true;
			btn.textContent = 'Installing...';
			log.info('[ExamplePress] Demo install started');
			try {
				const res = await fetch(data.demoInstallUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				});
				const result = await res.json();
				if (res.ok && result.success) {
					status = result.status;
					log.info(`[ExamplePress] Demo install success: ${result.status}`);
					renderPlugin();
					if (status === 'active') initSettings();
				} else {
					const msg = result.message || result.data?.message || 'Install failed.';
					message.textContent = msg;
					log.error(`[ExamplePress] Demo install error: ${msg}`);
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
		const btn = document.getElementById('ep-demo-uninstall');
		if (!btn) return;
		btn.addEventListener('click', async () => {
			btn.disabled = true;
			btn.textContent = 'Removing...';
			log.info('[ExamplePress] Demo uninstall started');
			try {
				const res = await fetch(data.demoUninstallUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				});
				const result = await res.json();
				if (res.ok && result.success) {
					status = result.status;
					log.info('[ExamplePress] Demo uninstall success');
					renderPlugin();
				} else {
					const msg = result.message || result.data?.message || 'Uninstall failed.';
					message.textContent = msg;
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
		const btn          = document.getElementById('ep-demo-check-btn');
		const curVerBadge  = document.getElementById('ep-demo-current-ver');
		const targetRow    = document.getElementById('ep-demo-target-row');
		const targetBadge  = document.getElementById('ep-demo-target-ver');
		const availRow     = document.getElementById('ep-demo-available-row');
		const availBadge   = document.getElementById('ep-demo-available-ver');
		const checkMsg     = document.getElementById('ep-demo-check-message');
		if (!btn) return;

		if (data.demo.current_version) {
			curVerBadge.textContent = data.demo.current_version;
		}

		btn.addEventListener('click', async () => {
			btn.disabled = true;
			btn.textContent = 'Checking...';
			checkMsg.textContent = 'Checking GitHub releases against your channel/pin settings...';
			targetRow.style.display = 'none';
			availRow.style.display = 'none';

			try {
				const res = await fetch(data.demoCheckUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
				});
				const result = await res.json();

				if (res.ok && result.success) {
					curVerBadge.textContent = result.current_version || '—';

					if (result.target) {
						targetRow.style.display = '';
						const tag = result.target.prerelease ? ' (pre-release)' : '';
						targetBadge.textContent = result.target.version + tag;
					}

					if (result.available) {
						availRow.style.display = '';
						const isPinned = result.settings?.pinned_version;

						availBadge.textContent = result.available.version;
						availBadge.className = 'ep-badge ep-badge--warning';

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
		const btn = document.getElementById('ep-demo-update-btn');
		if (!btn) return;
		btn.style.display = '';
		btn.disabled = false;
		btn.textContent = `Update to ${toVersion}`;

		const fresh = btn.cloneNode(true);
		btn.parentNode.replaceChild(fresh, btn);

		fresh.addEventListener('click', async () => {
			const checkMsg = document.getElementById('ep-demo-check-message');
			const curVerBadge = document.getElementById('ep-demo-current-ver');
			fresh.disabled = true;
			fresh.textContent = 'Updating...';
			checkMsg.textContent = `Downloading and installing ${toVersion}...`;

			try {
				const res = await fetch(data.demoUpdateUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
					body: JSON.stringify({ from_version: fromVersion }),
				});
				const result = await res.json();

				if (res.ok && result.success) {
					curVerBadge.textContent = result.new_version || toVersion;
					checkMsg.textContent = result.message;
					fresh.style.display = 'none';

					const availRow = document.getElementById('ep-demo-available-row');
					if (availRow) availRow.style.display = 'none';

					status = result.status || 'active';
					renderPlugin();

					log.info(`[ExamplePress] Demo plugin updated: ${fromVersion} → ${result.new_version}`);
				} else {
					const msg = result.message || result.data?.message || 'Update failed.';
					checkMsg.textContent = msg;
					fresh.disabled = false;
					fresh.textContent = 'Retry Update';
					log.error(`[ExamplePress] Demo update error: ${msg}`);
				}
			} catch (err) {
				checkMsg.textContent = 'Network error: ' + err.message;
				fresh.disabled = false;
				fresh.textContent = 'Retry Update';
			}
		});
	}

	function hideUpdateButton() {
		const btn = document.getElementById('ep-demo-update-btn');
		if (btn) btn.style.display = 'none';
	}

	// ── Settings (channel + pin) ─────────────────────────────────────

	async function initSettings() {
		const channelSelect = document.getElementById('ep-demo-channel');
		const pinSelect     = document.getElementById('ep-demo-pin');
		const saveBtn       = document.getElementById('ep-demo-save-settings');
		const settingsMsg   = document.getElementById('ep-demo-settings-message');
		if (!channelSelect || !pinSelect || !saveBtn) return;

		try {
			const res = await fetch(data.demoSettingsUrl, {
				headers: { 'X-WP-Nonce': data.nonce },
			});
			const result = await res.json();
			if (result.channel) channelSelect.value = result.channel;

			await loadReleases(pinSelect, result.pinned_version || '');
		} catch (err) {
			log.error('[ExamplePress] Failed to load demo settings: ' + err.message);
		}

		saveBtn.addEventListener('click', async () => {
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving...';
			settingsMsg.style.display = 'none';

			try {
				const res = await fetch(data.demoSettingsUrl, {
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
			const res = await fetch(data.demoReleasesUrl, {
				headers: { 'X-WP-Nonce': data.nonce },
			});
			const result = await res.json();

			if (!result.success || !result.releases) return;

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
			log.error('[ExamplePress] Failed to load demo releases: ' + err.message);
		}
	}

	// ── Init ─────────────────────────────────────────────────────────

	renderPlugin();
	initCheckNow();
	initSettings();
}
