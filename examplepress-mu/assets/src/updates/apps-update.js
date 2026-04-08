/**
 * Updates → Apps tab renderer.
 *
 * Backed by Infrastructure\AppUpdateProvider via the /updates/apps/*
 * REST namespace (UpdatesController). Read-only summary — the actual
 * install step happens on the native WordPress Plugins screen because
 * AppUpdateProvider publishes update records into the native
 * update_plugins transient.
 */
import {
	toast, revealBody, setBadge, api, esc, formatRelative,
} from './ui.js';

export function renderAppsUpdate(data) {
	const panel = document.getElementById('ep-apps-update-body');
	if (!panel) return;

	let apps = data.appsUpdate || [];
	let busy = false;

	const tableEl     = document.getElementById('ep-apps-update-table');
	const emptyEl     = document.getElementById('ep-apps-update-empty');
	const refreshBtn  = document.getElementById('ep-apps-update-refresh-btn');
	const pluginsLink = document.getElementById('ep-apps-update-plugins-link');

	if (pluginsLink && data.pluginsAdminUrl) {
		pluginsLink.href = data.pluginsAdminUrl;
	}

	// ── Render ───────────────────────────────────────────────────────

	function render() {
		if (!apps || !apps.length) {
			tableEl.innerHTML = '';
			emptyEl.hidden = false;
			return;
		}
		emptyEl.hidden = true;

		tableEl.innerHTML = `
			<div class="ep-updates-apps-row ep-updates-apps-head">
				<div>App</div>
				<div>Installed</div>
				<div>Latest</div>
				<div>Status</div>
				<div></div>
			</div>
			${apps.map((a) => {
				const state = a.update_available ? 'update' : 'current';
				const label = a.update_available
					? `Update to ${a.new_version}`
					: 'Up to date';
				const repo = a.owner_repo
					? `<a href="https://github.com/${esc(a.owner_repo)}" target="_blank" rel="noopener" class="ep-updates-apps-repo">${esc(a.owner_repo)}</a>`
					: '';
				const releaseLink = a.release_url && a.update_available
					? ` <a href="${esc(a.release_url)}" target="_blank" rel="noopener">View release</a>`
					: '';
				return `
					<div class="ep-updates-apps-row">
						<div>
							<div class="ep-updates-apps-name">${esc(a.name)}</div>
							${repo}
						</div>
						<div>${esc(a.current_version || '—')}</div>
						<div>${esc(a.new_version || '—')}</div>
						<div>
							<span class="ep-updates-badge ep-updates-badge--${state}">${esc(label)}</span>
							${releaseLink}
						</div>
						<div></div>
					</div>
				`;
			}).join('')}
		`;
	}

	// ── Loading ──────────────────────────────────────────────────────

	async function refresh() {
		try {
			const res = await api('GET', data.appsUpdateStatusUrl);
			apps = res.apps || [];
			render();
		} catch (err) {
			toast('error', `Could not load app updates: ${err.message}`);
		}
	}

	async function handleRefresh() {
		if (busy) return;
		busy = true;
		refreshBtn.disabled = true;
		refreshBtn.textContent = 'Refreshing…';
		try {
			const res = await api('POST', data.appsUpdateCheckUrl);
			apps = res.apps || [];
			render();
			toast('success', 'Companion app update cache flushed.');
		} catch (err) {
			toast('error', err.message);
		} finally {
			busy = false;
			refreshBtn.disabled = false;
			refreshBtn.textContent = 'Refresh';
		}
	}

	// ── Init ─────────────────────────────────────────────────────────

	refreshBtn.addEventListener('click', handleRefresh);

	revealBody('ep-apps-update-skeleton', 'ep-apps-update-body');
	render();
}
