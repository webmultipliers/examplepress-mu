/**
 * Theme page entry point.
 * Tabs: Color Palette (default), Layout, Typography, Size Scale
 */
import '../css/base.css';

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { renderDesign } from './design.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initTabs('colors');

	const { colors, layout, fonts, sizes } = data;
	renderDesign(colors || [], layout, fonts || [], sizes || []);

	log.info('[ExamplePress] Theme page ready.');
});
