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
	const cls = on ? 'badge-on' : 'badge-off';
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
	let html = '<div class="ep-row ep-row-head ep-cols-3"><div class="ep-th">Feature</div><div class="ep-th">Status</div><div class="ep-th">Source</div></div>';
	items.forEach(f => {
		const hasDetail = featureDetails && featureDetails[f.id];
		const rowCls = hasDetail ? 'ep-row ep-cols-3 ep-row-clickable' : 'ep-row ep-cols-3';
		const dataAttr = hasDetail ? ` data-feature-id="${esc(f.id)}"` : '';
		html += `<div class="${rowCls}"${dataAttr}>
			<div class="ep-td-label"><span class="ep-name">${esc(f.name)}</span><span class="ep-id">${esc(f.id)}</span>${f.opts ? `<span class="ep-opt"><em>${esc(f.opts)}</em></span>` : ''}</div>
			<div>${badge(f.on)}</div>
			<div>${srcTag(f.src, f.srcDetail)}</div>
		</div>`;
	});
	el.innerHTML = html;
}

export function healthTable(containerId, items) {
	const el = document.getElementById(containerId);
	if (!el || !items || !items.length) return;
	let html = '<div class="ep-row ep-row-head ep-cols-health"><div class="ep-th">Check</div><div class="ep-th">Status</div><div class="ep-th">Requirement</div></div>';
	items.forEach(h => {
		const cls = h.status === 'pass' ? 'badge-on' : h.status === 'warn' ? 'badge-warn' : h.status === 'fail' ? 'badge-err' : 'badge-info';
		const lbl = h.status === 'pass' ? 'Pass' : h.status === 'warn' ? 'Warning' : h.status === 'fail' ? 'Fail' : 'Info';
		const noteColor = h.status === 'fail' ? 'var(--red)' : 'var(--amber)';
		html += `<div class="ep-row ep-cols-health">
			<div class="ep-td-label"><span class="ep-name">${esc(h.name)}</span><span class="ep-id">${esc(h.detail)}</span>${h.note ? `<span class="ep-desc-small" style="color:${noteColor}">${esc(h.note)}</span>` : ''}</div>
			<div><span class="ep-badge ${cls}"><span class="ep-dot"></span>${lbl}</span></div>
			<div><span class="ep-id">${esc(h.req)}</span></div>
		</div>`;
	});
	el.innerHTML = html;
}
