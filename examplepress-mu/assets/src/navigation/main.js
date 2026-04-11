/**
 * Navigation page entry point.
 * Tabs: Locations (default), Menus
 */

import '../css/index.css';
import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { renderNavigation } from './navigation.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('locations');

	renderNavigation(data.navigation);

	log.info('[ExamplePress] Navigation page ready.');
});
