/**
 * WordPress dependencies.
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies.
 */
import { name } from './block.json';
import edit from './edit';

/**
 * Styles.
 */
import './style.css';

/**
 * Register block.
 */
registerBlockType(name, {
	icon: 'location',
	edit,
	save: () => {},
});
