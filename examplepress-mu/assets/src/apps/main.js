/**
 * Apps page entry point.
 * Tabs: Apps (default), Demo
 */

import '../css/index.css';
import { initLogger, log } from '../lib/logger.js';
import { initApi } from '../lib/api.js';
import { initTabs } from '../lib/tabs.js';
import { initModal, closeAppModal, initEscapeHandler } from '../lib/modal.js';
import { assertDataKeys, safeRender } from '../lib/page-init.js';
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

	// Required keys for the Apps page to function. If any are missing,
	// the PHP DataProvider failed to populate something and we degrade
	// instead of crashing into undefined URLs.
	const missing = assertDataKeys('Apps page', data, ['nonce', 'appsBaseUrl', 'apps']);
	if (missing.length) {
		return;
	}

	initApi(data.nonce);
	initModal();
	initEscapeHandler([
		'ep-apps-scaffold-modal', 'ep-apps-troy-modal', 'ep-apps-codespace-modal',
		'ep-agent-modal', 'ep-agent-iterate-modal',
		'ep-agent-jobs-modal', 'ep-agent-repair-modal', 'ep-agent-review-modal',
	]);
	initTabs('apps');

	initApps(data);

	document.querySelectorAll('[data-modal]').forEach(btn => {
		btn.addEventListener('click', () => closeAppModal(btn.dataset.modal));
	});

	// Each section is wrapped so a failure in one cannot prevent the
	// others from initialising (e.g. an agent-runtime exception should
	// not blank the Apps table above it). Mirrors the pattern from
	// updates/main.js.
	safeRender('Apps → table init',     initAppsTable);
	safeRender('Apps → table render',   renderAppsTable);
	safeRender('Apps → scaffold',       initScaffold, data);
	safeRender('Apps → troy-modal',     initTroyModal, data);
	safeRender('Apps → outside-click',  initAppsOutsideClick);
	safeRender('Apps → demo',           renderDemo, data);
	safeRender('Apps → agent',          initAgent, data);

	log.info('[ExamplePress] Apps page ready.');
});
