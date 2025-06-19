/**
 * WordPress dependencies.
 */
import { createRoot, WPElement, useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { activePostType, postTypes, metaFields } from './config';
import Control from './components/control';

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
				input.value = values[settings.key] || '';
				form.appendChild(input);
			});
		};
		form.addEventListener('submit', submit);
		return () => form.removeEventListener('submit', submit);
	}, [currentPostType.settingsSchema, values]);

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
			{currentPostType.settingsSchema.map((schema) => {
				/**
				 * Skip rendering if the control should not be rendered based on requires_fields.
				 */
				if (!shouldRenderControl(schema.requires_fields)) {
					return null;
				}

				const value =
					typeof values[schema.key] !== 'undefined' ? values[schema.key] : schema.default;

				return (
					<Control
						type={schema.type}
						settings={schema}
						value={value}
						onChange={(val) => setValues((prev) => ({ ...prev, [schema.key]: val }))}
					/>
				);
			})}
		</div>
	);
};

const rootEl = document.getElementById('ep-post-types-dashboard');
if (rootEl) {
	createRoot(rootEl).render(<App />);
}
