/**
 * Dependencies page entry point.
 * Tabs: Required (default), Recommended
 */
import '../css/base.css';

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initModal, initEscapeHandler } from '../lib/modal.js';
import { renderDependencies } from './dependencies.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initModal();
	initEscapeHandler();
	initTabs('required');

	renderDependencies(data.dependencies);

	log.info('[ExamplePress] Dependencies page ready.');
});
