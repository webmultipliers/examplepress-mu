/**
 * Theme — Config tab: file switcher with JSON syntax highlighting.
 */
import { esc, highlightJson } from '../lib/dom.js';
import { setUrlParams } from '../lib/url.js';

export function renderConfig(configFiles, urlParams) {
	const configSwitcher = document.getElementById('config-switcher');
	const configViewer = document.getElementById('config-viewer');
	if (!configSwitcher || !configViewer || !configFiles) return;

	const files = Object.keys(configFiles);
	let activeFile = files[0] || null;

	function renderFile(name) {
		activeFile = name;
		configSwitcher.querySelectorAll('.ep-config-tab').forEach(t =>
			t.classList.toggle('active', t.dataset.file === name)
		);
		configViewer.innerHTML = `<div class="ep-json-block">
			<div class="ep-json-header"><span class="ep-json-filename">${esc(name)}</span><span class="ep-json-badge">read-only</span></div>
			<pre class="ep-json-body">${highlightJson(configFiles[name])}</pre>
		</div>`;
		setUrlParams('config', name);
	}

	files.forEach(f => {
		const btn = document.createElement('button');
		btn.className = 'ep-config-tab';
		btn.textContent = f;
		btn.dataset.file = f;
		btn.addEventListener('click', () => renderFile(f));
		configSwitcher.appendChild(btn);
	});

	// Restore section from URL.
	const initSection = urlParams.tab === 'config' && urlParams.section;
	if (initSection && files.includes(initSection)) {
		renderFile(initSection);
	} else if (activeFile) {
		renderFile(activeFile);
	}
}
