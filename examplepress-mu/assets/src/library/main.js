/**
 * Library page entry point.
 * Single panel — coming soon placeholder.
 */
import '../css/base.css';

import { initLogger, log } from '../lib/logger.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	log.info('[ExamplePress] Library page ready.');
});
