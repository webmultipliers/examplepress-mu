/**
 * Apps store — manages companion plugin list state.
 */
import { atom } from 'nanostores';

export const $apps = atom([]);

export function initApps(data) {
	$apps.set((data.apps || []).slice());
}

export function addApp(app) {
	$apps.set([app, ...$apps.get()]);
}

export function updateApp(slug, updatedApp) {
	const current = $apps.get();
	const idx = current.findIndex(a => a.slug === slug);
	if (idx >= 0) {
		const next = [...current];
		next[idx] = updatedApp;
		$apps.set(next);
	} else {
		addApp(updatedApp);
	}
}

export function removeApp(slug) {
	$apps.set($apps.get().filter(a => a.slug !== slug));
}

export function deactivateApp(slug) {
	const current = $apps.get();
	const idx = current.findIndex(a => a.slug === slug);
	if (idx >= 0) {
		const next = [...current];
		next[idx] = { ...next[idx], active: false };
		$apps.set(next);
	}
}
