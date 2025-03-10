/**
 * WordPress dependencies.
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies.
 */
import AllowEmbedding from './plugins/allow-embedding';

registerPlugin('ep-allow-embedding', {
	render: AllowEmbedding,
	icon: null,
});
