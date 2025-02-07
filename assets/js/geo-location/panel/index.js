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

	const onUpdateLatitude = (latitude) => {
		editPost({ meta: { ...meta, ep_latitude: latitude } });
	};

	const onUpdateLongitude = (longitude) => {
		editPost({ meta: { ...meta, ep_longitude: longitude } });
	};

	const onPlaceSelected = (place) => {
		editPost({ meta: { ...meta, ep_address: place.formatted_address } });

		if (place.geometry && place.geometry.location) {
			const latitude = place.geometry.location.lat();
			const longitude = place.geometry.location.lng();

			onUpdateLatitude(latitude);
			onUpdateLongitude(longitude);
		}
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
			{epGeoLocation.has_map_key && (
				<AutoCompleteField value={ep_address} onPlaceSelected={onPlaceSelected} />
			)}

			<TextControl
				label={__('Latitude', 'elasticpress-labs')}
				value={ep_latitude}
				onChange={onUpdateLatitude}
				type="number"
			/>
			<TextControl
				label={__('Longitude', 'elasticpress-labs')}
				value={ep_longitude}
				onChange={onUpdateLongitude}
				type="number"
			/>
		</WrapperElement>
	);
};

const GeoLocationPanelWithCondition = ifCondition(() => !epGeoLocation.is_external_meta)(
	GeoLocationPanel,
);

export default GeoLocationPanelWithCondition;
