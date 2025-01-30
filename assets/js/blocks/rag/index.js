/**
 * WordPress dependencies.
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies.
 */
import { name } from './block.json';

registerBlockType(name, {
	edit: () => <p>Testing</p>,
	save: () => {},
});
