/**
 * Updates → Kernel tab renderer.
 *
 * Backed by Infrastructure\Updater via the /updates/kernel/* REST
 * namespace (UpdatesController). The kernel updater is intentionally
 * cron-driven — there are NO manual check or install controls because
 * triggering an upgrade from inside the very kernel rendering this page
 * is a footgun. The only actions exposed are the two RECOVERY ones:
 * rollback to the previous on-disk snapshot, and clear quarantine state.
 * Both move you AWAY from a broken kernel toward a known-good one.
 */
import {
	toast, revealBody, showProgress, hideProgress,
	setBadge, api, esc, formatTimestamp, formatUntil,
} from './ui.js';

export function renderKernelUpdate(data) {
	const panel = document.getElementById('ep-kernel-update-panel');
	if (!panel) return;

	let status = data.kernelUpdate || null;
	let busy   = false;

	// ── DOM refs ─────────────────────────────────────────────────────
	const $ = (id) => document.getElementById(id);
	const curVer             = $('ep-kernel-update-current-ver');
	const remoteVer          = $('ep-kernel-update-remote-ver');
	const statusBadge        = $('ep-kernel-update-status-badge');
	const lastFetched        = $('ep-kernel-update-last-fetched');
	const nextScheduled      = $('ep-kernel-update-next-scheduled');
	const previous           = $('ep-kernel-update-previous');
	const rollbackBtn        = $('ep-kernel-update-rollback-btn');
	const quarantineSection  = $('ep-kernel-update-quarantine-section');
	const quarantineNotice   = $('ep-kernel-update-quarantine-notice');
	const clearQuarantineBtn = $('ep-kernel-update-clear-quarantine-btn');

	const required = { curVer, remoteVer, statusBadge, lastFetched, nextScheduled, previous, rollbackBtn };
	for (const [name, el] of Object.entries(required)) {
		if (!el) {
			console.warn(`[ExamplePress] Kernel update renderer: missing element "${name}". Aborting.`);
			return;
		}
	}

	// ── Render ───────────────────────────────────────────────────────

	function render() {
		if (!status) return;

		curVer.textContent    = status.current_version || '—';
		remoteVer.textContent = status.remote_version || '—';
		lastFetched.textContent = status.remote_fetched_at
			? formatTimestamp(status.remote_fetched_at)
			: 'Never';
		nextScheduled.textContent = status.next_scheduled
			? `${formatTimestamp(status.next_scheduled)} (${formatUntil(status.next_scheduled)})`
			: '—';
		previous.textContent = status.previous_version_available ? 'Available' : 'None';

		if (!status.remote_version) {
			setBadge(statusBadge, 'info', 'Not yet checked by cron');
		} else if (status.update_available) {
			setBadge(statusBadge, 'update', `Cron will install ${status.remote_version}`);
		} else {
			setBadge(statusBadge, 'current', 'Up to date');
		}

		// Rollback button shown when a previous snapshot exists on disk.
		if (status.previous_version_available) {
			rollbackBtn.hidden = false;
			rollbackBtn.disabled = busy;
		} else {
			rollbackBtn.hidden = true;
		}

		// Quarantine banner.
		if (status.quarantined && status.boot_attempts >= 3) {
			quarantineSection.hidden = false;
			const when = status.quarantined.time
				? formatTimestamp(status.quarantined.time)
				: 'unknown time';
			const attempts = status.quarantined.attempts || status.boot_attempts;
			quarantineNotice.innerHTML =
				`Kernel quarantined at <strong>${esc(when)}</strong> after <strong>${attempts}</strong> failed boot attempts. ` +
				`Boot counter is currently at <strong>${status.boot_attempts}</strong>.` +
				(status.coldstart_error ? ` Last cold-start error: <em>${esc(status.coldstart_error)}</em>.` : '');
		} else {
			quarantineSection.hidden = true;
		}
	}

	// ── Loading ──────────────────────────────────────────────────────

	async function refreshStatus() {
		try {
			status = await api('GET', data.kernelUpdateStatusUrl);
			render();
		} catch (err) {
			toast('error', `Could not load kernel status: ${err.message}`);
		}
	}

	// ── Actions ──────────────────────────────────────────────────────

	async function handleRollback() {
		if (!status || !status.previous_version_available) return;
		const confirmed = window.confirm(
			'Rollback the kernel to the previous version?\n\n' +
			'The previous snapshot on disk will become the active kernel on the next request. ' +
			'This is irreversible without reinstalling.'
		);
		if (!confirmed) return;

		setBusy(true);
		showProgress('ep-kernel-update-progress', 'Rolling back kernel…');
		try {
			const res = await api('POST', data.kernelUpdateRollbackUrl);
			status = res.status;
			render();
			toast('success', res.message || 'Rollback complete.');
		} catch (err) {
			toast('error', err.message);
		} finally {
			hideProgress('ep-kernel-update-progress');
			setBusy(false);
		}
	}

	async function handleClearQuarantine() {
		const confirmed = window.confirm(
			'Clear the quarantine state?\n\n' +
			'The loader will attempt a normal boot on the next request. ' +
			'Only do this after you\'ve remediated whatever caused the fatal loop.'
		);
		if (!confirmed) return;

		setBusy(true);
		try {
			const res = await api('POST', data.kernelUpdateClearQuarantineUrl);
			status = res.status;
			render();
			toast('success', res.message || 'Quarantine cleared.');
		} catch (err) {
			toast('error', err.message);
		} finally {
			setBusy(false);
		}
	}

	function setBusy(value) {
		busy = value;
		rollbackBtn.disabled = value || !(status && status.previous_version_available);
		if (clearQuarantineBtn) clearQuarantineBtn.disabled = value;
	}

	// ── Init ─────────────────────────────────────────────────────────

	rollbackBtn.addEventListener('click', handleRollback);
	if (clearQuarantineBtn) clearQuarantineBtn.addEventListener('click', handleClearQuarantine);

	if (status) {
		revealBody('ep-kernel-update-skeleton', 'ep-kernel-update-body');
		render();
	} else {
		refreshStatus().then(() => {
			revealBody('ep-kernel-update-skeleton', 'ep-kernel-update-body');
		});
	}
}
