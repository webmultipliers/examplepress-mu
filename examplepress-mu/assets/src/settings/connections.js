/**
 * Build — Connections tab: form hydration, save, test, and auth callbacks.
 */
import { $connections, updateConnections } from '../stores/connections.js';

const data = () => window.ExamplePressData;
const conn = () => data().connections;

const SUCCESS_COLOR = '#006414';
const ERROR_COLOR = '#9b2c2c';
const CONFIGURED = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022  (configured)';

// ── Helpers ──────────────────────────────────────────────────────

function setStatus(el, text, ok) {
	if (!el) return;
	el.textContent = text;
	el.style.color = ok ? SUCCESS_COLOR : (ok === false ? ERROR_COLOR : '');
}

function post(url, body) {
	return fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data().nonce },
		body: body ? JSON.stringify(body) : undefined,
	}).then(r => r.json());
}

// ── Hydrate form fields from saved state ─────────────────────────

function hydrateForm() {
	const c = conn();
	const orgEl = document.getElementById('ep-conn-github-org');
	const templateEl = document.getElementById('ep-conn-app-template');
	const troyUrlEl = document.getElementById('ep-conn-troy-url');
	const patEl = document.getElementById('ep-conn-github-pat');
	const troyPatEl = document.getElementById('ep-conn-troy-github-pat');
	const troyStatus = document.getElementById('ep-troy-auth-status');
	const ghAppStatus = document.getElementById('ep-github-app-status');

	if (orgEl && c.githubOrg) orgEl.value = c.githubOrg;
	if (templateEl && c.appTemplateRepo) templateEl.value = c.appTemplateRepo;
	if (troyUrlEl && c.troyServerUrl) troyUrlEl.value = c.troyServerUrl;
	if (patEl && c.hasGithubPat) patEl.placeholder = CONFIGURED;
	if (troyPatEl && c.hasTroyGithubPat) troyPatEl.placeholder = CONFIGURED;
	if (c.hasTroyCreds) setStatus(troyStatus, '\u2713 Authorized', true);
	if (c.hasGithubApp) setStatus(ghAppStatus, '\u2713 Installed', true);
}

// ── Save Connections ─────────────────────────────────────────────

function saveConnections(btn) {
	const statusEl = document.getElementById('ep-conn-status');
	btn.disabled = true;
	btn.textContent = 'Saving...';
	setStatus(statusEl, '', null);

	const patVal = (document.getElementById('ep-conn-github-pat') || {}).value?.trim() || '';
	const orgVal = (document.getElementById('ep-conn-github-org') || {}).value?.trim() || '';
	const templateVal = (document.getElementById('ep-conn-app-template') || {}).value?.trim() || '';
	const troyUrl = (document.getElementById('ep-conn-troy-url') || {}).value?.trim() || '';
	const troyPat = (document.getElementById('ep-conn-troy-github-pat') || {}).value?.trim() || '';

	const body = {};
	if (patVal) body.github_pat = patVal;
	if (orgVal) body.github_org = orgVal;
	if (templateVal) body.app_template_repo = templateVal;
	if (troyUrl) body.troy_server_url = troyUrl;
	if (troyPat) body.troy_github_pat = troyPat;

	post(data().connectionsUrl, body)
		.then(res => {
			if (res.success) {
				setStatus(statusEl, '\u2713 Saved', true);
				const patEl = document.getElementById('ep-conn-github-pat');
				const troyPatEl = document.getElementById('ep-conn-troy-github-pat');
				if (patVal && patEl) { patEl.value = ''; patEl.placeholder = CONFIGURED; }
				if (troyPat && troyPatEl) { troyPatEl.value = ''; troyPatEl.placeholder = CONFIGURED; }
				updateConnections({
					hasGithubPat: conn().hasGithubPat || !!patVal,
					hasTroyGithubPat: conn().hasTroyGithubPat || !!troyPat,
					...(orgVal && { githubOrg: orgVal }),
					...(troyUrl && { troyServerUrl: troyUrl }),
				});
			} else {
				setStatus(statusEl, res.message || 'Save failed.', false);
			}
		})
		.catch(err => setStatus(statusEl, err.message || 'Network error.', false))
		.finally(() => { btn.disabled = false; btn.textContent = 'Save Connections'; });
}

// ── GitHub App Install ───────────────────────────────────────────

function githubAppInstall(btn) {
	const statusEl = document.getElementById('ep-github-app-status');
	const orgVal = (document.getElementById('ep-conn-github-org') || {}).value || '';
	const slug = conn().githubAppSlug;
	const installUrl = 'https://github.com/apps/' + slug + '/installations/new';

	btn.disabled = true;
	btn.textContent = 'Waiting...';
	setStatus(statusEl, 'Complete installation on GitHub...', null);

	const popup = window.open(installUrl, 'ep_github_app', 'width=700,height=800');
	if (!popup) {
		setStatus(statusEl, 'Popup blocked.', false);
		btn.disabled = false;
		btn.textContent = 'Install GitHub App';
		return;
	}

	// Save org in background.
	if (orgVal.trim() && data().connectionsUrl) {
		post(data().connectionsUrl, { github_org: orgVal.trim() });
	}

	function onMsg(e) {
		if (e.origin !== window.location.origin) return;
		if (!e.data || typeof e.data.success === 'undefined') return;
		window.removeEventListener('message', onMsg);
		btn.disabled = false;
		btn.textContent = 'Install GitHub App';
		if (e.data.success) {
			setStatus(statusEl, '\u2713 Installed', true);
			updateConnections({ hasGithubApp: true });
		} else {
			setStatus(statusEl, e.data.message || 'Failed.', false);
		}
	}
	window.addEventListener('message', onMsg);

	const t = setInterval(() => {
		if (popup.closed) { clearInterval(t); btn.disabled = false; btn.textContent = 'Install GitHub App'; }
	}, 500);
}

// ── Troy Auth ────────────────────────────────────────────────────

function troyAuth(btn) {
	const statusEl = document.getElementById('ep-troy-auth-status');
	const urlInput = document.getElementById('ep-conn-troy-url');
	const troyUrl = urlInput ? urlInput.value.trim() : '';

	if (!troyUrl) {
		setStatus(statusEl, 'Enter a Troy Server URL first.', false);
		return;
	}

	const troy = troyUrl.replace(/\/+$/, '');
	const adminUrl = data().adminUrl.replace(/\/$/, '') + '/admin.php';
	const successUrl = adminUrl + '?page=examplepress-settings&ep_troy_auth_cb=1';
	const rejectUrl = adminUrl + '?page=examplepress-settings&ep_troy_auth_cb=rejected';
	const siteName = window.location.hostname;
	const authUrl = troy + '/wp-admin/authorize-application.php'
		+ '?app_name=' + encodeURIComponent('ExamplePress (' + siteName + ')')
		+ '&app_id=f47ac10b-58cc-4372-a567-0e02b2c3d479'
		+ '&success_url=' + encodeURIComponent(successUrl)
		+ '&reject_url=' + encodeURIComponent(rejectUrl);

	btn.disabled = true;
	btn.textContent = 'Waiting...';
	setStatus(statusEl, 'Complete authorization in the popup...', null);

	const popup = window.open(authUrl, 'ep_troy_auth', 'width=600,height=700');
	if (!popup) {
		setStatus(statusEl, 'Popup blocked \u2014 allow popups for this site.', false);
		btn.disabled = false;
		btn.textContent = 'Authorize with Troy';
		return;
	}

	if (data().connectionsUrl) {
		post(data().connectionsUrl, { troy_server_url: troyUrl });
	}

	function onMsg(e) {
		if (e.origin !== window.location.origin) return;
		if (!e.data || typeof e.data.success === 'undefined') return;
		window.removeEventListener('message', onMsg);
		btn.disabled = false;
		btn.textContent = 'Authorize with Troy';
		if (e.data.success) {
			setStatus(statusEl, '\u2713 Authorized', true);
			updateConnections({ hasTroyCreds: true });
		} else {
			setStatus(statusEl, e.data.message || 'Failed.', false);
		}
	}
	window.addEventListener('message', onMsg);

	const t = setInterval(() => {
		if (popup.closed) { clearInterval(t); btn.disabled = false; btn.textContent = 'Authorize with Troy'; }
	}, 500);
}

// ── Test Connections ─────────────────────────────────────────────

function testGithub(btn) {
	const s = document.getElementById('ep-test-github-status');
	btn.disabled = true;
	btn.textContent = 'Testing...';
	setStatus(s, '', null);

	post(conn().testGithubUrl)
		.then(d => {
			const parts = [];
			if (d.checks) {
				if (d.checks.write) parts.push('Write: ' + d.checks.write.message);
				if (d.checks.read) parts.push('Read: ' + d.checks.read.message);
			}
			setStatus(s, parts.join(' | ') || d.message, d.success);
		})
		.catch(() => setStatus(s, 'Network error.', false))
		.finally(() => { btn.disabled = false; btn.textContent = 'Test GitHub'; });
}

function testTroy(btn) {
	const s = document.getElementById('ep-test-troy-status');
	btn.disabled = true;
	btn.textContent = 'Testing...';
	setStatus(s, '', null);

	post(conn().testTroyUrl)
		.then(d => {
			const parts = [];
			if (d.checks) {
				if (d.checks.url) parts.push('URL: ' + d.checks.url.message);
				if (d.checks.auth) parts.push('Auth: ' + d.checks.auth.message);
			}
			setStatus(s, parts.join(' | ') || d.message, d.success);
		})
		.catch(() => setStatus(s, 'Network error.', false))
		.finally(() => { btn.disabled = false; btn.textContent = 'Test Troy'; });
}

// ── Init ─────────────────────────────────────────────────────────

export function initConnectionsUI() {
	hydrateForm();

	// Bind buttons.
	const saveBtn = document.getElementById('ep-conn-save-btn');
	const ghAppBtn = document.getElementById('ep-conn-github-app-btn');
	const troyAuthBtn = document.getElementById('ep-conn-troy-auth-btn');
	const testGhBtn = document.getElementById('ep-test-github-btn');
	const testTroyBtn = document.getElementById('ep-test-troy-btn');

	if (saveBtn) saveBtn.addEventListener('click', () => saveConnections(saveBtn));
	if (ghAppBtn) ghAppBtn.addEventListener('click', () => githubAppInstall(ghAppBtn));
	if (troyAuthBtn) troyAuthBtn.addEventListener('click', () => troyAuth(troyAuthBtn));
	if (testGhBtn) testGhBtn.addEventListener('click', () => testGithub(testGhBtn));
	if (testTroyBtn) testTroyBtn.addEventListener('click', () => testTroy(testTroyBtn));
}
