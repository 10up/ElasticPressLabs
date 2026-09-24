/* global epGeoLocation */

/**
 * WordPress dependencies
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { PluginDocumentSettingPanel as PluginDocumentSettingPanelLegacy } from '@wordpress/edit-post';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { WPElement } from '@wordpress/element';
import { ifCondition } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import AutoCompleteField from './AutoCompleteField';

/**
 * ElasticPress Geo Location Panel
 *
 * @returns {WPElement} Component.
 */
const GeoLocationPanel = () => {
	const { editPost } = useDispatch('core/editor');

	const {
		ep_latitude = false,
		ep_longitude = false,
		ep_address = false,
		...meta
	} = useSelect((select) => select('core/editor').getEditedPostAttribute('meta') || {});

	/**
	 * Update the latitude entered manually.
	 *
	 * @param {string} latitude Latitude input value.
	 */
	const onUpdateLatitude = (latitude) => {
		editPost({ meta: { ...meta, ep_latitude: latitude } });
	};

	/**
	 * Update the longitude entered manually.
	 *
	 * @param {string} longitude Longitude input value.
	 */
	const onUpdateLongitude = (longitude) => {
		editPost({ meta: { ...meta, ep_longitude: longitude } });
	};

	/**
	 * Save the selected address and coordinates in one editor update.
	 *
	 * @param {object} place Google Place with fetched address and location.
	 */
	const onPlaceSelected = (place) => {
		editPost({
			meta: {
				...meta,
				ep_address: place.formattedAddress,
				ep_latitude: place.location.lat(),
				ep_longitude: place.location.lng(),
			},
		});
	};

	const WrapperElement =
		typeof PluginDocumentSettingPanel !== 'undefined'
			? PluginDocumentSettingPanel
			: PluginDocumentSettingPanelLegacy;

	return (
		<WrapperElement
			name="ep-lat-long-panel"
			title={__('ElasticPress Geo Location', 'elasticpress-labs')}
			className="ep-lat-long-panel"
		>
			{epGeoLocation.hasMapKey && (
				<AutoCompleteField value={ep_address} onPlaceSelected={onPlaceSelected} />
			)}

			<TextControl
				label={__('Latitude', 'elasticpress-labs')}
				value={ep_latitude}
				onChange={onUpdateLatitude}
				type="number"
				style={{ marginBottom: '8px' }}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={__('Longitude', 'elasticpress-labs')}
				value={ep_longitude}
				onChange={onUpdateLongitude}
				type="number"
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</WrapperElement>
	);
};

const GeoLocationPanelWithCondition = ifCondition(() => !epGeoLocation.isExternalMeta)(
	GeoLocationPanel,
);

export default GeoLocationPanelWithCondition;
