/**
 * WordPress dependencies.
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies.
 */
import ExcludeFromEmbedding from './plugins/exclude-from-embedding';

registerPlugin('ep-embedding-exclude', {
	render: ExcludeFromEmbedding,
	icon: null,
});
