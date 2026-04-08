/**
 * Updates page entry point.
 *
 * Currently one tab (Theme); structured as a tabbed page so additional
 * update surfaces (MU self-update status, companion-app update summary,
 * etc.) can be added later without a page restructure.
 */
import '../css/base.css';

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { renderThemeUpdate } from './theme-update.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('theme-update');

	renderThemeUpdate(data);

	log.info('[ExamplePress] Updates page ready.');
});
