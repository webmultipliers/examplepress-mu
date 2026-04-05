/**
 * Reference — Docs tab: doc cards and hook reference with detail modals.
 */
import { esc } from '../lib/dom.js';
import { openModal } from '../lib/modal.js';

export function renderDocs(docs) {
	const docsCards = document.getElementById('docs-cards');
	if (docsCards && docs.length) {
		docsCards.innerHTML = docs.map(d => `
			<a class="ep-doc-card" href="${esc(d.link)}" target="_blank" rel="noopener">
				<div class="ep-doc-card-eyebrow">${esc(d.eyebrow)}</div>
				<div class="ep-doc-card-title">${esc(d.title)}</div>
				<div class="ep-doc-card-desc">${esc(d.desc)}</div>
				<div class="ep-doc-card-link">${esc(d.label)} &rarr;</div>
			</a>`).join('');
	}
}

export function renderHooks(hooks) {
	const hooksList = document.getElementById('hooks-list');
	if (!hooksList || !hooks.length) return;

	hooksList.innerHTML = hooks.map(h => `
		<div class="ep-hook-item ep-hook-item-clickable" data-hook-name="${esc(h.name)}">
			<span class="ep-hook-type ${h.type === 'filter' ? 'hook-filter' : 'hook-action'}">${esc(h.type)}</span>
			<span class="ep-hook-name">${esc(h.name)}</span>
			<span class="ep-hook-desc">${esc(h.desc)}</span>
		</div>`).join('');

	hooksList.querySelectorAll('.ep-hook-item-clickable').forEach(item => {
		item.addEventListener('click', () => {
			const name = item.dataset.hookName;
			const h = hooks.find(hk => hk.name === name);
			if (!h) return;

			const typeCls = h.type === 'filter' ? 'hook-filter' : 'hook-action';
			let body = '<div class="ep-modal-status">';
			body += `<span class="ep-hook-type ${typeCls}">${esc(h.type)}</span>`;
			body += '</div>';

			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Description</div>';
			body += `<p class="ep-modal-text">${esc(h.desc)}</p></div>`;

			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Hook Name</div>';
			body += `<pre class="ep-modal-code">${esc(h.name)}</pre></div>`;

			let usage = '';
			if (h.type === 'filter') {
				usage = `add_filter( '${h.name}', function ( $value ) {\n    // Your modification here.\n    return $value;\n} );`;
			} else {
				usage = `add_action( '${h.name}', function () {\n    // Your code here.\n} );`;
			}
			body += '<div class="ep-modal-section"><div class="ep-modal-section-title">Usage Example</div>';
			body += `<pre class="ep-modal-code">${esc(usage)}</pre></div>`;

			openModal(h.name, h.type, body);
		});
	});
}
