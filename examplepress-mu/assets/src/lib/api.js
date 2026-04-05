/**
 * REST API fetch wrapper with nonce injection and error handling.
 */

let nonce = '';

export function initApi(wpNonce) {
	nonce = wpNonce || '';
}

export async function apiFetch(url, options = {}) {
	const headers = {
		'X-WP-Nonce': nonce,
		...(options.headers || {}),
	};

	if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
		headers['Content-Type'] = 'application/json';
		options.body = JSON.stringify(options.body);
	}

	const res = await fetch(url, { ...options, headers });
	const data = await res.json();

	if (!res.ok) {
		const message = data.message || data.data?.message || `Request failed (${res.status})`;
		const err = new Error(message);
		err.data = data;
		err.status = res.status;
		throw err;
	}

	return data;
}
