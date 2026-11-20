/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';

export default ({ onPlaceSelected, value }) => {
	const inputRef = useRef(null);

	const [location, setLocation] = useState(value);

	useEffect(() => {
		if (inputRef.current && window.google) {
			const autocomplete = new window.google.maps.places.Autocomplete(inputRef.current, {
				types: ['geocode'],
			});

			autocomplete.addListener('place_changed', () => {
				const place = autocomplete.getPlace();
				setLocation(place.formatted_address);
				onPlaceSelected(place);
			});
		}
	}, [onPlaceSelected]);

	return (
		<TextControl
			ref={inputRef}
			type="text"
			label={__('Address', 'elasticpress-labs')}
			placeholder={__('Enter an address', 'elasticpress-labs')}
			value={location}
			onChange={(value) => setLocation(value)}
			style={{ marginBottom: '8px' }}
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
};
