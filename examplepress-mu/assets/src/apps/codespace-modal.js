/**
 * Build — Codespace launch modal.
 */
import { openAppModal } from '../lib/modal.js';

export function handleCodespaceOpen(repoId) {
	const url = `https://github.com/codespaces/new?hide_repo_select=true&repo=${repoId}`;
	const urlEl = document.getElementById('ep-apps-codespace-url');
	if (urlEl) {
		urlEl.innerHTML = '';
		const link = document.createElement('a');
		link.href = url;
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = url;
		urlEl.appendChild(link);
	}
	openAppModal('ep-apps-codespace-modal');
	window.open(url, '_blank');
}
