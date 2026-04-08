/**
 * Updates → Theme tab renderer.
 *
 * Backed by Infrastructure\ThemeUpdateProvider via the /theme-update/*
 * REST namespace (ThemeUpdateController). Feature set:
 *
 *   - Skeleton loader on initial render
 *   - Semantic status badge (current / update / error)
 *   - Channel selector with source lock messaging
 *   - Pin dropdown with sanity warning on current > pinned
 *   - Install / Reinstall with indeterminate progress
 *   - Release history with collapsible GitHub release notes
 *   - WP-native dismissible toasts on every async result
 */
import { log } from '../lib/logger.js';
import {
	toast, revealBody, showProgress, hideProgress,
	setBadge, api, versionCompare, esc, formatTimestamp,
} from './ui.js';

export function renderThemeUpdate(data) {
	const panel = document.getElementById('ep-theme-update-panel');
	if (!panel) return;

	// ── State ────────────────────────────────────────────────────────
	let status    = data.themeUpdate || null;
	let releases  = [];
	let busy      = false;

	// ── DOM refs ─────────────────────────────────────────────────────
	const $ = (id) => document.getElementById(id);
	const curVer        = $('ep-theme-update-current-ver');
	const latestVer     = $('ep-theme-update-latest-ver');
	const statusBadge   = $('ep-theme-update-status-badge');
	const lastChecked   = $('ep-theme-update-last-checked');
	const checkBtn      = $('ep-theme-update-check-btn');
	const installBtn    = $('ep-theme-update-install-btn');
	const reinstallBtn  = $('ep-theme-update-reinstall-btn');
	const channelSelect = $('ep-theme-update-channel');
	const channelSource = $('ep-theme-update-channel-source');
	const pinSelect     = $('ep-theme-update-pin');
	const pinNotice     = $('ep-theme-update-pin-notice');
	const releasesList  = $('ep-theme-update-releases');
	const releasesSkel  = $('ep-theme-update-releases-skeleton');

	// Required elements — if any of these are missing the template is broken
	// in a way the renderer can't compensate for. Bail loud.
	const required = { curVer, latestVer, statusBadge, lastChecked, checkBtn, installBtn, reinstallBtn, channelSelect, pinSelect };
	for (const [name, el] of Object.entries(required)) {
		if (!el) {
			console.warn(`[ExamplePress] Theme update renderer: missing element "${name}". Aborting.`);
			return;
		}
	}

	// ── Render ───────────────────────────────────────────────────────

	function render() {
		if (!status) return;

		curVer.textContent    = status.current_version || '—';
		latestVer.textContent = status.latest_version  || '—';
		lastChecked.textContent = status.last_checked
			? formatTimestamp(status.last_checked)
			: 'Never';

		// Badge state.
		if (!status.current_version) {
			setBadge(statusBadge, 'error', 'Theme not installed');
		} else if (status.update_available) {
			const label = status.pinned_version
				? `Update to ${status.pinned_version}`
				: `Update to ${status.latest_version || '?'}`;
			setBadge(statusBadge, 'update', label);
		} else {
			setBadge(statusBadge, 'current', 'Up to date');
		}

		// Install button.
		if (status.update_available && status.current_version) {
			installBtn.hidden = false;
			installBtn.disabled = busy;
			installBtn.textContent = `Install ${status.pinned_version || status.latest_version}`;
		} else {
			installBtn.hidden = true;
		}

		// Reinstall requires theme to exist.
		reinstallBtn.disabled = busy || !status.current_version;

		// Channel.
		if (status.channel) channelSelect.value = status.channel;
		const locked = status.channel_source === 'filter' || status.channel_source === 'constant';
		channelSelect.disabled = locked;
		if (locked) {
			const sourceLabel = status.channel_source === 'filter'
				? 'a WordPress filter (examplepress_mu_theme_update_channel)'
				: 'the EP_THEME_UPDATE_CHANNEL constant';
			channelSource.textContent = `Channel is controlled by ${sourceLabel} and cannot be changed here.`;
		} else if (status.channel_source === 'auto') {
			channelSource.textContent = 'Channel auto-detected from the installed theme version string.';
		} else {
			channelSource.textContent = '';
		}

		// Pin dropdown selection + sanity warning.
		if (pinSelect) {
			pinSelect.value = status.pinned_version || '';
		}
		renderPinNotice();
	}

	function renderPinNotice() {
		if (!pinNotice) return;
		if (!status || !status.pinned_version || !status.current_version) {
			pinNotice.hidden = true;
			return;
		}
		const cmp = versionCompare(status.current_version, status.pinned_version);
		if (cmp > 0) {
			pinNotice.hidden = false;
			pinNotice.textContent = `Installed version ${status.current_version} is newer than pinned version ${status.pinned_version}. Clear the pin or pin to a newer version.`;
		} else {
			pinNotice.hidden = true;
		}
	}

	function renderReleases() {
		if (!releasesList || !releasesSkel) return;
		releasesSkel.hidden = true;
		releasesList.hidden = false;

		if (!releases.length) {
			releasesList.innerHTML = '<li class="ep-updates-empty">No releases found.</li>';
			return;
		}

		releasesList.innerHTML = releases.map((r) => {
			const dateStr = r.date ? new Date(r.date).toLocaleDateString() : '';
			const prereleaseBadge = r.prerelease
				? '<span class="ep-updates-badge ep-updates-badge--prerelease">prerelease</span>'
				: '';
			const body = r.body
				? esc(r.body)
				: '<em>No release notes.</em>';
			return `
				<li class="ep-updates-release">
					<button type="button" class="ep-updates-release-header">
						<span class="ep-updates-release-toggle">&#9654;</span>
						<span class="ep-updates-release-version">${esc(r.version || r.tag)}</span>
						${prereleaseBadge}
						<span class="ep-updates-release-name">${esc(r.name || '')}</span>
						<span class="ep-updates-release-date">${esc(dateStr)}</span>
					</button>
					<div class="ep-updates-release-body">${body}</div>
				</li>
			`;
		}).join('');

		releasesList.querySelectorAll('.ep-updates-release-header').forEach((h) => {
			h.addEventListener('click', () => {
				h.parentElement.classList.toggle('ep-open');
			});
		});
	}

	// ── Loading ──────────────────────────────────────────────────────

	async function refreshStatus() {
		try {
			status = await api('GET', data.themeUpdateStatusUrl);
			render();
		} catch (err) {
			toast('error', `Could not load theme status: ${err.message}`);
		}
	}

	async function loadReleases() {
		try {
			const res = await api('GET', data.themeUpdateReleasesUrl);
			releases = res.releases || [];
			buildPinOptions(status && status.pinned_version);
			renderReleases();
		} catch (err) {
			if (releasesSkel) releasesSkel.hidden = true;
			if (releasesList) {
				releasesList.hidden = false;
				releasesList.innerHTML = `<li class="ep-updates-empty">Could not load releases: ${esc(err.message)}</li>`;
			}
		}
	}

	function buildPinOptions(currentPin) {
		if (!pinSelect) return;
		pinSelect.innerHTML = '<option value="">Latest (no pin)</option>';
		releases.forEach((r) => {
			const opt = document.createElement('option');
			opt.value = r.version || r.tag;
			const label = r.prerelease ? `${opt.value} (prerelease)` : opt.value;
			const dateStr = r.date ? ` — ${new Date(r.date).toLocaleDateString()}` : '';
			opt.textContent = `${label}${dateStr}`;
			pinSelect.appendChild(opt);
		});
		if (currentPin) pinSelect.value = currentPin;
	}

	// ── Actions ──────────────────────────────────────────────────────

	async function handleCheck() {
		setBusy(true);
		checkBtn.disabled = true;
		try {
			status = await api('POST', data.themeUpdateCheckUrl);
			render();
			toast('success', 'Update check complete.');
			loadReleases();
		} catch (err) {
			toast('error', err.message);
		} finally {
			setBusy(false);
			checkBtn.disabled = false;
		}
	}

	async function handleInstall() {
		if (!status || !status.update_available) return;
		setBusy(true);
		installBtn.disabled = true;
		showProgress('ep-theme-update-progress', 'Installing theme update…');
		try {
			const res = await api('POST', data.themeUpdateInstallUrl);
			toast('success', res.message || 'Theme updated.');
			log.info(`[ExamplePress] Theme updated to ${res.version}`);
			await refreshStatus();
		} catch (err) {
			toast('error', err.message);
		} finally {
			hideProgress('ep-theme-update-progress');
			setBusy(false);
		}
	}

	async function handleReinstall() {
		if (!status || !status.current_version) return;
		const confirmed = window.confirm(
			`Reinstall ExamplePress v${status.current_version}?\n\n` +
			'This will replace the current theme files with a clean copy from the release. ' +
			'Any local modifications to theme files will be lost.'
		);
		if (!confirmed) return;

		setBusy(true);
		reinstallBtn.disabled = true;
		showProgress('ep-theme-update-progress', 'Reinstalling theme…');
		try {
			const res = await api('POST', data.themeUpdateReinstallUrl);
			toast('success', res.message || 'Theme reinstalled.');
			await refreshStatus();
		} catch (err) {
			toast('error', err.message);
		} finally {
			hideProgress('ep-theme-update-progress');
			setBusy(false);
		}
	}

	async function handleChannelChange() {
		if (channelSelect.disabled) return;
		setBusy(true);
		try {
			status = await api('POST', data.themeUpdateChannelUrl, { channel: channelSelect.value });
			render();
			toast('success', `Channel set to ${channelSelect.value}.`);
			loadReleases();
		} catch (err) {
			toast('error', err.message);
			// Reset to server state.
			if (status) render();
		} finally {
			setBusy(false);
		}
	}

	async function handlePinChange() {
		setBusy(true);
		try {
			const version = pinSelect.value || null;
			status = await api('POST', data.themeUpdatePinUrl, { version });
			render();
			toast('success', version ? `Pinned to ${version}.` : 'Pin cleared.');
		} catch (err) {
			toast('error', err.message);
		} finally {
			setBusy(false);
		}
	}

	function setBusy(value) {
		busy = value;
		checkBtn.disabled     = value;
		installBtn.disabled   = value || (status && !status.update_available);
		reinstallBtn.disabled = value || !(status && status.current_version);
	}

	// ── Init ─────────────────────────────────────────────────────────

	checkBtn.addEventListener('click', handleCheck);
	installBtn.addEventListener('click', handleInstall);
	reinstallBtn.addEventListener('click', handleReinstall);
	channelSelect.addEventListener('change', handleChannelChange);
	pinSelect.addEventListener('change', handlePinChange);

	// Seed from the initial payload if present, then reveal.
	if (status) {
		revealBody('ep-theme-update-skeleton', 'ep-theme-update-body');
		render();
	} else {
		refreshStatus().then(() => {
			revealBody('ep-theme-update-skeleton', 'ep-theme-update-body');
		});
	}

	loadReleases();
}
