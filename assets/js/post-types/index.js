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

	return (
		<div>
			{currentPostType.settingsSchema.map((schema) => {
				return (
					<Control
						type={schema.type}
						settings={schema}
						value={values[schema.key] || ''}
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
