/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useInstanceId } from '@wordpress/compose';
import { BaseControl, Notice } from '@wordpress/components';

/**
 * Render Google Places address autocomplete.
 *
 * @param {object} props Component props.
 * @param {Function} props.onPlaceSelected Receives the selected place with address and location.
 * @param {string|boolean} props.value Saved address.
 * @returns {WPElement} Address control.
 */
const AutoCompleteField = ({ onPlaceSelected, value }) => {
	const instanceId = useInstanceId(AutoCompleteField);
	const id = `ep-geo-address-${instanceId}`;
	const containerRef = useRef(null);
	const autocompleteRef = useRef(null);
	const onPlaceSelectedRef = useRef(onPlaceSelected);
	const valueRef = useRef(value);
	const [error, setError] = useState('');

	useEffect(() => {
		onPlaceSelectedRef.current = onPlaceSelected;
	}, [onPlaceSelected]);

	useEffect(() => {
		valueRef.current = value;
		if (autocompleteRef.current) {
			autocompleteRef.current.value = value || '';
		}
	}, [value]);

	useEffect(() => {
		let disposed = false;
		let autocomplete;
		let selection = 0;

		/** Display lookup errors while the component is mounted. */
		const showError = () => {
			if (!disposed) {
				setError(
					__(
						'Address lookup failed. You can enter coordinates manually.',
						'elasticpress-labs',
					),
				);
			}
		};

		/**
		 * Fetch place details, ignoring stale selections and unmounted components.
		 *
		 * @param {object} event Google Places selection event.
		 * @param {object} event.placePrediction Selected prediction.
		 * @returns {Promise<void>} Resolves after processing the selection.
		 */
		const selectPlace = async ({ placePrediction }) => {
			const currentSelection = ++selection;
			const selectedValue = autocomplete.value;
			setError('');

			try {
				const place = placePrediction.toPlace();
				await place.fetchFields({ fields: ['formattedAddress', 'location'] });

				if (
					disposed ||
					currentSelection !== selection ||
					autocomplete.value !== selectedValue
				) {
					return;
				}
				if (!place.formattedAddress || !place.location) {
					showError();
					return;
				}

				onPlaceSelectedRef.current(place);
			} catch {
				if (currentSelection === selection && autocomplete.value === selectedValue) {
					showError();
				}
			}
		};

		/**
		 * Load Places and attach the widget and event listeners.
		 *
		 * @returns {Promise<void>} Resolves after widget setup.
		 */
		const initialize = async () => {
			try {
				const { PlaceAutocompleteElement } =
					await window.google.maps.importLibrary('places');
				if (disposed) {
					return;
				}

				autocomplete = new PlaceAutocompleteElement({
					placeholder: __('Enter an address', 'elasticpress-labs'),
				});
				autocomplete.id = id;
				autocomplete.value = valueRef.current || '';
				autocomplete.setAttribute('aria-label', __('Address', 'elasticpress-labs'));
				autocomplete.style.width = '100%';
				autocomplete.style.colorScheme = 'light';
				autocomplete.addEventListener('gmp-select', selectPlace);
				autocomplete.addEventListener('gmp-error', showError);
				autocompleteRef.current = autocomplete;
				containerRef.current.appendChild(autocomplete);
			} catch {
				showError();
			}
		};

		initialize();

		// Ignore pending requests and detach the widget on unmount.
		return () => {
			disposed = true;
			if (autocomplete) {
				autocomplete.removeEventListener('gmp-select', selectPlace);
				autocomplete.removeEventListener('gmp-error', showError);
				autocomplete.remove();
			}
			autocompleteRef.current = null;
		};
	}, [id]);

	return (
		<BaseControl id={id} label={__('Address', 'elasticpress-labs')} __nextHasNoMarginBottom>
			<div ref={containerRef} style={{ marginBottom: '8px' }} />
			{error && (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			)}
		</BaseControl>
	);
};

export default AutoCompleteField;
