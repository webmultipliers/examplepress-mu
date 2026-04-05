/**
 * Build — Blocks tab: searchable datatable with block detail modals.
 */
import { esc } from '../lib/dom.js';
import { renderDatatable } from '../lib/datatable.js';
import { openModal } from '../lib/modal.js';
import { log } from '../lib/logger.js';

export function renderBlocks(blocks) {
	if (!blocks || !blocks.length) return 0;

	renderDatatable('tbl-blocks', {
		columns: [
			{ key: 'title', label: 'Block', render: b => `<div class="ep-td-label"><span class="ep-name">${esc(b.title)}</span><span class="ep-id">${esc(b.name)}</span></div>` },
			{ key: 'cat', label: 'Category', render: b => `<span class="ep-id">${esc(b.cat)}</span>` },
			{ key: 'source', label: 'Source', render: b => `<span class="ep-src">${esc(b.source)}</span>` },
			{ key: 'type', label: 'Type', render: b => { const cls = b.type === 'template' ? 'badge-on' : b.type === 'system' ? 'badge-info' : 'badge-off'; return `<span class="ep-badge ${cls}"><span class="ep-dot"></span>${esc(b.type)}</span>`; } },
		],
		data: blocks,
		searchKeys: ['title', 'name', 'cat', 'source', 'type'],
		searchPlaceholder: 'Search blocks...',
		gridClass: 'ep-cols-blocks',
		groupBy: b => b.name.split('/')[0],
		onRowClick: b => {
			const typeCls = b.type === 'template' ? 'badge-on' : b.type === 'system' ? 'badge-info' : 'badge-off';
			let body = '<div class="ep-modal-status">';
			body += `<span class="ep-badge ${typeCls}"><span class="ep-dot"></span>${esc(b.type)}</span>`;
			body += `<span class="ep-src">${esc(b.source)}</span>`;
			body += '</div>';
			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Category</div>';
			body += `<p class="ep-modal-text">${esc(b.cat)}</p></div>`;
			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Namespace</div>';
			body += `<p class="ep-modal-text"><code class="ep-modal-filter">${esc(b.name.split('/')[0])}</code></p></div>`;
			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Block Name</div>';
			body += `<pre class="ep-modal-code">${esc(b.name)}</pre></div>`;
			if (b.type === 'template') {
				body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Router</div>';
				body += '<p class="ep-modal-text">This is a template block &mdash; the router can dispatch requests to it based on the resolved route.</p></div>';
			}
			openModal(b.title, b.name, body);
		},
		emptyMessage: 'No blocks match your search.',
	});

	const namespaces = [...new Set(blocks.map(b => b.name.split('/')[0]))];
	log.info(`[ExamplePress] Blocks: ${blocks.length} blocks across ${namespaces.length} namespaces`);

	return blocks.length;
}
