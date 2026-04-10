/**
 * Skills-as-Docs — world-class documentation experience for the
 * agent skill curriculum.
 *
 * Features:
 *   - Grouped sidebar navigation with clean titles
 *   - Full-text search with keyboard shortcut (/)
 *   - Syntax-highlighted code blocks (highlight.js)
 *   - Auto-generated table of contents with scroll-spy
 *   - Anchor links on every heading
 *   - Responsive 3-column → 2-column → 1-column layout
 */

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

/* ── Skill grouping map ───────────────────────────────────────────── */

const GROUPS = [
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
		<div class="ep-code-header"><span class="ep-code-lang">${esc(langLabel)}</span><button class="ep-code-copy" aria-label="Copy code" data-code="${escAttr(text)}"><svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 010 1.5h-1.5a.25.25 0 00-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 00.25-.25v-1.5a.75.75 0 011.5 0v1.5A1.75 1.75 0 019.25 16h-7.5A1.75 1.75 0 010 14.25v-7.5z"/><path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0114.25 11h-7.5A1.75 1.75 0 015 9.25v-7.5zm1.75-.25a.25.25 0 00-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 00.25-.25v-7.5a.25.25 0 00-.25-.25h-7.5z"/></svg></button></div>
		<pre><code class="hljs language-${esc(language)}">${highlighted}</code></pre>
	</div>`;
};

renderer.heading = function ({ text, depth }) {
	const id = slugify(text);
	const anchor = `<a class="ep-heading-anchor" href="#${id}" aria-label="Link to this section">#</a>`;
	return `<h${depth} id="${id}">${text} ${anchor}</h${depth}>`;
};

marked.setOptions({
	gfm:       true,
	breaks:    false,
	renderer,
});

/* ── Boot ─────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);

	const skills    = Array.isArray(data.skills) ? data.skills : [];
	const navEl     = document.getElementById('ep-docs-nav-groups');
	const contentEl = document.getElementById('ep-docs-content');
	const tocListEl = document.getElementById('ep-docs-toc-list');
	const tocEl     = document.getElementById('ep-docs-toc');
	const searchEl  = document.getElementById('ep-docs-search');

	if (!navEl || !contentEl) return;

	if (skills.length === 0) {
		contentEl.innerHTML = '<div class="ep-docs-empty"><p>No skills loaded.</p></div>';
		return;
	}

	const skillMap = Object.fromEntries(skills.map(s => [s.id, s]));
	let activeId = null;

	/* ── Render grouped sidebar ─────────────────────────────────── */

	function renderNav(filter = '') {
		const lc = filter.toLowerCase();

		navEl.innerHTML = GROUPS.map(group => {
			const items = group.ids
				.map(id => skillMap[id])
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
					data-skill-id="${escAttr(s.id)}"
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

		// Handle ungrouped skills (if any skill ID isn't in GROUPS).
		const groupedIds = new Set(GROUPS.flatMap(g => g.ids));
		const ungrouped = skills.filter(s => !groupedIds.has(s.id)).filter(s => {
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
					data-skill-id="${escAttr(s.id)}"
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

	/* ── Render skill content ───────────────────────────────────── */

	function renderSkill(skill) {
		activeId = skill.id;

		try {
			const html = marked.parse(skill.body || '');
			contentEl.innerHTML = `
				<div class="ep-docs-breadcrumb">
					<span class="ep-docs-breadcrumb-group">${esc(groupForSkill(skill.id))}</span>
					<svg viewBox="0 0 20 20" width="12" height="12" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
					<span class="ep-docs-breadcrumb-skill">${esc(skill.title)}</span>
				</div>
				<article class="ep-docs-article">${html}</article>
			`;

			contentEl.scrollTop = 0;
			buildToc();
			highlightActiveNav();
			initCopyButtons();
			initScrollSpy();

			// Update URL hash for deep-linking.
			history.replaceState(null, '', `#${skill.id}`);
		} catch (err) {
			log.error('[skills] render failed', err);
			contentEl.innerHTML = `<div class="ep-docs-empty"><p>Failed to render <code>${esc(skill.name)}</code>: ${esc(err.message)}</p></div>`;
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

	/* ── Scroll-spy for TOC highlighting ────────────────────────── */

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
					btn.innerHTML = '<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M13.78 4.22a.75.75 0 010 1.06l-7.25 7.25a.75.75 0 01-1.06 0L2.22 9.28a.75.75 0 011.06-1.06L6 10.94l6.72-6.72a.75.75 0 011.06 0z"/></svg>';
					setTimeout(() => {
						btn.classList.remove('is-copied');
						btn.innerHTML = '<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 010 1.5h-1.5a.25.25 0 00-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 00.25-.25v-1.5a.75.75 0 011.5 0v1.5A1.75 1.75 0 019.25 16h-7.5A1.75 1.75 0 010 14.25v-7.5z"/><path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0114.25 11h-7.5A1.75 1.75 0 015 9.25v-7.5zm1.75-.25a.25.25 0 00-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 00.25-.25v-7.5a.25.25 0 00-.25-.25h-7.5z"/></svg>';
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

		// Keyboard shortcut: "/" focuses search.
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

	/* ── Tab click delegation ───────────────────────────────────── */

	navEl.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-skill-id]');
		if (!btn) return;
		const skill = skillMap[btn.dataset.skillId];
		if (!skill) return;
		renderSkill(skill);
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
			const isActive = btn.dataset.skillId === activeId;
			btn.classList.toggle('is-active', isActive);
			btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});
	}

	/* ── Deep-link support ──────────────────────────────────────── */

	function initialSkill() {
		const hash = location.hash.replace('#', '');
		if (hash && skillMap[hash]) return skillMap[hash];
		return skills[0];
	}

	/* ── Init ───────────────────────────────────────────────────── */

	renderNav();
	renderSkill(initialSkill());

	log.info('[ExamplePress] Skills docs ready.', { count: skills.length });
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

function groupForSkill(id) {
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
