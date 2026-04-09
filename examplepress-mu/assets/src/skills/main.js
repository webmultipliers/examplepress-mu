/**
 * Skills page entry point.
 *
 * Renders the agent skill curriculum as a sidebar of tabs + a content
 * panel showing the active skill's markdown rendered to HTML via
 * marked. Skill data (with merge tags already resolved server-side)
 * comes from window.ExamplePressData.skills.
 */

import '../css/base.css';
import './skills.css';

import { marked } from 'marked';
import { initLogger, log } from '../lib/logger.js';

marked.setOptions({
	gfm:        true,
	breaks:     false,
	headerIds:  false,
	mangle:     false,
});

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);

	const skills = Array.isArray(data.skills) ? data.skills : [];
	const sidebarEl = document.getElementById('ep-skills-sidebar');
	const contentEl = document.getElementById('ep-skills-content');
	if (!sidebarEl || !contentEl) return;

	if (skills.length === 0) {
		contentEl.innerHTML = '<div class="ep-skills-empty"><p>No skills loaded. Check that <code>examplepress-mu/agent/skills/</code> contains <code>.md</code> files.</p></div>';
		return;
	}

	// Render the sidebar tab list.
	sidebarEl.innerHTML = skills.map((s, i) => `
		<button
			class="ep-skill-tab${i === 0 ? ' is-active' : ''}"
			role="tab"
			aria-selected="${i === 0 ? 'true' : 'false'}"
			data-skill-id="${escapeAttr(s.id)}"
		>
			<span class="ep-skill-tab-name">${escapeHtml(s.name)}</span>
			<span class="ep-skill-tab-title">${escapeHtml(s.title)}</span>
			<span class="ep-skill-tab-bytes">${formatBytes(s.bytes)}</span>
		</button>
	`).join('');

	// Render initial skill (first in list).
	renderSkill(skills[0]);

	// Wire tab clicks.
	sidebarEl.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-skill-id]');
		if (!btn) return;
		const skill = skills.find(s => s.id === btn.dataset.skillId);
		if (!skill) return;

		sidebarEl.querySelectorAll('.ep-skill-tab').forEach(t => {
			const isActive = t === btn;
			t.classList.toggle('is-active', isActive);
			t.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		renderSkill(skill);
	});

	function renderSkill(skill) {
		try {
			const html = marked.parse(skill.body || '');
			contentEl.innerHTML = `
				<header class="ep-skill-header">
					<div class="ep-skill-header-name"><code>${escapeHtml(skill.name)}</code></div>
					<h1 class="ep-skill-header-title">${escapeHtml(skill.title)}</h1>
					<div class="ep-skill-header-meta">${formatBytes(skill.bytes)} · ${(skill.body || '').split('\n').length} lines</div>
				</header>
				<div class="ep-skill-body">${html}</div>
			`;
			contentEl.scrollTop = 0;
		} catch (err) {
			log.error('[skills] render failed', err);
			contentEl.innerHTML = `<div class="ep-skills-empty"><p>Failed to render <code>${escapeHtml(skill.name)}</code>: ${escapeHtml(err.message)}</p></div>`;
		}
	}

	log.info('[ExamplePress] Skills page ready.', { count: skills.length });
});

function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function escapeAttr(s) {
	return escapeHtml(s);
}
function formatBytes(b) {
	if (!b) return '0 B';
	if (b < 1024) return b + ' B';
	return (b / 1024).toFixed(1) + ' KB';
}
