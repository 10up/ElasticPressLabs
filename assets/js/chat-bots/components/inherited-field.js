/**
 * WordPress dependencies.
 */
import {
	Button,
	ComboboxControl,
	RadioControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState, WPElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { answering } from '../config';
import FieldHelp from './field-help';

/**
 * The site default as one short line ("10", "on", "Show the reason", the start of a text).
 *
 * @param {object} field Field from the screen config.
 * @returns {string}
 */
const describeDefault = (field) => {
	const value = String(field.default ?? '');

	if (field.type !== 'text') {
		return value;
	}

	if (!value) {
		return __('none', 'elasticpress-labs');
	}

	return value.length > 80 ? `“${value.slice(0, 79)}…”` : `“${value}”`;
};

/**
 * The input of a changed setting, by type.
 *
 * @param {object} props Component props.
 * @param {string} props.fieldKey Setting key.
 * @param {object} props.field Field from the screen config.
 * @param {string} props.value Value.
 * @param {Function} props.onChange Change handler.
 * @param {string} props.helpId ID of the help, for aria-describedby.
 * @returns {WPElement}
 */
const Input = ({ fieldKey, field, value, onChange, helpId }) => {
	const common = { label: field.label, hideLabelFromVision: true, __nextHasNoMarginBottom: true };

	if (field.type === 'model') {
		const options = [...answering.models];

		// A saved model the providers no longer list stays visible, so it can be cleared.
		if (value && !options.some((option) => option.value === value)) {
			options.push({ value, label: value });
		}

		return (
			<ComboboxControl
				{...common}
				allowReset
				onChange={(next) => onChange(next ?? '')}
				options={options}
				placeholder={__('Pick a model', 'elasticpress-labs')}
				value={value}
				__next40pxDefaultSize
			/>
		);
	}

	if (field.type === 'bool') {
		return (
			<RadioControl
				{...common}
				aria-describedby={helpId}
				onChange={onChange}
				options={[
					{ value: '1', label: __('On', 'elasticpress-labs') },
					{ value: '0', label: __('Off', 'elasticpress-labs') },
				]}
				selected={value}
			/>
		);
	}

	if (field.type === 'choice') {
		const descriptions = field.descriptions || {};

		return (
			<RadioControl
				{...common}
				onChange={onChange}
				options={(field.choices || []).map((choice) => ({
					...choice,
					description: descriptions[choice.value] || undefined,
				}))}
				selected={value}
			/>
		);
	}

	if (field.type === 'text') {
		return (
			<TextareaControl
				{...common}
				maxLength={field.max ?? undefined}
				onChange={onChange}
				placeholder={field.default || ''}
				rows={6}
				value={value}
			/>
		);
	}

	return (
		<TextControl
			{...common}
			aria-describedby={helpId}
			max={field.max ?? undefined}
			min={field.min ?? undefined}
			onChange={onChange}
			placeholder={String(field.default ?? '')}
			step={fieldKey === 'rerank_floor' ? 0.01 : 1}
			type="number"
			value={value}
			__next40pxDefaultSize
		/>
	);
};

/**
 * A setting the bot can take from the site or change. Until changed, it is one line: "Site
 * default: 10" and a Change button. Changed, it shows its input, a "Changed" tag, and "Reset to
 * site default". An empty value always means the site default.
 *
 * @param {object} props Component props.
 * @param {string} props.fieldKey Setting key.
 * @param {string} props.value Value ('' uses the site default).
 * @param {Function} props.onChange Change handler.
 * @param {WPElement} [props.children] Shown under the input while it is open.
 * @returns {WPElement}
 */
const Field = ({ fieldKey, value, onChange, children }) => {
	const field = answering.fields[fieldKey];
	const id = useInstanceId(TextControl, `ep-chat-bot-field-${fieldKey}`);
	const [isEditing, setIsEditing] = useState(false);
	const isChanged = value !== '';
	const isOpen = isChanged || isEditing;

	return (
		<div
			className={`ep-chat-bot-field${isChanged ? ' is-changed' : ''}`}
			role="group"
			aria-labelledby={`${id}-label`}
		>
			<div className="ep-chat-bot-field__head">
				<span className="ep-chat-bot-field__label" id={`${id}-label`}>
					{field.label}
				</span>
				{isChanged ? (
					<span className="ep-chat-bot-field__tag">
						{__('Changed', 'elasticpress-labs')}
					</span>
				) : null}
			</div>
			{isOpen ? (
				<>
					<Input
						field={field}
						fieldKey={fieldKey}
						helpId={`${id}-help`}
						onChange={onChange}
						value={value}
					/>
					{children}
					<Button
						className="ep-chat-bot-field__reset"
						onClick={() => {
							setIsEditing(false);
							onChange('');
						}}
						variant="link"
					>
						{sprintf(
							/* translators: %s: the site default value */
							__('Reset to site default (%s)', 'elasticpress-labs'),
							describeDefault(field),
						)}
					</Button>
				</>
			) : (
				<div className="ep-chat-bot-field__inherited">
					<span>
						{sprintf(
							/* translators: %s: the site default value */
							__('Site default: %s', 'elasticpress-labs'),
							describeDefault(field),
						)}
					</span>
					<Button
						aria-label={sprintf(
							/* translators: %s: setting name */
							__('Change %s', 'elasticpress-labs'),
							field.label,
						)}
						onClick={() => setIsEditing(true)}
						size="small"
						variant="secondary"
					>
						{__('Change', 'elasticpress-labs')}
					</Button>
				</div>
			)}
			<FieldHelp details={field.details} help={field.help} id={`${id}-help`} />
		</div>
	);
};

/**
 * The field, when the screen knows the setting.
 *
 * @param {object} props Props of Field.
 * @param {string} props.fieldKey Setting key.
 * @returns {WPElement|null}
 */
export default ({ fieldKey, ...props }) =>
	answering.fields[fieldKey] ? <Field fieldKey={fieldKey} {...props} /> : null;
