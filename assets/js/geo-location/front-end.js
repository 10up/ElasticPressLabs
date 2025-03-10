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
		navigator.geolocation.getCurrentPosition((position) => {
			const { latitude, longitude } = position.coords;

			setCookie('ep_coordinates', `${latitude},${longitude}`, {
				secure: true,
				'max-age': 3600,
			});

			window.location.reload();
		});
	}
});
