/**
 * URL parameter helpers for tab and section state.
 */
export function getUrlParams() {
	const params = new URLSearchParams(window.location.search);
	return {
		tab: params.get('tab'),
		section: params.get('section'),
	};
}

export function setUrlParams(tab, section) {
	const params = new URLSearchParams(window.location.search);
	if (tab) {
		params.set('tab', tab);
	} else {
		params.delete('tab');
	}
	if (section) {
		params.set('section', section);
	} else {
		params.delete('section');
	}
	history.replaceState(null, '', '?' + params.toString());
}
