/**
 * Notifications store — manages active and archived notifications.
 */
import { atom, computed } from 'nanostores';
import { apiFetch } from '../lib/api.js';

export const $notifications = atom([]);
export const $archived = atom([]);

let restUrl = '';

export function initNotifications(data) {
	$notifications.set(data.notifications || []);
	$archived.set(data.archived || []);
	restUrl = data.restUrl || '';
}

export const $activeNotifications = computed(
	[$notifications, $archived],
	(notifs, archived) => notifs.filter(n => !archived.includes(n.id))
);

export const $archivedNotifications = computed(
	[$notifications, $archived],
	(notifs, archived) => notifs.filter(n => archived.includes(n.id))
);

export async function archiveNotification(id) {
	const current = $archived.get();
	if (!current.includes(id)) {
		$archived.set([...current, id]);
	}
	await apiFetch(restUrl, {
		method: 'POST',
		body: { id, action: 'archive' },
	});
}

export async function restoreNotification(id) {
	$archived.set($archived.get().filter(i => i !== id));
	await apiFetch(restUrl, {
		method: 'POST',
		body: { id, action: 'restore' },
	});
}
