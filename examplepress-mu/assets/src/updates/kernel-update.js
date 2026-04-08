/**
 * Updates → Kernel tab renderer.
 *
 * Backed by Infrastructure\Updater via the /updates/kernel/* REST
 * namespace (UpdatesController). Exposes what the cron-based self-updater
 * already knows, plus manual check / update / rollback / clear-quarantine
 * actions.
 */
import { log } from '../lib/logger.js';
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
	const curVer        = document.getElementById('ep-kernel-update-current-ver');
	const remoteVer     = document.getElementById('ep-kernel-update-remote-ver');
	const statusBadge   = document.getElementById('ep-kernel-update-status-badge');
	const lastFetched   = document.getElementById('ep-kernel-update-last-fetched');
	const nextScheduled = document.getElementById('ep-kernel-update-next-scheduled');
	const previous      = document.getElementById('ep-kernel-update-previous');
	const checkBtn      = document.getElementById('ep-kernel-update-check-btn');
	const installBtn    = document.getElementById('ep-kernel-update-install-btn');
	const rollbackBtn   = document.getElementById('ep-kernel-update-rollback-btn');
	const quarantineSection = document.getElementById('ep-kernel-update-quarantine-section');
	const quarantineNotice  = document.getElementById('ep-kernel-update-quarantine-notice');
	const clearQuarantineBtn = document.getElementById('ep-kernel-update-clear-quarantine-btn');

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
			setBadge(statusBadge, 'info', 'Not yet checked');
		} else if (status.update_available) {
			setBadge(statusBadge, 'update', `Update to ${status.remote_version}`);
		} else {
			setBadge(statusBadge, 'current', 'Up to date');
		}

		// Install button shown when a newer remote version is known.
		if (status.update_available) {
			installBtn.hidden = false;
			installBtn.disabled = busy;
			installBtn.textContent = `Install ${status.remote_version}`;
		} else {
			installBtn.hidden = true;
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

	async function handleCheck() {
		setBusy(true);
		try {
			const res = await api('POST', data.kernelUpdateCheckUrl);
			status = res.status;
			render();
			toast('success', status.update_available
				? `Update available: ${status.remote_version}`
				: 'Kernel is up to date.');
		} catch (err) {
			toast('error', err.message);
		} finally {
			setBusy(false);
		}
	}

	async function handleInstall() {
		if (!status || !status.update_available) return;
		const confirmed = window.confirm(
			`Install kernel v${status.remote_version}?\n\n` +
			'The new code will take effect on the next request. ' +
			'If the new kernel fatals, the loader will auto-rollback to the previous version.'
		);
		if (!confirmed) return;

		setBusy(true);
		showProgress('ep-kernel-update-progress', `Installing kernel v${status.remote_version}…`);
		try {
			const res = await api('POST', data.kernelUpdateInstallUrl);
			status = res.status;
			render();
			toast('success', res.message || 'Kernel updated.');
			log.info(`[ExamplePress] Kernel updated to ${status.current_version}`);
		} catch (err) {
			toast('error', err.message);
		} finally {
			hideProgress('ep-kernel-update-progress');
			setBusy(false);
		}
	}

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
		checkBtn.disabled    = value;
		installBtn.disabled  = value || !(status && status.update_available);
		rollbackBtn.disabled = value || !(status && status.previous_version_available);
		if (clearQuarantineBtn) clearQuarantineBtn.disabled = value;
	}

	// ── Init ─────────────────────────────────────────────────────────

	checkBtn.addEventListener('click', handleCheck);
	installBtn.addEventListener('click', handleInstall);
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
