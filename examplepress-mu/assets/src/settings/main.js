/**
 * Settings page entry point.
 * Tabs: GitHub (default), Troy
 */

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initConnections } from '../stores/connections.js';
import { initConnectionsUI } from './connections.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('github');

	initConnections(data);
	initConnectionsUI();

	log.info('[ExamplePress] Settings page ready.');
});
