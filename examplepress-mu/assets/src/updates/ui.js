/**
 * Shared UI primitives for the Updates page.
 *
 * - Toast notices (WP-native .notice markup, auto-dismiss after 10s)
 * - Skeleton ↔ body swap on data load
 * - Indeterminate progress bar with message
 * - Semantic status badges (current / update / error / prerelease)
 * - REST helper wrapping lib/api.js
 * - Version comparison
 */

import { apiFetch } from '../lib/api.js';

// ── Toasts ──────────────────────────────────────────────────────────

let noticeRoot = null;

export function initNotices(el) {
	noticeRoot = el;
}

/**
 * Push a WP-native dismissible notice. Auto-dismisses after 10s.
 * @param {'success'|'error'|'warning'|'info'} type
 * @param {string} message
 */
export function toast(type, message) {
	if (!noticeRoot) return;

	const div = document.createElement('div');
	div.className = `notice notice-${type} is-dismissible ep-updates-toast`;
	div.innerHTML = `<p>${esc(message)}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>`;

	noticeRoot.prepend(div);

	div.querySelector('.notice-dismiss').addEventListener('click', () => div.remove());

	setTimeout(() => {
		if (div.parentNode) div.remove();
	}, 10000);
}

// ── Skeleton swap ───────────────────────────────────────────────────

/**
 * Hide the skeleton and show the body. Both elements must exist.
 */
export function revealBody(skeletonId, bodyId) {
	const skel = document.getElementById(skeletonId);
	const body = document.getElementById(bodyId);
	if (skel) skel.hidden = true;
	if (body) body.hidden = false;
}

// ── Progress ────────────────────────────────────────────────────────

export function showProgress(containerId, message) {
	const el = document.getElementById(containerId);
	if (!el) return;
	el.hidden = false;
	const msg = el.querySelector('.ep-updates-progress-message');
	if (msg) msg.textContent = message || '';
}

export function hideProgress(containerId) {
	const el = document.getElementById(containerId);
	if (!el) return;
	el.hidden = true;
}

// ── Semantic badges ─────────────────────────────────────────────────

/**
 * Apply a semantic state to a badge element.
 * @param {HTMLElement} el
 * @param {'current'|'update'|'error'|'prerelease'|'info'} state
 * @param {string} label
 */
export function setBadge(el, state, label) {
	if (!el) return;
	el.className = `ep-updates-badge ep-updates-badge--${state}`;
	el.textContent = label;
}

// ── REST helper ─────────────────────────────────────────────────────

/**
 * Thin wrapper around apiFetch() that throws on network errors and
 * returns the parsed body on success. Callers handle UI state.
 */
export async function api(method, url, body) {
	return apiFetch(url, {
		method,
		body: body !== undefined ? body : undefined,
	});
}

// ── Version compare ─────────────────────────────────────────────────

/**
 * Simple semver comparison. Returns -1, 0, or 1.
 */
export function versionCompare(a, b) {
	if (!a || !b) return 0;
	const pa = String(a).split('.').map(Number);
	const pb = String(b).split('.').map(Number);
	const len = Math.max(pa.length, pb.length);
	for (let i = 0; i < len; i++) {
		const na = pa[i] || 0;
		const nb = pb[i] || 0;
		if (na > nb) return 1;
		if (na < nb) return -1;
	}
	return 0;
}

// ── HTML escape ─────────────────────────────────────────────────────

export function esc(str) {
	if (str === null || str === undefined) return '';
	const div = document.createElement('div');
	div.textContent = String(str);
	return div.innerHTML;
}

// ── Time formatting ─────────────────────────────────────────────────

export function formatTimestamp(unix) {
	if (!unix) return 'Never';
	try {
		return new Date(unix * 1000).toLocaleString();
	} catch (e) {
		return 'Never';
	}
}

export function formatRelative(unix) {
	if (!unix) return 'Never';
	const now = Math.floor(Date.now() / 1000);
	const diff = now - unix;
	if (diff < 60)     return 'just now';
	if (diff < 3600)   return `${Math.floor(diff / 60)}m ago`;
	if (diff < 86400)  return `${Math.floor(diff / 3600)}h ago`;
	if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
	return new Date(unix * 1000).toLocaleDateString();
}

/**
 * Format a future unix timestamp as a distance: "in 4h 20m".
 */
export function formatUntil(unix) {
	if (!unix) return '—';
	const now = Math.floor(Date.now() / 1000);
	const diff = unix - now;
	if (diff <= 0) return 'now';
	const h = Math.floor(diff / 3600);
	const m = Math.floor((diff % 3600) / 60);
	if (h >= 1) return `in ${h}h ${m}m`;
	return `in ${m}m`;
}
