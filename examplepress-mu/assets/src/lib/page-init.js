/**
 * Page-init helpers shared across admin entry points.
 *
 * - assertDataKeys: verify required keys are present on the global
 *   ExamplePressData payload before touching anything that reads from
 *   it. Returns an array of missing keys (empty = OK) so callers can
 *   decide how to degrade. Logs via the shared logger so devs see the
 *   failure even when console.info is gated by dev mode.
 *
 * - safeRender: wrap a renderer in try/catch so a single page-section
 *   failure can't blank the rest of the page. Mirrors the pattern that
 *   already lives inline in updates/main.js — pulled out here so every
 *   entry point can share it without copy-pasting the closure.
 */
import { log } from './logger.js';

/**
 * Assert that every required key is present on `data`.
 * Missing keys indicate the PHP DataProvider failed to populate
 * something the page needs — degrade gracefully instead of crashing
 * the module loader with a cryptic TypeError.
 *
 * @param {string}          pageLabel  Short page identifier for the log line.
 * @param {object}          data       The ExamplePressData payload.
 * @param {string[]}        required   Keys that MUST be present.
 * @returns {string[]}                 Missing keys — empty array = OK.
 */
export function assertDataKeys(pageLabel, data, required) {
	if (!data || typeof data !== 'object') {
		log.error(`[ExamplePress] ${pageLabel}: ExamplePressData is missing or not an object.`);
		return required.slice();
	}
	const missing = required.filter(k => !(k in data));
	if (missing.length) {
		log.error(`[ExamplePress] ${pageLabel}: missing data keys:`, missing);
	}
	return missing;
}

/**
 * Invoke a renderer with try/catch so one failing section can't take
 * down the rest of the page.
 *
 * @param {string}   sectionLabel  Short identifier used in the error log.
 * @param {Function} fn            Renderer to invoke.
 * @param {...any}   args          Args forwarded to the renderer.
 */
export function safeRender(sectionLabel, fn, ...args) {
	try {
		fn(...args);
	} catch (err) {
		log.error(`[ExamplePress] ${sectionLabel} renderer failed: ${err.message}`);
		console.error(`[ExamplePress] ${sectionLabel} renderer failed`, err);
	}
}
