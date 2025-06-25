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
	Card,
	CardHeader,
	CardBody,
} from '@wordpress/components';
import { safeHTML } from '@wordpress/dom';
import { RawHTML } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { usePostTypeSettings } from '../provider';

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

	const { values } = usePostTypeSettings();

	return (
		<div className="ep-post-types-control">
			{(() => {
				switch (type) {
					case 'checkbox': {
						return (
							<CheckboxControl
								checked={value === '1'}
								help={settings.help ?? false}
								label={settings.label ?? false}
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
					case 'field_group': {
						const shouldRenderField = (requires_fields) => {
							if (!requires_fields || Object.keys(requires_fields).length === 0) {
								return true;
							}

							// Get field requirements from 'conditions' key
							let fieldRequirements;

							if (requires_fields.conditions) {
								fieldRequirements = Object.entries(requires_fields.conditions);
							}

							// If no actual field requirements, return true
							if (fieldRequirements.length === 0) {
								return true;
							}

							// Define the condition check function
							const checkCondition = ([fieldKey, requiredValue]) => {
								const actualValue = values[fieldKey];
								// const defaultValue = defaultSettings[fieldKey] ?? false;
								return actualValue === requiredValue;
							};

							// Extract relationship type, default to 'AND'
							const relationship = (
								requires_fields.relationship || 'AND'
							).toUpperCase();

							// Apply the appropriate logic based on relationship type
							switch (relationship) {
								case 'OR':
									return fieldRequirements.some(checkCondition);
								case 'AND':
								default:
									// Default to AND for any unexpected values
									return fieldRequirements.every(checkCondition);
							}
						};

						return (
							<div className="ep-field-group">
								<Card>
									{settings.label && (
										<CardHeader>
											<strong>{settings.label}</strong>
										</CardHeader>
									)}
									<CardBody>
										{settings.fields.map((field) => {
											const shouldRender = shouldRenderField(
												field.requires_fields,
											);

											// Skip rendering if the field's requirements aren't met
											if (!shouldRender) {
												return null;
											}
											// Get the current value for this field
											const fieldValue = value?.[field.key] ?? field.default;

											return (
												<Control
													// key={field.key}
													{...field}
													value={fieldValue}
													settings={field}
													onChange={(newValue) => {
														// Create a new object with the updated field value
														const updatedValue = {
															...value,
															[field.key]: newValue,
														};

														// Call the parent onChange with the updated nested value
														onChange(updatedValue);
													}}
												/>
											);
										})}
									</CardBody>
								</Card>
							</div>
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
