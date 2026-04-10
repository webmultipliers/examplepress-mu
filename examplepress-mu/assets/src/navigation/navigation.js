/**
 * Reference — Navigation tab: location and menu tables.
 */
import { esc, badge } from '../lib/dom.js';
import { log } from '../lib/logger.js';

export function renderNavigation(navigation) {
	if (!navigation) return;

	const tblLocs = document.getElementById('tbl-nav-locations');
	if (tblLocs) {
		if (!navigation.locations || !navigation.locations.length) {
			tblLocs.innerHTML = '<p class="ep-notif-empty">No navigation locations registered. Register locations in your companion plugin via register_nav_menus().</p>';
		} else {
			let html = '<div class="ep-table__row ep-table__row--head ep-table--cols-nav"><div class="ep-table__th">Location</div><div class="ep-table__th">Slug</div><div class="ep-table__th">Status</div></div>';
			navigation.locations.forEach(loc => {
				const assigned = loc.assigned
					? `<span class="ep-badge ep-badge--success"><span class="ep-dot"></span>Assigned</span>`
					: `<span class="ep-badge ep-badge--pending"><span class="ep-dot"></span>Empty</span>`;
				html += `<div class="ep-table__row ep-table--cols-nav">
					<div class="ep-table__label"><span class="ep-table__name">${esc(loc.name)}</span></div>
					<div><span class="ep-table__id">${esc(loc.slug)}</span></div>
					<div>${assigned}</div>
				</div>`;
			});
			tblLocs.innerHTML = html;
		}
	}

	const tblMenus = document.getElementById('tbl-nav-menus');
	if (tblMenus) {
		if (!navigation.menus || !navigation.menus.length) {
			tblMenus.innerHTML = '<p class="ep-notif-empty">No menus created yet. Create menus via Appearance &rarr; Menus.</p>';
		} else {
			let html = '<div class="ep-table__row ep-table__row--head ep-table--cols-nav"><div class="ep-table__th">Menu</div><div class="ep-table__th">Items</div><div class="ep-table__th">Locations</div></div>';
			navigation.menus.forEach(menu => {
				const locTags = menu.locations.length
					? menu.locations.map(l => `<span class="ep-src">${esc(l)}</span>`).join(' ')
					: '<span class="ep-table__id">Unassigned</span>';
				html += `<div class="ep-table__row ep-table--cols-nav">
					<div class="ep-table__label"><span class="ep-table__name">${esc(menu.name)}</span><span class="ep-table__id">${esc(menu.slug)}</span></div>
					<div><span class="ep-badge ep-badge--info"><span class="ep-dot"></span>${menu.count}</span></div>
					<div>${locTags}</div>
				</div>`;
			});
			tblMenus.innerHTML = html;
		}
	}

	log.info(`[ExamplePress] Navigation: ${navigation.menus.length} menus, ${navigation.locations.length} locations`);
}
