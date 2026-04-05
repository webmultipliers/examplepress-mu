/**
 * Build — Troy connect modal: cloud vs custom server toggle.
 */
import { esc } from '../lib/dom.js';
import { closeAppModal } from '../lib/modal.js';
import { handleConnect, showAppsNotice } from './apps.js';

export function initTroyModal(data) {
	// Radio toggle for custom URL field.
	document.querySelectorAll('[name="ep-apps-troy-target"]').forEach(radio => {
		radio.addEventListener('change', () => {
			const customUrl = document.getElementById('ep-apps-troy-custom-url');
			if (customUrl) customUrl.style.display = radio.value === 'custom' && radio.checked ? '' : 'none';
		});
	});

	// Submit handler.
	const troySubmit = document.getElementById('ep-apps-troy-submit');
	if (troySubmit) {
		troySubmit.addEventListener('click', async () => {
			const slugEl = document.getElementById('ep-apps-troy-target-slug');
			const errEl = document.getElementById('ep-apps-troy-error');
			const slug = slugEl ? slugEl.value : '';
			const troyType = document.querySelector('[name="ep-apps-troy-target"]:checked')?.value || 'cloud';

			if (errEl) errEl.style.display = 'none';
			troySubmit.disabled = true;
			troySubmit.textContent = 'Connecting...';

			try {
				if (troyType === 'cloud') {
					closeAppModal('ep-apps-troy-modal');
					await handleConnect(slug);
				} else {
					const customUrl = document.getElementById('ep-apps-troy-custom-url')?.value || '';
					if (!customUrl) {
						if (errEl) { errEl.textContent = 'Please enter a Troy server URL.'; errEl.style.display = ''; }
						return;
					}
					const res = await fetch(`${data.appsBaseUrl}/${slug}/troy-bind`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
						body: JSON.stringify({ troy_type: 'custom', custom_url: customUrl }),
					});
					const result = await res.json();
					if (res.ok && result.success && result.redirect_url) {
						closeAppModal('ep-apps-troy-modal');
						window.open(result.redirect_url, '_blank');
						showAppsNotice(`Opened Troy scaffold page for <strong>${esc(slug)}</strong>. Complete setup in the new tab.`);
					} else {
						const msg = result.message || result.data?.message || 'Troy bind failed.';
						if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
					}
				}
			} catch (err) {
				if (errEl) { errEl.textContent = 'Network error: ' + err.message; errEl.style.display = ''; }
			} finally {
				troySubmit.disabled = false;
				troySubmit.textContent = 'Connect to Troy \u2192';
			}
		});
	}
}
