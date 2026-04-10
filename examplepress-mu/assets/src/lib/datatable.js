/**
 * Reusable searchable/groupable data table component.
 */
import { esc } from './dom.js';

export function renderDatatable(containerId, config) {
	const el = document.getElementById(containerId);
	if (!el) return;

	const { columns, data, searchKeys, searchPlaceholder, gridClass, onRowClick, groupBy, emptyMessage } = config;

	let searchHtml = '';
	if (searchKeys && searchKeys.length) {
		searchHtml = `<div class="ep-datatable__search"><input type="text" placeholder="${esc(searchPlaceholder || 'Search...')}" aria-label="${esc(searchPlaceholder || 'Search')}" role="searchbox" /></div>`;
	}

	let headerHtml = `<div class="ep-table__row ep-table__row--head ${gridClass}">`;
	columns.forEach(c => { headerHtml += `<div class="ep-table__th">${esc(c.label)}</div>`; });
	headerHtml += '</div>';

	function buildRows(items) {
		if (!items || !items.length) return `<div class="ep-datatable__empty">${emptyMessage || 'No items found.'}</div>`;
		let html = '';
		if (groupBy) {
			const groups = [];
			const seen = new Set();
			items.forEach(item => {
				const g = groupBy(item);
				if (!seen.has(g)) { seen.add(g); groups.push(g); }
			});
			groups.forEach(g => {
				html += `<div class="ep-block-cat">${esc(g)}</div>`;
				items.filter(item => groupBy(item) === g).forEach(item => {
					html += buildRow(item);
				});
			});
		} else {
			items.forEach(item => { html += buildRow(item); });
		}
		return html;
	}

	function buildRow(item) {
		const clickable = onRowClick ? ' ep-table__row--clickable' : '';
		let html = `<div class="ep-table__row ${gridClass}${clickable}">`;
		columns.forEach(c => { html += `<div>${c.render(item)}</div>`; });
		html += '</div>';
		return html;
	}

	el.innerHTML = searchHtml + '<div class="ep-datatable-table">' + headerHtml + '<div class="ep-datatable-rows">' + buildRows(data) + '</div></div>';

	const searchInput = el.querySelector('.ep-datatable__search input');
	const rowsContainer = el.querySelector('.ep-datatable-rows');
	if (searchInput && rowsContainer) {
		searchInput.addEventListener('input', () => {
			const q = searchInput.value.toLowerCase().trim();
			const filtered = q ? data.filter(item => searchKeys.some(key => {
				const val = item[key];
				return val != null && String(val).toLowerCase().includes(q);
			})) : data;
			rowsContainer.innerHTML = buildRows(filtered);
			if (onRowClick) bindRowClicks(rowsContainer);
		});
	}

	function bindRowClicks(container) {
		container.querySelectorAll('.ep-table__row--clickable').forEach((row, idx) => {
			const q = searchInput ? searchInput.value.toLowerCase().trim() : '';
			const visibleData = q ? data.filter(item => searchKeys.some(key => {
				const val = item[key];
				return val != null && String(val).toLowerCase().includes(q);
			})) : data;
			if (groupBy) {
				const groups = [];
				const seen = new Set();
				visibleData.forEach(item => {
					const g = groupBy(item);
					if (!seen.has(g)) { seen.add(g); groups.push(g); }
				});
				let rowCount = 0;
				for (const g of groups) {
					const groupItems = visibleData.filter(item => groupBy(item) === g);
					for (const item of groupItems) {
						if (rowCount === idx) {
							row.addEventListener('click', () => onRowClick(item));
							return;
						}
						rowCount++;
					}
				}
			} else {
				if (visibleData[idx]) {
					row.addEventListener('click', () => onRowClick(visibleData[idx]));
				}
			}
		});
	}
	if (onRowClick) bindRowClicks(el.querySelector('.ep-datatable-rows') || el);
}
