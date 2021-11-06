const { epgl } = window;

/**
 * Append the hidden inputs to submit lat long with the search form
 *
 * @param {Array} savedLocation Longitude and latitude information
 */
function appendHiddenInputs(savedLocation = []) {
	const buttonHolders = document.querySelectorAll(epgl.selector);
	const latInput = document.createElement('input');
	const longInput = document.createElement('input');

	latInput.setAttribute('type', 'hidden');
	latInput.setAttribute('name', 'epgl_latitude');
	longInput.setAttribute('type', 'hidden');
	longInput.setAttribute('name', 'epgl_longitude');

	if (savedLocation) {
		[latInput.value, longInput.value] = savedLocation;
	}

	buttonHolders.forEach((buttonHolder) => {
		buttonHolder.append(latInput);
		buttonHolder.append(longInput);
	});
}

/**
 * Clear location Cookie
 */
function clearLocationCookie() {
	document.cookie = 'epgl=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
}

/**
 * Updates the hidden inputs with the set coordinates
 *
 * @param {string} lat The latitude
 * @param {string} long The longitude
 */
function updateInputs(lat, long) {
	const latInputs = document.querySelectorAll('input[name="epgl_latitude"');
	const longInputs = document.querySelectorAll('input[name="epgl_longitude"');

	latInputs.forEach((latInput) => {
		latInput.value = lat; // eslint-disable-line no-param-reassign
	});

	longInputs.forEach((longInput) => {
		longInput.value = long; // eslint-disable-line no-param-reassign
	});
}

/**
 * Remove the location set messaging
 */
function removeLocationSetMessage() {
	document.querySelectorAll('.ep-located').forEach((messageWrapper) => {
		messageWrapper.querySelector('button').removeEventListener('click', clearCurrentLocation); // eslint-disable-line no-use-before-define
		messageWrapper.remove();
	});
}

/**
 * Set location Cookie
 *
 * @param {string} lat The latitude
 * @param {string} long The longitude
 */
function setLocationCookie(lat, long) {
	document.cookie = `epgl=${lat},${long}; expires=Tue, January 1, 2030 12:00:00 UTC`;
}

/**
 * Remove Locate me button
 */
function removeLocationButton() {
	document.querySelectorAll('.epgl-locate-me').forEach((locationButton) => {
		locationButton.removeEventListener('click', setUserLocation); // eslint-disable-line no-use-before-define
		locationButton.remove();
	});
}

/**
 * Appends a simple message that the location is set
 */
function appendLocationSetMessage() {
	const buttonHolders = document.querySelectorAll(epgl.selector);
	const locatedWrapper = document.createElement('div');
	const message = document.createElement('span');
	const removeButton = document.createElement('button');

	locatedWrapper.classList.add('ep-located');

	message.innerText = epgl.locationSetMessage;

	removeButton.innerText = epgl.removeLocationButtonText;
	removeButton.addEventListener('click', clearCurrentLocation); // eslint-disable-line no-use-before-define

	locatedWrapper.append(message);
	locatedWrapper.append(removeButton);

	buttonHolders.forEach((buttonHolder) => {
		buttonHolder.append(locatedWrapper);
	});
}

/**
 * Get the user's location and save it in a cookie
 *
 * @param {event} event The event that has occurred
 */
function setUserLocation(event) {
	event.preventDefault();

	if (navigator.geolocation) {
		navigator.geolocation.getCurrentPosition((position) => {
			const lat = position.coords.latitude;
			const long = position.coords.longitude;

			setLocationCookie(lat, long);
			updateInputs(lat, long);
			removeLocationButton();
			appendLocationSetMessage();
		});
	} else {
		epgl.selector.innerHTML = epgl.locationErrorMessage;
	}
}

/**
 * Appends the "Location Me" button to the specified selector
 */
function appendLocationButton() {
	const locationButton = document.createElement('button');
	const buttonHolders = document.querySelectorAll(epgl.selector);

	locationButton.classList.add('epgl-locate-me');
	locationButton.innerText = epgl.locationButtonText;
	locationButton.addEventListener('click', setUserLocation);

	buttonHolders.forEach((buttonHolder) => {
		buttonHolder.append(locationButton);
	});
}

/**
 * Clears the current location lat long information
 *
 * @param {event} event The event that has occurred
 */
function clearCurrentLocation(event) {
	event.preventDefault();

	clearLocationCookie();
	updateInputs('', '');
	removeLocationSetMessage();
	appendLocationButton();
}

/**
 * Get location Cookie
 *
 * @return {Array|boolean} They array with latitude and longitude or false.
 */
function getLocationFromCookie() {
	// Split cookie string and get all individual name=value pairs in an array
	const cookieArr = document.cookie.split(';');
	let coords = [];

	// eslint-disable-next-line consistent-return
	cookieArr.forEach((cookie) => {
		const cookiePair = cookie?.split('=');

		if (cookiePair?.[0].trim() === 'epgl') {
			coords = cookiePair?.[1].split(',');

			if (coords.length === 2) {
				return [coords[0], coords[1]]; // lat, long
			}
		}
	});

	return false;
}

if (epgl.selector && epgl.selector !== '' && document.querySelector(epgl.selector) !== null) {
	const savedLocation = getLocationFromCookie();

	if (savedLocation) {
		appendLocationSetMessage();
	} else {
		appendLocationButton();
	}

	appendHiddenInputs(savedLocation);
}
