/**
 * Generative Agent — Apps page module.
 *
 * Wires:
 *   - "✨ Generate with AI" button → ep-agent-modal → POST /agent/generate
 *     → poll → STEP_REVIEW pause → preview pane → Push (commit) or Discard
 *   - Per-app "✨ Iterate" affordance → ep-agent-iterate-modal (chat thread)
 *     → POST /agent/iterate/{slug} → same draft/commit dance
 *   - Repair → ep-agent-repair-modal for surgical error fixes
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
		// IMPORTANT: the three buttons are rendered with the HTML `hidden`
		// attribute by apps.php. Setting `style.display = ''` does NOT
		// override the [hidden] UA rule — we have to flip `el.hidden`
		// directly so the attribute is actually removed from the element.
		if (agent.enabled && agent.ready && agent.configured) {
			enabledBtn.hidden = false;
			jobsBtn.hidden    = false;
		} else if (agent.configured || agent.enabled) {
			// DISCOVERABILITY: if the operator has either toggled the
			// feature on OR saved an API key, show the "configure first"
			// button as a nudge toward Settings → AI Agent. Hiding the
			// surface entirely when one of the two knobs is set makes
			// the feature feel broken rather than gated.
			disabledBtn.hidden = false;
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
		warningEl.textContent = '⚠ Agent runtime failed to boot: ' + agent.error;
	}

	bindGenerate();
	bindIterate();
	bindJobs();
	bindRepair();

	// Initial drafts render from the localized payload, then refresh
	// from the REST endpoint to pick up anything newer than the page load.
	renderDraftsFromData(data.agentDrafts || []);
	refreshDrafts();

	// Per-row "✨ Iterate" links rendered by apps.js
	document.addEventListener('click', (e) => {
		const target = e.target.closest('[data-agent-iterate]');
		if (!target) return;
		e.preventDefault();
		openIterateModal(target.dataset.agentIterate);
	});

	// Per-row / per-job "🛠 Repair" links.
	document.addEventListener('click', (e) => {
		const target = e.target.closest('[data-agent-repair]');
		if (!target) return;
		e.preventDefault();
		openRepairModal(target.dataset.agentRepair, {
			error: target.dataset.agentRepairError || '',
		});
	});

	// "Resume" / "Discard" / "Push" links inside the drafts panel.
	document.addEventListener('click', async (e) => {
		const resume = e.target.closest('[data-agent-resume-draft]');
		if (resume) {
			e.preventDefault();
			openReviewModal(resume.dataset.agentResumeDraft);
			return;
		}
		const retry = e.target.closest('[data-agent-retry-generate]');
		if (retry) {
			e.preventDefault();
			const prompt = retry.dataset.agentRetryPrompt || '';
			const name   = retry.dataset.agentRetryName || '';
			const slug   = retry.dataset.agentRetrySlug || '';
			const desc   = retry.dataset.agentRetryDesc || '';
			// Open the generate modal with fields pre-filled.
			// The old draft stays in the panel — it gets discarded
			// only once the new generation actually starts.
			const promptEl = document.getElementById('ep-agent-prompt');
			const nameEl   = document.getElementById('ep-agent-app-name');
			const slugEl   = document.getElementById('ep-agent-app-slug');
			const descEl   = document.getElementById('ep-agent-app-description');
			if (promptEl) { promptEl.value = prompt; promptEl.dataset.preserved = '1'; }
			if (nameEl)   nameEl.value = name;
			if (slugEl)   slugEl.value = slug;
			if (descEl)   descEl.value = desc;
			openAppModal('ep-agent-modal');
			return;
		}
		const iterateDraft = e.target.closest('[data-agent-iterate-draft]');
		if (iterateDraft) {
			e.preventDefault();
			openIterateModal(iterateDraft.dataset.agentIterateDraft);
			return;
		}
		const push = e.target.closest('[data-agent-push-draft]');
		if (push) {
			e.preventDefault();
			const slug = push.dataset.agentPushDraft;
			if (!confirm(`Push "${slug}" to GitHub? A new release will be created and the plugin installed.`)) return;
			push.textContent = 'Pushing…';
			push.style.pointerEvents = 'none';
			try {
				const url = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
				const res = await apiFetch(url, { method: 'POST' });
				if (res.success) {
					await refreshDrafts();
					refreshAppsTable();
				} else {
					alert('Push failed: ' + (res.message || 'Unknown error'));
					push.textContent = 'Push to GitHub';
					push.style.pointerEvents = '';
				}
			} catch (err) {
				alert('Push failed: ' + (err.message || err));
				push.textContent = 'Push to GitHub';
				push.style.pointerEvents = '';
			}
			return;
		}
		const copyBtn = e.target.closest('[data-copy-prompt]');
		if (copyBtn) {
			e.preventDefault();
			navigator.clipboard.writeText(copyBtn.dataset.copyPrompt).then(() => {
				const orig = copyBtn.textContent;
				copyBtn.textContent = 'Copied';
				setTimeout(() => { copyBtn.textContent = orig; }, 1500);
			});
			return;
		}
		const discard = e.target.closest('[data-agent-discard-draft]');
		if (discard) {
			e.preventDefault();
			const slug = discard.dataset.agentDiscardDraft;
			if (!confirm(`Discard pending draft for "${slug}"? This cannot be undone.`)) return;
			try {
				const url = appData.agentDraftUrl.replace('__SLUG__', encodeURIComponent(slug));
				await apiFetch(url, { method: 'DELETE' });
				await refreshDrafts();
			} catch (err) {
				alert('Discard failed: ' + (err.message || err));
			}
			return;
		}
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
	bindGenerateFields();
}

let currentGenerateJobId = null;

function resetGenerateModal() {
	currentGenerateJobId = null;
	const nameEl   = document.getElementById('ep-agent-app-name');
	const slugEl   = document.getElementById('ep-agent-app-slug');
	const descEl   = document.getElementById('ep-agent-app-description');
	const promptEl = document.getElementById('ep-agent-prompt');
	if (!promptEl?.dataset.preserved) {
		if (nameEl) nameEl.value = '';
		if (slugEl) slugEl.value = '';
		if (descEl) descEl.value = '';
		if (promptEl) promptEl.value = '';
	}
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

function bindGenerateFields() {
	const nameEl = document.getElementById('ep-agent-app-name');
	const slugEl = document.getElementById('ep-agent-app-slug');
	if (!nameEl || !slugEl) return;
	let slugManual = false;
	slugEl.addEventListener('input', () => { slugManual = slugEl.value.trim() !== ''; });
	nameEl.addEventListener('input', () => {
		if (slugManual) return;
		slugEl.value = nameEl.value
			.toLowerCase()
			.replace(/[^a-z0-9\s-]/g, '')
			.replace(/\s+/g, '-')
			.replace(/-+/g, '-')
			.replace(/^-|-$/g, '');
	});
}

async function onGenerateSubmit() {
	const nameEl   = document.getElementById('ep-agent-app-name');
	const slugEl   = document.getElementById('ep-agent-app-slug');
	const descEl   = document.getElementById('ep-agent-app-description');
	const promptEl = document.getElementById('ep-agent-prompt');
	const errorEl  = document.getElementById('ep-agent-error');
	const submitEl = document.getElementById('ep-agent-submit');
	const stepsEl  = document.getElementById('ep-agent-steps');

	const name   = (nameEl?.value || '').trim();
	const slug   = (slugEl?.value || '').trim();
	const description = (descEl?.value || '').trim();
	const prompt = (promptEl?.value || '').trim();
	hide(errorEl);

	if (!name) {
		showError(errorEl, 'App name is required.');
		nameEl?.focus();
		return;
	}
	if (!slug || !/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(slug)) {
		showError(errorEl, 'Slug must be lowercase letters, numbers, and hyphens (e.g., team-directory).');
		slugEl?.focus();
		return;
	}
	if (prompt.length < 5) {
		showError(errorEl, 'Prompt must be at least 5 characters.');
		promptEl?.focus();
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Starting…';
	renderStep(stepsEl, 'queued');

	let jobId;
	try {
		const res = await apiFetch(appData.agentGenerateUrl, {
			method: 'POST',
			body: { app_name: name, app_slug: slug, app_description: description, prompt },
		});
		jobId = res.job_id;
		currentGenerateJobId = jobId;
		// Stash the slug on the modal so onGenerateCommit can find it.
		const pushContext = document.querySelector('[data-current-generate-slug]');
		if (pushContext) {
			pushContext.dataset.currentGenerateSlug = slug;
		} else {
			document.body.dataset.currentGenerateSlug = slug;
		}
		// Drafts panel reflects the job too.
		refreshDrafts();
	} catch (err) {
		log.error('[agent] generate failed', err);
		showError(errorEl, err.message || 'Generation failed to start.');
		submitEl.disabled = false;
		submitEl.textContent = 'Generate Draft';
		return;
	}

	submitEl.textContent = 'Working…';

	// Poll inline — the modal stays open and the user watches the
	// progress pill, then the draft preview is rendered right here
	// in the same modal when the LLM returns.
	try {
		const finalJob = await pollJob(jobId, (tick) => {
			renderStep(stepsEl, tick.step || 'queued');
		});

		if (finalJob.status === 'drafted') {
			renderStep(stepsEl, 'awaiting_review');
			renderDraftPreview(finalJob, 'ep-agent');
			hide(submitEl);
			document.getElementById('ep-agent-commit-btn').style.display = '';
			document.getElementById('ep-agent-discard-btn').style.display = '';
		} else if (finalJob.status === 'success') {
			renderStep(stepsEl, 'done');
			postDraftReadyToast(name, slug, 'success');
			setTimeout(() => {
				closeAppModal('ep-agent-modal');
				refreshAppsTable();
			}, 800);
		} else if (finalJob.status === 'failed') {
			renderStep(stepsEl, 'failed');
			showFriendlyErrors(errorEl, finalJob);
			submitEl.disabled = false;
			submitEl.textContent = 'Generate Draft';
			document.getElementById('ep-agent-retry-btn').style.display = '';
		}
	} catch (err) {
		log.error('[agent] poll failed', err);
		showError(errorEl, err.message || 'Polling failed. Check the drafts panel for status.');
		submitEl.disabled = false;
		submitEl.textContent = 'Generate Draft';
	}
}

/**
 * Render a friendly, user-facing summary of validation errors in
 * place of the raw technical strings. Falls back to raw errors if
 * the server didn't return friendly_errors (older jobs, etc.).
 */
function showFriendlyErrors(errorEl, job) {
	if (!errorEl) return;
	const friendly = job.friendly_errors || [];
	if (friendly.length === 0) {
		showError(errorEl, (job.errors || []).join(' ') || 'Job failed.');
		return;
	}
	const lines = friendly.map(f => `• ${f.summary}`).join('\n');
	errorEl.style.display = '';
	errorEl.style.whiteSpace = 'pre-line';
	errorEl.textContent = lines;
}

/**
 * Minimal, unobtrusive toast so users who navigate away from the
 * modal during drafting still get notified when their draft is
 * ready. Auto-dismisses after 6 seconds; click to dismiss sooner.
 */
function postDraftReadyToast(appName, slug, kind) {
	let host = document.getElementById('ep-agent-toast-host');
	if (!host) {
		host = document.createElement('div');
		host.id = 'ep-agent-toast-host';
		host.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
		document.body.appendChild(host);
	}
	const bg = kind === 'success' ? '#16a34a' : (kind === 'failed' ? '#9b2c2c' : '#7c3aed');
	const icon = kind === 'success' ? '✓' : (kind === 'failed' ? '✗' : '✨');
	const label = kind === 'success'
		? `${appName} released`
		: (kind === 'failed' ? `${appName} failed to generate` : `${appName} draft ready`);
	const toast = document.createElement('div');
	toast.style.cssText = `background:${bg};color:white;padding:12px 18px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;cursor:pointer;max-width:320px;`;
	toast.innerHTML = `<strong>${icon} ${escapeHtml(label)}</strong><br><span style="opacity:0.85;font-size:11px;">${escapeHtml(slug)}</span>`;
	toast.addEventListener('click', () => toast.remove());
	host.appendChild(toast);
	setTimeout(() => toast.remove(), 6000);
}

async function onGenerateCommit() {
	const errorEl = document.getElementById('ep-agent-error');
	const stepsEl = document.getElementById('ep-agent-steps');
	const commitBtn = document.getElementById('ep-agent-commit-btn');
	const slug = document.body.dataset.currentGenerateSlug
		|| document.querySelector('[data-current-generate-slug]')?.dataset.currentGenerateSlug;
	if (!slug) {
		showError(errorEl, 'No draft slug — use the drafts panel to push.');
		return;
	}
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		// commitDraft enqueues an async push. Poll the draft endpoint
		// until it either succeeds (draft cleared, payload gone) or
		// reports a failure state.
		const url = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
		await apiFetch(url, { method: 'POST' });
		await pollDraftUntilDone(slug, stepsEl);
		renderStep(stepsEl, 'done');
		postDraftReadyToast(slug, slug, 'success');
		setTimeout(async () => {
			closeAppModal('ep-agent-modal');
			await refreshDrafts();
			refreshAppsTable();
		}, 600);
	} catch (err) {
		showError(errorEl, err.message || 'Push failed.');
		commitBtn.disabled = false;
		commitBtn.textContent = 'Push to GitHub';
	}
}

/**
 * Poll /agent/drafts/{slug} until it stops returning a payload
 * (success) or its draft_status goes to 'failed'. Throws on
 * terminal failure so callers can surface an error.
 */
async function pollDraftUntilDone(slug, stepsEl) {
	const start = Date.now();
	const url = appData.agentDraftUrl.replace('__SLUG__', encodeURIComponent(slug));
	while (Date.now() - start < POLL_TIMEOUT_MS) {
		await new Promise(r => setTimeout(r, POLL_INTERVAL_MS));
		try {
			const draft = await apiFetch(url);
			if (draft && draft.draft_status === 'failed') {
				throw new Error((draft.errors || []).join(' ') || 'Push failed.');
			}
			if (stepsEl) renderStep(stepsEl, draft.draft_step || 'pushing');
		} catch (err) {
			// 404 on the draft endpoint means the stash was cleared
			// after a successful push — we're done.
			if ((err.code || err.message || '').toString().match(/no_draft|404/)) {
				return;
			}
			throw err;
		}
	}
	throw new Error('Push timed out.');
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

	// If the user is resuming a draft that already has a stashed
	// payload, render the preview pane so they can review what's
	// there before pushing or iterating further. The draft endpoint
	// returns the real origin_job_id (if the originating job is
	// still in history), which we use as the preview's job id so
	// file clicks route through the normal /jobs/{id}/file endpoint.
	try {
		const draftUrl = appData.agentDraftUrl.replace('__SLUG__', encodeURIComponent(slug));
		const draft = await apiFetch(draftUrl);
		if (draft && draft.payload) {
			// Use the real current-job-id from the server. If it's
			// been evicted from history, fall back to a slug-based
			// synthetic id; the /jobs/{id}/file endpoint handles
			// the "resume-<slug>" prefix as a recognised fallback.
			const jobId = draft.origin_job_id || ('resume-' + slug);
			currentIterateJobId = jobId;
			renderDraftPreview({ id: jobId, draft: draft.payload }, 'ep-agent-iterate');
		}
	} catch (_err) {
		// No stashed draft — first iteration on a published app.
		// That's fine; the chat thread is the only context the user needs.
	}

	const hint = document.getElementById('ep-agent-iterate-context-hint');
	if (hint) {
		hint.textContent = 'Iteration sends the current repo tree (or stashed draft) as context.';
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
			// Prefer the friendly summaries returned by the server
			// when available — raw validator output is too technical
			// for the chat surface.
			const friendly = j.friendly_errors || [];
			const summary = friendly.length > 0
				? friendly.map(f => f.summary).join(' · ')
				: ((j.errors || []).join(' ') || 'Failed');
			agentBubble = `<div style="display:flex;margin-bottom:14px;"><div style="max-width:75%;background:#fef2f2;border:1px solid #fecaca;color:#9b2c2c;padding:8px 12px;border-radius:12px 12px 12px 2px;font-size:13px;">✗ ${escapeHtml(summary)}</div></div>`;
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
	const submitEl = document.getElementById('ep-agent-iterate-submit');
	const stepsEl  = document.getElementById('ep-agent-iterate-steps');

	hide(errorEl);

	if (prompt.length < 5) {
		showError(errorEl, 'Change request must be at least 5 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Starting…';
	renderStep(stepsEl, 'queued');

	// Optimistically append the user bubble to the chat thread.
	const threadEl = document.getElementById('ep-agent-chat-thread');
	const optimistic = document.createElement('div');
	optimistic.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:8px;"><div style="max-width:75%;background:#7c3aed;color:white;padding:8px 12px;border-radius:12px 12px 2px 12px;font-size:13px;">${escapeHtml(prompt)}</div></div>`;
	threadEl.appendChild(optimistic.firstChild);
	threadEl.scrollTop = threadEl.scrollHeight;

	let jobId;
	try {
		const url = appData.agentIterateUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url, { method: 'POST', body: { prompt } });
		jobId = res.job_id;
		currentIterateJobId = jobId;
		promptEl.value = '';
		refreshDrafts();
	} catch (err) {
		log.error('[agent] iterate failed', err);
		showError(errorEl, err.message || 'Iteration failed to start.');
		submitEl.disabled = false;
		submitEl.textContent = 'Send';
		return;
	}

	submitEl.textContent = 'Working…';

	// Stay in the modal and poll inline — same pattern as
	// onGenerateSubmit. Chat thread gets refreshed on completion
	// so the agent bubble reflects the real terminal state.
	try {
		const finalJob = await pollJob(jobId, (tick) => {
			renderStep(stepsEl, tick.step || 'queued');
		});

		// Refresh chat thread from server so the agent bubble
		// shows success/failed/drafted consistently.
		try {
			const refreshUrl = appData.agentJobsForSlugUrl.replace('__SLUG__', encodeURIComponent(slug));
			const refreshed = await apiFetch(refreshUrl);
			renderChatThread(refreshed.jobs || []);
		} catch (_e) { /* non-fatal */ }

		if (finalJob.status === 'drafted') {
			renderStep(stepsEl, 'awaiting_review');
			renderDraftPreview(finalJob, 'ep-agent-iterate');
		} else if (finalJob.status === 'success') {
			renderStep(stepsEl, 'done');
			postDraftReadyToast(slug, slug, 'success');
			setTimeout(() => {
				closeAppModal('ep-agent-iterate-modal');
				refreshAppsTable();
			}, 800);
		} else if (finalJob.status === 'failed') {
			renderStep(stepsEl, 'failed');
			showFriendlyErrors(errorEl, finalJob);
		}
	} catch (err) {
		log.error('[agent] iterate poll failed', err);
		showError(errorEl, err.message || 'Polling failed. Check the drafts panel.');
	} finally {
		submitEl.disabled = false;
		submitEl.textContent = 'Send';
	}
}

async function onIterateCommit() {
	const slug = document.getElementById('ep-agent-iterate-target-slug').value;
	if (!slug) return;
	const errorEl = document.getElementById('ep-agent-iterate-error');
	const stepsEl = document.getElementById('ep-agent-iterate-steps');
	const commitBtn = document.getElementById('ep-agent-iterate-commit-btn');
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		// Async commit — enqueue then poll the draft endpoint until
		// the stash is cleared (success) or draft_status goes failed.
		const url = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
		await apiFetch(url, { method: 'POST' });
		await pollDraftUntilDone(slug, stepsEl);
		renderStep(stepsEl, 'done');
		postDraftReadyToast(slug, slug, 'success');
		hide(document.getElementById('ep-agent-iterate-draft-preview'));
		hide(commitBtn);
		hide(document.getElementById('ep-agent-iterate-discard-btn'));
		currentIterateJobId = null;
		closeAppModal('ep-agent-iterate-modal');
		await refreshDrafts();
		refreshAppsTable();
	} catch (err) {
		showError(errorEl, err.message || 'Push failed.');
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

// ── Repair (surgical fix for a reported error) ─────────────────

function bindRepair() {
	document.getElementById('ep-agent-repair-submit')?.addEventListener('click', onRepairSubmit);
	document.getElementById('ep-agent-repair-commit-btn')?.addEventListener('click', onRepairCommit);
	document.getElementById('ep-agent-repair-discard-btn')?.addEventListener('click', onRepairDiscard);
}

let currentRepairJobId = null;

function openRepairModal(slug, prefill = {}) {
	currentRepairJobId = null;
	document.getElementById('ep-agent-repair-target-slug').value = slug;
	document.getElementById('ep-agent-repair-current-job-id').value = '';
	document.getElementById('ep-agent-repair-slug').textContent = slug;
	document.getElementById('ep-agent-repair-error').value = prefill.error || '';
	document.getElementById('ep-agent-repair-file').value = prefill.file || '';
	document.getElementById('ep-agent-repair-line').value = prefill.line || '';
	document.getElementById('ep-agent-repair-prompt').value = '';
	hide(document.getElementById('ep-agent-repair-error-msg'));
	hide(document.getElementById('ep-agent-repair-draft-preview'));
	hide(document.getElementById('ep-agent-repair-commit-btn'));
	hide(document.getElementById('ep-agent-repair-discard-btn'));
	document.getElementById('ep-agent-repair-steps').innerHTML = '';
	document.getElementById('ep-agent-repair-draft-files').innerHTML = '';
	document.getElementById('ep-agent-repair-change-badges').innerHTML = '';
	const submit = document.getElementById('ep-agent-repair-submit');
	if (submit) { submit.style.display = ''; submit.disabled = false; submit.textContent = 'Diagnose & Draft Fix'; }
	openAppModal('ep-agent-repair-modal');
}

async function onRepairSubmit() {
	const slug      = document.getElementById('ep-agent-repair-target-slug').value;
	const errorMsg  = document.getElementById('ep-agent-repair-error').value.trim();
	const errorFile = document.getElementById('ep-agent-repair-file').value.trim();
	const errorLine = parseInt(document.getElementById('ep-agent-repair-line').value, 10) || 0;
	const prompt    = document.getElementById('ep-agent-repair-prompt').value.trim();
	const errorEl   = document.getElementById('ep-agent-repair-error-msg');
	const submitEl  = document.getElementById('ep-agent-repair-submit');
	const stepsEl   = document.getElementById('ep-agent-repair-steps');

	hide(errorEl);

	if (errorMsg.length < 3) {
		showError(errorEl, 'Error message must be at least 3 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Starting…';
	renderStep(stepsEl, 'queued');

	let jobId;
	try {
		const url = appData.agentRepairUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url, {
			method: 'POST',
			body: {
				error_message: errorMsg,
				error_file:    errorFile,
				error_line:    errorLine,
				prompt:        prompt,
			},
		});
		jobId = res.job_id;
		currentRepairJobId = jobId;
		refreshDrafts();
	} catch (err) {
		log.error('[agent] repair failed', err);
		showError(errorEl, err.message || 'Repair failed to start.');
		submitEl.disabled = false;
		submitEl.textContent = 'Diagnose & Draft Fix';
		return;
	}

	submitEl.textContent = 'Working…';

	// Stay in the modal and poll inline — surgical fixes benefit
	// from the user watching progress, and the change summary is
	// rendered in the preview as soon as the job hits drafted.
	try {
		const finalJob = await pollJob(jobId, (tick) => {
			renderStep(stepsEl, tick.step || 'queued');
		});

		if (finalJob.status === 'drafted') {
			renderStep(stepsEl, 'awaiting_review');
			renderRepairDraft(finalJob);
		} else if (finalJob.status === 'success') {
			renderStep(stepsEl, 'done');
			postDraftReadyToast(slug, slug, 'success');
			setTimeout(() => { closeAppModal('ep-agent-repair-modal'); refreshAppsTable(); }, 800);
		} else if (finalJob.status === 'failed') {
			renderStep(stepsEl, 'failed');
			showFriendlyErrors(errorEl, finalJob);
		}
	} catch (err) {
		log.error('[agent] repair poll failed', err);
		showError(errorEl, err.message || 'Polling failed. Check the drafts panel.');
	} finally {
		submitEl.disabled = false;
		submitEl.textContent = 'Diagnose & Draft Fix';
	}
}

async function onRepairCommit() {
	if (!currentRepairJobId) return;
	const slug = document.getElementById('ep-agent-repair-target-slug').value;
	const errorEl = document.getElementById('ep-agent-repair-error-msg');
	const stepsEl = document.getElementById('ep-agent-repair-steps');
	const commitBtn = document.getElementById('ep-agent-repair-commit-btn');
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		// Async commit via the draft-commit endpoint — same path as
		// iterate, so slow GitHub responses don't hang the REST call.
		const url = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
		await apiFetch(url, { method: 'POST' });
		await pollDraftUntilDone(slug, stepsEl);
		renderStep(stepsEl, 'done');
		postDraftReadyToast(slug, slug, 'success');
		setTimeout(() => { closeAppModal('ep-agent-repair-modal'); refreshAppsTable(); }, 600);
	} catch (err) {
		showError(errorEl, err.message || 'Push failed.');
		commitBtn.disabled = false;
		commitBtn.textContent = 'Push fix to GitHub';
	}
}

async function onRepairDiscard() {
	if (!currentRepairJobId) return;
	if (!confirm('Discard this repair draft?')) return;
	try {
		const url = appData.agentJobDiscardUrl.replace('__ID__', encodeURIComponent(currentRepairJobId));
		await apiFetch(url, { method: 'POST' });
		closeAppModal('ep-agent-repair-modal');
	} catch (err) {
		showError(document.getElementById('ep-agent-repair-error-msg'), err.message || 'Discard failed.');
	}
}

function renderRepairDraft(job) {
	const draft = job.draft;
	if (!draft) return;
	const previewEl = document.getElementById('ep-agent-repair-draft-preview');
	const summaryEl = document.getElementById('ep-agent-repair-draft-summary');
	const filesEl   = document.getElementById('ep-agent-repair-draft-files');
	const badgesEl  = document.getElementById('ep-agent-repair-change-badges');
	const commitBtn = document.getElementById('ep-agent-repair-commit-btn');
	const discardBtn = document.getElementById('ep-agent-repair-discard-btn');
	const submitEl  = document.getElementById('ep-agent-repair-submit');

	previewEl.style.display = '';
	commitBtn.style.display = '';
	discardBtn.style.display = '';
	hide(submitEl);

	const totalBytes = (draft.files || []).reduce((s, f) => s + (f.bytes || 0), 0);
	summaryEl.textContent = `${(draft.files || []).length} files · ${formatBytes(totalBytes)} · v${draft.version || ''}`;

	const cs = draft.change_summary || {};
	const badge = (label, count, bg) =>
		count > 0
			? `<span style="display:inline-block;background:${bg};color:white;padding:2px 8px;border-radius:10px;margin-right:4px;font-weight:600;">${count} ${label}</span>`
			: '';
	badgesEl.innerHTML =
		badge('modified', cs.modified || 0, '#ca8a04') +
		badge('added', cs.added || 0, '#16a34a') +
		badge('removed', cs.removed || 0, '#9b2c2c') +
		`<span style="color:#9ca3af;">${cs.unchanged || 0} unchanged</span>`;

	// Sort files: modified first, then added, then unchanged.
	const order = { modified: 0, added: 1, unchanged: 2 };
	const sortedFiles = (draft.files || []).slice().sort(
		(a, b) => (order[a.change] ?? 99) - (order[b.change] ?? 99)
	);
	filesEl.innerHTML = sortedFiles.map(f => {
		const change = f.change || 'unchanged';
		const tag = {
			modified:  '<span style="display:inline-block;width:60px;color:#ca8a04;font-weight:600;">[mod]</span>',
			added:     '<span style="display:inline-block;width:60px;color:#16a34a;font-weight:600;">[add]</span>',
			unchanged: '<span style="display:inline-block;width:60px;color:#9ca3af;">[ ]</span>',
		}[change];
		const dim = change === 'unchanged' ? 'color:#9ca3af;' : '';
		return `<div style="padding:2px 0;cursor:pointer;${dim}" data-file-path="${escapeHtml(f.path)}">${tag} ${escapeHtml(f.path)} <span style="color:#9ca3af;float:right;">${formatBytes(f.bytes || 0)}</span></div>`;
	}).join('');

	// Click a row → fetch and render contents in the repair viewer.
	// Modified files are far more interesting than unchanged — this
	// is the whole point of the surgical review.
	const viewerEl = document.getElementById('ep-agent-repair-draft-file-viewer');
	filesEl.querySelectorAll('[data-file-path]').forEach(row => {
		row.addEventListener('click', async () => {
			if (!viewerEl) return;
			const path = row.dataset.filePath;
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
		const modeLabel = { iterate: '✨ Iterate', repair: '🛠 Repair', generate: '✨ Generate' }[j.mode] || '✨ Generate';
		const promptPreview = (j.prompt || '').slice(0, 120) + ((j.prompt || '').length > 120 ? '…' : '');

		// Failed jobs with a target slug get a Repair affordance.
		// Clicking it opens the repair modal pre-populated with the
		// failure error message so the user can re-attempt as a
		// surgical fix instead of a wholesale re-generation.
		const errorText = (j.errors || []).join(' ');
		const friendlyText = (j.friendly_errors || []).map(f => f.summary).join(' · ');
		const showRepair = j.status === 'failed' && j.target_slug;
		const repairBtn = showRepair
			? `<a href="#" data-agent-repair="${escapeHtml(j.target_slug)}" data-agent-repair-error="${escapeAttr(errorText)}" style="font-size:11px;color:#7c3aed;text-decoration:underline;margin-left:8px;">🛠 Repair</a>`
			: '';

		const displayError = friendlyText || errorText;
		const errorLine = j.status === 'failed' && displayError
			? `<div style="font-size:11px;color:#9b2c2c;margin-top:4px;background:#fef2f2;border-left:2px solid #fecaca;padding:4px 8px;">${escapeHtml(displayError.slice(0, 300))}${displayError.length > 300 ? '…' : ''}</div>`
			: '';

		return `
			<div style="padding:12px 18px;border-bottom:1px solid #e5e7eb;">
				<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
					<div><strong>${escapeHtml(slug)}</strong> <span style="color:#6b7280;font-size:12px;">${escapeHtml(modeLabel)}</span>${repairBtn}</div>
					${statusBadge}
				</div>
				<div style="font-size:13px;color:#374151;margin-bottom:4px;">${escapeHtml(promptPreview)}</div>
				${errorLine}
				<div style="font-size:11px;color:#9ca3af;">${escapeHtml(time)}${j.provider ? ' • ' + escapeHtml(j.provider) + (j.model ? ' / ' + escapeHtml(j.model) : '') : ''}${j.result?.version ? ' • v' + escapeHtml(j.result.version) : ''}</div>
			</div>
		`;
	}).join('');
}

function escapeAttr(s) { return escapeHtml(s); }

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

	// Resolve a file viewer element. Generate modal has its own
	// viewer; iterate/repair modals share one through the review
	// path, so clicks in those prefixes fall back to the generate
	// viewer if the prefix-specific one is absent.
	const viewerEl =
		document.getElementById(prefix + '-draft-file-viewer') ||
		document.getElementById('ep-agent-draft-file-viewer');

	// Click a row → fetch (or inline) the file contents and show
	// them in the viewer. For payloads that already have inline
	// contents attached (resume flow), skip the REST call entirely.
	filesEl.querySelectorAll('[data-file-path]').forEach(row => {
		row.addEventListener('click', async () => {
			if (!viewerEl) return;
			const path = row.dataset.filePath;

			// Inline lookup first — when renderDraftPreview is given
			// a payload straight from /agent/drafts/{slug}, every
			// file already carries its contents.
			const inline = (draft.files || []).find(f => f && f.path === path);
			if (inline && typeof inline.contents === 'string') {
				viewerEl.style.display = '';
				viewerEl.textContent = '// ' + path + '\n\n' + inline.contents;
				return;
			}

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
	}
}

// ── Drafts panel ────────────────────────────────────────────────
//
// The panel is the canonical view of draft state. It polls /agent/drafts
// every 3s while ANY draft is in flight (status=pending or running)
// and stops when everything is in a terminal state (drafted/success/failed).
// This is what makes "click Generate, close the modal, walk away" work:
// the panel keeps tracking state independently of any open modal.

const POLL_INTERVAL_DRAFTS_MS = 3000;
let draftsPollHandle = null;

async function refreshDrafts() {
	if (!appData?.agentDraftsUrl) return;
	try {
		const res = await apiFetch(appData.agentDraftsUrl);
		const drafts = res.drafts || [];
		renderDraftsFromData(drafts);

		// Start or stop the poll loop based on whether anything's in flight.
		const anyInFlight = drafts.some(d => d.in_flight);
		if (anyInFlight && !draftsPollHandle) {
			draftsPollHandle = setTimeout(refreshDrafts, POLL_INTERVAL_DRAFTS_MS);
		} else if (anyInFlight) {
			clearTimeout(draftsPollHandle);
			draftsPollHandle = setTimeout(refreshDrafts, POLL_INTERVAL_DRAFTS_MS);
		} else if (draftsPollHandle) {
			clearTimeout(draftsPollHandle);
			draftsPollHandle = null;
		}
	} catch (err) {
		log.error('[agent] refresh drafts failed', err);
		// Retry once after a longer delay so a transient REST error
		// doesn't kill the polling loop entirely.
		if (draftsPollHandle) {
			clearTimeout(draftsPollHandle);
			draftsPollHandle = setTimeout(refreshDrafts, POLL_INTERVAL_DRAFTS_MS * 3);
		}
	}
}

function startDraftsPolling() {
	if (draftsPollHandle) return;
	draftsPollHandle = setTimeout(refreshDrafts, POLL_INTERVAL_DRAFTS_MS);
}

function renderDraftsFromData(drafts) {
	const sectionEl = document.getElementById('ep-agent-drafts-section');
	const listEl = document.getElementById('ep-agent-drafts-list');
	if (!sectionEl || !listEl) return;

	if (!Array.isArray(drafts) || drafts.length === 0) {
		sectionEl.style.display = 'none';
		listEl.innerHTML = '';
		return;
	}

	sectionEl.style.display = '';

	const now = Date.now() / 1000;

	listEl.innerHTML = drafts.map(d => {
		const ts = d.updated_at ? new Date(d.updated_at * 1000).toLocaleString() : '';
		const stage = d.post_status === 'draft' ? 'never pushed' : 'pending iteration';
		const stageColor = d.post_status === 'draft' ? '#7c3aed' : '#ca8a04';

		const status = d.draft_status || '';
		const statusColor = {
			drafted: '#16a34a',
			success: '#16a34a',
			failed:  '#9b2c2c',
			running: '#2563eb',
			pending: '#6b7280',
		}[status] || '#6b7280';

		const cs = d.change_summary || {};
		const csLine = (cs.modified || cs.added || cs.removed)
			? `<span style="margin-left:8px;font-size:11px;color:#6b7280;">${cs.modified || 0} mod · ${cs.added || 0} add · ${cs.removed || 0} del · ${cs.unchanged || 0} unchanged</span>`
			: '';

		// In-flight visual: spinner glyph + elapsed time. The panel
		// polls every 3s so this updates without user action.
		const inFlight = !!d.in_flight;
		const isStalled = !!d.stalled;
		const elapsedSec = d.updated_at ? Math.max(0, Math.floor(now - d.updated_at)) : 0;
		const elapsedLabel = elapsedSec < 60
			? `${elapsedSec}s`
			: `${Math.floor(elapsedSec / 60)}m ${elapsedSec % 60}s`;

		const badgeColor = isStalled ? '#ca8a04' : statusColor;
		const badgeLabel = isStalled ? `⚠ stalled · ${elapsedLabel}` : (inFlight ? `⟳ ${escapeHtml(status)} · ${elapsedLabel}` : escapeHtml(status));
		const statusBadge = (inFlight || isStalled)
			? `<span style="display:inline-block;background:${badgeColor};color:white;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;margin-left:4px;">${badgeLabel}</span>`
			: (status ? `<span style="display:inline-block;background:${statusColor};color:white;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;margin-left:4px;">${escapeHtml(status)}</span>` : '');

		// Prompt line with copy button so the user can see what was asked.
		const promptBlock = d.prompt
			? `<div style="font-size:11px;color:#6b7280;margin-bottom:8px;display:flex;align-items:baseline;gap:6px;">
				<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeAttr(d.prompt)}">${escapeHtml(d.prompt.length > 120 ? d.prompt.slice(0, 120) + '…' : d.prompt)}</span>
				<button type="button" data-copy-prompt="${escapeAttr(d.prompt)}" style="flex-shrink:0;background:none;border:1px solid #d1d5db;border-radius:4px;padding:1px 6px;font-size:10px;color:#6b7280;cursor:pointer;" title="Copy prompt">Copy</button>
			</div>`
			: '';

		// Errors panel for failed drafts. Prefer the server-rendered
		// friendly_errors summary when available — raw validator
		// output is too technical for the panel.
		const friendlyDraft = Array.isArray(d.friendly_errors) && d.friendly_errors.length
			? d.friendly_errors.map(f => f.summary).join(' · ')
			: '';
		const rawDraft = Array.isArray(d.errors) ? d.errors.join(' · ') : '';
		const draftErrorText = friendlyDraft || rawDraft;
		const errorBlock = (status === 'failed' && draftErrorText)
			? `<div style="font-size:11px;color:#9b2c2c;margin-bottom:8px;background:#fef2f2;border-left:2px solid #fecaca;padding:6px 10px;border-radius:0 4px 4px 0;">${escapeHtml(draftErrorText.slice(0, 300))}</div>`
			: '';

		// Action gating:
		// - in-flight → "Working…"
		// - stalled (5+ min) → warning + Discard
		// - review → Resume + Push (always actionable on success)
		// - failed with payload → Resume + Repair
		// - failed without payload → Retry Generate
		const canAct = status === 'drafted' || status === 'failed';
		const hasPayload = !!d.has_payload;
		let resumeAction, repairAction, pushAction;
		pushAction = '';
		const isOrphan = !status && !inFlight && d.post_status === 'draft';
		if (isOrphan) {
			resumeAction = `<span style="font-size:12px;color:#6b7280;">Orphaned draft — discard to free the slug.</span>`;
			repairAction = '';
		} else if (isStalled) {
			resumeAction = `<span style="font-size:12px;color:#ca8a04;font-weight:600;">⚠ Appears stalled — the background job may not have run. Discard and retry.</span>`;
			repairAction = '';
		} else if (!canAct) {
			resumeAction = `<span style="font-size:12px;color:#9ca3af;">Working… (you can leave this page)</span>`;
			repairAction = '';
		} else if (status === 'drafted') {
			resumeAction = `<a href="#" data-agent-resume-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#7c3aed;text-decoration:underline;">Review Files</a>`;
			pushAction = `<a href="#" data-agent-push-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#16a34a;font-weight:600;text-decoration:underline;">Push to GitHub</a>`;
			repairAction = `<a href="#" data-agent-iterate-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#2563eb;text-decoration:underline;">Iterate</a>`;
		} else if (hasPayload) {
			resumeAction = `<a href="#" data-agent-resume-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#7c3aed;text-decoration:underline;">Resume / Iterate</a>`;
			repairAction = `<a href="#" data-agent-repair="${escapeHtml(d.slug)}" data-agent-repair-error="${escapeAttr((d.errors || []).join(' '))}" style="font-size:12px;color:#ca8a04;text-decoration:underline;">🛠 Repair</a>`;
		} else {
			resumeAction = `<a href="#" data-agent-retry-generate="${escapeHtml(d.slug)}" data-agent-retry-prompt="${escapeAttr(d.prompt || '')}" data-agent-retry-name="${escapeAttr(d.name || '')}" data-agent-retry-slug="${escapeAttr(d.slug || '')}" data-agent-retry-desc="" style="font-size:12px;color:#7c3aed;text-decoration:underline;">Retry Generate</a>`;
			repairAction = '';
		}

		return `
			<div style="border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;margin-bottom:8px;background:${inFlight ? '#fafaff' : '#fafafa'};">
				<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
					<div>
						<strong>${escapeHtml(d.name || d.slug)}</strong>
						<code style="font-size:11px;color:#6b7280;margin-left:6px;">${escapeHtml(d.slug)}</code>
						${d.version ? `<span style="font-size:11px;color:#6b7280;margin-left:6px;">v${escapeHtml(d.version)}</span>` : ''}
					</div>
					<div>
						<span style="display:inline-block;background:${stageColor};color:white;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;text-transform:uppercase;">${stage}</span>
						${statusBadge}
					</div>
				</div>
				<div style="font-size:11px;color:#9ca3af;margin-bottom:8px;">
					${d.files_count || 0} files · last update ${escapeHtml(ts)}${csLine}
				</div>
				${promptBlock}
				${errorBlock}
				${renderDraftLog(d.log)}
				<div style="display:flex;gap:12px;align-items:center;">
					${resumeAction}
					${repairAction}
					${pushAction}
					<a href="#" data-agent-discard-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#9b2c2c;text-decoration:underline;margin-left:auto;">Discard draft</a>
				</div>
			</div>
		`;
	}).join('');
}

// ── Review Modal ───────────────────────────────────────────────

async function openReviewModal(slug) {
	const slugEl      = document.getElementById('ep-agent-review-slug');
	const fileListEl  = document.getElementById('ep-agent-review-file-list');
	const contentsEl  = document.getElementById('ep-agent-review-file-contents');
	const summaryEl   = document.getElementById('ep-agent-review-summary');
	const pushBtn     = document.getElementById('ep-agent-review-push-btn');

	slugEl.textContent = slug;
	fileListEl.innerHTML = '<div style="padding:8px 12px;color:#9ca3af;">Loading…</div>';
	contentsEl.textContent = '';
	summaryEl.textContent = '';
	pushBtn.disabled = false;
	pushBtn.textContent = 'Push to GitHub';
	pushBtn.onclick = null;

	openAppModal('ep-agent-review-modal');

	try {
		const url = appData.agentDraftUrl.replace('__SLUG__', encodeURIComponent(slug));
		const data = await apiFetch(url);
		const files = data.payload?.files || [];
		const totalBytes = files.reduce((s, f) => s + (f.bytes || 0), 0);
		summaryEl.textContent = `${files.length} files · ${formatBytes(totalBytes)}${data.payload?.version ? ' · v' + data.payload.version : ''}`;

		fileListEl.innerHTML = files.map((f, i) => {
			const change = f.change || '';
			const badge = change === 'modified' ? '~' : change === 'added' ? '+' : change === 'removed' ? '−' : '';
			const badgeColor = change === 'modified' ? '#ca8a04' : change === 'added' ? '#16a34a' : change === 'removed' ? '#9b2c2c' : '';
			return `<div data-review-file-index="${i}" style="padding:4px 12px;cursor:pointer;display:flex;gap:6px;align-items:center;border-left:2px solid transparent;" title="${escapeAttr(f.path)}">
				${badge ? `<span style="color:${badgeColor};font-weight:700;font-size:10px;flex-shrink:0;">${badge}</span>` : ''}
				<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(f.path)}</span>
				<span style="margin-left:auto;color:#9ca3af;flex-shrink:0;font-size:10px;">${formatBytes(f.bytes || 0)}</span>
			</div>`;
		}).join('');

		// Click a file to show its contents
		const showFile = (index) => {
			const f = files[index];
			if (!f) return;
			contentsEl.textContent = f.contents || '';
			// Highlight active row
			fileListEl.querySelectorAll('[data-review-file-index]').forEach(row => {
				row.style.background = parseInt(row.dataset.reviewFileIndex) === index ? '#f3f4f6' : '';
				row.style.borderLeftColor = parseInt(row.dataset.reviewFileIndex) === index ? '#7c3aed' : 'transparent';
			});
		};
		fileListEl.addEventListener('click', (e) => {
			const row = e.target.closest('[data-review-file-index]');
			if (row) showFile(parseInt(row.dataset.reviewFileIndex));
		});

		// Auto-select first file
		if (files.length > 0) showFile(0);

		// Push button
		pushBtn.onclick = async () => {
			if (!confirm(`Push "${slug}" to GitHub? A new release will be created and the plugin installed.`)) return;
			pushBtn.disabled = true;
			pushBtn.textContent = 'Pushing…';
			try {
				const commitUrl = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
				const res = await apiFetch(commitUrl, { method: 'POST' });
				if (res.success) {
					closeAppModal('ep-agent-review-modal');
					await refreshDrafts();
					refreshAppsTable();
				} else {
					alert('Push failed: ' + (res.message || 'Unknown error'));
					pushBtn.disabled = false;
					pushBtn.textContent = 'Push to GitHub';
				}
			} catch (err) {
				alert('Push failed: ' + (err.message || err));
				pushBtn.disabled = false;
				pushBtn.textContent = 'Push to GitHub';
			}
		};
	} catch (err) {
		fileListEl.innerHTML = `<div style="padding:8px 12px;color:#9b2c2c;">Failed to load draft: ${escapeHtml(err.message || '')}</div>`;
	}
}

function renderDraftLog(log) {
	if (!Array.isArray(log) || log.length === 0) return '';
	const entries = log.slice(-8).map(e => {
		const t = e.ts ? new Date(e.ts * 1000).toLocaleTimeString() : '';
		return `<div style="display:flex;gap:6px;align-items:baseline;">
			<span style="flex-shrink:0;color:#9ca3af;min-width:60px;">${escapeHtml(t)}</span>
			<span>${escapeHtml(e.msg || '')}</span>
		</div>`;
	}).join('');
	return `<details style="font-size:11px;color:#6b7280;margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:6px 10px;" open>
		<summary style="cursor:pointer;font-weight:600;margin-bottom:4px;">Activity log</summary>
		${entries}
	</details>`;
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
