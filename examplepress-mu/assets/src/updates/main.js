/**
 * Updates page entry point.
 *
 * Two tabs, each driving a different update surface:
 *   Theme  → ThemeUpdateProvider (/theme-update/*)
 *   Kernel → Infrastructure\Updater (/updates/kernel/*) — read-only status + recovery actions
 *
 * Companion app updates are intentionally NOT here — they live on the
 * dedicated Apps page and publish into the native WordPress Plugins screen
 * via AppUpdateProvider. Surfacing them in three places would just be noise.
 */
import '../css/index.css';
import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initNotices } from './ui.js';
import { renderThemeUpdate } from './theme-update.js';
import { renderKernelUpdate } from './kernel-update.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('theme-update');
	initNotices(document.getElementById('ep-updates-notices'));

	// Each renderer is wrapped so a failure in one tab cannot prevent the
	// other two from initialising. Errors land in the console with a clear
	// tag and the page degrades gracefully instead of going dark.
	const safeRender = (name, fn) => {
		try {
			fn(data);
		} catch (err) {
			log.error(`[ExamplePress] Updates → ${name} renderer failed: ${err.message}`);
			console.error(`[ExamplePress] Updates → ${name} renderer failed`, err);
		}
	};

	safeRender('theme',  renderThemeUpdate);
	safeRender('kernel', renderKernelUpdate);

	log.info('[ExamplePress] Updates page ready.');
});
