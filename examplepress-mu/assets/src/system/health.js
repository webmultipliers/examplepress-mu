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

	healthTable('tbl-health-env', healthChecks.env);
	healthTable('tbl-health-theme', healthChecks.theme);
	healthTable('tbl-health-router', healthChecks.router);
	healthTable('tbl-health-security', healthChecks.security);
	log.info(`[ExamplePress] Health: ${pass} pass, ${warn} warn, ${fail} fail, ${info} info`);

	// Collapsible section toggles.
	document.querySelectorAll('.ep-collapsible-header .ep-collapse-toggle').forEach(btn => {
		const section = btn.closest('.ep-collapsible');
		const body = section ? section.querySelector('.ep-collapsible-body') : null;
		if (!body) return;
		btn.addEventListener('click', () => {
			const expanded = btn.getAttribute('aria-expanded') === 'true';
			btn.setAttribute('aria-expanded', String(!expanded));
			if (expanded) {
				body.style.maxHeight = body.scrollHeight + 'px';
				requestAnimationFrame(() => { body.style.maxHeight = '0'; });
			} else {
				body.style.maxHeight = body.scrollHeight + 'px';
				const onEnd = () => { body.style.maxHeight = ''; body.removeEventListener('transitionend', onEnd); };
				body.addEventListener('transitionend', onEnd);
			}
		});
	});

	// Health search filter.
	const healthSearch = document.getElementById('ep-health-search');
	if (healthSearch) {
		const healthSections = [
			{ key: 'env', items: healthChecks.env || [] },
			{ key: 'theme', items: healthChecks.theme || [] },
			{ key: 'router', items: healthChecks.router || [] },
			{ key: 'security', items: healthChecks.security || [] },
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
					if (q && filtered.length) {
						const toggle = section.querySelector('.ep-collapse-toggle');
						const body = section.querySelector('.ep-collapsible-body');
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
