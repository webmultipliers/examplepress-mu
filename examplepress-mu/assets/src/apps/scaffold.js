/**
 * Build — Scaffold modal: new app creation with step indicators.
 */
import { esc } from '../lib/dom.js';
import { openAppModal, closeAppModal } from '../lib/modal.js';
import { $connections } from '../stores/connections.js';
import { addApp } from '../stores/apps.js';
import { showAppsNotice, renderAppsTable } from './apps.js';

export function renderScaffoldSteps(results) {
	const stepsEl = document.getElementById('ep-scaffold-steps');
	if (!stepsEl) return;

	const conn = $connections.get();
	const hasGithub = conn.hasGithubApp || conn.hasGithubPat;
	const githubSkip = !hasGithub ? 'Install GitHub App or configure a write token' : '';

	const steps = [
		{ label: 'Scaffold plugin locally', ok: true, key: 'scaffold' },
		{ label: 'Create GitHub repo', ok: hasGithub, skip: githubSkip, key: 'template_create' },
		{ label: 'Replace placeholders', ok: hasGithub, skip: githubSkip, key: 'placeholder_replace' },
		{ label: 'Tag initial release (v0.0.0)', ok: hasGithub, skip: githubSkip, key: 'initial_release' },
	];

	stepsEl.innerHTML = steps.map(s => {
		if (results && s.key in results) {
			const val = results[s.key];
			if (val === true) {
				return `<div class="ep-scaffold-step ep-scaffold-step-ok"><span class="ep-scaffold-step-icon">&#10003;</span> ${s.label}</div>`;
			}
			if (val === false) {
				return `<div class="ep-scaffold-step ep-scaffold-step-fail"><span class="ep-scaffold-step-icon">&#10007;</span> ${s.label}</div>`;
			}
			return `<div class="ep-scaffold-step ep-scaffold-step-skip"><span class="ep-scaffold-step-icon">&mdash;</span> ${s.label} <span class="ep-scaffold-step-reason">Skipped</span></div>`;
		}
		if (s.ok) {
			return `<div class="ep-scaffold-step ep-scaffold-step-ok"><span class="ep-scaffold-step-icon">&#10003;</span> ${s.label}</div>`;
		}
		return `<div class="ep-scaffold-step ep-scaffold-step-skip"><span class="ep-scaffold-step-icon">&#9888;</span> ${s.label} <span class="ep-scaffold-step-reason">${s.skip}</span></div>`;
	}).join('');
}

export function initScaffold(data) {
	const appsNewBtn = document.getElementById('ep-apps-new-btn');
	if (appsNewBtn) {
		appsNewBtn.addEventListener('click', () => {
			document.getElementById('ep-apps-scaffold-name').value = '';
			document.getElementById('ep-apps-scaffold-desc').value = '';
			const errEl = document.getElementById('ep-apps-scaffold-error');
			if (errEl) errEl.style.display = 'none';
			const warnEl = document.getElementById('ep-apps-scaffold-warnings');
			if (warnEl) { warnEl.style.display = 'none'; warnEl.innerHTML = ''; }
			renderScaffoldSteps();
			openAppModal('ep-apps-scaffold-modal');
			setTimeout(() => document.getElementById('ep-apps-scaffold-name')?.focus(), 80);
		});
	}

	const scaffoldSubmit = document.getElementById('ep-apps-scaffold-submit');
	if (scaffoldSubmit) {
		scaffoldSubmit.addEventListener('click', async () => {
			const nameInput = document.getElementById('ep-apps-scaffold-name');
			const descInput = document.getElementById('ep-apps-scaffold-desc');
			const errEl = document.getElementById('ep-apps-scaffold-error');
			const warnEl = document.getElementById('ep-apps-scaffold-warnings');
			const name = nameInput.value.trim();
			const desc = descInput.value.trim();

			if (!name) {
				if (errEl) { errEl.textContent = 'App name is required.'; errEl.style.display = ''; }
				return;
			}

			scaffoldSubmit.disabled = true;
			scaffoldSubmit.textContent = 'Creating...';
			if (errEl) errEl.style.display = 'none';
			if (warnEl) { warnEl.style.display = 'none'; warnEl.innerHTML = ''; }

			try {
				const res = await fetch(data.appsScaffoldUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
					body: JSON.stringify({ name, description: desc }),
				});
				const result = await res.json();

				if (res.ok && result.success) {
					if (result.app) {
						addApp(result.app);
						renderAppsTable();
					}
					if (result.steps) renderScaffoldSteps(result.steps);

					if (result.warnings && result.warnings.length) {
						if (warnEl) {
							warnEl.innerHTML = result.warnings.map(w => `<div class="ep-scaffold-warning">${esc(w)}</div>`).join('');
							warnEl.style.display = '';
						}
						showAppsNotice(`App <strong>${esc(name)}</strong> created with ${result.warnings.length} warning(s). Check the modal for details.`);
					} else {
						closeAppModal('ep-apps-scaffold-modal');
						let noticeHtml = `App <strong>${esc(name)}</strong> provisioned.`;
						if (result.app) {
							noticeHtml = `App <strong>${esc(name)}</strong> created at <code>wp-content/plugins/${esc(result.app.slug)}/</code>.`;
						} else if (result.codespaces_url) {
							noticeHtml += ` <a href="${esc(result.codespaces_url)}" target="_blank" rel="noopener">Open in Codespaces &rarr;</a>`;
						}
						showAppsNotice(noticeHtml);
					}
				} else {
					const msg = result.message || result.data?.message || 'Scaffold failed.';
					if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
				}
			} catch (err) {
				if (errEl) { errEl.textContent = 'Network error: ' + err.message; errEl.style.display = ''; }
			} finally {
				scaffoldSubmit.disabled = false;
				scaffoldSubmit.textContent = 'Create App';
			}
		});
	}
}
