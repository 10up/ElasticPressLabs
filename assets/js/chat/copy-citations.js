/**
 * Copy text to the clipboard.
 *
 * @param {string} text Text.
 * @returns {Promise<void>} Rejects when the browser does not allow it.
 */
export const copyText = async (text) => {
	if (window.navigator?.clipboard?.writeText) {
		await window.navigator.clipboard.writeText(text);
		return;
	}

	// Older browsers, and pages without a secure context.
	const field = document.createElement('textarea');

	field.value = text;
	field.setAttribute('readonly', '');
	field.style.position = 'fixed';
	field.style.opacity = '0';
	document.body.appendChild(field);
	field.select();

	const copied = document.execCommand('copy');

	field.remove();

	if (!copied) {
		throw new Error('copy');
	}
};
