/**
 * Theme — Features tab: searchable datatable with feature detail modals.
 */
import { esc, badge, srcTag } from '../lib/dom.js';
import { renderDatatable } from '../lib/datatable.js';
import { openModal } from '../lib/modal.js';
import { log } from '../lib/logger.js';

const categoryLabels = {
	'theme-support': 'Theme Support',
	'editor': 'Editor & Content Controls',
	'admin': 'Admin Customization',
	'design': 'Design Tokens',
};

export function renderFeatures(features, featureDetails) {
	const allFeatures = [];
	Object.entries(features).forEach(([cat, items]) => {
		(items || []).forEach(f => allFeatures.push({ ...f, category: cat, categoryLabel: categoryLabels[cat] || cat }));
	});

	renderDatatable('tbl-features', {
		columns: [
			{ key: 'name', label: 'Feature', render: f => `<div class="ep-td-label"><span class="ep-name">${esc(f.name)}</span><span class="ep-id">${esc(f.id)}</span>${f.opts ? `<span class="ep-opt"><em>${esc(f.opts)}</em></span>` : ''}</div>` },
			{ key: 'status', label: 'Status', render: f => badge(f.on) },
			{ key: 'source', label: 'Source', render: f => srcTag(f.src, f.srcDetail) },
		],
		data: allFeatures,
		searchKeys: ['name', 'id', 'categoryLabel'],
		searchPlaceholder: 'Search features...',
		gridClass: 'ep-cols-3',
		groupBy: f => categoryLabels[f.category] || f.category,
		onRowClick: f => {
			if (featureDetails && featureDetails[f.id]) openFeatureModal(f.id, features, featureDetails);
		},
		emptyMessage: 'No features match your search.',
	});

	const featureCount = allFeatures.length;
	const catCount = Object.keys(features).length;
	log.info(`[ExamplePress] Features rendered: ${featureCount} features across ${catCount} categories`);

	return featureCount;
}

function findFeatureData(features, featureId) {
	for (const cat of Object.values(features)) {
		if (!cat) continue;
		const found = cat.find(f => f.id === featureId);
		if (found) return found;
	}
	return null;
}

function openFeatureModal(featureId, features, featureDetails) {
	const detail = featureDetails[featureId];
	const fData = findFeatureData(features, featureId);
	if (!detail) return;

	let html = '';

	html += '<div class="ep-modal-status">';
	if (fData) {
		html += badge(fData.on);
		html += ' ' + srcTag(fData.src, fData.srcDetail);
	}
	html += '</div>';

	if (detail.description) {
		html += '<div class="ep-modal-section">';
		html += '<div class="ep-modal-section-title">What It Does</div>';
		html += `<p class="ep-modal-text">${esc(detail.description)}</p>`;
		html += '</div>';
	}

	if (detail.technical) {
		html += '<div class="ep-modal-section">';
		html += '<div class="ep-modal-section-title">Technical Detail</div>';
		html += `<p class="ep-modal-text">${esc(detail.technical)}</p>`;
		html += '</div>';
	}

	if (detail.override) {
		html += '<div class="ep-modal-section">';
		html += '<div class="ep-modal-section-title">Override Example</div>';
		html += `<pre class="ep-modal-code">${esc(detail.override)}</pre>`;
		html += '</div>';
	}

	if (fData && fData.opts) {
		html += '<div class="ep-modal-section">';
		html += '<div class="ep-modal-section-title">Current Options</div>';
		html += `<p class="ep-modal-text"><span class="ep-modal-opt">${esc(fData.opts)}</span></p>`;
		html += '</div>';
	}

	html += '<div class="ep-modal-section">';
	html += '<div class="ep-modal-section-title">Filter Hooks</div>';
	html += `<p class="ep-modal-text">Toggle: <code class="ep-modal-filter">examplepress_feature_${esc(featureId)}</code></p>`;
	html += '</div>';

	openModal(fData ? fData.name : featureId, featureId, html);
	log.info(`[ExamplePress] Feature modal opened: ${featureId}`);
}
