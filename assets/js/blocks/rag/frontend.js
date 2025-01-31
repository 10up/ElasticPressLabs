/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { Placeholder } from '@wordpress/components';
import { createRoot, render, useEffect, useState, WPElement } from '@wordpress/element';
import Skeleton from 'react-loading-skeleton';
import 'react-loading-skeleton/dist/skeleton.css';

const { searchQuery, restApiEndpoint } = window.epRag;

/**
 * App component
 *
 * @returns {WPElement} App component.
 */
const App = () => {
	const [message, setMessage] = useState('');
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		apiFetch({
			path: `${restApiEndpoint}?search_query=${searchQuery}`,
		})
			.then((response) => {
				setMessage(response);
			})
			.finally(() => {
				setIsLoading(false);
			});
	}, []);

	return isLoading ? (
		<Placeholder>
			<Skeleton count={5} />
		</Placeholder>
	) : (
		<p>{message}</p>
	);
};

domReady(() => {
	const ragBlocks = document.querySelectorAll('.ep-rag-response');

	ragBlocks.forEach((ragBlock) => {
		if (typeof createRoot === 'function') {
			const root = createRoot(ragBlock);

			root.render(<App />);
		} else {
			render(<App />, ragBlock);
		}
	});
});
