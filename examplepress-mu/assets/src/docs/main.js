/**
 * Docs page entry point — unified reference + skill curriculum.
 *
 * Merges two pages that used to live separately:
 *   - Former "Docs" page:     hook reference, resolution order, support links.
 *   - Former "Skills" page:   agent skill curriculum (markdown files on disk).
 *
 * Both now render through the same ep-docs-layout (sidebar nav +
 * content + TOC rail). Reference entries are synthesized client-side
 * from the legacy `data.docs`, `data.hooks`, and a static resolution
 * table so the unified page has a single rendering pipeline.
 */

import '../css/index.css';
import { marked }    from 'marked';
import hljs          from 'highlight.js/lib/core';
import javascript    from 'highlight.js/lib/languages/javascript';
import php           from 'highlight.js/lib/languages/php';
import json          from 'highlight.js/lib/languages/json';
import xml           from 'highlight.js/lib/languages/xml';
import css           from 'highlight.js/lib/languages/css';
import bash          from 'highlight.js/lib/languages/bash';
import sql           from 'highlight.js/lib/languages/sql';
import yaml          from 'highlight.js/lib/languages/yaml';
import plaintext     from 'highlight.js/lib/languages/plaintext';
import { initLogger, log } from '../lib/logger.js';

/* ── Highlight.js setup ───────────────────────────────────────────── */

hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('js',         javascript);
hljs.registerLanguage('php',        php);
hljs.registerLanguage('json',       json);
hljs.registerLanguage('html',       xml);
hljs.registerLanguage('xml',        xml);
hljs.registerLanguage('css',        css);
hljs.registerLanguage('bash',       bash);
hljs.registerLanguage('sh',         bash);
hljs.registerLanguage('shell',      bash);
hljs.registerLanguage('sql',        sql);
hljs.registerLanguage('yaml',       yaml);
hljs.registerLanguage('yml',        yaml);
hljs.registerLanguage('plaintext',  plaintext);
hljs.registerLanguage('text',       plaintext);

/* ── Navigation groups ────────────────────────────────────────────── */

/*
 * The Reference group is synthesized from static data; the other
 * groups hold real skill files keyed by their filename-derived IDs.
 * Entries in REFERENCE_IDS are always rendered first.
 */
const REFERENCE_IDS = ['ref-hooks', 'ref-resolution', 'ref-support'];

const GROUPS = [
	{
		label: 'Reference',
		icon: '📘',
		ids: REFERENCE_IDS,
	},
	{
		label: 'Fundamentals',
		icon: '📖',
		ids: ['00-overview', '05-output-format', '10-app-skeleton', '15-iteration-mode'],
	},
	{
		label: 'Routing & Pages',
		icon: '🗺️',
		ids: ['20-routing', '70-pages-and-patterns'],
	},
	{
		label: 'Blocks & Templates',
		icon: '🧱',
		ids: ['30-blockstudio-blocks', '40-block-attributes', '50-block-context'],
	},
	{
		label: 'Data & State',
		icon: '🗄️',
		ids: ['45-state-and-data-sources', '80-database-and-rpc', '85-interactivity-api'],
	},
	{
		label: 'Design & Quality',
		icon: '✨',
		ids: ['60-design-tokens', '65-i18n-and-accessibility', '90-security-and-style', '95-self-check'],
	},
];

/* ── Marked renderer overrides ────────────────────────────────────── */

const renderer = new marked.Renderer();

renderer.code = function ({ text, lang }) {
	const language = lang && hljs.getLanguage(lang) ? lang : 'plaintext';
	const highlighted = hljs.highlight(text, { language }).value;
	const langLabel = lang || 'text';
	return `<div class="ep-code-block">
		<div class="ep-code-header"><span class="ep-code-lang">${esc(langLabel)}</span><button class="ep-code-copy" aria-label="Copy code" data-code="${escAttr(text)}">Copy</button></div>
		<pre><code class="hljs language-${esc(language)}">${highlighted}</code></pre>
	</div>`;
};

renderer.heading = function ({ text, depth }) {
	// Convert markdown inline code (`foo`) to <code>foo</code>. Marked's
	// default heading renderer hands us the raw text of the heading
	// tokens — we have to do the narrow inline-code pass ourselves so
	// headings like `` ## \`examplepress_mu_hook\` `` render as
	// <code>examplepress_mu_hook</code> instead of displaying literal
	// backticks.
	const displayText = text.replace(/`([^`]+)`/g, '<code>$1</code>');
	const plainText   = text.replace(/`/g, '');
	const id          = slugify(plainText);
	const anchor      = `<a class="ep-heading-anchor" href="#${id}" aria-label="Link to this section">#</a>`;
	return `<h${depth} id="${id}">${displayText} ${anchor}</h${depth}>`;
};

marked.setOptions({
	gfm:       true,
	breaks:    false,
	renderer,
});

/* ── Reference document synthesis ─────────────────────────────────── */

/**
 * Build the three synthetic Reference entries from the legacy
 * data.docs / data.hooks payload. Each becomes a markdown string
 * that flows through the same render pipeline as real skill files.
 */
function buildReferenceEntries(data) {
	const entries = [];

	// Hooks Reference — one entry per filter/action.
	const hooks = Array.isArray(data.hooks) ? data.hooks : [];
	if (hooks.length) {
		const lines = ['# Hooks Reference', '', 'Every filter and action the ExamplePress kernel exposes. Use these from your companion plugin.', ''];
		for (const h of hooks) {
			lines.push(`## \`${h.name}\``);
			lines.push('');
			lines.push(`**Type:** ${h.type}`);
			lines.push('');
			if (h.desc) {
				lines.push(h.desc);
				lines.push('');
			}
			const usage = h.type === 'filter'
				? `add_filter( '${h.name}', function ( $value ) {\n    // Your modification here.\n    return $value;\n} );`
				: `add_action( '${h.name}', function () {\n    // Your code here.\n} );`;
			lines.push('```php');
			lines.push(usage);
			lines.push('```');
			lines.push('');
		}
		entries.push({
			id:    'ref-hooks',
			name:  'reference/hooks.md',
			title: 'Hooks Reference',
			body:  lines.join('\n'),
		});
	}

	// Resolution Order — static description with a feature-sources table.
	entries.push({
		id:    'ref-resolution',
		name:  'reference/resolution.md',
		title: 'Resolution Order',
		body:
`# Resolution Order

When the feature registry resolves a value, it checks sources in priority
order. **The first match wins.**

| Priority | Source                    | Notes                                           |
|---------:|---------------------------|-------------------------------------------------|
| 100      | Runtime filter            | \`apply_filters()\` — last-mile override        |
| 75       | Site option               | \`get_option()\` — admin-editable               |
| 50       | Theme configuration       | \`examplepress.json\` / \`theme.json\`          |
| 25       | Companion plugin default  | App manifest or plugin constant                 |
| 0        | Kernel fallback           | Hardcoded default in kernel code                |

The first layer to return a non-\`null\` value wins, regardless of whether
any lower-priority layer would have returned something more specific.
Plugins should therefore use filters rather than directly writing to
options if they need context-sensitive overrides.
`,
	});

	// Support & Resources — rendered from data.docs cards.
	const docs = Array.isArray(data.docs) ? data.docs : [];
	if (docs.length) {
		const lines = ['# Support & Resources', '', 'Find help, contribute to the project, or get in touch.', ''];
		for (const d of docs) {
			const titleLine = d.link
				? `## [${d.title}](${d.link})`
				: `## ${d.title}`;
			lines.push(titleLine);
			if (d.eyebrow) {
				lines.push('');
				lines.push(`*${d.eyebrow}*`);
			}
			if (d.desc) {
				lines.push('');
				lines.push(d.desc);
			}
			lines.push('');
		}
		entries.push({
			id:    'ref-support',
			name:  'reference/support.md',
			title: 'Support & Resources',
			body:  lines.join('\n'),
		});
	}

	return entries;
}

/* ── Boot ─────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);

	const skills     = Array.isArray(data.skills) ? data.skills : [];
	const references = buildReferenceEntries(data);
	const documents  = [...references, ...skills];

	const navEl     = document.getElementById('ep-docs-nav-groups');
	const contentEl = document.getElementById('ep-docs-content');
	const tocListEl = document.getElementById('ep-docs-toc-list');
	const tocEl     = document.getElementById('ep-docs-toc');
	const searchEl  = document.getElementById('ep-docs-search');

	if (!navEl || !contentEl) return;

	if (documents.length === 0) {
		contentEl.innerHTML = '<div class="ep-docs-empty"><p>No documentation loaded.</p></div>';
		return;
	}

	const docMap = Object.fromEntries(documents.map(d => [d.id, d]));
	let activeId = null;

	/* ── Render grouped sidebar ─────────────────────────────────── */

	function renderNav(filter = '') {
		const lc = filter.toLowerCase();

		navEl.innerHTML = GROUPS.map(group => {
			const items = group.ids
				.map(id => docMap[id])
				.filter(Boolean)
				.filter(s => {
					if (!lc) return true;
					return s.title.toLowerCase().includes(lc)
						|| s.name.toLowerCase().includes(lc)
						|| (s.body || '').toLowerCase().includes(lc);
				});

			if (items.length === 0) return '';

			const buttons = items.map(s => `
				<button
					class="ep-docs-nav-item${s.id === activeId ? ' is-active' : ''}"
					role="tab"
					aria-selected="${s.id === activeId ? 'true' : 'false'}"
					data-doc-id="${escAttr(s.id)}"
				>
					<span class="ep-docs-nav-title">${esc(s.title)}</span>
				</button>
			`).join('');

			return `
				<div class="ep-docs-nav-group">
					<div class="ep-docs-nav-group-label">
						<span class="ep-docs-nav-group-icon">${group.icon}</span>
						${esc(group.label)}
					</div>
					${buttons}
				</div>
			`;
		}).join('');

		// Ungrouped documents (anything with an ID not claimed by a GROUP).
		const groupedIds = new Set(GROUPS.flatMap(g => g.ids));
		const ungrouped = documents.filter(s => !groupedIds.has(s.id)).filter(s => {
			if (!lc) return true;
			return s.title.toLowerCase().includes(lc)
				|| s.name.toLowerCase().includes(lc)
				|| (s.body || '').toLowerCase().includes(lc);
		});

		if (ungrouped.length > 0) {
			const buttons = ungrouped.map(s => `
				<button
					class="ep-docs-nav-item${s.id === activeId ? ' is-active' : ''}"
					role="tab"
					aria-selected="${s.id === activeId ? 'true' : 'false'}"
					data-doc-id="${escAttr(s.id)}"
				>
					<span class="ep-docs-nav-title">${esc(s.title)}</span>
				</button>
			`).join('');

			navEl.innerHTML += `
				<div class="ep-docs-nav-group">
					<div class="ep-docs-nav-group-label">
						<span class="ep-docs-nav-group-icon">📄</span>
						Other
					</div>
					${buttons}
				</div>
			`;
		}
	}

	/* ── Render document content ────────────────────────────────── */

	function renderDoc(doc) {
		activeId = doc.id;

		try {
			const html = marked.parse(doc.body || '');
			contentEl.innerHTML = `
				<div class="ep-docs-breadcrumb">
					<span class="ep-docs-breadcrumb-group">${esc(groupForDoc(doc.id))}</span>
					<span class="ep-docs-breadcrumb-sep">›</span>
					<span class="ep-docs-breadcrumb-skill">${esc(doc.title)}</span>
				</div>
				<article class="ep-docs-article">${html}</article>
			`;

			contentEl.scrollTop = 0;
			buildToc();
			highlightActiveNav();
			initCopyButtons();
			initScrollSpy();

			// Update URL hash for deep-linking.
			history.replaceState(null, '', `#${doc.id}`);
		} catch (err) {
			log.error('[docs] render failed', err);
			contentEl.innerHTML = `<div class="ep-docs-empty"><p>Failed to render <code>${esc(doc.name)}</code>: ${esc(err.message)}</p></div>`;
		}
	}

	/* ── Table of contents ──────────────────────────────────────── */

	function buildToc() {
		if (!tocListEl || !tocEl) return;

		const headings = contentEl.querySelectorAll('.ep-docs-article h1, .ep-docs-article h2, .ep-docs-article h3');
		if (headings.length < 2) {
			tocEl.classList.add('is-empty');
			tocListEl.innerHTML = '';
			return;
		}

		tocEl.classList.remove('is-empty');
		tocListEl.innerHTML = Array.from(headings).map(h => {
			const depth = parseInt(h.tagName.charAt(1), 10);
			return `<li class="ep-docs-toc-item is-h${depth}">
				<a class="ep-docs-toc-link" href="#${h.id}" data-target="${h.id}">${h.textContent.replace(/#$/, '').trim()}</a>
			</li>`;
		}).join('');
	}

	/* ── Scroll-spy ─────────────────────────────────────────────── */

	let spyObserver = null;

	function initScrollSpy() {
		if (spyObserver) spyObserver.disconnect();
		if (!tocListEl) return;

		const headings = contentEl.querySelectorAll('.ep-docs-article h1, .ep-docs-article h2, .ep-docs-article h3');
		if (headings.length < 2) return;

		spyObserver = new IntersectionObserver(entries => {
			for (const entry of entries) {
				if (entry.isIntersecting) {
					tocListEl.querySelectorAll('.ep-docs-toc-link').forEach(a => {
						a.classList.toggle('is-active', a.dataset.target === entry.target.id);
					});
				}
			}
		}, {
			root: contentEl,
			rootMargin: '0px 0px -70% 0px',
			threshold: 0.1,
		});

		headings.forEach(h => spyObserver.observe(h));
	}

	/* ── Copy-to-clipboard on code blocks ───────────────────────── */

	function initCopyButtons() {
		contentEl.querySelectorAll('.ep-code-copy').forEach(btn => {
			btn.addEventListener('click', async () => {
				const code = btn.dataset.code;
				try {
					await navigator.clipboard.writeText(code);
					btn.classList.add('is-copied');
					btn.textContent = 'Copied!';
					setTimeout(() => {
						btn.classList.remove('is-copied');
						btn.textContent = 'Copy';
					}, 1500);
				} catch { /* clipboard API may fail in some contexts */ }
			});
		});
	}

	/* ── Search ─────────────────────────────────────────────────── */

	if (searchEl) {
		let debounce = null;
		searchEl.addEventListener('input', () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => {
				renderNav(searchEl.value.trim());
			}, 150);
		});

		document.addEventListener('keydown', (e) => {
			if (e.key === '/' && document.activeElement !== searchEl && !isEditable(document.activeElement)) {
				e.preventDefault();
				searchEl.focus();
				searchEl.select();
			}
			if (e.key === 'Escape' && document.activeElement === searchEl) {
				searchEl.value = '';
				searchEl.blur();
				renderNav();
			}
		});
	}

	/* ── Nav click delegation ───────────────────────────────────── */

	navEl.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-doc-id]');
		if (!btn) return;
		const doc = docMap[btn.dataset.docId];
		if (!doc) return;
		renderDoc(doc);
	});

	/* ── TOC click handling (smooth scroll) ─────────────────────── */

	if (tocListEl) {
		tocListEl.addEventListener('click', (e) => {
			const link = e.target.closest('.ep-docs-toc-link');
			if (!link) return;
			e.preventDefault();
			const target = contentEl.querySelector(`#${CSS.escape(link.dataset.target)}`);
			if (target) {
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	}

	/* ── Active nav highlighting ────────────────────────────────── */

	function highlightActiveNav() {
		navEl.querySelectorAll('.ep-docs-nav-item').forEach(btn => {
			const isActive = btn.dataset.docId === activeId;
			btn.classList.toggle('is-active', isActive);
			btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});
	}

	/* ── Deep-link support ──────────────────────────────────────── */

	function initialDoc() {
		const hash = location.hash.replace('#', '');
		if (hash && docMap[hash]) return docMap[hash];
		return documents[0];
	}

	/* ── Init ───────────────────────────────────────────────────── */

	renderNav();
	renderDoc(initialDoc());

	log.info('[ExamplePress] Docs ready.', { references: references.length, skills: skills.length });
});

/* ── Helpers ──────────────────────────────────────────────────────── */

function esc(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function escAttr(s) {
	return esc(s);
}

function slugify(text) {
	return String(text ?? '')
		.toLowerCase()
		.replace(/<[^>]+>/g, '')
		.replace(/[^\w\s-]/g, '')
		.replace(/\s+/g, '-')
		.replace(/-+/g, '-')
		.trim();
}

function groupForDoc(id) {
	for (const g of GROUPS) {
		if (g.ids.includes(id)) return g.label;
	}
	return 'Other';
}

function isEditable(el) {
	if (!el) return false;
	const tag = el.tagName;
	return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}
