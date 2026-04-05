/**
 * Build — Routes tab: resolution flow, route table, sitemap tree.
 */
import { esc } from '../lib/dom.js';
import { log } from '../lib/logger.js';

export function renderRoutes() {
	const routeTopology = window.ExamplePressData.routeTopology || { origins: [], conflicts: {}, mode: 'legacy', resolved: {} };
	const { origins, conflicts } = routeTopology;
	const statsEl = document.getElementById('ep-routes-stats');
	const filtersEl = document.getElementById('ep-routes-filters');
	const toggleEl = document.getElementById('ep-routes-view-toggle');
	const contentEl = document.getElementById('ep-routes-content');

	if (!statsEl || !contentEl) return 0;

	const PALETTE = ['#2271b1','#1D9E75','#D85A30','#D4537E','#7F77DD','#639922','#BA7517','#993556','#5F5E5A','#E24B4A'];
	origins.forEach((o, i) => { o.color = PALETTE[i % PALETTE.length]; });

	const sorted = [...origins].sort((a, b) => a.priority - b.priority);

	const allRoutes = [];
	sorted.forEach(app => {
		Object.entries(app.routes).forEach(([slug, meta]) => {
			const isConflict = !!conflicts[slug];
			const isWinner = isConflict && conflicts[slug][0].namespace === app.namespace;
			allRoutes.push({ slug, meta, app, isConflict, isWinner });
		});
	});

	const totalSlugs = allRoutes.length;
	const totalUrls = allRoutes.reduce((n, r) => n + (r.meta.urls || []).length, 0);
	const conflictN = Object.keys(conflicts).length;

	let selectedApp = null;
	let view = 'flow';

	function render() {
		const visible = selectedApp ? sorted.filter(a => a.id === selectedApp) : sorted;
		const visibleRoutes = selectedApp ? allRoutes.filter(r => r.app.id === selectedApp) : allRoutes;

		statsEl.innerHTML = [
			stat('Companion Apps', sorted.length),
			stat('Route Slugs', totalSlugs),
			stat('Mapped URLs', totalUrls || '\u2014'),
			stat('Conflicts', conflictN, conflictN > 0),
		].join('');

		filtersEl.innerHTML = pill('All Apps', null, !selectedApp) +
			sorted.map(a => pill(a.name, a.id, selectedApp === a.id, a.color, a.priority)).join('');

		filtersEl.querySelectorAll('[data-app-filter]').forEach(el => {
			el.addEventListener('click', () => {
				const id = el.dataset.appFilter;
				selectedApp = selectedApp === id ? null : (id || null);
				render();
			});
		});

		toggleEl.innerHTML = ['flow', 'table', 'sitemap'].map(v =>
			`<button data-route-view="${v}" style="padding:5px 12px;border:none;border-radius:var(--radius);font-size:12px;font-weight:500;cursor:pointer;background:${view === v ? 'var(--surface)' : 'transparent'};color:${view === v ? 'var(--text)' : 'var(--text-faint)'};box-shadow:${view === v ? 'var(--shadow-sm)' : 'none'};font-family:var(--sans);transition:all .15s">${{flow:'Resolution Flow', table:'Route Table', sitemap:'Sitemap Tree'}[v]}</button>`
		).join('');

		toggleEl.querySelectorAll('[data-route-view]').forEach(el => {
			el.addEventListener('click', () => { view = el.dataset.routeView; render(); });
		});

		if (view === 'flow') contentEl.innerHTML = renderFlow(visible, sorted, conflicts, selectedApp);
		if (view === 'table') contentEl.innerHTML = renderTable(visibleRoutes);
		if (view === 'sitemap') contentEl.innerHTML = renderSitemap(visible, sorted, selectedApp);
	}

	function stat(label, value, warn) {
		return `<div class="ep-overview-card" style="cursor:default">
			<div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-faint);margin-bottom:2px">${esc(label)}</div>
			<div style="font-size:20px;font-weight:500;color:${warn ? 'var(--red)' : 'var(--text)'}">${value}</div>
		</div>`;
	}

	function pill(label, id, active, color, priority) {
		const c = color || 'var(--text-faint)';
		const bg = active ? c : 'transparent';
		const fg = active ? '#fff' : c;
		const dot = color ? `<span style="width:6px;height:6px;border-radius:50%;background:${active ? '#fff' : c};display:inline-block"></span>` : '';
		const pri = priority !== undefined ? `<span style="opacity:.6;font-size:10px">p${priority}</span>` : '';
		return `<span data-app-filter="${id || ''}" style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500;letter-spacing:.02em;border:1.5px solid ${c};background:${bg};color:${fg};cursor:pointer;transition:all .2s;user-select:none;white-space:nowrap;font-family:var(--sans)">${dot}${esc(label)}${pri}</span>`;
	}

	render();
	log.info(`[ExamplePress] Routes: ${sorted.length} origins, ${totalSlugs} slugs, ${conflictN} conflicts`);

	return totalSlugs;
}

function renderFlow(apps, sorted, conflicts, selectedApp) {
	let html = `<div class="ep-table" style="padding:1.25rem">
		<div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-faint);font-weight:600;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:16px">Priority Evaluation Cascade</div>`;

	if (!apps.length) {
		html += `<p style="font-size:12px;color:var(--text-faint);font-style:italic;padding:1rem 0">No route origins registered. Install a companion app or use <code>examplepress_register_route_origin()</code>.</p>`;
		html += `</div>`;
		return html;
	}

	apps.forEach((app, i) => {
		html += `<div style="position:relative;margin-bottom:16px">`;
		if (i > 0) html += `<div style="position:absolute;left:11px;top:-14px;width:1.5px;height:14px;background:var(--border)"></div>`;

		html += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
			<div style="width:22px;height:22px;border-radius:50%;background:${app.color};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0">${app.priority}</div>
			<span style="font-weight:500;font-size:13px;color:var(--text)">${esc(app.name)}</span>
			<code style="font-family:var(--mono);font-size:11px;color:var(--text-faint)">${esc(app.namespace)}</code>
			${!app.active ? '<span class="ep-badge badge-off"><span class="ep-dot"></span>Inactive</span>' : ''}
		</div>`;

		html += `<div style="margin-left:34px;display:flex;flex-wrap:wrap;gap:6px">`;
		Object.entries(app.routes).forEach(([slug, meta]) => {
			const isConflict = !!conflicts[slug];
			const isWinner = isConflict && conflicts[slug][0].namespace === app.namespace;
			const border = isConflict
				? `1.5px dashed ${isWinner ? 'var(--green)' : 'var(--red)'}`
				: `1px solid ${app.color}40`;
			const tag = isConflict
				? `<span style="font-size:8px;margin-left:4px;text-transform:uppercase;letter-spacing:.04em;color:${isWinner ? 'var(--green)' : 'var(--red)'}">${isWinner ? 'wins' : 'shadowed'}</span>`
				: '';

			html += `<div title="${esc(meta.desc || slug)}\n${esc(meta.condition || '(closure)')}\n\u2192 ${esc(app.namespace)}/template-${esc(slug)}" style="padding:4px 10px;border-radius:var(--radius);font-size:11px;font-family:var(--mono);background:${app.color}10;color:${app.color};border:${border};cursor:help">${esc(slug)}${tag}</div>`;
		});
		html += `</div></div>`;
	});

	if (Object.keys(conflicts).length && !selectedApp) {
		html += `<div style="margin-top:20px;padding:12px 16px;background:var(--red-bg);border:1px solid var(--red-border);border-radius:var(--radius-lg)">
			<div style="font-size:12px;font-weight:600;color:var(--red);margin-bottom:8px">Route Conflicts Detected</div>`;

		Object.entries(conflicts).forEach(([slug, entries]) => {
			html += `<div style="font-size:11px;color:var(--text-faint);margin-bottom:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
				<code style="font-family:var(--mono);font-weight:600;color:var(--text);background:var(--surface);padding:1px 6px;border-radius:var(--radius-sm);border:1px solid var(--border)">${esc(slug)}</code>
				<span>claimed by</span>`;
			entries.forEach((e, idx) => {
				html += `<span style="font-weight:500">${esc(e.namespace)} <span style="opacity:.5;font-weight:400">(p${e.priority})</span></span>`;
				if (idx < entries.length - 1) html += `<span style="color:var(--text-faint)">,</span>`;
			});
			html += `<span style="font-size:10px;color:var(--green);text-transform:uppercase;letter-spacing:.04em">&rarr; ${esc(entries[0].namespace)} wins</span></div>`;
		});
		html += `</div>`;
	}

	html += `</div>`;
	return html;
}

function renderTable(routes) {
	let html = `<div class="ep-table">
		<div class="ep-row ep-row-head" style="grid-template-columns:120px 1fr 220px">
			<div class="ep-th">Slug</div>
			<div class="ep-th">Matched URLs</div>
			<div class="ep-th" style="text-align:right">Dispatch Target</div>
		</div>`;

	if (!routes.length) {
		html += `<div class="ep-row" style="grid-template-columns:1fr"><div style="font-size:12px;color:var(--text-faint);font-style:italic;padding:.5rem 0">No routes registered.</div></div>`;
		html += `</div>`;
		return html;
	}

	routes.forEach(r => {
		const opacity = (r.isConflict && !r.isWinner) ? 'opacity:.45;' : '';
		const bg = (r.isConflict && r.isWinner) ? `background:${r.app.color}08;` : '';
		const blockName = `${r.app.namespace}/template-${r.slug}`;
		const status = r.isConflict
			? `<span style="font-size:10px;color:${r.isWinner ? 'var(--green)' : 'var(--red)'}">${r.isWinner ? '\u2713' : '\u2717'}</span>`
			: '';
		const urls = (r.meta.urls || []).map(u =>
			`<code style="font-family:var(--mono);font-size:11px;padding:1px 6px;border-radius:var(--radius-sm);background:var(--surface-alt);color:var(--text-faint)">${esc(u)}</code>`
		).join(' ');

		html += `<div class="ep-row" style="grid-template-columns:120px 1fr 220px;${opacity}${bg}">
			<div style="display:flex;align-items:center;gap:4px">
				<code style="font-family:var(--mono);font-size:12px;font-weight:600;color:${r.app.color}">${esc(r.slug)}</code>
				${status}
			</div>
			<div style="display:flex;flex-wrap:wrap;gap:4px">${urls || '<span style="font-size:11px;color:var(--text-faint);font-style:italic">No URLs declared</span>'}</div>
			<div style="text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
				<code style="font-family:var(--mono);font-size:10px;color:var(--text-faint)">${esc(blockName)}</code>
			</div>
		</div>`;
	});

	html += `</div>`;
	return html;
}

function renderSitemap(apps, sorted, selectedApp) {
	const tree = {};
	apps.forEach(app => {
		Object.entries(app.routes).forEach(([slug, meta]) => {
			(meta.urls || []).forEach(url => {
				const parts = url.replace(/^\/|\/$/g, '').split('/').filter(Boolean);
				let node = tree;
				parts.forEach((p, i) => {
					if (!node[p]) node[p] = { _routes: [], _children: {} };
					if (i === parts.length - 1) node[p]._routes.push({ slug, app, meta });
					node = node[p]._children;
				});
				if (!parts.length) {
					if (!tree['/']) tree['/'] = { _routes: [], _children: {} };
					tree['/']._routes.push({ slug, app, meta });
				}
			});
		});
	});

	function walk(nodes, depth) {
		return Object.entries(nodes).map(([seg, data]) => {
			if (seg === '_routes' || seg === '_children') return '';
			const routes = data._routes || [];
			const children = data._children || {};
			const hasKids = Object.keys(children).filter(k => k !== '_routes' && k !== '_children').length > 0;
			const bg = routes.length ? `${routes[0].app.color}12` : 'transparent';
			const pills = routes.map(r =>
				`<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:500;border:1px solid ${r.app.color};color:${r.app.color};font-family:var(--sans)">${esc(r.app.name)} &rarr; ${esc(r.slug)}</span>`
			).join('');

			return `<div style="margin-left:${depth * 24}px;margin-top:4px">
				<div style="display:flex;align-items:center;gap:6px;padding:4px 8px;border-radius:var(--radius);background:${bg}">
					<span style="font-family:var(--mono);font-size:11px;color:var(--text-faint);width:12px;text-align:center">${hasKids ? '\u25B8' : '\u00B7'}</span>
					<code style="font-family:var(--mono);font-size:12px;font-weight:500;color:var(--text)">/${seg === '/' ? '' : seg}/</code>
					${pills}
				</div>
				${walk(children, depth + 1)}
			</div>`;
		}).join('');
	}

	const label = selectedApp ? (sorted.find(a => a.id === selectedApp)?.name || 'Filtered') : 'All Apps';
	let html = `<div class="ep-table" style="padding:1.25rem">
		<div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-faint);font-weight:600;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:12px">URL Hierarchy \u2014 ${esc(label)}</div>`;

	const treeHtml = walk(tree, 0);
	html += treeHtml || '<p style="font-size:12px;color:var(--text-faint);font-style:italic;padding:1rem 0">No URLs declared by any registered origin. Add <code>routing.routes</code> to your companion app\'s examplepress.json to populate this view.</p>';
	html += `</div>`;
	return html;
}
