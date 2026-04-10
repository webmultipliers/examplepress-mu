/**
 * Docs page entry point.
 * Tabs: Guides (default), Hooks, Support
 */

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initModal, initEscapeHandler } from '../lib/modal.js';
import { renderDocs, renderHooks } from './docs.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initModal();
	initEscapeHandler();
	initTabs('guides');

	renderDocs(data.docs || []);
	renderHooks(data.hooks || []);

	log.info('[ExamplePress] Docs page ready.');
});
