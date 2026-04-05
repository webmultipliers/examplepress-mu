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
			let html = '<div class="ep-row ep-row-head ep-cols-nav"><div class="ep-th">Location</div><div class="ep-th">Slug</div><div class="ep-th">Status</div></div>';
			navigation.locations.forEach(loc => {
				const assigned = loc.assigned
					? `<span class="ep-badge badge-on"><span class="ep-dot"></span>Assigned</span>`
					: `<span class="ep-badge badge-off"><span class="ep-dot"></span>Empty</span>`;
				html += `<div class="ep-row ep-cols-nav">
					<div class="ep-td-label"><span class="ep-name">${esc(loc.name)}</span></div>
					<div><span class="ep-id">${esc(loc.slug)}</span></div>
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
			let html = '<div class="ep-row ep-row-head ep-cols-nav"><div class="ep-th">Menu</div><div class="ep-th">Items</div><div class="ep-th">Locations</div></div>';
			navigation.menus.forEach(menu => {
				const locTags = menu.locations.length
					? menu.locations.map(l => `<span class="ep-src">${esc(l)}</span>`).join(' ')
					: '<span class="ep-id">Unassigned</span>';
				html += `<div class="ep-row ep-cols-nav">
					<div class="ep-td-label"><span class="ep-name">${esc(menu.name)}</span><span class="ep-id">${esc(menu.slug)}</span></div>
					<div><span class="ep-badge badge-info"><span class="ep-dot"></span>${menu.count}</span></div>
					<div>${locTags}</div>
				</div>`;
			});
			tblMenus.innerHTML = html;
		}
	}

	log.info(`[ExamplePress] Navigation: ${navigation.menus.length} menus, ${navigation.locations.length} locations`);
}
