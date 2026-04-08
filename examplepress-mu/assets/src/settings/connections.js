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

	// Agent fields.
	const a = c.agent || {};
	const agentEnabledEl = document.getElementById('ep-agent-enabled');
	const agentProviderEl = document.getElementById('ep-agent-provider');
	const agentModelEl = document.getElementById('ep-agent-model');
	const agentKeyEl = document.getElementById('ep-agent-api-key');
	const agentRuntimeEl = document.getElementById('ep-agent-runtime-status');

	if (agentEnabledEl) agentEnabledEl.checked = !!a.enabled;
	if (agentProviderEl && a.provider) agentProviderEl.value = a.provider;
	if (agentProviderEl) {
		agentProviderEl.addEventListener('change', () => loadAgentModels(agentProviderEl.value));
	}
	loadAgentModels(a.provider || 'anthropic', a.model || '');
	if (agentKeyEl && a.hasKey) agentKeyEl.placeholder = CONFIGURED;
	if (agentRuntimeEl) {
		if (a.ready) {
			setStatus(agentRuntimeEl, '\u2713 Prism container ready', true);
		} else if (a.error) {
			setStatus(agentRuntimeEl, 'Runtime error: ' + a.error, false);
		} else if (a.enabled) {
			setStatus(agentRuntimeEl, 'Runtime not booted (run composer install).', false);
		} else {
			setStatus(agentRuntimeEl, 'Disabled', null);
		}
	}
}

// Cached providers payload so we don't refetch on every provider change.
let agentProvidersCache = null;

async function fetchAgentProviders() {
	if (agentProvidersCache) return agentProvidersCache;
	const url = (conn().agent && conn().agent.providersUrl) || '';
	if (!url) return null;
	try {
		const res = await fetch(url, { headers: { 'X-WP-Nonce': data().nonce } });
		agentProvidersCache = await res.json();
		return agentProvidersCache;
	} catch (_e) {
		return null;
	}
}

async function loadAgentModels(providerId, preferredModel) {
	const selectEl = document.getElementById('ep-agent-model');
	if (!selectEl) return;
	const payload = await fetchAgentProviders();
	if (!payload || !Array.isArray(payload.providers)) {
		selectEl.innerHTML = '<option value="">(no providers available)</option>';
		return;
	}
	const provider = payload.providers.find(p => p.id === providerId) || payload.providers[0];
	if (!provider) {
		selectEl.innerHTML = '<option value="">(unknown provider)</option>';
		return;
	}
	const target = preferredModel || provider.default || (provider.models[0] && provider.models[0].id);
	selectEl.innerHTML = provider.models.map(m =>
		`<option value="${m.id}"${m.id === target ? ' selected' : ''}>${m.label || m.id}</option>`
	).join('');
}

function testAgent(btn) {
	const statusEl = document.getElementById('ep-test-agent-status');
	btn.disabled = true;
	btn.textContent = 'Testing...';
	setStatus(statusEl, '', null);

	const url = (conn().agent && conn().agent.testUrl) || '';
	if (!url) {
		setStatus(statusEl, 'No test URL — save settings and reload first.', false);
		btn.disabled = false;
		btn.textContent = 'Test Agent';
		return;
	}
	post(url)
		.then(d => {
			if (d.success) {
				setStatus(statusEl, '\u2713 ' + (d.message || 'Ready'), true);
			} else {
				setStatus(statusEl, d.message || (d.data && d.data.message) || 'Test failed.', false);
			}
		})
		.catch(err => setStatus(statusEl, err.message || 'Network error.', false))
		.finally(() => { btn.disabled = false; btn.textContent = 'Test Agent'; });
}

function saveAgent(btn) {
	const statusEl = document.getElementById('ep-conn-status-agent');
	btn.disabled = true;
	btn.textContent = 'Saving...';
	setStatus(statusEl, '', null);

	const enabled = document.getElementById('ep-agent-enabled')?.checked || false;
	const provider = document.getElementById('ep-agent-provider')?.value || 'anthropic';
	const model = document.getElementById('ep-agent-model')?.value?.trim() || '';
	const key = document.getElementById('ep-agent-api-key')?.value?.trim() || '';

	const body = { agent_enabled: enabled, agent_provider: provider };
	if (model) body.agent_model = model;
	if (key) body.agent_api_key = key;

	post(data().connectionsUrl, body)
		.then(res => {
			if (res.success) {
				setStatus(statusEl, '\u2713 Saved (reload to re-boot agent runtime)', true);
				const keyEl = document.getElementById('ep-agent-api-key');
				if (key && keyEl) { keyEl.value = ''; keyEl.placeholder = CONFIGURED; }
			} else {
				setStatus(statusEl, res.message || 'Save failed.', false);
			}
		})
		.catch(err => setStatus(statusEl, err.message || 'Network error.', false))
		.finally(() => { btn.disabled = false; btn.textContent = 'Save Agent Settings'; });
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

	const saveAgentBtn = document.getElementById('ep-conn-save-btn-agent');
	if (saveAgentBtn) saveAgentBtn.addEventListener('click', () => saveAgent(saveAgentBtn));

	const testAgentBtn = document.getElementById('ep-test-agent-btn');
	if (testAgentBtn) testAgentBtn.addEventListener('click', () => testAgent(testAgentBtn));
}
