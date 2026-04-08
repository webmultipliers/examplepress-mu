/**
 * Apps page entry point.
 * Tabs: Apps (default), Demo
 */
import '../css/base.css';

import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initModal, closeAppModal, initEscapeHandler } from '../lib/modal.js';
import { initApps } from '../stores/apps.js';
import { initAppsTable, renderAppsTable, initAppsOutsideClick } from './apps.js';
import { initScaffold } from './scaffold.js';
import { initTroyModal } from './troy-modal.js';
import { renderDemo } from './demo.js';
import { initAgent } from './agent.js';

document.addEventListener('DOMContentLoaded', () => {
	const data = window.ExamplePressData;
	if (!data) return;

	initLogger(data.devMode);
	initApi(data.nonce);
	initModal();
	initEscapeHandler(['ep-apps-scaffold-modal', 'ep-apps-troy-modal', 'ep-apps-codespace-modal', 'ep-agent-modal', 'ep-agent-iterate-modal', 'ep-agent-eject-modal', 'ep-agent-jobs-modal']);
	initTabs('apps');

	initApps(data);

	document.querySelectorAll('[data-modal]').forEach(btn => {
		btn.addEventListener('click', () => closeAppModal(btn.dataset.modal));
	});

	initAppsTable();
	renderAppsTable();
	initScaffold(data);
	initTroyModal(data);
	initAppsOutsideClick();
	renderDemo(data);
	initAgent(data);

	log.info('[ExamplePress] Apps page ready.');
});
