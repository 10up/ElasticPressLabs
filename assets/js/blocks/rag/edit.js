/**
 * WordPress dependencies.
 */
import { RichText, useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Edit component.
 *
 * @param {object} props Component props.
 * @param {object} props.attributes Block attributes.
 * @param {Function} props.setAttributes Block attribute setter.
 * @returns {Function} Component.
 */
export default ({ attributes, setAttributes }) => {
	const { title } = attributes;

	const blockProps = useBlockProps({
		className: 'ep-rag',
	});

	return (
		<div {...blockProps}>
			<RichText
				aria-label={__('Title text', 'elasticpress-labs')}
				placeholder={__('Add title', 'elasticpress-labs')}
				withoutInteractiveFormatting
				value={title}
				onChange={(html) => setAttributes({ title: html })}
			/>
		</div>
	);
};
