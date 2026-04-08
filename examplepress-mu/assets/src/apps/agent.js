/**
 * Generative UI Agent — Apps page module.
 *
 * Wires:
 *   - "✨ Generate with AI" button → ep-agent-modal → POST /agent/generate
 *     → poll → STEP_REVIEW pause → preview pane → Push (commit) or Discard
 *   - Per-app "✨ Iterate" affordance → ep-agent-iterate-modal (chat thread)
 *     → POST /agent/iterate/{slug} → same draft/commit dance
 *   - Eject → separate ep-agent-eject-modal with type-to-confirm
 *   - Jobs & History button → ep-agent-jobs-modal
 *   - Discoverability: button shows DISABLED when feature is enabled but
 *     not configured (vs hidden entirely).
 *   - Runtime errors surfaced inline on the Apps page when boot failed.
 */

import { apiFetch } from '../lib/api.js';
import { openAppModal, closeAppModal } from '../lib/modal.js';
import { log } from '../lib/logger.js';

const POLL_INTERVAL_MS = 1500;
const POLL_TIMEOUT_MS  = 5 * 60 * 1000;

const STEP_LABELS = {
	queued:          'Queued…',
	drafting:        'Drafting blueprint…',
	writing_code:    'Writing code…',
	awaiting_review: 'Awaiting your review',
	pushing:         'Pushing to GitHub…',
	done:            'Done',
	failed:          'Failed',
};

const STEP_ORDER = ['queued', 'drafting', 'writing_code', 'awaiting_review', 'pushing', 'done'];

let appData = null;

// ── Init ────────────────────────────────────────────────────────

export function initAgent(data) {
	appData = data;
	const agent = data.agent || {};

	const enabledBtn  = document.getElementById('ep-apps-generate-btn');
	const disabledBtn = document.getElementById('ep-apps-generate-btn-disabled');
	const jobsBtn     = document.getElementById('ep-apps-jobs-btn');
	const warningEl   = document.getElementById('ep-agent-runtime-warning');

	if (enabledBtn && disabledBtn && jobsBtn) {
		if (agent.enabled && agent.ready && agent.configured) {
			enabledBtn.style.display = '';
			jobsBtn.style.display = '';
		} else if (agent.enabled) {
			// Feature is on but provider/key not configured — DISCOVERABILITY
			// surface vs hiding entirely.
			disabledBtn.style.display = '';
			disabledBtn.addEventListener('click', () => {
				if (data.adminUrl) {
					window.location.href = data.adminUrl + 'admin.php?page=examplepress-settings#agent';
				}
			});
		}
	}

	// Surface runtime errors (PrismContainer boot failed) on the Apps page
	// instead of silently hiding the button. Without this the user has zero
	// signal that the feature is broken.
	if (warningEl && agent.enabled && !agent.ready && agent.error) {
		warningEl.style.display = '';
		warningEl.textContent = '⚠ Generative UI Agent runtime failed to boot: ' + agent.error;
	}

	bindGenerate();
	bindIterate();
	bindEject();
	bindJobs();

	// Per-row "✨ Iterate" links rendered by apps.js
	document.addEventListener('click', (e) => {
		const target = e.target.closest('[data-agent-iterate]');
		if (!target) return;
		e.preventDefault();
		openIterateModal(target.dataset.agentIterate);
	});
}

// ── Generate (new app) ──────────────────────────────────────────

function bindGenerate() {
	const generateBtn = document.getElementById('ep-apps-generate-btn');
	if (generateBtn) {
		generateBtn.addEventListener('click', () => {
			resetGenerateModal();
			setModelBadge('ep-agent-model-badge');
			openAppModal('ep-agent-modal');
		});
	}
	document.getElementById('ep-agent-submit')?.addEventListener('click', onGenerateSubmit);
	document.getElementById('ep-agent-commit-btn')?.addEventListener('click', onGenerateCommit);
	document.getElementById('ep-agent-discard-btn')?.addEventListener('click', onGenerateDiscard);
	document.getElementById('ep-agent-retry-btn')?.addEventListener('click', onGenerateRetry);
}

let currentGenerateJobId = null;

function resetGenerateModal() {
	currentGenerateJobId = null;
	const promptEl = document.getElementById('ep-agent-prompt');
	if (promptEl && !promptEl.dataset.preserved) promptEl.value = '';
	delete promptEl?.dataset.preserved;
	hide(document.getElementById('ep-agent-error'));
	hide(document.getElementById('ep-agent-draft-preview'));
	hide(document.getElementById('ep-agent-commit-btn'));
	hide(document.getElementById('ep-agent-discard-btn'));
	hide(document.getElementById('ep-agent-retry-btn'));
	const submit = document.getElementById('ep-agent-submit');
	if (submit) { submit.style.display = ''; submit.disabled = false; submit.textContent = 'Generate Draft'; }
	document.getElementById('ep-agent-steps').innerHTML = '';
	document.getElementById('ep-agent-draft-files').innerHTML = '';
	document.getElementById('ep-agent-draft-file-viewer').style.display = 'none';
}

async function onGenerateSubmit() {
	const promptEl = document.getElementById('ep-agent-prompt');
	const errorEl  = document.getElementById('ep-agent-error');
	const stepsEl  = document.getElementById('ep-agent-steps');
	const submitEl = document.getElementById('ep-agent-submit');

	const prompt = (promptEl?.value || '').trim();
	hide(errorEl);

	if (prompt.length < 5) {
		showError(errorEl, 'Prompt must be at least 5 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Drafting…';
	renderStep(stepsEl, 'queued');
	hide(document.getElementById('ep-agent-retry-btn'));

	try {
		const res = await apiFetch(appData.agentGenerateUrl, { method: 'POST', body: { prompt } });
		currentGenerateJobId = res.job_id;

		const job = await pollJob(res.job_id, (state) => renderStep(stepsEl, state.step));

		if (job.status === 'drafted') {
			renderDraftPreview(job, 'ep-agent');
		} else if (job.status === 'success') {
			renderStep(stepsEl, 'done');
			closeAppModal('ep-agent-modal');
			refreshAppsTable();
		} else {
			showError(errorEl, (job.errors || []).join(' ') || 'Generation failed.');
			renderStep(stepsEl, 'failed');
			showRetry('ep-agent', prompt);
		}
	} catch (err) {
		log.error('[agent] generate failed', err);
		showError(errorEl, err.message || 'Generation failed.');
		renderStep(stepsEl, 'failed');
		showRetry('ep-agent', prompt);
	} finally {
		submitEl.disabled = false;
		submitEl.textContent = 'Generate Draft';
	}
}

async function onGenerateCommit() {
	if (!currentGenerateJobId) return;
	const errorEl = document.getElementById('ep-agent-error');
	const stepsEl = document.getElementById('ep-agent-steps');
	const commitBtn = document.getElementById('ep-agent-commit-btn');
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		const url = appData.agentJobCommitUrl.replace('__ID__', encodeURIComponent(currentGenerateJobId));
		const res = await apiFetch(url, { method: 'POST' });
		if (res.success) {
			renderStep(stepsEl, 'done');
			setTimeout(() => { closeAppModal('ep-agent-modal'); refreshAppsTable(); }, 800);
		} else {
			showError(errorEl, res.message || 'Push failed.');
			commitBtn.disabled = false;
			commitBtn.textContent = 'Push to GitHub';
		}
	} catch (err) {
		showError(errorEl, err.message || 'Push failed.');
		commitBtn.disabled = false;
		commitBtn.textContent = 'Push to GitHub';
	}
}

async function onGenerateDiscard() {
	if (!currentGenerateJobId) return;
	if (!confirm('Discard this draft? It will be deleted with no GitHub side effects.')) return;
	try {
		const url = appData.agentJobDiscardUrl.replace('__ID__', encodeURIComponent(currentGenerateJobId));
		await apiFetch(url, { method: 'POST' });
		closeAppModal('ep-agent-modal');
	} catch (err) {
		showError(document.getElementById('ep-agent-error'), err.message || 'Discard failed.');
	}
}

function onGenerateRetry() {
	const promptEl = document.getElementById('ep-agent-prompt');
	if (promptEl) promptEl.dataset.preserved = '1';
	hide(document.getElementById('ep-agent-retry-btn'));
	onGenerateSubmit();
}

// ── Iterate (chat thread) ───────────────────────────────────────

function bindIterate() {
	document.getElementById('ep-agent-iterate-submit')?.addEventListener('click', onIterateSubmit);
	document.getElementById('ep-agent-iterate-commit-btn')?.addEventListener('click', onIterateCommit);
	document.getElementById('ep-agent-iterate-discard-btn')?.addEventListener('click', onIterateDiscard);
	document.getElementById('ep-agent-iterate-eject-btn')?.addEventListener('click', () => {
		const slug = document.getElementById('ep-agent-iterate-target-slug')?.value;
		if (slug) openEjectModal(slug);
	});
}

let currentIterateJobId = null;

async function openIterateModal(slug) {
	document.getElementById('ep-agent-iterate-target-slug').value = slug;
	document.getElementById('ep-agent-iterate-slug').textContent = slug;
	document.getElementById('ep-agent-iterate-prompt').value = '';
	document.getElementById('ep-agent-iterate-current-job-id').value = '';
	currentIterateJobId = null;
	hide(document.getElementById('ep-agent-iterate-error'));
	hide(document.getElementById('ep-agent-iterate-draft-preview'));
	hide(document.getElementById('ep-agent-iterate-commit-btn'));
	hide(document.getElementById('ep-agent-iterate-discard-btn'));
	document.getElementById('ep-agent-iterate-steps').innerHTML = '';
	document.getElementById('ep-agent-iterate-draft-files').innerHTML = '';
	setModelBadge('ep-agent-iterate-model-badge');
	openAppModal('ep-agent-iterate-modal');

	// Load existing chat thread for this app.
	const threadEl = document.getElementById('ep-agent-chat-thread');
	threadEl.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:12px;padding:20px;">Loading history…</div>';
	try {
		const url = appData.agentJobsForSlugUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url);
		renderChatThread(res.jobs || []);
	} catch (err) {
		threadEl.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:12px;padding:20px;">No history yet — your first message will start the thread.</div>';
	}

	// Estimate the context size hint by looking at how many files the
	// app currently has.
	const app = (appData.apps || []).find(a => a.slug === slug);
	const hint = document.getElementById('ep-agent-iterate-context-hint');
	if (hint && app) {
		hint.textContent = 'Iteration sends the current repo tree as context.';
	}
}

function renderChatThread(jobs) {
	const threadEl = document.getElementById('ep-agent-chat-thread');
	if (!jobs.length) {
		threadEl.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:12px;padding:20px;">No history yet — your first message will start the thread.</div>';
		return;
	}

	// Newest at bottom. Backend returns newest-first; reverse for chat order.
	const ordered = jobs.slice().reverse();
	threadEl.innerHTML = ordered.map(j => {
		const time = new Date((j.created_at || 0) * 1000).toLocaleString();
		const userBubble = `<div style="display:flex;justify-content:flex-end;margin-bottom:8px;"><div style="max-width:75%;background:#7c3aed;color:white;padding:8px 12px;border-radius:12px 12px 2px 12px;font-size:13px;"><div>${escapeHtml(j.prompt || '')}</div><div style="font-size:10px;opacity:0.7;margin-top:4px;">${escapeHtml(time)}</div></div></div>`;

		let agentBubble = '';
		if (j.status === 'success') {
			const v = j.result?.version || '';
			agentBubble = `<div style="display:flex;margin-bottom:14px;"><div style="max-width:75%;background:white;border:1px solid #e5e7eb;padding:8px 12px;border-radius:12px 12px 12px 2px;font-size:13px;">✓ Released <strong>v${escapeHtml(v)}</strong></div></div>`;
		} else if (j.status === 'failed') {
			agentBubble = `<div style="display:flex;margin-bottom:14px;"><div style="max-width:75%;background:#fef2f2;border:1px solid #fecaca;color:#9b2c2c;padding:8px 12px;border-radius:12px 12px 12px 2px;font-size:13px;">✗ ${escapeHtml((j.errors || []).join(' ') || 'Failed')}</div></div>`;
		} else if (j.status === 'drafted') {
			agentBubble = `<div style="display:flex;margin-bottom:14px;"><div style="max-width:75%;background:#fefce8;border:1px solid #fde047;padding:8px 12px;border-radius:12px 12px 12px 2px;font-size:13px;">⏸ Draft awaiting review</div></div>`;
		} else if (j.status === 'running' || j.status === 'pending') {
			agentBubble = `<div style="display:flex;margin-bottom:14px;"><div style="max-width:75%;background:white;border:1px solid #e5e7eb;padding:8px 12px;border-radius:12px 12px 12px 2px;font-size:13px;color:#6b7280;">${escapeHtml(STEP_LABELS[j.step] || j.step)}</div></div>`;
		}

		return userBubble + agentBubble;
	}).join('');

	threadEl.scrollTop = threadEl.scrollHeight;
}

async function onIterateSubmit() {
	const slug = document.getElementById('ep-agent-iterate-target-slug').value;
	const promptEl = document.getElementById('ep-agent-iterate-prompt');
	const prompt = (promptEl?.value || '').trim();
	const errorEl = document.getElementById('ep-agent-iterate-error');
	const stepsEl = document.getElementById('ep-agent-iterate-steps');
	const submitEl = document.getElementById('ep-agent-iterate-submit');

	hide(errorEl);
	hide(document.getElementById('ep-agent-iterate-draft-preview'));
	hide(document.getElementById('ep-agent-iterate-commit-btn'));
	hide(document.getElementById('ep-agent-iterate-discard-btn'));

	if (prompt.length < 5) {
		showError(errorEl, 'Change request must be at least 5 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Drafting…';
	renderStep(stepsEl, 'queued');

	// Optimistically append the user bubble to the thread immediately.
	const threadEl = document.getElementById('ep-agent-chat-thread');
	const optimistic = document.createElement('div');
	optimistic.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:8px;"><div style="max-width:75%;background:#7c3aed;color:white;padding:8px 12px;border-radius:12px 12px 2px 12px;font-size:13px;">${escapeHtml(prompt)}</div></div>`;
	threadEl.appendChild(optimistic.firstChild);
	threadEl.scrollTop = threadEl.scrollHeight;

	try {
		const url = appData.agentIterateUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url, { method: 'POST', body: { prompt } });
		currentIterateJobId = res.job_id;
		document.getElementById('ep-agent-iterate-current-job-id').value = res.job_id;

		const job = await pollJob(res.job_id, (state) => renderStep(stepsEl, state.step));

		if (job.status === 'drafted') {
			renderDraftPreview(job, 'ep-agent-iterate');
			promptEl.value = '';
		} else if (job.status === 'success') {
			renderStep(stepsEl, 'done');
			promptEl.value = '';
			// Refresh the thread to show the success bubble.
			const refreshUrl = appData.agentJobsForSlugUrl.replace('__SLUG__', encodeURIComponent(slug));
			const refreshed = await apiFetch(refreshUrl);
			renderChatThread(refreshed.jobs || []);
			refreshAppsTable();
		} else {
			showError(errorEl, (job.errors || []).join(' ') || 'Iteration failed.');
			renderStep(stepsEl, 'failed');
		}
	} catch (err) {
		log.error('[agent] iterate failed', err);
		showError(errorEl, err.message || 'Iteration failed.');
		renderStep(stepsEl, 'failed');
	} finally {
		submitEl.disabled = false;
		submitEl.textContent = 'Send';
	}
}

async function onIterateCommit() {
	if (!currentIterateJobId) return;
	const slug = document.getElementById('ep-agent-iterate-target-slug').value;
	const errorEl = document.getElementById('ep-agent-iterate-error');
	const stepsEl = document.getElementById('ep-agent-iterate-steps');
	const commitBtn = document.getElementById('ep-agent-iterate-commit-btn');
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		const url = appData.agentJobCommitUrl.replace('__ID__', encodeURIComponent(currentIterateJobId));
		const res = await apiFetch(url, { method: 'POST' });
		if (res.success) {
			renderStep(stepsEl, 'done');
			hide(document.getElementById('ep-agent-iterate-draft-preview'));
			hide(commitBtn);
			hide(document.getElementById('ep-agent-iterate-discard-btn'));
			currentIterateJobId = null;
			// Refresh chat
			const refreshUrl = appData.agentJobsForSlugUrl.replace('__SLUG__', encodeURIComponent(slug));
			const refreshed = await apiFetch(refreshUrl);
			renderChatThread(refreshed.jobs || []);
			refreshAppsTable();
		} else {
			showError(errorEl, res.message || 'Push failed.');
		}
	} catch (err) {
		showError(errorEl, err.message || 'Push failed.');
	} finally {
		commitBtn.disabled = false;
		commitBtn.textContent = 'Push to GitHub';
	}
}

async function onIterateDiscard() {
	if (!currentIterateJobId) return;
	if (!confirm('Discard this draft?')) return;
	const slug = document.getElementById('ep-agent-iterate-target-slug').value;
	try {
		const url = appData.agentJobDiscardUrl.replace('__ID__', encodeURIComponent(currentIterateJobId));
		await apiFetch(url, { method: 'POST' });
		hide(document.getElementById('ep-agent-iterate-draft-preview'));
		hide(document.getElementById('ep-agent-iterate-commit-btn'));
		hide(document.getElementById('ep-agent-iterate-discard-btn'));
		currentIterateJobId = null;
		// Refresh chat to drop the discarded draft
		const refreshUrl = appData.agentJobsForSlugUrl.replace('__SLUG__', encodeURIComponent(slug));
		const refreshed = await apiFetch(refreshUrl);
		renderChatThread(refreshed.jobs || []);
	} catch (err) {
		showError(document.getElementById('ep-agent-iterate-error'), err.message || 'Discard failed.');
	}
}

// ── Eject (separate hardened modal) ─────────────────────────────

function bindEject() {
	document.getElementById('ep-agent-eject-confirm')?.addEventListener('input', (e) => {
		const expected = document.getElementById('ep-agent-eject-target-slug').value;
		const btn = document.getElementById('ep-agent-eject-confirm-btn');
		btn.disabled = e.target.value !== expected;
	});
	document.getElementById('ep-agent-eject-confirm-btn')?.addEventListener('click', onEjectConfirm);
}

function openEjectModal(slug) {
	document.getElementById('ep-agent-eject-target-slug').value = slug;
	document.getElementById('ep-agent-eject-slug-display').textContent = slug;
	document.getElementById('ep-agent-eject-confirm').value = '';
	document.getElementById('ep-agent-eject-confirm-btn').disabled = true;
	hide(document.getElementById('ep-agent-eject-error'));
	openAppModal('ep-agent-eject-modal');
}

async function onEjectConfirm() {
	const slug = document.getElementById('ep-agent-eject-target-slug').value;
	const errorEl = document.getElementById('ep-agent-eject-error');
	const btn = document.getElementById('ep-agent-eject-confirm-btn');
	hide(errorEl);
	btn.disabled = true;
	btn.textContent = 'Ejecting…';
	try {
		const url = appData.agentEjectUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url, { method: 'POST' });
		if (res.success) {
			closeAppModal('ep-agent-eject-modal');
			closeAppModal('ep-agent-iterate-modal');
			refreshAppsTable();
		} else {
			showError(errorEl, res.message || 'Eject failed.');
			btn.disabled = false;
			btn.textContent = 'Eject';
		}
	} catch (err) {
		showError(errorEl, err.message || 'Eject failed.');
		btn.disabled = false;
		btn.textContent = 'Eject';
	}
}

// ── Jobs & History ──────────────────────────────────────────────

function bindJobs() {
	document.getElementById('ep-apps-jobs-btn')?.addEventListener('click', async () => {
		openAppModal('ep-agent-jobs-modal');
		const listEl = document.getElementById('ep-agent-jobs-list');
		listEl.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:30px;">Loading…</div>';
		try {
			const res = await apiFetch(appData.agentJobsUrl);
			renderJobsList(res.jobs || []);
		} catch (err) {
			listEl.innerHTML = '<div style="text-align:center;color:#9b2c2c;padding:30px;">' + escapeHtml(err.message || 'Failed to load jobs.') + '</div>';
		}
	});
}

function renderJobsList(jobs) {
	const listEl = document.getElementById('ep-agent-jobs-list');
	if (!jobs.length) {
		listEl.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:30px;">No jobs yet.</div>';
		return;
	}
	listEl.innerHTML = jobs.map(j => {
		const time = new Date((j.created_at || 0) * 1000).toLocaleString();
		const statusColor = {
			success: '#16a34a',
			failed: '#9b2c2c',
			drafted: '#ca8a04',
			running: '#2563eb',
			pending: '#6b7280',
		}[j.status] || '#6b7280';
		const statusBadge = `<span style="display:inline-block;background:${statusColor};color:white;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">${escapeHtml(j.status || '?')}</span>`;
		const slug = j.target_slug || '(no slug)';
		const mode = j.mode === 'iterate' ? '✨ Iterate' : '✨ Generate';
		const promptPreview = (j.prompt || '').slice(0, 120) + ((j.prompt || '').length > 120 ? '…' : '');
		return `
			<div style="padding:12px 18px;border-bottom:1px solid #e5e7eb;">
				<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
					<div><strong>${escapeHtml(slug)}</strong> <span style="color:#6b7280;font-size:12px;">${escapeHtml(mode)}</span></div>
					${statusBadge}
				</div>
				<div style="font-size:13px;color:#374151;margin-bottom:4px;">${escapeHtml(promptPreview)}</div>
				<div style="font-size:11px;color:#9ca3af;">${escapeHtml(time)}${j.provider ? ' • ' + escapeHtml(j.provider) + (j.model ? ' / ' + escapeHtml(j.model) : '') : ''}${j.result?.version ? ' • v' + escapeHtml(j.result.version) : ''}</div>
			</div>
		`;
	}).join('');
}

// ── Draft preview ───────────────────────────────────────────────

function renderDraftPreview(job, prefix) {
	const draft = job.draft;
	if (!draft) return;
	const previewEl = document.getElementById(prefix + '-draft-preview');
	const summaryEl = document.getElementById(prefix + '-draft-summary');
	const filesEl   = document.getElementById(prefix + '-draft-files');
	const commitBtn = document.getElementById(prefix + '-commit-btn');
	const discardBtn = document.getElementById(prefix + '-discard-btn');

	previewEl.style.display = '';
	commitBtn.style.display = '';
	discardBtn.style.display = '';

	const totalBytes = (draft.files || []).reduce((s, f) => s + (f.bytes || 0), 0);
	summaryEl.textContent = `${(draft.files || []).length} files · ${formatBytes(totalBytes)} · v${draft.version || ''}`;

	filesEl.innerHTML = (draft.files || []).map(f => `
		<div style="display:flex;justify-content:space-between;padding:3px 0;cursor:pointer;" data-file-path="${escapeHtml(f.path)}">
			<span>${escapeHtml(f.path)}</span>
			<span style="color:#9ca3af;">${formatBytes(f.bytes || 0)}</span>
		</div>
	`).join('');

	// Click a row → fetch and show its contents in the viewer
	filesEl.querySelectorAll('[data-file-path]').forEach(row => {
		row.addEventListener('click', async () => {
			const path = row.dataset.filePath;
			const viewerEl = document.getElementById(prefix === 'ep-agent' ? 'ep-agent-draft-file-viewer' : null);
			if (!viewerEl) return; // iterate modal omits the viewer for space
			try {
				const url = appData.agentJobFileUrl.replace('__ID__', encodeURIComponent(job.id)) + '?path=' + encodeURIComponent(path);
				const res = await apiFetch(url);
				viewerEl.style.display = '';
				viewerEl.textContent = '// ' + path + '\n\n' + res.contents;
			} catch (err) {
				viewerEl.style.display = '';
				viewerEl.textContent = '// Error loading ' + path + ': ' + err.message;
			}
		});
	});

	if (prefix === 'ep-agent') {
		hide(document.getElementById('ep-agent-submit'));
	} else {
		// iterate flow keeps the submit button visible so the user can
		// abandon the draft and send a new prompt instead.
	}
}

// ── Helpers ─────────────────────────────────────────────────────

async function pollJob(jobId, onTick) {
	const start = Date.now();
	const url = appData.agentJobUrl.replace('__ID__', encodeURIComponent(jobId));
	while (Date.now() - start < POLL_TIMEOUT_MS) {
		await new Promise(r => setTimeout(r, POLL_INTERVAL_MS));
		const job = await apiFetch(url);
		onTick(job);
		// Drafted = phase 1 done, awaiting user. Success/failed = terminal.
		if (job.status === 'drafted' || job.status === 'success' || job.status === 'failed') {
			return job;
		}
	}
	throw new Error('Job timed out.');
}

function renderStep(container, step) {
	if (!container) return;
	const idx = STEP_ORDER.indexOf(step);
	const failed = step === 'failed';
	container.innerHTML = STEP_ORDER.map((key, i) => {
		const label = STEP_LABELS[key] || key;
		let cls = 'ep-scaffold-step';
		if (failed) cls += ' is-failed';
		else if (i < idx) cls += ' is-done';
		else if (i === idx) cls += ' is-active';
		return `<div class="${cls}">${label}</div>`;
	}).join('');
}

function setModelBadge(elementId) {
	const el = document.getElementById(elementId);
	if (!el) return;
	const agent = appData.agent || {};
	if (agent.provider && agent.model) {
		el.textContent = agent.provider + ' / ' + agent.model;
	}
}

function showRetry(prefix, _prompt) {
	const retryBtn = document.getElementById(prefix + '-retry-btn');
	if (retryBtn) retryBtn.style.display = '';
}

function refreshAppsTable() {
	// The simplest correct refresh: reload the page. A future iteration
	// could call /apps and re-render the table in place — for now, the
	// reload preserves correctness without coupling agent.js to the
	// apps store internals.
	window.location.reload();
}

function hide(el) { if (el) el.style.display = 'none'; }
function showError(el, msg) { if (!el) return; el.style.display = ''; el.textContent = msg; }
function escapeHtml(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function formatBytes(b) {
	if (b < 1024) return b + ' B';
	if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
	return (b / 1024 / 1024).toFixed(1) + ' MB';
}
