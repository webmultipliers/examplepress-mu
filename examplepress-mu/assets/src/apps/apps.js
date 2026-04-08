/**
 * Build — Apps tab: table, health panels, row actions, stats bar.
 */
import { esc } from '../lib/dom.js';
import { log } from '../lib/logger.js';
import { $apps, updateApp, removeApp, deactivateApp } from '../stores/apps.js';
import { handleCodespaceOpen } from './codespace-modal.js';

let appsTableEl = null;
let appsStatsEl = null;
let appsNoticeTimer;

export function initAppsTable() {
	appsTableEl = document.getElementById('ep-apps-table');
	appsStatsEl = document.getElementById('ep-apps-stats');
}

export function showAppsNotice(html) {
	let el = document.getElementById('ep-apps-notice');
	if (!el) {
		const notice = document.createElement('div');
		notice.id = 'ep-apps-notice';
		notice.className = 'ep-apps-notice';
		const tableSection = appsTableEl?.closest('.ep-section');
		if (tableSection) tableSection.parentNode.insertBefore(notice, tableSection);
	}
	const noticeEl = document.getElementById('ep-apps-notice');
	if (!noticeEl) return;
	noticeEl.innerHTML = html;
	noticeEl.style.display = 'flex';
	clearTimeout(appsNoticeTimer);
	appsNoticeTimer = setTimeout(() => { noticeEl.style.display = 'none'; }, 6000);
}

function renderAppsStats() {
	if (!appsStatsEl) return;
	const apps = $apps.get();
	const connected = apps.filter(a => a.status === 'connected').length;
	const disconnected = apps.filter(a => a.status === 'disconnected').length;
	appsStatsEl.innerHTML = `
		<span class="ep-apps-stat"><span class="ep-apps-dot ep-apps-dot-connected"></span><strong>${connected}</strong> Connected</span>
		<span class="ep-apps-stat"><span class="ep-apps-dot ep-apps-dot-disconnected"></span><strong>${disconnected}</strong> Disconnected</span>
		<span class="ep-apps-stat ep-apps-stat-right">GitHub + Codespaces</span>
	`;
}

export function renderAppsTable() {
	if (!appsTableEl) return;
	const apps = $apps.get();
	const data = window.ExamplePressData;
	renderAppsStats();

	if (!apps.length) {
		appsTableEl.innerHTML = '<p class="ep-section-desc">No apps discovered. Click <strong>New App</strong> to scaffold one, or install a plugin with an <code>examplepress.json</code>.</p>';
		return;
	}

	let html = '<table class="ep-apps-list-table">';
	html += '<thead><tr>';
	html += '<th class="ep-apps-col-plugin">Plugin</th>';
	html += '<th class="ep-apps-col-status">Status</th>';
	html += '<th class="ep-apps-col-repo">Repository</th>';
	html += '<th class="ep-apps-col-version">Version</th>';
	html += '<th class="ep-apps-col-desc">Description</th>';
	html += '</tr></thead><tbody>';

	apps.forEach(app => {
		const exists = app.exists || {};
		const isOrphan = !!app.orphan;
		const isConnected = app.status === 'connected';
		const isActive = app.active || (app.local && app.local.active);

		// Status badge.
		let statusBadge;
		if (isOrphan) {
			statusBadge = `<span class="ep-apps-status-badge ep-apps-status-orphan ep-apps-health-trigger" data-slug="${esc(app.slug)}" role="button" tabindex="0" title="Plugin deleted locally — exists on GitHub">&#9888; Orphan</span>`;
		} else if (isConnected) {
			statusBadge = `<span class="ep-apps-status-badge ep-apps-status-connected ep-apps-health-trigger" data-slug="${esc(app.slug)}" role="button" tabindex="0" title="Click to check health">&#10003; Connected</span>`;
		} else {
			statusBadge = `<span class="ep-apps-status-badge ep-apps-status-disconnected ep-apps-health-trigger" data-slug="${esc(app.slug)}" role="button" tabindex="0" title="Click to check health">&#11046; Disconnected</span>`;
		}

		// Repo cell.
		const ghRepo = (app.github && app.github.owner_repo) || (app.troy && app.troy.repo) || '';
		let repoCell = '<span class="ep-apps-empty">&mdash;</span>';
		if (ghRepo) {
			repoCell = `<a href="https://github.com/${esc(ghRepo)}" target="_blank" rel="noopener" class="ep-apps-repo-link">${esc(ghRepo)}</a>`;
			const troyServer = app.troy && app.troy.server_url;
			if (troyServer) {
				const troyCloudHost = (data.troyCloudUrl || '').replace(/^https?:\/\//, '').replace(/\/+$/, '');
				const label = troyServer === troyCloudHost ? 'TROY CLOUD' : 'TROY SELF-HOSTED';
				repoCell += `<span class="ep-apps-troy-chip">${label}</span>`;
			}
		}

		// Version cell.
		const versionCell = app.version
			? `<strong>v${esc(app.version)}</strong>`
			: '<span class="ep-apps-empty">&mdash;</span>';

		// Editor link (in-browser Monaco editor).
		const editorUrl = data.editorUrl ? data.editorUrl.replace('__SLUG__', encodeURIComponent(app.slug)) : '';
		const editorLink = editorUrl && !isOrphan
			? `<span class="ep-apps-sep">|</span><span class="ep-apps-action"><a href="${esc(editorUrl)}">Edit</a></span>`
			: '';

		// Codespace link.
		const ghRepoId = (app.github && app.github.repo_id) || (app.troy && app.troy.repo_id) || '';
		let codespaceLink = '';
		if (ghRepo) {
			codespaceLink = ghRepoId
				? `<span class="ep-apps-sep">|</span><span class="ep-apps-action"><a href="#" data-action="codespace" data-repo-id="${esc(ghRepoId)}" class="ep-apps-action-edit">Codespace</a></span>`
				: `<span class="ep-apps-sep">|</span><span class="ep-apps-action"><a href="https://github.com/codespaces/new?hide_repo_select=true&repo=${encodeURIComponent(ghRepo)}" target="_blank" rel="noopener" class="ep-apps-action-edit">Codespace</a></span>`;
		}

		// Row actions.
		let actions = '';
		if (isOrphan) {
			actions = `
				${ghRepo ? `<span class="ep-apps-action"><a href="https://github.com/${esc(ghRepo)}" target="_blank" rel="noopener">Repo</a></span><span class="ep-apps-sep">|</span>` : ''}
				${codespaceLink ? codespaceLink.replace(/^<span class="ep-apps-sep">\|<\/span>/, '') + '<span class="ep-apps-sep">|</span>' : ''}
				<span class="ep-apps-action"><a href="#" data-action="destroy" data-slug="${esc(app.slug)}" class="ep-apps-action-destroy" style="color:#9b2c2c">Delete Everywhere</a></span>
			`;
		} else if (isConnected) {
			const activateAction = isActive
				? `<span class="ep-apps-action"><a href="#" data-action="deactivate" data-slug="${esc(app.slug)}">Deactivate</a></span>`
				: `<span class="ep-apps-action"><a href="${esc(data.adminUrl || '')}plugins.php?s=${esc(app.slug)}" target="_blank">Activate</a></span>`;
			const iterateLink = app.supports_ai_iteration
				? `<span class="ep-apps-sep">|</span><span class="ep-apps-action"><a href="#" data-agent-iterate="${esc(app.slug)}" class="ep-apps-action-manage" title="Iterate with AI">✨ Iterate</a></span>`
				: '';
			actions = `
				${activateAction}
				<span class="ep-apps-sep">|</span>
				<span class="ep-apps-action"><a href="${esc(data.adminUrl || '')}plugins.php?s=${esc(app.slug)}" target="_blank">Locate</a></span>
				${editorLink}
				<span class="ep-apps-sep">|</span>
				<span class="ep-apps-action"><a href="https://github.com/${esc(ghRepo)}" target="_blank" rel="noopener" class="ep-apps-action-repo">Repo</a></span>
				${codespaceLink}
				${iterateLink}
				<span class="ep-apps-sep">|</span>
				<span class="ep-apps-action"><a href="#" data-action="manage" data-slug="${esc(app.slug)}" class="ep-apps-action-manage">Manage</a></span>
				<span class="ep-apps-sep">|</span>
				<span class="ep-apps-action"><a href="#" data-action="destroy" data-slug="${esc(app.slug)}" class="ep-apps-action-destroy" style="color:#9b2c2c">Delete Everywhere</a></span>
			`;
		} else {
			const activateAction = isActive
				? `<span class="ep-apps-action"><a href="#" data-action="deactivate" data-slug="${esc(app.slug)}">Deactivate</a></span>`
				: `<span class="ep-apps-action"><a href="${esc(data.adminUrl || '')}plugins.php?s=${esc(app.slug)}" target="_blank">Activate</a></span>`;
			actions = `
				${activateAction}
				<span class="ep-apps-sep">|</span>
				<span class="ep-apps-action"><a href="${esc(data.adminUrl || '')}plugins.php?s=${esc(app.slug)}" target="_blank">Locate</a></span>
				${editorLink}
				<span class="ep-apps-sep">|</span>
				<span class="ep-apps-action"><a href="#" data-action="manage" data-slug="${esc(app.slug)}" class="ep-apps-action-manage">Connect</a></span>
			`;
		}

		const existsParts = [exists.github ? 'GitHub' : '', exists.troy ? 'Troy' : ''].filter(Boolean);
		const existsWhere = isOrphan && existsParts.length
			? ` <span class="ep-apps-exists">(${existsParts.join(' + ')})</span>`
			: '';

		html += `<tr class="${isOrphan ? 'ep-apps-row-orphan' : ''}">
			<td class="ep-apps-col-plugin">
				<span class="ep-apps-plugin-name">${esc(app.name)}</span>
				<span class="ep-apps-plugin-slug">${esc(app.slug)}</span>
				<div class="ep-apps-row-actions">${actions}</div>
			</td>
			<td class="ep-apps-col-status">
				<div class="ep-apps-health-cell">
					${statusBadge}
					<div class="ep-apps-health-panel" id="ep-health-panel-${esc(app.slug)}" style="display:none;"></div>
				</div>
			</td>
			<td class="ep-apps-col-repo">${repoCell}</td>
			<td class="ep-apps-col-version">${versionCell}</td>
			<td class="ep-apps-col-desc">${esc(app.description)}${existsWhere}</td>
		</tr>`;
	});

	html += '</tbody></table>';
	appsTableEl.innerHTML = html;

	// Bind row actions.
	appsTableEl.querySelectorAll('[data-action]').forEach(link => {
		link.addEventListener('click', e => {
			e.preventDefault();
			const action = link.dataset.action;
			if (action === 'manage') handleConnect(link.dataset.slug);
			if (action === 'codespace') handleCodespaceOpen(link.dataset.repoId);
			if (action === 'deactivate') handleDeactivate(link.dataset.slug);
			if (action === 'destroy') handleDestroy(link.dataset.slug, link);
		});
	});

	// Bind health badge triggers.
	appsTableEl.querySelectorAll('.ep-apps-health-trigger').forEach(badge => {
		badge.addEventListener('click', () => fetchAppHealth(badge.dataset.slug));
		badge.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fetchAppHealth(badge.dataset.slug); } });
	});
}

// Health panel.
function renderHealthDot(ok) {
	if (ok === true) return '<span class="ep-health-dot ep-health-dot-ok"></span>';
	if (ok === false) return '<span class="ep-health-dot ep-health-dot-fail"></span>';
	return '<span class="ep-health-dot ep-health-dot-none"></span>';
}

function renderHealthPanel(panel, data) {
	const troy = data.troy || {};
	const gh = data.github || {};
	const troyGh = troy.github || {};

	let html = '<div class="ep-health-panel-inner">';
	html += `<div class="ep-health-panel-title">${esc(data.name)} <a href="#" class="ep-health-panel-close">&times;</a></div>`;

	html += '<div class="ep-health-section">';
	html += '<div class="ep-health-section-title">Local</div>';
	html += `<div class="ep-health-row">${renderHealthDot(data.local?.active)} Plugin ${data.local?.active ? 'active' : 'inactive'} <span class="ep-health-meta">v${esc(data.local?.version || '?')}</span></div>`;
	html += '</div>';

	html += '<div class="ep-health-section">';
	html += '<div class="ep-health-section-title">GitHub</div>';
	if (gh.reachable) {
		html += `<div class="ep-health-row">${renderHealthDot(true)} Reachable</div>`;
		html += `<div class="ep-health-row"><a href="${esc(gh.html_url)}" target="_blank" rel="noopener" class="ep-health-link">${esc(gh.owner_repo)}</a>${gh.private ? ' <span class="ep-health-meta">private</span>' : ''}</div>`;
		if (gh.latest_release) {
			html += `<div class="ep-health-row">Latest release: <strong>${esc(gh.latest_release)}</strong></div>`;
		} else {
			html += `<div class="ep-health-row ep-health-row-warn">No releases found</div>`;
		}
	} else {
		html += `<div class="ep-health-row">${renderHealthDot(false)} ${esc(gh.error || 'Unreachable')}</div>`;
		if (gh.owner_repo) html += `<div class="ep-health-row"><span class="ep-health-meta">${esc(gh.owner_repo)}</span></div>`;
	}
	html += '</div>';

	// Troy section — only render when the app has Troy configured.
	if (data.troy) {
		html += '<div class="ep-health-section">';
		html += '<div class="ep-health-section-title">Troy</div>';
		if (troy.reachable) {
			html += `<div class="ep-health-row">${renderHealthDot(true)} ${esc(troy.status === 'publish' ? 'Published' : troy.status || 'Registered')}</div>`;
			if (troy.latest_version) {
				html += `<div class="ep-health-row">Version: <strong>${esc(troy.latest_version)}</strong></div>`;
			}
			if (troy.edit_link) {
				html += `<div class="ep-health-row"><a href="${esc(troy.edit_link)}" target="_blank" rel="noopener" class="ep-health-link">Edit on Troy &rarr;</a></div>`;
			}
			if (troyGh.owner_repo) {
				const troyGhOk = troyGh.reachable;
				html += `<div class="ep-health-row">${renderHealthDot(troyGhOk)} Troy &harr; GitHub ${troyGhOk ? 'OK' : esc(troyGh.error || 'unreachable')}</div>`;
				if (troyGh.tag_count != null) {
					html += `<div class="ep-health-row"><span class="ep-health-meta">${troyGh.tag_count} tag(s)${troyGh.auto_process ? ', auto-process on' : ''}</span></div>`;
				}
			}
		} else {
			html += `<div class="ep-health-row">${renderHealthDot(troy.error ? false : null)} ${esc(troy.error || 'Not configured')}</div>`;
		}
		html += '</div>';
	}

	html += '</div>';
	panel.innerHTML = html;
	panel.style.display = '';

	panel.querySelector('.ep-health-panel-close')?.addEventListener('click', e => { e.preventDefault(); panel.style.display = 'none'; });
}

async function fetchAppHealth(slug) {
	const data = window.ExamplePressData;
	const panel = document.getElementById(`ep-health-panel-${slug}`);
	if (!panel) return;

	if (panel.style.display !== 'none') {
		panel.style.display = 'none';
		return;
	}

	panel.innerHTML = '<div class="ep-health-panel-inner"><div class="ep-health-panel-loading">Checking&hellip;</div></div>';
	panel.style.display = '';

	const badge = appsTableEl?.querySelector(`.ep-apps-health-trigger[data-slug="${slug}"]`);
	const originalBadgeHtml = badge ? badge.innerHTML : '';
	if (badge) badge.innerHTML = '&#8987; Checking&hellip;';

	try {
		const res = await fetch(`${data.appsBaseUrl}/${encodeURIComponent(slug)}/health`, {
			headers: { 'X-WP-Nonce': data.nonce },
		});
		const result = await res.json();

		if (res.ok) {
			renderHealthPanel(panel, result);

			if (badge) {
				const ghOk = result.github?.reachable;
				const troyOk = result.troy?.reachable;
				// GitHub alone is sufficient for healthy. Troy is a bonus.
				if (ghOk && (!result.troy || troyOk)) {
					badge.className = 'ep-apps-status-badge ep-apps-status-healthy ep-apps-health-trigger';
					badge.innerHTML = '&#10003; Healthy';
				} else if (ghOk) {
					// GitHub OK but Troy configured and failing.
					badge.className = 'ep-apps-status-badge ep-apps-status-degraded ep-apps-health-trigger';
					badge.innerHTML = '&#9888; Degraded';
				} else if (result.local?.active) {
					badge.className = 'ep-apps-status-badge ep-apps-status-disconnected ep-apps-health-trigger';
					badge.innerHTML = '&#11046; Local only';
				}
			}
		} else {
			panel.innerHTML = `<div class="ep-health-panel-inner"><div class="ep-health-panel-error">${esc(result.message || 'Health check failed.')}</div></div>`;
			if (badge) badge.innerHTML = originalBadgeHtml;
		}
	} catch (err) {
		panel.innerHTML = `<div class="ep-health-panel-inner"><div class="ep-health-panel-error">Network error: ${esc(err.message)}</div></div>`;
		if (badge) badge.innerHTML = originalBadgeHtml;
	}
}

export async function handleConnect(slug) {
	const data = window.ExamplePressData;
	const link = appsTableEl?.querySelector(`[data-action="manage"][data-slug="${slug}"]`);
	if (link) { link.textContent = 'Connecting...'; link.style.pointerEvents = 'none'; }

	try {
		const res = await fetch(`${data.appsBaseUrl}/${slug}/connect`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
		});
		const result = await res.json();

		if (res.ok && result.success && result.app) {
			updateApp(slug, result.app);
			renderAppsTable();

			if (result.warnings && result.warnings.length) {
				showAppsNotice(`<strong>${esc(slug)}</strong>: ${result.warnings.map(w => esc(w)).join(' | ')}`);
			} else {
				showAppsNotice(`<strong>${esc(slug)}</strong>: Connected to GitHub.`);
			}
		} else {
			const msg = result.message || result.data?.message || 'Connection failed.';
			showAppsNotice(`Failed to connect <strong>${esc(slug)}</strong>: ${esc(msg)}`);
		}
	} catch (err) {
		showAppsNotice('Network error: ' + err.message);
	} finally {
		if (link) { link.textContent = 'Manage'; link.style.pointerEvents = ''; }
	}
}

async function handleDeactivate(slug) {
	const data = window.ExamplePressData;
	try {
		const res = await fetch(`${data.appsBaseUrl}/${slug}/deactivate`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
		});
		const result = await res.json();
		if (res.ok && result.success) {
			deactivateApp(slug);
			renderAppsTable();
			showAppsNotice(result.message);
		}
	} catch (err) {
		showAppsNotice('Error deactivating app: ' + err.message);
	}
}

async function handleDestroy(slug, link) {
	if (!confirm(`Delete "${slug}" everywhere?\n\nThis will remove the local plugin and delete the GitHub repo. This cannot be undone.`)) {
		return;
	}

	const data = window.ExamplePressData;
	const origText = link ? link.textContent : '';
	if (link) { link.textContent = 'Deleting...'; link.style.pointerEvents = 'none'; }

	try {
		const destroyNonce = (data.destroyNonces || {})[slug] || '';
		const res = await fetch(`${data.appsBaseUrl}/${slug}/destroy`, {
			method: 'DELETE',
			headers: {
				'X-WP-Nonce': data.nonce,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({ confirm: destroyNonce }),
		});
		const result = await res.json();
		if (result.success) {
			const parts = [];
			if (result.deleted && result.deleted.length) parts.push('Deleted: ' + result.deleted.join(', '));
			if (result.failed && result.failed.length) parts.push('Failed: ' + result.failed.join(', '));
			showAppsNotice(`<strong>${esc(slug)}</strong>: ${parts.join('. ') || 'Done.'}`);

			removeApp(slug);
			renderAppsTable();
		} else {
			const msg = result.message || result.data?.message || 'Delete failed.';
			showAppsNotice(`Failed: ${esc(msg)}`);
			if (link) { link.textContent = origText; link.style.pointerEvents = ''; }
		}
	} catch (err) {
		showAppsNotice('Error: ' + err.message);
		if (link) { link.textContent = origText; link.style.pointerEvents = ''; }
	}
}

export function initAppsOutsideClick() {
	document.addEventListener('click', e => {
		if (!e.target.closest('.ep-apps-health-cell')) {
			document.querySelectorAll('.ep-apps-health-panel').forEach(p => { p.style.display = 'none'; });
		}
	});
}
