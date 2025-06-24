/**
 * WordPress dependencies.
 */
import { doAction } from '@wordpress/hooks';

const setCookie = (name, value, originalAttributes = {}) => {
	const attributes = {
		path: '/',
		// add other defaults here if necessary
		...originalAttributes,
	};

	if (attributes.expires instanceof Date) {
		attributes.expires = attributes.expires.toUTCString();
	}

	let updatedCookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}`;

	Object.keys(attributes).forEach((attributeKey) => {
		updatedCookie += `; ${attributeKey}`;
		const attributeValue = attributes[attributeKey];
		if (attributeValue !== true) {
			updatedCookie += `=${attributeValue}`;
		}
	});

	document.cookie = updatedCookie;
};

document.addEventListener('DOMContentLoaded', () => {
	if (navigator.geolocation) {
		navigator.geolocation.getCurrentPosition(
			(position) => {
				const { latitude, longitude } = position.coords;
				const cookieCurrentValue = `; ${document.cookie}`
					.split(`; ep_coordinates=`)
					.pop()
					.split(';')[0];

				setCookie('ep_coordinates', `${latitude},${longitude}`, {
					secure: true,
					'max-age': 3600,
				});

				if (!cookieCurrentValue) {
					window.location.reload();
				}
			},
			(error) => {
				/**
				 * Allow handle any errors with the geolocation API, including when the
				 * user does not allow it.
				 *
				 * @action epLabs.GeoLocation.currentPositionError
				 * @since 2.4.0
				 *
				 * @param {object} error Error.
				 */
				doAction('epLabs.GeoLocation.currentPositionError', error);
			},
		);
	} else {
		/**
		 * Allow actions to be run when the geolocation API is not available.
		 *
		 * @action epLabs.GeoLocation.apiNotAvailable
		 * @since 2.4.0
		 */
		doAction('epLabs.GeoLocation.apiNotAvailable');
	}
});
