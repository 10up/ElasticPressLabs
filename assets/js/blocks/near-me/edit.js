/**
 * WordPress dependencies
 */
import { __ } from "@wordpress/i18n";
import { RichText, useBlockProps } from "@wordpress/block-editor";

/**
 * Edit component.
 * See https://wordpress.org/gutenberg/handbook/designers-developers/developers/block-api/block-edit-save/#edit
 *
 * @param {object}   props                  The block props.
 * @param {object}   props.attributes       Block attributes.
 * @param {string}   props.attributes.title Custom title to be displayed.
 * @param {string}   props.className        Class name for the block.
 * @param {Function} props.setAttributes    Sets the value for block attributes.
 * @returns {Function} Render the edit screen
 */
const ExampleBlockEdit = (props) => {
	const { attributes, setAttributes } = props;
	const { buttonText } = attributes;

	const blockProps = useBlockProps();

	return (
		<div {...blockProps}>
			<RichText
				className="wp-element-button ep-near-me-block__button"
				tagName="button"
				placeholder={__("Show Near Me", "elasticpress-labs")}
				value={buttonText}
				onChange={(buttonText) => setAttributes({ buttonText })}
			/>
		</div>
	);
};
export default ExampleBlockEdit;
