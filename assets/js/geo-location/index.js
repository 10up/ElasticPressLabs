/**
 * WordPress dependencies.
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies.
 */
import LatLongPanel from './panel';

registerPlugin('ep-lat-long', {
	render: LatLongPanel,
	icon: null,
});
