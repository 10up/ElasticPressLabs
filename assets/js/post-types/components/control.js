/**
 * WordPress dependencies.
 */
import {
	CheckboxControl,
	FormTokenField,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { safeHTML } from '@wordpress/dom';
import { RawHTML } from '@wordpress/element';

const Control = ({ type, settings, value, onChange }) => {
	/**
	 * Handle change to checkbox values.
	 *
	 * @param {boolean} checked Whether checkbox is checked.
	 */
	const onChangeCheckbox = (checked) => {
		const value = checked ? '1' : '0';

		onChange(value);
	};

	/**
	 * Handle change to token field values.
	 *
	 * The FormTokenField control does not support separate values and labels,
	 * so whenever a change is made we need to set the field value based on the
	 * selected label.
	 *
	 * @param {string[]} values Selected values.
	 */
	const onChangeFormTokenField = (values) => {
		const value = values
			.map((v) => settings.options.find((o) => o.label === v)?.value)
			.filter(Boolean)
			.join(',');

		onChange(value);
	};

	return (
		<div className="ep-post-types-control">
			{(() => {
				switch (type) {
					case 'checkbox': {
						return (
							<CheckboxControl
								checked={value === '1'}
								help={settings.help}
								label={settings.label}
								onChange={onChangeCheckbox}
								// disabled={isDisabled}
								__nextHasNoMarginBottom
							/>
						);
					}
					case 'hidden': {
						return null;
					}
					case 'markup': {
						return <RawHTML>{safeHTML(settings.label)}</RawHTML>;
					}
					case 'multiple': {
						const suggestions = settings.options.map((o) => o.label);
						const values = value
							.split(',')
							.map((v) => settings.options.find((o) => o.value === v)?.label)
							.filter(Boolean);

						return (
							<FormTokenField
								__experimentalExpandOnFocus
								__experimentalShowHowTo={false}
								label={settings.label}
								onChange={onChangeFormTokenField}
								// disabled={isDisabled}
								suggestions={suggestions}
								value={values}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						);
					}
					case 'radio': {
						return (
							<RadioControl
								help={settings.help}
								label={settings.label}
								onChange={onChange}
								options={settings.options}
								// disabled={isDisabled}
								selected={value}
							/>
						);
					}
					case 'select': {
						return (
							<SelectControl
								help={settings.help}
								label={settings.label}
								onChange={onChange}
								options={settings.options}
								// disabled={isDisabled}
								value={value}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						);
					}
					case 'toggle': {
						return (
							<ToggleControl
								checked={value}
								help={settings.help}
								label={settings.label}
								onChange={onChange}
								// disabled={isDisabled}
								__nextHasNoMarginBottom
							/>
						);
					}
					case 'textarea': {
						return (
							<TextareaControl
								help={settings.help}
								label={settings.label}
								onChange={onChange}
								// disabled={isDisabled}
								value={value}
								__nextHasNoMarginBottom
							/>
						);
					}
					default: {
						return (
							<TextControl
								help={settings.help}
								label={settings.label}
								// onChange={onChange}
								// disabled={isDisabled}
								value={value}
								onChange={onChange}
								type={type}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						);
					}
				}
			})()}
		</div>
	);
};

export default Control;
