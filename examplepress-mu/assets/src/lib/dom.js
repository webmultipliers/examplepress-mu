/**
 * Shared DOM render helpers.
 */

export function esc(str) {
	if (str == null) return '';
	const d = document.createElement('div');
	d.textContent = String(str);
	return d.innerHTML;
}

export function badge(on, label) {
	const cls = on ? 'ep-badge--success' : 'ep-badge--pending';
	return `<span class="ep-badge ${cls}"><span class="ep-dot"></span>${label || (on ? 'Enabled' : 'Disabled')}</span>`;
}

export function srcTag(src, detail) {
	const cls = src === 'json' ? 'src-json' : src === 'php' ? 'src-php' : '';
	const label = src === 'php' ? 'filter' : src;
	const title = detail ? ` title="${esc(detail)}"` : '';
	return `<span class="ep-src ${cls}"${title}>${label}</span>`;
}

export function highlightJson(obj) {
	return JSON.stringify(obj, null, 2)
		.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
		.replace(/"([^"]+)":/g, '<span class="j-key">"$1"</span>:')
		.replace(/: "(.*?)"/g, ': <span class="j-str">"$1"</span>')
		.replace(/: (true|false)/g, ': <span class="j-bool">$1</span>')
		.replace(/: (\d+\.?\d*)/g, ': <span class="j-num">$1</span>')
		.replace(/: (null)/g, ': <span class="j-null">$1</span>');
}

export function featureTable(containerId, items, featureDetails) {
	const el = document.getElementById(containerId);
	if (!el || !items || !items.length) return;
	let html = '<div class="ep-table"><div class="ep-table__row ep-table__row--head ep-table--cols-3"><div class="ep-table__th">Feature</div><div class="ep-table__th">Status</div><div class="ep-table__th">Source</div></div>';
	items.forEach(f => {
		const hasDetail = featureDetails && featureDetails[f.id];
		const rowCls = hasDetail ? 'ep-table__row ep-table--cols-3 ep-table__row--clickable' : 'ep-table__row ep-table--cols-3';
		const dataAttr = hasDetail ? ` data-feature-id="${esc(f.id)}"` : '';
		html += `<div class="${rowCls}"${dataAttr}>
			<div class="ep-table__label"><span class="ep-table__name">${esc(f.name)}</span><span class="ep-table__id">${esc(f.id)}</span>${f.opts ? `<span class="ep-table__meta"><em>${esc(f.opts)}</em></span>` : ''}</div>
			<div>${badge(f.on)}</div>
			<div>${srcTag(f.src, f.srcDetail)}</div>
		</div>`;
	});
	html += '</div>';
	el.innerHTML = html;
}

export function healthTable(containerId, items) {
	const el = document.getElementById(containerId);
	if (!el || !items || !items.length) return;
	let html = '<div class="ep-table"><div class="ep-table__row ep-table__row--head ep-table--cols-3"><div class="ep-table__th">Check</div><div class="ep-table__th">Status</div><div class="ep-table__th">Requirement</div></div>';
	items.forEach(h => {
		const cls = h.status === 'pass' ? 'ep-badge--success' : h.status === 'warn' ? 'ep-badge--warning' : h.status === 'fail' ? 'ep-badge--danger' : 'ep-badge--info';
		const lbl = h.status === 'pass' ? 'Pass' : h.status === 'warn' ? 'Warning' : h.status === 'fail' ? 'Fail' : 'Info';
		const noteColor = h.status === 'fail' ? 'var(--red)' : 'var(--amber)';
		html += `<div class="ep-table__row ep-table--cols-3">
			<div class="ep-table__label"><span class="ep-table__name">${esc(h.name)}</span><span class="ep-table__id">${esc(h.detail)}</span>${h.note ? `<span class="ep-table__desc">${esc(h.note)}</span>` : ''}</div>
			<div><span class="ep-badge ${cls}"><span class="ep-dot"></span>${lbl}</span></div>
			<div><span class="ep-table__id">${esc(h.req)}</span></div>
		</div>`;
	});
	html += '</div>';
	el.innerHTML = html;
}
