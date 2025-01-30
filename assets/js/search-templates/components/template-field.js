/**
 * WordPress Dependencies.
 */
import { BaseControl, Button, Flex, Notice } from '@wordpress/components';
import { createInterpolateElement, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { defaultTemplate } from '../config';

/**
 * Template Field component.
 *
 * @param {object} props Component props.
 * @param {string} props.value The search template as a string.
 * @param {Function} props.onChange Function to be executed when the value changes.
 * @param {boolean} props.disabled If the field should be enabled or not.
 * @returns {WPElement}
 */
export default ({ value, onChange, disabled }) => {
	const isValueValidJson = () => {
		if (!value) {
			return true;
		}

		try {
			return JSON.parse(value) && !!value;
		} catch (e) {
			return false;
		}
	};

	return (
		<BaseControl
			help={createInterpolateElement(
				__(
					'Make sure your template is a valid JSON object and has <code>{{ep_placeholder}}</code>, so it can be replaced by the actual search term.',
					'elasticpress-labs',
				),
				{ code: <code /> },
			)}
		>
			{isValueValidJson() || (
				<Notice status="error" isDismissible={false}>
					{__('This does not seem to be a valid JSON object.', 'elasticpress-labs')}
				</Notice>
			)}
			<Flex style={{ marginBottom: '10px' }}>
				<BaseControl.VisualLabel>
					{__('Template', 'elasticpress-labs')}
				</BaseControl.VisualLabel>
				{defaultTemplate && (
					<Button
						disabled={false}
						isBusy={false}
						size="small"
						onClick={() => {
							onChange(JSON.stringify(defaultTemplate, null, '\t'));
						}}
						type="button"
						variant="secondary"
					>
						{__('Import default template', 'elasticpress-labs')}
					</Button>
				)}
			</Flex>
			<textarea
				value={value}
				onChange={(e) => onChange(e.target.value)}
				rows={10}
				disabled={disabled}
				style={{
					width: '100%',
				}}
			/>
		</BaseControl>
	);
};
