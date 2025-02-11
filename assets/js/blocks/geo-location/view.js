/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Handles the click event for retrieving geolocation data.
 *
 * @param {Event} e - The click event object.
 * @returns {void}
 */
const onClickAction = (e) => {
	e.preventDefault();

	const errorMessage = __(
		'Error retrieving location data. Please ensure that location services are enabled.',
		'elasticpress-labs',
	);

	const form = e.target.closest('form');

	if (navigator.geolocation) {
		navigator.geolocation.getCurrentPosition(
			(position) => {
				const { latitude, longitude } = position.coords;

				form.querySelector('input[name=ep_lat]').value = latitude;
				form.querySelector('input[name=ep_lon]').value = longitude;
				form.submit();
			},
			() => {
				form.querySelector('.ep-geo-location__error').textContent = errorMessage;
			},
		);
	} else {
		form.querySelector('.ep-geo-location__error').textContent = errorMessage;
	}
};

document.addEventListener('DOMContentLoaded', () => {
	const buttons = document.querySelectorAll('.ep-geo-location__submit-button');
	if (!buttons) {
		return;
	}

	buttons.forEach((button) => {
		button.addEventListener('click', onClickAction);
	});
});
