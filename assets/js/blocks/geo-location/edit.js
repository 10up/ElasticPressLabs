/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, Disabled, WPElement } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * GeoLocation block edit component.
 *
 * @param {object} props Component props.
 * @returns {WPElement} Component.
 */
const GeoLocation = (props) => {
	const { attributes, setAttributes, name } = props;
	const {
		textWithoutLocation,
		buttonTextWithoutLocation,
		textWithLocation,
		buttonTextWithLocation,
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('Settings', 'elasticpress-labs')}>
					<TextControl
						label={__('Text when location is not set', 'elasticpress-labs')}
						value={textWithoutLocation}
						onChange={(value) => setAttributes({ textWithoutLocation: value })}
					/>

					<TextControl
						label={__('Button text when location is not set', 'elasticpress-labs')}
						value={buttonTextWithoutLocation}
						onChange={(value) => setAttributes({ buttonTextWithoutLocation: value })}
					/>

					<TextControl
						label={__('Text when location is set', 'elasticpress-labs')}
						value={textWithLocation}
						onChange={(value) => setAttributes({ textWithLocation: value })}
					/>

					<TextControl
						label={__('Button text when location is set', 'elasticpress-labs')}
						value={buttonTextWithLocation}
						onChange={(value) => setAttributes({ buttonTextWithLocation: value })}
					/>
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
				<Disabled>
					<ServerSideRender
						attributes={{
							...attributes,
							isPreview: true,
						}}
						block={name}
						skipBlockSupportAttributes
					/>
				</Disabled>
			</div>
		</>
	);
};

export default GeoLocation;
