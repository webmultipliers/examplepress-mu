/**
 * Theme — Dependencies tab: tables, subtabs, detail modals.
 */
import { esc, badge } from '../lib/dom.js';
import { openModal } from '../lib/modal.js';
import { log } from '../lib/logger.js';

function renderDepTable(containerId, deps) {
	const el = document.getElementById(containerId);
	if (!el) return;
	if (!deps || !deps.length) {
		el.innerHTML = '<p class="ep-notif-empty">No dependencies in this category.</p>';
		return;
	}
	let html = '<div class="ep-table__row ep-table__row--head ep-table--cols-4"><div class="ep-table__th">Dependency</div><div class="ep-table__th">Tier</div><div class="ep-table__th">Status</div><div class="ep-table__th">Source</div></div>';
	deps.forEach(p => {
		const statusMap = {
			active:    { cls: 'ep-badge--success',   lbl: 'Active' },
			installed: { cls: 'ep-badge--warning', lbl: 'Installed' },
			fallback:  { cls: 'ep-badge--info', lbl: 'Free Alt.' },
			missing:   { cls: 'ep-badge--danger',  lbl: 'Missing' },
		};
		const st = statusMap[p.status] || statusMap.missing;
		const tierMap = { required: 'tier-req', recommended: 'tier-rec', optional: 'tier-opt' };
		const tierCls = tierMap[p.tier] || 'tier-opt';

		let badges = '';
		if (p.pricing === 'paid') badges += '<span class="ep-meta-badge meta-paid">Paid</span>';
		if (p.cloud) badges += '<span class="ep-meta-badge meta-cloud">Cloud</span>';
		if (p.source === 'private') badges += '<span class="ep-meta-badge meta-private">Private</span>';
		if (p.checkType && p.checkType !== 'plugin') badges += `<span class="ep-meta-badge meta-check">${esc(p.checkType)}</span>`;

		let fallbackNote = '';
		if (p.fallback) {
			const fbSt = p.fallback.status === 'active' ? 'active' : p.fallback.status === 'installed' ? 'installed' : 'not installed';
			fallbackNote = `<span class="ep-table__desc">Free alt: ${esc(p.fallback.slug)} (${fbSt})</span>`;
		}

		let sourceLink = '';
		if (p.url) {
			let domain = '';
			try { domain = new URL(p.url).hostname; } catch (e) { domain = p.url; }
			sourceLink = `<a href="${esc(p.url)}" class="ep-link" target="_blank" rel="noopener">${esc(domain)} &rarr;</a>`;
		}

		html += `<div class="ep-table__row ep-table--cols-4 ep-table__row--clickable" data-dep-slug="${esc(p.slug)}">
			<div class="ep-table__label"><span class="ep-table__name">${esc(p.name)}${badges}</span><span class="ep-table__id">${esc(p.slug)}</span>${fallbackNote}</div>
			<div><span class="ep-tier ${tierCls}">${esc(p.tier)}</span></div>
			<div><span class="ep-badge ${st.cls}"><span class="ep-dot"></span>${st.lbl}</span></div>
			<div>${sourceLink}</div>
		</div>`;
	});
	el.innerHTML = html;

	// Dependency detail modals.
	el.querySelectorAll('.ep-table__row--clickable[data-dep-slug]').forEach(row => {
		row.addEventListener('click', (e) => {
			if (e.target.closest('a')) return;
			const slug = row.dataset.depSlug;
			const p = deps.find(d => d.slug === slug);
			if (!p) return;
			const statusMap = {
				active:    { cls: 'ep-badge--success',   lbl: 'Active' },
				installed: { cls: 'ep-badge--warning', lbl: 'Installed' },
				fallback:  { cls: 'ep-badge--info', lbl: 'Free Alt.' },
				missing:   { cls: 'ep-badge--danger',  lbl: 'Missing' },
			};
			const st = statusMap[p.status] || statusMap.missing;
			const tierMap = { required: 'tier-req', recommended: 'tier-rec', optional: 'tier-opt' };
			const tierCls = tierMap[p.tier] || 'tier-opt';

			let body = '<div class="ep-modal-status">';
			body += `<span class="ep-badge ${st.cls}"><span class="ep-dot"></span>${st.lbl}</span>`;
			body += `<span class="ep-tier ${tierCls}">${esc(p.tier)}</span>`;
			if (p.pricing === 'paid') body += '<span class="ep-meta-badge meta-paid">Paid</span>';
			if (p.cloud) body += '<span class="ep-meta-badge meta-cloud">Cloud</span>';
			body += '</div>';

			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Slug</div>';
			body += `<pre class="ep-modal-code">${esc(p.slug)}</pre></div>`;

			if (p.checkType) {
				body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Detection Method</div>';
				body += `<p class="ep-modal-text">${esc(p.checkType === 'plugin' ? 'WordPress plugin registry scan' : p.checkType === 'class' ? 'class_exists() check (Composer)' : 'function_exists() check')}</p></div>`;
			}

			if (p.fallback) {
				body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Free Alternative</div>';
				body += `<p class="ep-modal-text">${esc(p.fallback.slug)} &mdash; ${esc(p.fallback.status || 'unknown')}</p></div>`;
			}

			if (p.url) {
				body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Source</div>';
				body += `<p class="ep-modal-text"><a href="${esc(p.url)}" class="ep-link" target="_blank" rel="noopener">${esc(p.url)} &rarr;</a></p></div>`;
			}

			openModal(p.name, p.slug, body);
		});
	});
}

export function renderDependencies(dependencies) {
	if (dependencies && dependencies.length) {
		const requiredDeps = dependencies.filter(d => d.tier === 'required');
		const recommendedDeps = dependencies.filter(d => d.tier === 'recommended' || d.tier === 'optional');
		renderDepTable('deps-required', requiredDeps);
		renderDepTable('deps-recommended', recommendedDeps);
		log.info(`[ExamplePress] Dependencies: ${dependencies.length} loaded (${requiredDeps.length} required, ${recommendedDeps.length} recommended/optional)`);
	} else {
		const reqEl = document.getElementById('deps-required');
		if (reqEl) reqEl.innerHTML = '<p class="ep-notif-empty">No dependencies declared in examplepress.json.</p>';
		log.warn('[ExamplePress] No dependencies declared');
	}

	// Tab switching for Required/Recommended is handled by lib/tabs.js
	// via the `.ep-tabs` nav in dependencies.php — no custom subtab
	// wiring needed here.
}
