/**
 * Editor subpage entry point.
 * In-browser Monaco code editor for companion plugins.
 */
import '../css/base.css';

import { initLogger, log } from '../lib/logger.js';
import { initApi, apiFetch } from '../lib/api.js';

let editor = null;
let currentFile = null;
let isDirty = false;

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data || !data.valid) return;

	initLogger(data.devMode);
	initApi(data.nonce);

	const appNameEl = document.getElementById('ep-editor-app-name');
	const filePathEl = document.getElementById('ep-editor-file-path');
	const statusEl = document.getElementById('ep-editor-status');
	const saveBtn = document.getElementById('ep-editor-save');

	if (appNameEl) appNameEl.textContent = data.appName;

	// Load file tree.
	loadTree(data);

	// Save handlers.
	if (saveBtn) {
		saveBtn.addEventListener('click', () => saveCurrentFile(data));
	}

	document.addEventListener('keydown', e => {
		if ((e.ctrlKey || e.metaKey) && e.key === 's') {
			e.preventDefault();
			saveCurrentFile(data);
		}
	});

	// Codespace button.
	if (data.github && data.github.owner_repo) {
		const toolbar = saveBtn?.parentElement;
		if (toolbar) {
			const csBtn = document.createElement('a');
			csBtn.href = data.github.repo_id
				? `https://github.com/codespaces/new?repo=${data.github.repo_id}`
				: `https://github.com/codespaces/new?repo=${encodeURIComponent(data.github.owner_repo)}`;
			csBtn.target = '_blank';
			csBtn.rel = 'noopener';
			csBtn.className = 'ep-editor-btn-secondary';
			csBtn.textContent = 'Codespace';
			toolbar.insertBefore(csBtn, saveBtn);
		}
	}

	log.info('[ExamplePress] Editor page ready.');
});

async function loadTree(data) {
	const treeEl = document.getElementById('ep-editor-tree');
	if (!treeEl) return;

	treeEl.innerHTML = '<div style="padding:12px;color:#646970;">Loading...</div>';

	try {
		const res = await fetch(data.fsTreeUrl, {
			headers: { 'X-WP-Nonce': data.nonce },
		});
		const result = await res.json();

		if (!res.ok) {
			treeEl.innerHTML = `<div style="padding:12px;color:#9b2c2c;">${result.message || 'Failed to load tree.'}</div>`;
			return;
		}

		treeEl.innerHTML = renderTreeNodes(result, data, 0);
		bindTreeClicks(treeEl, data);
	} catch (err) {
		treeEl.innerHTML = `<div style="padding:12px;color:#9b2c2c;">Error: ${err.message}</div>`;
	}
}

function renderTreeNodes(nodes, data, depth) {
	if (!Array.isArray(nodes)) return '';
	let html = '';

	nodes.forEach(node => {
		const pad = depth * 16;
		const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

		if (node.type === 'directory') {
			html += `<div class="ep-tree-dir" data-path="${esc(node.path)}" style="padding:3px 8px 3px ${pad + 8}px;cursor:pointer;user-select:none;">`;
			html += `<span class="ep-tree-arrow" style="display:inline-block;width:12px;font-size:10px;">&#9656;</span> `;
			html += `<span style="color:#1e1e1e;">${esc(node.name)}/</span>`;
			html += `</div>`;
			html += `<div class="ep-tree-children" style="display:none;">`;
			html += renderTreeNodes(node.children || [], data, depth + 1);
			html += `</div>`;
		} else {
			html += `<div class="ep-tree-file" data-path="${esc(node.path)}" style="padding:3px 8px 3px ${pad + 20}px;cursor:pointer;user-select:none;">`;
			html += `<span style="color:#50575e;">${esc(node.name)}</span>`;
			html += `</div>`;
		}
	});

	return html;
}

function bindTreeClicks(treeEl, data) {
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
		file.addEventListener('click', () => openFile(file.dataset.path, data));

		// Hover styles.
		file.addEventListener('mouseenter', () => { if (!file.classList.contains('ep-tree-active')) file.style.background = '#e8e8e8'; });
		file.addEventListener('mouseleave', () => { if (!file.classList.contains('ep-tree-active')) file.style.background = ''; });
	});
}

async function openFile(path, data) {
	const filePathEl = document.getElementById('ep-editor-file-path');
	const statusEl = document.getElementById('ep-editor-status');
	const saveBtn = document.getElementById('ep-editor-save');

	if (statusEl) statusEl.textContent = 'Loading...';

	try {
		const res = await fetch(`${data.fsFileUrl}?path=${encodeURIComponent(path)}`, {
			headers: { 'X-WP-Nonce': data.nonce },
		});
		const result = await res.json();

		if (!res.ok) {
			if (statusEl) statusEl.textContent = result.message || 'Failed to load file.';
			return;
		}

		currentFile = path;
		if (filePathEl) filePathEl.textContent = path;
		if (statusEl) statusEl.textContent = '';

		await initMonaco(result.content, result.language || 'plaintext');

		isDirty = false;
		if (saveBtn) saveBtn.disabled = true;

		// Highlight active file in tree.
		const treeEl = document.getElementById('ep-editor-tree');
		if (treeEl) {
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
	} catch (err) {
		if (statusEl) statusEl.textContent = 'Error: ' + err.message;
	}
}

/**
 * Monaco version pinned for CDN loading.
 * Update this when bumping the monaco-editor dependency.
 */
const MONACO_VERSION = '0.52.2';
const MONACO_CDN = `https://cdn.jsdelivr.net/npm/monaco-editor@${MONACO_VERSION}`;

let monacoModule = null;

async function loadMonaco() {
	if (monacoModule) return monacoModule;

	// Load Monaco from CDN — keeps it out of our Vite bundle entirely.
	monacoModule = await import(/* @vite-ignore */ `${MONACO_CDN}/+esm`);

	self.MonacoEnvironment = {
		getWorkerUrl(_, label) {
			// Monaco workers are loaded from the CDN as well.
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

async function initMonaco(content, language) {
	const container = document.getElementById('ep-editor-container');
	if (!container) return;

	const monaco = await loadMonaco();

	if (!editor) {
		editor = monaco.editor.create(container, {
			value: content,
			language: language,
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
			isDirty = true;
			const saveBtn = document.getElementById('ep-editor-save');
			if (saveBtn) saveBtn.disabled = false;
		});
	} else {
		editor.setValue(content);
		monaco.editor.setModelLanguage(editor.getModel(), language);
	}
}

async function saveCurrentFile(data) {
	if (!currentFile || !editor) return;

	const saveBtn = document.getElementById('ep-editor-save');
	const statusEl = document.getElementById('ep-editor-status');

	if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
	if (statusEl) statusEl.textContent = 'Saving...';

	try {
		const res = await fetch(data.fsFileUrl, {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.nonce,
			},
			body: JSON.stringify({
				path: currentFile,
				content: editor.getValue(),
			}),
		});
		const result = await res.json();

		if (res.ok && result.success) {
			isDirty = false;
			if (statusEl) {
				statusEl.textContent = 'Saved';
				setTimeout(() => { if (statusEl.textContent === 'Saved') statusEl.textContent = ''; }, 2000);
			}
			if (saveBtn) saveBtn.textContent = 'Save';
		} else {
			if (statusEl) statusEl.textContent = result.message || 'Save failed.';
			if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
		}
	} catch (err) {
		if (statusEl) statusEl.textContent = 'Error: ' + err.message;
		if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
	}
}
