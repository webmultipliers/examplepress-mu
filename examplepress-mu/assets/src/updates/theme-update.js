/**
 * Updates → Theme — ExamplePress theme update manager.
 *
 * The theme update lifecycle lives directly in the MU kernel
 * (Infrastructure\ThemeUpdateProvider) — no companion plugin involved.
 * This UI just talks to /theme-update/* REST endpoints.
 *
 * Sections:
 *   1. Status + actions (Check, Install, Reinstall)
 *   2. Channel + version pinning
 */
import { log } from '../lib/logger.js';

export function renderThemeUpdate(data) {
	const panel = document.getElementById('ep-theme-update-panel');
	if (!panel || !data.themeUpdate) return;

	// ── DOM refs ─────────────────────────────────────────────────────
	const curVerBadge    = document.getElementById('ep-theme-update-current-ver');
	const latestRow      = document.getElementById('ep-theme-update-latest-row');
	const latestBadge    = document.getElementById('ep-theme-update-latest-ver');
	const availableRow   = document.getElementById('ep-theme-update-available-row');
	const availableBadge = document.getElementById('ep-theme-update-available-ver');
	const message        = document.getElementById('ep-theme-update-message');
	const checkBtn       = document.getElementById('ep-theme-update-check-btn');
	const installBtn     = document.getElementById('ep-theme-update-install-btn');
	const reinstallBtn   = document.getElementById('ep-theme-update-reinstall-btn');
	const channelSelect  = document.getElementById('ep-theme-update-channel');
	const channelSource  = document.getElementById('ep-theme-update-channel-source');
	const pinSelect      = document.getElementById('ep-theme-update-pin');
	const settingsMsg    = document.getElementById('ep-theme-update-settings-message');

	// ── Render state from a status payload ───────────────────────────

	function applyStatus(status) {
		if (!status) return;

		curVerBadge.textContent = status.current_version || '—';

		if (status.latest_version) {
			latestRow.style.display = '';
			latestBadge.textContent = status.latest_version;
		} else {
			latestRow.style.display = 'none';
		}

		if (status.update_available && status.latest_version) {
			availableRow.style.display = '';
			availableBadge.textContent = status.pinned_version || status.latest_version;
			installBtn.style.display   = '';
			installBtn.disabled        = false;
			installBtn.textContent     = `Install ${status.pinned_version || status.latest_version}`;

			if (status.pinned_version) {
				message.textContent = `Pinned to ${status.pinned_version} — installed version differs.`;
			} else {
				message.textContent = `Update available: ${status.latest_version}.`;
			}
		} else {
			availableRow.style.display = 'none';
			installBtn.style.display   = 'none';

			if (status.last_checked) {
				const when = new Date(status.last_checked * 1000).toLocaleString();
				const ch   = status.pinned_version
					? `pinned to ${status.pinned_version}`
					: `channel: ${status.channel || 'stable'}`;
				message.textContent = `Up to date (${ch}). Checked at ${when}.`;
			} else {
				message.textContent = `Channel: ${status.channel || 'stable'}. Click Check Now to query GitHub.`;
			}
		}

		// Channel controls.
		if (status.channel) channelSelect.value = status.channel;

		const locked = status.channel_source === 'filter' || status.channel_source === 'constant';
		channelSelect.disabled = locked;
		if (locked) {
			channelSource.textContent = `Channel is locked by a ${status.channel_source}; the admin control is disabled.`;
		} else if (status.channel_source === 'auto') {
			channelSource.textContent = 'Channel auto-detected from the installed theme version string.';
		} else {
			channelSource.textContent = '';
		}
	}

	// ── REST helpers ─────────────────────────────────────────────────

	async function apiGet(url) {
		const res = await fetch(url, { headers: { 'X-WP-Nonce': data.nonce } });
		return res.json();
	}

	async function apiPost(url, body = null) {
		const res = await fetch(url, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
			body:    body ? JSON.stringify(body) : undefined,
		});
		return { ok: res.ok, body: await res.json() };
	}

	// ── Check Now ────────────────────────────────────────────────────

	checkBtn?.addEventListener('click', async () => {
		checkBtn.disabled = true;
		checkBtn.textContent = 'Checking…';
		message.textContent = 'Flushing cache and querying GitHub…';

		try {
			const { ok, body } = await apiPost(data.themeUpdateCheckUrl);
			if (ok) {
				applyStatus(body);
			} else {
				message.textContent = body?.message || body?.data?.message || 'Check failed.';
			}
		} catch (err) {
			message.textContent = 'Network error: ' + err.message;
		}

		checkBtn.disabled = false;
		checkBtn.textContent = 'Check Now';
	});

	// ── Install Update ───────────────────────────────────────────────

	installBtn?.addEventListener('click', async () => {
		installBtn.disabled = true;
		installBtn.textContent = 'Installing…';
		message.textContent = 'Downloading and installing the theme update…';

		try {
			const { ok, body } = await apiPost(data.themeUpdateInstallUrl);
			if (ok && body?.success) {
				message.textContent = body.message;
				log.info(`[ExamplePress] Theme updated to ${body.version}`);
				// Refresh status after install.
				const fresh = await apiGet(data.themeUpdateStatusUrl);
				applyStatus(fresh);
			} else {
				const msg = body?.message || body?.data?.message || 'Install failed.';
				message.textContent = msg;
				installBtn.disabled    = false;
				installBtn.textContent = 'Retry Install';
				log.error(`[ExamplePress] Theme update failed: ${msg}`);
			}
		} catch (err) {
			message.textContent = 'Network error: ' + err.message;
			installBtn.disabled    = false;
			installBtn.textContent = 'Retry Install';
		}
	});

	// ── Reinstall Current ────────────────────────────────────────────

	reinstallBtn?.addEventListener('click', async () => {
		if (!confirm('Reinstall the current version of the ExamplePress theme? Local modifications inside the theme directory will be lost.')) {
			return;
		}
		reinstallBtn.disabled = true;
		reinstallBtn.textContent = 'Reinstalling…';
		message.textContent = 'Reinstalling the theme directory from GitHub…';

		try {
			const { ok, body } = await apiPost(data.themeUpdateReinstallUrl);
			if (ok && body?.success) {
				message.textContent = body.message;
				const fresh = await apiGet(data.themeUpdateStatusUrl);
				applyStatus(fresh);
			} else {
				message.textContent = body?.message || body?.data?.message || 'Reinstall failed.';
			}
		} catch (err) {
			message.textContent = 'Network error: ' + err.message;
		}

		reinstallBtn.disabled    = false;
		reinstallBtn.textContent = 'Reinstall Current';
	});

	// ── Channel ──────────────────────────────────────────────────────

	channelSelect?.addEventListener('change', async () => {
		if (channelSelect.disabled) return;
		settingsMsg.style.display = 'none';

		try {
			const { ok, body } = await apiPost(data.themeUpdateChannelUrl, { channel: channelSelect.value });
			if (ok) {
				applyStatus(body);
				settingsMsg.style.display = '';
				settingsMsg.textContent   = 'Channel saved.';
			} else {
				settingsMsg.style.display = '';
				settingsMsg.textContent   = body?.message || body?.data?.message || 'Could not save channel.';
			}
		} catch (err) {
			settingsMsg.style.display = '';
			settingsMsg.textContent   = 'Network error: ' + err.message;
		}
	});

	// ── Pin ──────────────────────────────────────────────────────────

	async function loadReleasesIntoPin(currentPin) {
		try {
			const body = await apiGet(data.themeUpdateReleasesUrl);
			if (!body?.releases) return;

			// Preserve the "Latest" default option.
			pinSelect.innerHTML = '<option value="">Latest (no pin)</option>';

			body.releases.forEach((r) => {
				const opt = document.createElement('option');
				opt.value = r.version;
				const label = r.prerelease ? `${r.version} (pre-release)` : r.version;
				const date  = r.date ? ` — ${new Date(r.date).toLocaleDateString()}` : '';
				opt.textContent = `${label}${date}`;
				pinSelect.appendChild(opt);
			});

			if (currentPin) {
				pinSelect.value = currentPin;
			}
		} catch (err) {
			log.error('[ExamplePress] Failed to load theme releases: ' + err.message);
		}
	}

	pinSelect?.addEventListener('change', async () => {
		settingsMsg.style.display = 'none';

		try {
			const { ok, body } = await apiPost(data.themeUpdatePinUrl, { version: pinSelect.value || null });
			if (ok) {
				applyStatus(body);
				settingsMsg.style.display = '';
				settingsMsg.textContent   = pinSelect.value
					? `Pinned to ${pinSelect.value}.`
					: 'Pin cleared.';
			} else {
				settingsMsg.style.display = '';
				settingsMsg.textContent   = body?.message || body?.data?.message || 'Could not save pin.';
			}
		} catch (err) {
			settingsMsg.style.display = '';
			settingsMsg.textContent   = 'Network error: ' + err.message;
		}
	});

	// ── Init ─────────────────────────────────────────────────────────

	applyStatus(data.themeUpdate);
	loadReleasesIntoPin(data.themeUpdate.pinned_version || '');
}
