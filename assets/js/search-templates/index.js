/**
 * WordPress dependencies.
 */
import { createRoot, render, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SettingsScreenProvider } from '../settings-screen';
import { SearchTemplatesProvider } from './provider';
import SearchTemplatesList from './apps/search-templates-list';

/**
 * Styles.
 */
import './style.css';

/**
 * App component.
 *
 * @returns {WPElement}
 */
const App = () => (
	<SettingsScreenProvider title={__('Search Templates', 'elasticpress-labs')}>
		<SearchTemplatesProvider>
			<SearchTemplatesList />
		</SearchTemplatesProvider>
	</SettingsScreenProvider>
);

/**
 * Root element.
 */
const el = document.getElementById('ep-search-templates');

/**
 * Render.
 */
if (typeof createRoot === 'function') {
	const root = createRoot(el);

	root.render(<App />);
} else {
	render(<App />, el);
}
