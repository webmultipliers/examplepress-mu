/**
 * Updates page entry point.
 *
 * Three tabs, each driving a different update surface:
 *   Theme  → ThemeUpdateProvider (/theme-update/*)
 *   Kernel → Infrastructure\Updater (/updates/kernel/*)
 *   Apps   → AppUpdateProvider (/updates/apps/*)
 */
import '../css/base.css';
import '../css/updates.css';

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initNotices } from './ui.js';
import { renderThemeUpdate } from './theme-update.js';
import { renderKernelUpdate } from './kernel-update.js';
import { renderAppsUpdate } from './apps-update.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('theme-update');
	initNotices(document.getElementById('ep-updates-notices'));

	renderThemeUpdate(data);
	renderKernelUpdate(data);
	renderAppsUpdate(data);

	log.info('[ExamplePress] Updates page ready.');
});
