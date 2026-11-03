/**
 * WordPress dependencies.
 */
import { TextareaControl } from '@wordpress/components';
import { addFilter } from '@wordpress/hooks';
import { WPElement } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import PromptPreview from '../chat-shared/prompt-preview';
import '../chat-shared/help-more.css';
import './style.css';

/**
 * Window dependencies.
 */
const { fieldType, previewEndpoint } = window.epChatSettings || {};

/**
 * Answer instructions on the Chat feature: a text area and a preview of the full prompt.
 *
 * @param {object} props Props from the ElasticPress settings control.
 * @param {boolean} props.disabled Whether the field is disabled.
 * @param {WPElement} props.help Help text.
 * @param {string} props.label Label.
 * @param {Function} props.onChange Change handler.
 * @param {string} props.value Value.
 * @returns {WPElement}
 */
const InstructionsControl = ({ disabled, help, label, onChange, value }) => (
	<>
		<TextareaControl
			disabled={disabled}
			help={help}
			label={label}
			onChange={onChange}
			rows={6}
			value={value ?? ''}
			__nextHasNoMarginBottom
		/>
		<PromptPreview
			data={{ bot_id: 0, answer_instructions: value ?? '' }}
			path={previewEndpoint}
		/>
	</>
);

addFilter('ep.Features.Control', 'elasticpress-labs/chat-instructions', (component, type) =>
	type === fieldType ? InstructionsControl : component,
);
