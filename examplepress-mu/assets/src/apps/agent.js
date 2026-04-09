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
			openIterateModal(resume.dataset.agentResumeDraft);
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
	const submitEl = document.getElementById('ep-agent-submit');

	const prompt = (promptEl?.value || '').trim();
	hide(errorEl);

	if (prompt.length < 5) {
		showError(errorEl, 'Prompt must be at least 5 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Starting…';

	try {
		// Fire-and-track: enqueue the job, then IMMEDIATELY close the
		// modal and surface the in-flight draft in the panel below.
		// The user can navigate away, come back later, and the drafts
		// panel will show the result whenever it's ready. The modal
		// is no longer a trap.
		await apiFetch(appData.agentGenerateUrl, { method: 'POST', body: { prompt } });

		closeAppModal('ep-agent-modal');
		await refreshDrafts();
		startDraftsPolling();

		// Brief flash on the drafts section so the user notices the
		// new placeholder card appearing.
		const sectionEl = document.getElementById('ep-agent-drafts-section');
		if (sectionEl) {
			sectionEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	} catch (err) {
		log.error('[agent] generate failed', err);
		showError(errorEl, err.message || 'Generation failed to start.');
	} finally {
		submitEl.disabled = false;
		submitEl.textContent = 'Generate Draft';
	}
}

async function onGenerateCommit() {
	// In the new flow, the Generate modal closes immediately after
	// submit so this button is rarely reached — but keep it for the
	// case where the user happens to still have the modal open when
	// the LLM returns. Push routes through the draft-commit endpoint
	// (post-meta payload, slug-keyed) instead of the job ID.
	const errorEl = document.getElementById('ep-agent-error');
	const stepsEl = document.getElementById('ep-agent-steps');
	const commitBtn = document.getElementById('ep-agent-commit-btn');
	const slug = document.querySelector('[data-current-generate-slug]')?.dataset.currentGenerateSlug;
	if (!slug) {
		showError(errorEl, 'No draft slug — refresh the panel below and use the Resume button.');
		return;
	}
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		const url = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url, { method: 'POST' });
		if (res.success) {
			renderStep(stepsEl, 'done');
			setTimeout(async () => {
				closeAppModal('ep-agent-modal');
				await refreshDrafts();
				refreshAppsTable();
			}, 600);
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

	// If the user is resuming a draft that already has a stashed
	// payload (status=review), render the preview pane + Push button
	// so they can review what's there before either pushing or
	// iterating further. This is the canonical "I left and came back"
	// experience.
	try {
		const draftUrl = appData.agentDraftUrl.replace('__SLUG__', encodeURIComponent(slug));
		const draft = await apiFetch(draftUrl);
		if (draft && draft.payload) {
			const fakeJob = {
				id:    'resume-' + slug,
				draft: draft.payload,
			};
			currentIterateJobId = fakeJob.id;
			renderDraftPreview(fakeJob, 'ep-agent-iterate');
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
	const submitEl = document.getElementById('ep-agent-iterate-submit');

	hide(errorEl);

	if (prompt.length < 5) {
		showError(errorEl, 'Change request must be at least 5 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Starting…';

	// Optimistically append the user bubble to the chat thread.
	const threadEl = document.getElementById('ep-agent-chat-thread');
	const optimistic = document.createElement('div');
	optimistic.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:8px;"><div style="max-width:75%;background:#7c3aed;color:white;padding:8px 12px;border-radius:12px 12px 2px 12px;font-size:13px;">${escapeHtml(prompt)}</div></div>`;
	threadEl.appendChild(optimistic.firstChild);
	threadEl.scrollTop = threadEl.scrollHeight;

	try {
		const url = appData.agentIterateUrl.replace('__SLUG__', encodeURIComponent(slug));
		await apiFetch(url, { method: 'POST', body: { prompt } });

		// Fire-and-track: close modal, refresh panel, start polling.
		// The user can leave and come back; the iteration result lives
		// on the post and shows up in the drafts panel when ready.
		promptEl.value = '';
		closeAppModal('ep-agent-iterate-modal');
		await refreshDrafts();
		startDraftsPolling();
	} catch (err) {
		log.error('[agent] iterate failed', err);
		showError(errorEl, err.message || 'Iteration failed to start.');
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
		// The iterate modal's Push button always commits whatever is
		// stashed on the draft post for this slug — that includes both
		// freshly-iterated drafts (job still in flight) and resumed
		// drafts (original job long gone). The draft-commit endpoint
		// reads the post-meta payload, no job state required.
		const url = appData.agentDraftCommitUrl.replace('__SLUG__', encodeURIComponent(slug));
		const res = await apiFetch(url, { method: 'POST' });
		if (res.success) {
			renderStep(stepsEl, 'done');
			hide(document.getElementById('ep-agent-iterate-draft-preview'));
			hide(commitBtn);
			hide(document.getElementById('ep-agent-iterate-discard-btn'));
			currentIterateJobId = null;
			closeAppModal('ep-agent-iterate-modal');
			await refreshDrafts();
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

	hide(errorEl);

	if (errorMsg.length < 3) {
		showError(errorEl, 'Error message must be at least 3 characters.');
		return;
	}

	submitEl.disabled = true;
	submitEl.textContent = 'Starting…';

	try {
		const url = appData.agentRepairUrl.replace('__SLUG__', encodeURIComponent(slug));
		await apiFetch(url, {
			method: 'POST',
			body: {
				error_message: errorMsg,
				error_file:    errorFile,
				error_line:    errorLine,
				prompt:        prompt,
			},
		});

		// Fire-and-track. Close immediately, panel takes over.
		closeAppModal('ep-agent-repair-modal');
		await refreshDrafts();
		startDraftsPolling();
	} catch (err) {
		log.error('[agent] repair failed', err);
		showError(errorEl, err.message || 'Repair failed to start.');
	} finally {
		submitEl.disabled = false;
		submitEl.textContent = 'Diagnose & Draft Fix';
	}
}

async function onRepairCommit() {
	if (!currentRepairJobId) return;
	const errorEl = document.getElementById('ep-agent-repair-error-msg');
	const stepsEl = document.getElementById('ep-agent-repair-steps');
	const commitBtn = document.getElementById('ep-agent-repair-commit-btn');
	commitBtn.disabled = true;
	commitBtn.textContent = 'Pushing…';
	hide(errorEl);
	renderStep(stepsEl, 'pushing');
	try {
		const url = appData.agentJobCommitUrl.replace('__ID__', encodeURIComponent(currentRepairJobId));
		const res = await apiFetch(url, { method: 'POST' });
		if (res.success) {
			renderStep(stepsEl, 'done');
			setTimeout(() => { closeAppModal('ep-agent-repair-modal'); refreshAppsTable(); }, 800);
		} else {
			showError(errorEl, res.message || 'Push failed.');
			commitBtn.disabled = false;
			commitBtn.textContent = 'Push fix to GitHub';
		}
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
		return `<div style="padding:2px 0;${dim}">${tag} ${escapeHtml(f.path)} <span style="color:#9ca3af;float:right;">${formatBytes(f.bytes || 0)}</span></div>`;
	}).join('');
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
		const showRepair = j.status === 'failed' && j.target_slug;
		const repairBtn = showRepair
			? `<a href="#" data-agent-repair="${escapeHtml(j.target_slug)}" data-agent-repair-error="${escapeAttr(errorText)}" style="font-size:11px;color:#7c3aed;text-decoration:underline;margin-left:8px;">🛠 Repair</a>`
			: '';

		const errorLine = j.status === 'failed' && errorText
			? `<div style="font-size:11px;color:#9b2c2c;margin-top:4px;background:#fef2f2;border-left:2px solid #fecaca;padding:4px 8px;">${escapeHtml(errorText.slice(0, 200))}${errorText.length > 200 ? '…' : ''}</div>`
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

// ── Drafts panel ────────────────────────────────────────────────
//
// The panel is the canonical view of draft state. It polls /agent/drafts
// every 3s while ANY draft is in flight (queued/drafting/iterating/
// repairing/pushing) and stops when everything is in a terminal state.
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
			review:    '#16a34a',
			failed:    '#9b2c2c',
			drafting:  '#2563eb',
			iterating: '#2563eb',
			repairing: '#2563eb',
			pushing:   '#2563eb',
			queued:    '#6b7280',
		}[status] || '#6b7280';

		const cs = d.change_summary || {};
		const csLine = (cs.modified || cs.added || cs.removed)
			? `<span style="margin-left:8px;font-size:11px;color:#6b7280;">${cs.modified || 0} mod · ${cs.added || 0} add · ${cs.removed || 0} del · ${cs.unchanged || 0} unchanged</span>`
			: '';

		// In-flight visual: spinner glyph + elapsed time. The panel
		// polls every 3s so this updates without user action.
		const inFlight = !!d.in_flight;
		const elapsedSec = d.updated_at ? Math.max(0, Math.floor(now - d.updated_at)) : 0;
		const elapsedLabel = elapsedSec < 60
			? `${elapsedSec}s`
			: `${Math.floor(elapsedSec / 60)}m ${elapsedSec % 60}s`;

		const statusBadge = inFlight
			? `<span style="display:inline-block;background:${statusColor};color:white;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;margin-left:4px;">⟳ ${escapeHtml(status)} · ${elapsedLabel}</span>`
			: (status ? `<span style="display:inline-block;background:${statusColor};color:white;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;margin-left:4px;">${escapeHtml(status)}</span>` : '');

		// Errors panel for failed drafts.
		const errorBlock = (status === 'failed' && Array.isArray(d.errors) && d.errors.length)
			? `<div style="font-size:11px;color:#9b2c2c;margin-bottom:8px;background:#fef2f2;border-left:2px solid #fecaca;padding:6px 10px;border-radius:0 4px 4px 0;">${escapeHtml(d.errors.join(' · ').slice(0, 300))}</div>`
			: '';

		// Action gating: Resume only works once the draft has a payload
		// (status=review). While in-flight, show "Working…" instead.
		const canResume = status === 'review' || status === 'failed';
		const resumeAction = canResume
			? `<a href="#" data-agent-resume-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#7c3aed;text-decoration:underline;">Resume / Iterate</a>`
			: `<span style="font-size:12px;color:#9ca3af;">Working… (you can leave this page)</span>`;
		const repairAction = canResume
			? `<a href="#" data-agent-repair="${escapeHtml(d.slug)}" data-agent-repair-error="${escapeAttr((d.errors || []).join(' '))}" style="font-size:12px;color:#ca8a04;text-decoration:underline;">🛠 Repair</a>`
			: '';

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
				${errorBlock}
				<div style="display:flex;gap:12px;align-items:center;">
					${resumeAction}
					${repairAction}
					<a href="#" data-agent-discard-draft="${escapeHtml(d.slug)}" style="font-size:12px;color:#9b2c2c;text-decoration:underline;margin-left:auto;">Discard draft</a>
				</div>
			</div>
		`;
	}).join('');
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
