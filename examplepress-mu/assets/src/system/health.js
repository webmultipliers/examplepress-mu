/**
 * Dashboard — Health tab: summary stats, collapsible sections, search.
 */
import { healthTable } from '../lib/dom.js';
import { log } from '../lib/logger.js';

export function renderHealth(healthChecks) {
	if (!healthChecks) return;

	const all = [
		...(healthChecks.env || []),
		...(healthChecks.theme || []),
		...(healthChecks.router || []),
		...(healthChecks.security || []),
	];
	const pass = all.filter(h => h.status === 'pass').length;
	const warn = all.filter(h => h.status === 'warn').length;
	const fail = all.filter(h => h.status === 'fail').length;
	const info = all.filter(h => h.status === 'info').length;

	const summary = document.getElementById('health-summary');
	if (summary) {
		summary.innerHTML = `
			<div class="ep-health-stat"><div class="ep-health-num val-ok">${pass}</div><div class="ep-health-lbl">Passing</div></div>
			<div class="ep-health-stat"><div class="ep-health-num" style="color:var(--amber)">${warn}</div><div class="ep-health-lbl">Warnings</div></div>
			<div class="ep-health-stat"><div class="ep-health-num" style="color:var(--red)">${fail}</div><div class="ep-health-lbl">Failing</div></div>
			<div class="ep-health-stat"><div class="ep-health-num" style="color:var(--blue)">${info}</div><div class="ep-health-lbl">Info</div></div>`;
	}

	healthTable('tbl-health-env',         healthChecks.env);
	healthTable('tbl-health-theme',       healthChecks.theme);
	healthTable('tbl-health-router',      healthChecks.router);
	healthTable('tbl-health-security',    healthChecks.security);
	healthTable('tbl-health-connections', healthChecks.connections);
	log.info(`[ExamplePress] Health: ${pass} pass, ${warn} warn, ${fail} fail, ${info} info`);

	// Collapsible section toggles — scoped to `ep-section--collapsible`
	// markup emitted by system.php. Toggling `aria-expanded` on the
	// toggle button drives the arrow rotation (via CSS attribute
	// selector in section.css); here we drive the body's max-height
	// animation to match the `transition: max-height` rule in
	// section.css.
	document.querySelectorAll('.ep-section--collapsible .ep-section__toggle').forEach(btn => {
		const section = btn.closest('.ep-section--collapsible');
		const body    = section ? section.querySelector('.ep-section__body') : null;
		if (!body) return;
		btn.addEventListener('click', () => {
			const expanded = btn.getAttribute('aria-expanded') === 'true';
			btn.setAttribute('aria-expanded', String(!expanded));
			if (expanded) {
				// Collapsing: pin to current height, then animate to 0
				// on the next frame so the transition has both endpoints.
				body.style.maxHeight = body.scrollHeight + 'px';
				requestAnimationFrame(() => { body.style.maxHeight = '0'; });
			} else {
				// Expanding: animate from 0 to content height, then clear
				// the inline style so the section can grow with its
				// content naturally.
				body.style.maxHeight = body.scrollHeight + 'px';
				const onEnd = () => {
					body.style.maxHeight = '';
					body.removeEventListener('transitionend', onEnd);
				};
				body.addEventListener('transitionend', onEnd);
			}
		});
	});

	// Health search filter.
	const healthSearch = document.getElementById('ep-health-search');
	if (healthSearch) {
		const healthSections = [
			{ key: 'env',         items: healthChecks.env         || [] },
			{ key: 'theme',       items: healthChecks.theme       || [] },
			{ key: 'router',      items: healthChecks.router      || [] },
			{ key: 'security',    items: healthChecks.security    || [] },
			{ key: 'connections', items: healthChecks.connections || [] },
		];
		healthSearch.addEventListener('input', () => {
			const q = healthSearch.value.toLowerCase().trim();
			healthSections.forEach(({ key, items }) => {
				const filtered = q ? items.filter(h =>
					(h.name || '').toLowerCase().includes(q) ||
					(h.detail || '').toLowerCase().includes(q) ||
					(h.req || '').toLowerCase().includes(q) ||
					(h.note || '').toLowerCase().includes(q)
				) : items;
				healthTable(`tbl-health-${key}`, filtered);
				const section = document.querySelector(`[data-health-section="${key}"]`);
				if (section) {
					section.style.display = filtered.length || !q ? '' : 'none';
					// Auto-expand sections that have matching hits.
					if (q && filtered.length) {
						const toggle = section.querySelector('.ep-section__toggle');
						const body   = section.querySelector('.ep-section__body');
						if (toggle && body && toggle.getAttribute('aria-expanded') === 'false') {
							toggle.setAttribute('aria-expanded', 'true');
							body.style.maxHeight = '';
						}
					}
				}
			});
		});
	}
}
