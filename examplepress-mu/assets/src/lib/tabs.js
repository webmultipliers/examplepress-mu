/**
 * Tab switching, aria state, and count badges.
 */
import { getUrlParams, setUrlParams } from './url.js';
import { log } from './logger.js';

let initialized = false;

export function activateTab(tabId) {
	const tab = document.querySelector(`.ep-tab[data-tab-id="${tabId}"]`);
	if (!tab) return;
	document.querySelectorAll('.ep-tab').forEach(t => t.setAttribute('aria-selected', 'false'));
	document.querySelectorAll('.ep-panel').forEach(p => p.setAttribute('aria-hidden', 'true'));
	tab.setAttribute('aria-selected', 'true');
	const panel = document.getElementById(tab.getAttribute('aria-controls'));
	if (panel) panel.setAttribute('aria-hidden', 'false');
	setUrlParams(tabId);
	log.info(`[ExamplePress] Tab activated: ${tabId}`);
}

export function initTabs(defaultTab = 'overview') {
	if (initialized) return;
	initialized = true;

	document.querySelectorAll('.ep-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			const tabId = tab.dataset.tabId;
			if (tabId) activateTab(tabId);
		});
	});

	const urlParams = getUrlParams();
	activateTab(urlParams.tab || defaultTab);
}

export function hideTab(tabId) {
	const tabBtn = document.querySelector(`.ep-tab[data-tab-id="${tabId}"]`);
	if (tabBtn) tabBtn.style.display = 'none';
	const panelId = tabBtn ? tabBtn.getAttribute('aria-controls') : `p-${tabId}`;
	const panel = document.getElementById(panelId);
	if (panel) panel.style.display = 'none';
}

export function updateTabCount(tabElementId, count, hideWhenZero = false) {
	const tab = document.getElementById(tabElementId);
	if (!tab) return;
	let countEl = tab.querySelector('.ep-tab__count');
	if (!countEl) {
		countEl = document.createElement('span');
		countEl.className = 'ep-tab__count';
		tab.appendChild(countEl);
	}
	countEl.textContent = count;
	if (hideWhenZero) {
		countEl.style.display = count > 0 ? '' : 'none';
	}
}
