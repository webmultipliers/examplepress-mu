/**
 * Dashboard — Notifications tab: cards, archive/restore, subtabs.
 */
import { esc } from '../lib/dom.js';
import { log } from '../lib/logger.js';
import {
	$activeNotifications,
	$archivedNotifications,
	archiveNotification,
	restoreNotification,
} from '../stores/notifications.js';

export function renderNotifications(updateTabCounts) {
	function render() {
		const active = $activeNotifications.get();
		const archivedList = $archivedNotifications.get();

		const activeEl = document.getElementById('notices-active');
		const archivedEl = document.getElementById('notices-archived');
		if (!activeEl || !archivedEl) return;

		function renderCard(n, isArchived) {
			const typeMap = { error: 'notif-error', warn: 'notif-warn', info: 'notif-info' };
			const cls = typeMap[n.type] || 'notif-info';
			const action = isArchived ? 'restore' : 'archive';
			const btnLabel = isArchived ? 'Restore' : 'Archive';
			return `<div class="ep-notif-card ${cls}">
				<div class="ep-notif-top">
					<span class="ep-notif-title">${esc(n.title)}</span>
					<button class="ep-notif-action" data-id="${esc(n.id)}" data-action="${action}">${btnLabel}</button>
				</div>
				<p class="ep-notif-msg">${esc(n.message)}</p>
			</div>`;
		}

		activeEl.innerHTML = active.length
			? active.map(n => renderCard(n, false)).join('')
			: '<p class="ep-notif-empty">No active notifications.</p>';

		archivedEl.innerHTML = archivedList.length
			? archivedList.map(n => renderCard(n, true)).join('')
			: '<p class="ep-notif-empty">No archived notifications.</p>';

		// Bind archive/restore buttons.
		document.querySelectorAll('.ep-notif-action').forEach(btn => {
			btn.addEventListener('click', async () => {
				const id = btn.dataset.id;
				const action = btn.dataset.action;

				if (action === 'archive') {
					log.info(`[ExamplePress] Notification archived: ${id}`);
					await archiveNotification(id);
				} else if (action === 'restore') {
					log.info(`[ExamplePress] Notification restored: ${id}`);
					await restoreNotification(id);
				}
			});
		});
	}

	// Subscribe to store changes for reactive re-renders.
	$activeNotifications.subscribe(() => {
		render();
		if (updateTabCounts) updateTabCounts();
	});

	// Subtab switching.
	document.querySelectorAll('.ep-notif-subtab').forEach(btn => {
		btn.addEventListener('click', () => {
			document.querySelectorAll('.ep-notif-subtab').forEach(b => b.classList.remove('active'));
			btn.classList.add('active');
			const target = btn.dataset.target;
			document.getElementById('notices-active').style.display = target === 'notices-active' ? '' : 'none';
			document.getElementById('notices-archived').style.display = target === 'notices-archived' ? '' : 'none';
		});
	});

	// Initial render.
	render();
}
