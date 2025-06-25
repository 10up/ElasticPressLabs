/**
 * WordPress dependencies.
 */
import { createRoot, WPElement, useState, useEffect, useMemo } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { activePostType, postTypes, metaFields } from './config';
import Control from './components/control';
import { PostTypeContext } from './provider';

/**
 * Styles.
 */
import './style.css';
// eslint-disable-next-line import/no-relative-packages
import '../../../../elasticpress/assets/css/dashboard.css';

/**
 * App component.
 *
 * @returns {WPElement} App component.
 */
const App = () => {
	const [values, setValues] = useState(metaFields);
	const currentPostType = postTypes.find((t) => t.slug === activePostType);

	const contextValue = useMemo(() => ({ values, setValues }), [values]);

	useEffect(() => {
		const form = document.querySelector('form#post');
		const submit = () => {
			const nonce = window.epPostTypes?.nonce;
			const nonceInput = document.createElement('input');
			nonceInput.type = 'hidden';
			nonceInput.name = 'ep_post_type_nonce';
			nonceInput.value = nonce;
			form.appendChild(nonceInput);

			currentPostType.settingsSchema.forEach((settings) => {
				const existing = form.querySelector(`input[name="${settings.key}"]`);
				if (existing) {
					existing.remove();
				}
				const input = document.createElement('input');
				input.type = 'hidden';
				input.name = settings.key;

				if (settings.type === 'field_group') {
					input.value = JSON.stringify(values[settings.key] || {});
				} else {
					input.value = values[settings.key] || '';
				}

				if (
					input.value.length === 0 &&
					currentPostType.defaultSettings[settings.key]?.length > 0
				) {
					input.value = currentPostType.defaultSettings[settings.key];
				}

				const defaults = currentPostType?.defaultSettings?.[settings.key];

				if (
					input.value === '{}' &&
					defaults &&
					typeof defaults === 'object' &&
					Object.keys(defaults).length
				) {
					input.value = JSON.stringify(defaults);
				}

				if (
					(input.value === '{}' ||
						input.value === 'undefined' ||
						input.value.length === 0) &&
					settings?.type === 'field_group' &&
					Array.isArray(settings?.fields) &&
					settings.fields.some((f) => 'default' in f)
				) {
					const defaultMeta = settings.fields.reduce((acc, field) => {
						if ('default' in field && 'key' in field) {
							acc[field.key] = field.default;
						}
						return acc;
					}, {});

					input.value = JSON.stringify(defaultMeta);
				}

				form.appendChild(input);
			});
		};
		form.addEventListener('submit', submit);
		return () => form.removeEventListener('submit', submit);
	}, [currentPostType.settingsSchema, values, currentPostType.defaultSettings]);

	/**
	 * Determines whether a control should be rendered based on its requirements.
	 *
	 * @param {object} requires_fields An object representing the required field values for rendering.
	 * Can contain 'conditions' object with field requirements and 'relationship' key ('AND' or 'OR').
	 * @returns {boolean} Returns `true` if the control should be rendered, otherwise `false`.
	 */
	const shouldRenderControl = (requires_fields) => {
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
		const relationship = (requires_fields.relationship || 'AND').toUpperCase();

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
		<div>
			<PostTypeContext.Provider value={contextValue}>
				{currentPostType.settingsSchema.map((schema) => {
					/**
					 * Skip rendering if the control should not be rendered based on requires_fields.
					 */
					if (!shouldRenderControl(schema.requires_fields)) {
						return null;
					}

					let value;
					if (typeof values[schema.key] !== 'undefined') {
						value = values[schema.key];
						// For field_group, if value is a string, parse it
						if (schema.type === 'field_group' && typeof value === 'string') {
							try {
								value = JSON.parse(value);
							} catch (e) {
								value = {};
							}
						}
						// For field_group, if value is an object and has keys, use it
						// For other types, if value is not empty, use it
						if (
							(schema.type === 'field_group' &&
								value &&
								typeof value === 'object' &&
								Object.keys(value).length > 0) ||
							(schema.type !== 'field_group' && value && value.length > 0)
						) {
							// use value as is
						} else {
							value =
								currentPostType.defaultSettings[schema.key] ??
								(schema.type === 'field_group' ? {} : '');
						}
					} else {
						value =
							currentPostType.defaultSettings[schema.key] ??
							(schema.type === 'field_group' ? {} : '');
					}

					return (
						<Control
							type={schema.type}
							settings={schema}
							value={value}
							onChange={(val) => {
								if (schema.type === 'field_group') {
									setValues((prev) => ({
										...prev,
										[schema.key]: val,
									}));
								} else {
									setValues((prev) => ({ ...prev, [schema.key]: val }));
								}
							}}
						/>
					);
				})}
			</PostTypeContext.Provider>
		</div>
	);
};

const rootEl = document.getElementById('ep-post-types-dashboard');
if (rootEl) {
	createRoot(rootEl).render(<App />);
}
