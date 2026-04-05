/**
 * Gated logging — only emits to console when EP_DEV_MODE is active.
 */
let devMode = false;

export function initLogger(isDevMode) {
	devMode = !!isDevMode;
}

export const log = {
	info: (...args) => devMode && console.info(...args),
	warn: (...args) => devMode && console.warn(...args),
	error: (...args) => console.error(...args),
};
