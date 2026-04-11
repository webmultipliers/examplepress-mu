/**
 * System Preview page entry point.
 *
 * The preview page is a static design-system reference — no dynamic data,
 * no stores, no API calls. All we need is tab switching so each component
 * category gets its own panel.
 */

import '../css/index.css';
import { initTabs } from '../lib/tabs.js';

document.addEventListener('DOMContentLoaded', () => {
	initTabs('tokens');
});
