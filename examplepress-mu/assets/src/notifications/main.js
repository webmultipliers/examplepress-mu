/**
 * Notifications page entry point.
 * Tabs: Active (default), Archived
 */

import '../css/index.css';
import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initNotifications } from '../stores/notifications.js';
import { renderNotifications } from './notifications.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('active');

	initNotifications(data);
	renderNotifications(() => {});

	log.info('[ExamplePress] Notifications page ready.');
});
