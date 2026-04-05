/**
 * Theme — Design tab: colors, layout, fonts, sizes.
 */
import { esc } from '../lib/dom.js';
import { log } from '../lib/logger.js';

export function renderDesign(colors, layout, fonts, sizes) {
	// Colors.
	const colorsGrid = document.getElementById('colors-grid');
	if (colorsGrid && colors.length) {
		colorsGrid.innerHTML = colors.map(c => `
			<div class="ep-color-card">
				<div class="ep-color-swatch" style="background:${c.color}"></div>
				<div class="ep-color-info">
					<div class="ep-color-name">${esc(c.name)}</div>
					<div class="ep-color-meta"><span class="ep-color-hex">${esc(c.color)}</span><span class="ep-color-slug">${esc(c.slug)}</span></div>
				</div>
			</div>`).join('');
		log.info(`[ExamplePress] Design: ${colors.length} colors loaded`);
	}

	// Layout.
	const layoutEl = document.getElementById('layout-visual');
	if (layoutEl && layout) {
		const wideNum = parseFloat(layout.wideSize) || 1200;
		const contentNum = parseFloat(layout.contentSize) || 800;
		const ratio = wideNum > 0 ? ((contentNum / wideNum) * 100).toFixed(1) : 66.7;
		layoutEl.innerHTML = `
			<div class="ep-layout-bar bar-wide"><span class="ep-layout-lbl">Wide &mdash; ${esc(layout.wideSize)}</span></div>
			<div style="display:flex;justify-content:center;">
				<div class="ep-layout-bar bar-content" style="width:${ratio}%"><span class="ep-layout-lbl">Content &mdash; ${esc(layout.contentSize)}</span></div>
			</div>`;
	}

	// Typography.
	const typeStack = document.getElementById('type-stack');
	if (typeStack && fonts.length) {
		typeStack.innerHTML = fonts.map(f => `
			<div class="ep-type-row">
				<div class="ep-type-meta"><span class="ep-type-name">${esc(f.name)}</span><span class="ep-type-slug">${esc(f.slug)}</span></div>
				<div class="ep-type-preview" style="font-family:${f.stack};">The quick brown fox jumps over the lazy dog &mdash; 0123456789</div>
			</div>`).join('');
	}

	// Size scale.
	const sizeScale = document.getElementById('size-scale');
	if (sizeScale && sizes.length) {
		sizeScale.innerHTML = sizes.map(s => `
			<div class="ep-size-item">
				<span class="ep-size-sample" style="font-size:${s.size}">Aa</span>
				<span class="ep-size-label">${esc(s.name)}<br>${esc(s.slug)} &middot; ${esc(s.size)}</span>
			</div>`).join('');
	}
}
