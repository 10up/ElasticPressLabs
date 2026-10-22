/**
 * WordPress dependencies.
 */
import { createRoot, render, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SettingsScreenProvider } from '../settings-screen';
import { ChatBotsProvider } from './provider';
import ChatBotsList from './apps/chat-bots-list';

/**
 * Styles.
 */
import '../chat-shared/help-more.css';
import './style.css';

/**
 * App component.
 *
 * @returns {WPElement}
 */
const App = () => (
	<SettingsScreenProvider title={__('AI Chat Bots', 'elasticpress-labs')}>
		<ChatBotsProvider>
			<ChatBotsList />
		</ChatBotsProvider>
	</SettingsScreenProvider>
);

/**
 * Root element.
 */
const el = document.getElementById('ep-chat-bots');

/**
 * Render.
 */
if (el) {
	if (typeof createRoot === 'function') {
		const root = createRoot(el);

		root.render(<App />);
	} else {
		render(<App />, el);
	}
}
