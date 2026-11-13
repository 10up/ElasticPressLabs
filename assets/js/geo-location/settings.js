/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';

let placesKey;

/**
 * Find the native control associated with one of our help-text markers.
 *
 * @param {string} marker Marker class.
 * @returns {HTMLInputElement|null} Input.
 */
const getInput = (marker) =>
	document.querySelector(marker)?.closest('.components-base-control')?.querySelector('input');

// Use native input events so ElasticPress 5.3.5 keeps owning the settings state.
document.addEventListener('change', (event) => {
	const checkbox = getInput('.ep-geo-legacy-help');

	if (event.target !== checkbox) {
		return;
	}

	const keyInput = getInput('.ep-geo-key-help');

	if (!keyInput) {
		return;
	}

	if (checkbox.checked) {
		placesKey = keyInput.value;
	}

	const nextValue = checkbox.checked ? '' : (placesKey ?? '');

	// The conditional field is replaced when React handles the checkbox change.
	window.requestAnimationFrame(() => {
		const input = getInput('.ep-geo-key-help');

		if (!input) {
			return;
		}

		const { set } = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
		set.call(input, nextValue);
		input.dispatchEvent(new Event('input', { bubbles: true }));
	});
});

// Discard the temporary key only after a successful settings save.
apiFetch.use(async (options, next) => {
	const response = await next(options);
	const url = options.url || options.path || '';

	if (
		['POST', 'PUT', 'PATCH'].includes(options.method) &&
		url.includes('/elasticpress/v1/features')
	) {
		placesKey = undefined;
	}

	return response;
});
