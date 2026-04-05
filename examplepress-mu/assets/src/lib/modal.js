/**
 * Generic modal management.
 */

const modalOverlay = () => document.getElementById('ep-feature-modal');
const modalTitle = () => document.getElementById('ep-modal-title');
const modalId = () => document.getElementById('ep-modal-id');
const modalBody = () => document.getElementById('ep-modal-body');
const modalClose = () => document.getElementById('ep-modal-close');

let initialized = false;

export function initModal() {
	if (initialized) return;
	initialized = true;

	const close = modalClose();
	if (close) close.addEventListener('click', closeModal);

	const overlay = modalOverlay();
	if (overlay) {
		overlay.addEventListener('click', (e) => {
			if (e.target === overlay) closeModal();
		});
	}
}

export function openModal(title, subtitle, bodyHtml) {
	const overlay = modalOverlay();
	if (!overlay) return;
	const titleEl = modalTitle();
	const idEl = modalId();
	const bodyEl = modalBody();
	if (titleEl) titleEl.textContent = title;
	if (idEl) idEl.textContent = subtitle || '';
	if (bodyEl) bodyEl.innerHTML = bodyHtml;
	overlay.style.display = '';
}

export function closeModal() {
	const overlay = modalOverlay();
	if (overlay) overlay.style.display = 'none';
}

/**
 * App-specific modal helpers (scaffold, troy, codespace).
 */
export function openAppModal(id) {
	const el = document.getElementById(id);
	if (el) el.style.display = 'flex';
}

export function closeAppModal(id) {
	const el = document.getElementById(id);
	if (el) el.style.display = 'none';
}

/**
 * Initialize escape key handler for all modals.
 */
let escapeInitialized = false;
const escapeModalIds = new Set();

export function initEscapeHandler(modalIds = []) {
	modalIds.forEach(id => escapeModalIds.add(id));

	if (escapeInitialized) return;
	escapeInitialized = true;

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			closeModal();
			escapeModalIds.forEach(id => closeAppModal(id));
		}
	});
}
