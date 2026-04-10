/**
 * Proposer subpage entry point.
 * In-browser Monaco editor for drafting multi-file proposals (PRs) against
 * a pinned GitHub ref. Never writes to the local filesystem — all changes
 * accumulate in a draft that is submitted as a proposal via REST.
 */

import { initLogger, log } from '../lib/logger.js';
import { initApi, apiFetch } from '../lib/api.js';

/* ── State ─────────────────────────────────────────────────────────── */

let baseRef = '';
let repoTree = [];     // [{path, type, sha, size}]
let draft = { base_ref: '', files: {} }; // path -> {op, content, base_blob_sha, from?}
let openTabs = [];     // [{path, content, original, blobSha, dirty}]
let activeTab = null;
let editor = null;
let diffEditor = null;
let diffMode = false;
let slug = '';
let editorBaseUrl = '';
let nonce = '';
let draftDirty = false;
let autoSaveTimer = null;

const MONACO_VERSION = '0.52.2';
const MONACO_CDN = `https://cdn.jsdelivr.net/npm/monaco-editor@${MONACO_VERSION}`;
let monacoModule = null;

/* ── Bootstrap ─────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', async () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);

	slug = data.slug;
	editorBaseUrl = data.editorBaseUrl;
	nonce = data.nonce;

	try {
		const refData = await apiFetch(`${editorBaseUrl}/ref`);
		baseRef = refData.ref;
		setRefDisplay(baseRef);

		const treeData = await apiFetch(`${editorBaseUrl}/tree?ref=${encodeURIComponent(baseRef)}`);
		repoTree = treeData.tree || [];

		const savedDraft = await apiFetch(`${editorBaseUrl}/draft`).catch(() => null);
		if (savedDraft && savedDraft.files && Object.keys(savedDraft.files).length) {
			draft = { base_ref: savedDraft.base_ref || baseRef, files: savedDraft.files };
		} else {
			draft.base_ref = baseRef;
		}

		renderTree();
		updateDraftBadge();
		bindToolbar();

		autoSaveTimer = setInterval(autoSaveDraft, 30000);

		log.info('[ExamplePress] Proposer ready.', { baseRef, treeSize: repoTree.length });
	} catch (err) {
		log.error('[ExamplePress] Proposer init failed:', err);
		const tree = document.getElementById('ep-proposer-tree');
		if (tree) tree.innerHTML = `<div style="padding:12px;color:#9b2c2c;">Init failed: ${esc(err.message)}</div>`;
	}
});

/* ── Helpers ───────────────────────────────────────────────────────── */

function esc(s) {
	return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function shortSha(sha) {
	return sha ? sha.substring(0, 7) : '';
}

function setRefDisplay(ref) {
	const el = document.getElementById('ep-proposer-ref');
	if (el) el.textContent = `Base: ${shortSha(ref)}`;
}

function updateDraftBadge() {
	const badge = document.getElementById('ep-proposer-draft-badge');
	const count = Object.keys(draft.files).length;
	if (badge) {
		badge.textContent = count ? `${count} changed` : '';
		badge.style.display = count ? '' : 'none';
	}
}

function detectLanguage(path) {
	const ext = (path.split('.').pop() || '').toLowerCase();
	const map = {
		js: 'javascript', jsx: 'javascript', mjs: 'javascript',
		ts: 'typescript', tsx: 'typescript',
		json: 'json', css: 'css', scss: 'scss', less: 'less',
		html: 'html', htm: 'html', xml: 'xml', svg: 'xml',
		php: 'php', py: 'python', rb: 'ruby', go: 'go',
		rs: 'rust', java: 'java', c: 'c', cpp: 'cpp', h: 'c',
		md: 'markdown', yaml: 'yaml', yml: 'yaml',
		sh: 'shell', bash: 'shell', sql: 'sql',
	};
	return map[ext] || 'plaintext';
}

/* ── Toolbar ───────────────────────────────────────────────────────── */

function bindToolbar() {
	const discardBtn = document.getElementById('ep-proposer-discard');
	const submitBtn = document.getElementById('ep-proposer-submit');

	if (discardBtn) discardBtn.addEventListener('click', discardDraft);
	if (submitBtn) submitBtn.addEventListener('click', openSubmitDialog);

	document.addEventListener('keydown', e => {
		if ((e.ctrlKey || e.metaKey) && e.key === 's') {
			e.preventDefault();
			saveDraftNow();
		}
	});
}

/* ── Tree ──────────────────────────────────────────────────────────── */

function buildTreeStructure(flatTree) {
	const root = { name: '', children: [], type: 'directory' };

	flatTree.filter(n => n.type === 'blob' || n.type === 'tree').forEach(node => {
		const parts = node.path.split('/');
		let cur = root;
		parts.forEach((part, i) => {
			if (i === parts.length - 1 && node.type === 'blob') {
				cur.children.push({ name: part, path: node.path, type: 'file', sha: node.sha, size: node.size });
			} else {
				let child = cur.children.find(c => c.name === part && c.type === 'directory');
				if (!child) {
					child = { name: part, path: parts.slice(0, i + 1).join('/'), type: 'directory', children: [] };
					cur.children.push(child);
				}
				cur = child;
			}
		});
	});

	const sort = (nodes) => {
		nodes.sort((a, b) => {
			if (a.type !== b.type) return a.type === 'directory' ? -1 : 1;
			return a.name.localeCompare(b.name);
		});
		nodes.filter(n => n.type === 'directory').forEach(d => sort(d.children));
	};
	sort(root.children);
	return root.children;
}

function draftOp(path) {
	const entry = draft.files[path];
	if (!entry) return null;
	return entry.op; // 'modify' | 'create' | 'delete' | 'rename'
}

function opBadge(op) {
	if (!op) return '';
	const labels = { modify: 'M', create: 'A', delete: 'D', rename: 'R' };
	const colors = { modify: '#b45309', create: '#166534', delete: '#991b1b', rename: '#1e40af' };
	return ` <span style="font-size:10px;font-weight:600;color:${colors[op] || '#666'};margin-left:4px;">${labels[op] || '?'}</span>`;
}

function renderTree() {
	const treeEl = document.getElementById('ep-proposer-tree');
	if (!treeEl) return;

	const structured = buildTreeStructure(repoTree);

	// Add "new file" and draft-only files.
	const draftOnlyPaths = Object.keys(draft.files).filter(p => {
		return draft.files[p].op === 'create' && !repoTree.find(n => n.path === p);
	});

	let html = '<div class="ep-proposer-tree-toolbar" style="padding:4px 8px;border-bottom:1px solid #ddd;">';
	html += '<button id="ep-proposer-new-file" style="font-size:12px;cursor:pointer;">+ New File</button>';
	html += '</div>';
	html += renderTreeNodes(structured, 0);

	// Draft-only created files at root level.
	draftOnlyPaths.forEach(path => {
		html += `<div class="ep-tree-file" data-path="${esc(path)}" style="padding:3px 8px 3px 28px;cursor:pointer;user-select:none;">`;
		html += `<span style="color:#50575e;">${esc(path)}</span>${opBadge('create')}`;
		html += `</div>`;
	});

	treeEl.innerHTML = html;
	bindTreeEvents(treeEl);
}

function renderTreeNodes(nodes, depth) {
	let html = '';
	nodes.forEach(node => {
		const pad = depth * 16;
		if (node.type === 'directory') {
			html += `<div class="ep-tree-dir" data-path="${esc(node.path)}" style="padding:3px 8px 3px ${pad + 8}px;cursor:pointer;user-select:none;">`;
			html += `<span class="ep-tree-arrow" style="display:inline-block;width:12px;font-size:10px;">&#9656;</span> `;
			html += `<span style="color:#1e1e1e;">${esc(node.name)}/</span>`;
			html += `</div>`;
			html += `<div class="ep-tree-children" style="display:none;">`;
			html += renderTreeNodes(node.children || [], depth + 1);
			html += `</div>`;
		} else {
			const op = draftOp(node.path);
			html += `<div class="ep-tree-file" data-path="${esc(node.path)}" style="padding:3px 8px 3px ${pad + 20}px;cursor:pointer;user-select:none;">`;
			html += `<span style="color:#50575e;">${esc(node.name)}</span>${opBadge(op)}`;
			html += `</div>`;
		}
	});
	return html;
}

function bindTreeEvents(treeEl) {
	treeEl.querySelectorAll('.ep-tree-dir').forEach(dir => {
		dir.addEventListener('click', () => {
			const children = dir.nextElementSibling;
			const arrow = dir.querySelector('.ep-tree-arrow');
			if (children) {
				const isOpen = children.style.display !== 'none';
				children.style.display = isOpen ? 'none' : '';
				if (arrow) arrow.innerHTML = isOpen ? '&#9656;' : '&#9662;';
			}
		});
	});

	treeEl.querySelectorAll('.ep-tree-file').forEach(file => {
		file.addEventListener('click', () => openFile(file.dataset.path));
		file.addEventListener('mouseenter', () => { if (!file.classList.contains('ep-tree-active')) file.style.background = '#e8e8e8'; });
		file.addEventListener('mouseleave', () => { if (!file.classList.contains('ep-tree-active')) file.style.background = ''; });

		// Right-click to delete.
		file.addEventListener('contextmenu', e => {
			e.preventDefault();
			const path = file.dataset.path;
			if (draft.files[path]?.op === 'delete') return;
			if (!confirm(`Mark "${path}" for deletion?`)) return;
			markDeleted(path);
		});
	});

	const newFileBtn = document.getElementById('ep-proposer-new-file');
	if (newFileBtn) newFileBtn.addEventListener('click', promptNewFile);
}

function highlightActiveFile(path) {
	const treeEl = document.getElementById('ep-proposer-tree');
	if (!treeEl) return;
	treeEl.querySelectorAll('.ep-tree-file').forEach(f => {
		f.classList.remove('ep-tree-active');
		f.style.background = '';
	});
	const active = treeEl.querySelector(`.ep-tree-file[data-path="${CSS.escape(path)}"]`);
	if (active) {
		active.classList.add('ep-tree-active');
		active.style.background = '#cce5ff';
	}
}

/* ── File operations ───────────────────────────────────────────────── */

async function openFile(path) {
	// If deleted in draft, show notice.
	if (draft.files[path]?.op === 'delete') {
		alert(`"${path}" is marked for deletion in this draft.`);
		return;
	}

	// If already open, switch to it.
	const existing = openTabs.find(t => t.path === path);
	if (existing) {
		switchTab(path);
		return;
	}

	let content = '';
	let original = '';
	let blobSha = '';

	// Check if draft has content for this file.
	if (draft.files[path]) {
		content = draft.files[path].content || '';
		blobSha = draft.files[path].base_blob_sha || '';
	}

	// Fetch original from GitHub for comparison (unless it is a new file).
	if (draft.files[path]?.op !== 'create') {
		try {
			const fileData = await apiFetch(
				`${editorBaseUrl}/file?ref=${encodeURIComponent(baseRef)}&path=${encodeURIComponent(path)}`
			);
			original = fileData.content;
			blobSha = blobSha || fileData.sha;
			if (!draft.files[path]) content = original;
		} catch (err) {
			log.error('[ExamplePress] Failed to load file:', err);
			return;
		}
	}

	const tab = { path, content, original, blobSha, dirty: content !== original };
	openTabs.push(tab);
	switchTab(path);
}

function switchTab(path) {
	activeTab = path;
	diffMode = false;

	const tab = openTabs.find(t => t.path === path);
	if (!tab) return;

	renderTabs();
	highlightActiveFile(path);
	loadIntoEditor(tab);
}

function closeTab(path) {
	const idx = openTabs.findIndex(t => t.path === path);
	if (idx === -1) return;

	// Persist current content to draft before closing if dirty.
	const tab = openTabs[idx];
	if (tab.dirty) {
		updateDraftFile(tab);
	}

	openTabs.splice(idx, 1);

	if (activeTab === path) {
		if (openTabs.length) {
			switchTab(openTabs[Math.min(idx, openTabs.length - 1)].path);
		} else {
			activeTab = null;
			renderTabs();
			clearEditor();
		}
	} else {
		renderTabs();
	}
}

function promptNewFile() {
	const path = prompt('New file path (relative to repo root):');
	if (!path || !path.trim()) return;

	const clean = path.trim().replace(/^\/+/, '');
	if (draft.files[clean] || repoTree.find(n => n.path === clean)) {
		alert('A file with that path already exists.');
		return;
	}

	draft.files[clean] = { op: 'create', content: '', base_blob_sha: '' };
	draftDirty = true;
	renderTree();
	updateDraftBadge();
	openFile(clean);
}

function markDeleted(path) {
	const treeNode = repoTree.find(n => n.path === path);
	draft.files[path] = { op: 'delete', content: null, base_blob_sha: treeNode?.sha || '' };
	draftDirty = true;

	// Close tab if open.
	const idx = openTabs.findIndex(t => t.path === path);
	if (idx !== -1) {
		openTabs.splice(idx, 1);
		if (activeTab === path) {
			activeTab = openTabs.length ? openTabs[0].path : null;
			if (activeTab) switchTab(activeTab); else clearEditor();
		}
	}

	renderTree();
	renderTabs();
	updateDraftBadge();
}

/* ── Tabs ──────────────────────────────────────────────────────────── */

function renderTabs() {
	const tabBar = document.getElementById('ep-proposer-tabs');
	if (!tabBar) return;

	tabBar.innerHTML = openTabs.map(t => {
		const active = t.path === activeTab ? 'background:#fff;border-bottom:2px solid #2271b1;' : '';
		const dot = t.dirty ? '<span style="color:#d63638;margin-left:2px;">●</span>' : '';
		return `<div class="ep-proposer-tab" data-path="${esc(t.path)}" style="display:inline-flex;align-items:center;padding:6px 10px;cursor:pointer;font-size:12px;border-right:1px solid #ddd;${active}">
			<span>${esc(t.path.split('/').pop())}</span>${dot}
			<span class="ep-proposer-tab-close" data-path="${esc(t.path)}" style="margin-left:6px;font-size:14px;line-height:1;cursor:pointer;color:#999;">&times;</span>
		</div>`;
	}).join('');

	// Diff toggle button.
	if (activeTab) {
		const diffBtn = document.createElement('button');
		diffBtn.textContent = diffMode ? 'Editor' : 'Diff';
		diffBtn.style.cssText = 'margin-left:auto;padding:4px 10px;font-size:12px;cursor:pointer;';
		diffBtn.addEventListener('click', toggleDiff);
		tabBar.appendChild(diffBtn);
	}

	tabBar.querySelectorAll('.ep-proposer-tab').forEach(el => {
		el.addEventListener('click', e => {
			if (e.target.classList.contains('ep-proposer-tab-close')) return;
			switchTab(el.dataset.path);
		});
	});
	tabBar.querySelectorAll('.ep-proposer-tab-close').forEach(el => {
		el.addEventListener('click', e => {
			e.stopPropagation();
			closeTab(el.dataset.path);
		});
	});
}

/* ── Monaco ────────────────────────────────────────────────────────── */

async function loadMonaco() {
	if (monacoModule) return monacoModule;
	monacoModule = await import(/* @vite-ignore */ `${MONACO_CDN}/+esm`);

	self.MonacoEnvironment = {
		getWorkerUrl(_, label) {
			const base = `${MONACO_CDN}/esm/vs`;
			if (label === 'json') return `${base}/language/json/json.worker.js`;
			if (label === 'css' || label === 'scss' || label === 'less') return `${base}/language/css/css.worker.js`;
			if (label === 'html' || label === 'handlebars' || label === 'razor') return `${base}/language/html/html.worker.js`;
			if (label === 'typescript' || label === 'javascript') return `${base}/language/typescript/ts.worker.js`;
			return `${base}/editor/editor.worker.js`;
		},
	};
	return monacoModule;
}

let changeDebounce = null;

async function loadIntoEditor(tab) {
	const container = document.getElementById('ep-proposer-container');
	if (!container) return;

	const monaco = await loadMonaco();
	const lang = detectLanguage(tab.path);

	// Tear down diff editor if active.
	if (diffEditor) { diffEditor.dispose(); diffEditor = null; }
	container.innerHTML = '';

	if (!editor) {
		editor = monaco.editor.create(container, {
			value: tab.content,
			language: lang,
			theme: 'vs',
			fontSize: 13,
			fontFamily: "'JetBrains Mono', Consolas, 'Courier New', monospace",
			minimap: { enabled: false },
			scrollBeyondLastLine: false,
			wordWrap: 'on',
			automaticLayout: true,
			tabSize: 4,
			insertSpaces: false,
		});

		editor.onDidChangeModelContent(() => {
			if (!activeTab) return;
			const t = openTabs.find(x => x.path === activeTab);
			if (!t) return;

			t.content = editor.getValue();
			t.dirty = t.content !== t.original;
			renderTabs();

			clearTimeout(changeDebounce);
			changeDebounce = setTimeout(() => {
				updateDraftFile(t);
			}, 1000);
		});
	} else {
		editor.setValue(tab.content);
		monaco.editor.setModelLanguage(editor.getModel(), lang);
	}
}

function clearEditor() {
	if (editor) editor.setValue('');
	if (diffEditor) { diffEditor.dispose(); diffEditor = null; }
	renderTabs();
}

async function toggleDiff() {
	const container = document.getElementById('ep-proposer-container');
	if (!container) return;
	const tab = openTabs.find(t => t.path === activeTab);
	if (!tab) return;

	const monaco = await loadMonaco();
	diffMode = !diffMode;

	if (diffMode) {
		// Save current content from editor.
		if (editor) tab.content = editor.getValue();
		if (editor) { editor.dispose(); editor = null; }
		container.innerHTML = '';

		const originalModel = monaco.editor.createModel(tab.original, detectLanguage(tab.path));
		const modifiedModel = monaco.editor.createModel(tab.content, detectLanguage(tab.path));

		diffEditor = monaco.editor.createDiffEditor(container, {
			theme: 'vs',
			fontSize: 13,
			fontFamily: "'JetBrains Mono', Consolas, 'Courier New', monospace",
			automaticLayout: true,
			readOnly: true,
			renderSideBySide: true,
		});
		diffEditor.setModel({ original: originalModel, modified: modifiedModel });
	} else {
		if (diffEditor) { diffEditor.dispose(); diffEditor = null; }
		container.innerHTML = '';
		editor = null; // Force re-create.
		loadIntoEditor(tab);
	}

	renderTabs();
}

/* ── Draft management ──────────────────────────────────────────────── */

function updateDraftFile(tab) {
	const treeNode = repoTree.find(n => n.path === tab.path);
	const isNew = !treeNode && (!draft.files[tab.path] || draft.files[tab.path].op === 'create');

	if (tab.content === tab.original && !isNew) {
		// No change — remove from draft.
		delete draft.files[tab.path];
	} else {
		draft.files[tab.path] = {
			op: isNew ? 'create' : 'modify',
			content: tab.content,
			base_blob_sha: tab.blobSha,
		};
	}

	draftDirty = true;
	updateDraftBadge();
	renderTree();
}

async function saveDraftNow() {
	if (!Object.keys(draft.files).length && !draftDirty) return;

	try {
		await apiFetch(`${editorBaseUrl}/draft`, {
			method: 'PUT',
			body: { base_ref: draft.base_ref, files: draft.files },
		});
		draftDirty = false;
		log.info('[ExamplePress] Draft saved.');
	} catch (err) {
		log.error('[ExamplePress] Draft save failed:', err);
	}
}

function autoSaveDraft() {
	if (draftDirty) saveDraftNow();
}

async function discardDraft() {
	if (!confirm('Discard all draft changes? This cannot be undone.')) return;

	try {
		await apiFetch(`${editorBaseUrl}/draft`, { method: 'DELETE' });
	} catch (err) {
		log.error('[ExamplePress] Draft delete failed:', err);
	}

	draft = { base_ref: baseRef, files: {} };
	draftDirty = false;
	openTabs = [];
	activeTab = null;

	renderTree();
	renderTabs();
	updateDraftBadge();
	clearEditor();
}

/* ── Submit dialog ─────────────────────────────────────────────────── */

function openSubmitDialog() {
	const files = draft.files;
	const paths = Object.keys(files);
	if (!paths.length) { alert('No changes to submit.'); return; }

	// Check for conflicts.
	const conflicts = paths.filter(p => {
		if (files[p].op === 'create') return false;
		const treeNode = repoTree.find(n => n.path === p);
		return treeNode && files[p].base_blob_sha && treeNode.sha !== files[p].base_blob_sha;
	});

	// Build dialog.
	const overlay = document.createElement('div');
	overlay.id = 'ep-proposer-submit-overlay';
	overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:100000;';

	const opLabel = { modify: 'Modified', create: 'Added', delete: 'Deleted', rename: 'Renamed' };

	overlay.innerHTML = `
		<div style="background:#fff;border-radius:8px;width:520px;max-height:80vh;overflow-y:auto;padding:24px;">
			<h2 style="margin:0 0 16px;">Submit Proposal</h2>
			${conflicts.length ? `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:4px;padding:8px 12px;margin-bottom:12px;font-size:13px;color:#991b1b;">
				<strong>Warning:</strong> ${conflicts.length} file(s) may have changed on the remote since your draft was started. Review carefully.
			</div>` : ''}
			<div style="margin-bottom:16px;">
				<strong>Changeset (${paths.length} file${paths.length !== 1 ? 's' : ''}):</strong>
				<ul style="margin:8px 0;padding-left:20px;font-size:13px;color:#374151;">
					${paths.map(p => `<li><code>${esc(p)}</code> — ${opLabel[files[p].op] || files[p].op}</li>`).join('')}
				</ul>
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-weight:600;margin-bottom:4px;">Title</label>
				<input id="ep-proposal-title" type="text" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;" placeholder="Brief summary of changes" />
			</div>
			<div style="margin-bottom:16px;">
				<label style="display:block;font-weight:600;margin-bottom:4px;">Description</label>
				<textarea id="ep-proposal-body" rows="4" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;resize:vertical;" placeholder="Describe the proposed changes..."></textarea>
			</div>
			<div style="display:flex;gap:8px;justify-content:flex-end;">
				<button id="ep-proposal-cancel" style="padding:6px 16px;cursor:pointer;">Cancel</button>
				<button id="ep-proposal-send" style="padding:6px 16px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;">Submit</button>
			</div>
			<div id="ep-proposal-status" style="margin-top:12px;font-size:13px;"></div>
		</div>`;

	document.body.appendChild(overlay);

	overlay.querySelector('#ep-proposal-cancel').addEventListener('click', () => overlay.remove());
	overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

	overlay.querySelector('#ep-proposal-send').addEventListener('click', async () => {
		const title = overlay.querySelector('#ep-proposal-title').value.trim();
		const body = overlay.querySelector('#ep-proposal-body').value.trim();
		const status = overlay.querySelector('#ep-proposal-status');
		const sendBtn = overlay.querySelector('#ep-proposal-send');

		if (!title) { status.textContent = 'Title is required.'; status.style.color = '#991b1b'; return; }

		sendBtn.disabled = true;
		sendBtn.textContent = 'Submitting...';
		status.textContent = '';

		try {
			const result = await apiFetch(`${editorBaseUrl}/proposal`, {
				method: 'POST',
				body: { base_ref: draft.base_ref, files: draft.files, title, body },
			});

			// Success — clear draft and show result.
			await apiFetch(`${editorBaseUrl}/draft`, { method: 'DELETE' }).catch(() => {});
			draft = { base_ref: baseRef, files: {} };
			draftDirty = false;
			openTabs = [];
			activeTab = null;

			overlay.querySelector('div').innerHTML = `
				<h2 style="margin:0 0 16px;color:#166534;">Proposal Submitted</h2>
				<p>Proposal <strong>#${result.number}</strong> has been created.</p>
				<p><a href="${esc(result.html_url)}" target="_blank" rel="noopener">View on GitHub &rarr;</a></p>
				<div style="margin-top:16px;text-align:right;">
					<button id="ep-proposal-done" style="padding:6px 16px;cursor:pointer;">Close</button>
				</div>`;
			overlay.querySelector('#ep-proposal-done').addEventListener('click', () => {
				overlay.remove();
				renderTree();
				renderTabs();
				updateDraftBadge();
				clearEditor();
			});
		} catch (err) {
			status.textContent = err.message || 'Submission failed.';
			status.style.color = '#991b1b';
			sendBtn.disabled = false;
			sendBtn.textContent = 'Submit';
		}
	});
}
