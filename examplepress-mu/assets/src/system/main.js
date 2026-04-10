/**
 * System page entry point.
 * Tabs: Health (default), Features, Routes, Blocks
 */

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs, updateTabCount } from '../lib/tabs.js';
import { getUrlParams } from '../lib/url.js';
import { initModal, initEscapeHandler } from '../lib/modal.js';
import { renderHealth } from './health.js';
import { renderFeatures } from './features.js';
import { renderRoutes } from './routes.js';
import { renderBlocks } from './blocks.js';
import { renderConfig } from './config.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initModal();
	initEscapeHandler();
	initTabs('health');

	renderHealth(data.healthChecks);
	const featureCount = renderFeatures(data.features, data.featureDetails);
	const routeCount = renderRoutes();
	const blockCount = renderBlocks(data.blocks);
	renderConfig(data.configFiles, getUrlParams());

	updateTabCount('t-features', featureCount);
	updateTabCount('t-routes', routeCount);
	updateTabCount('t-blocks', blockCount);

	// Copy system report button.
	const copyBtn = document.getElementById('ep-copy-report');
	if (copyBtn) {
		copyBtn.addEventListener('click', () => {
			const report = JSON.stringify(data, null, 2);
			navigator.clipboard.writeText(report).then(() => {
				copyBtn.classList.add('copied');
				copyBtn.textContent = 'Copied!';
				setTimeout(() => {
					copyBtn.classList.remove('copied');
					copyBtn.textContent = 'Copy System Report';
				}, 2000);
			});
		});
	}

	log.info('[ExamplePress] System page ready.');
});
