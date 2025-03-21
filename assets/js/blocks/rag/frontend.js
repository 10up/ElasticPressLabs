import { env, pipeline } from '@huggingface/transformers';

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { Placeholder } from '@wordpress/components';
import { createRoot, render, useEffect, useState, WPElement } from '@wordpress/element';
import Skeleton from 'react-loading-skeleton';
import 'react-loading-skeleton/dist/skeleton.css';

const { modelUrl, restApiEndpoint, searchQuery, searchTermEmbeddingMethod } = window.epRag;

let finalModelUrl = 'Xenova/all-MiniLM-L6-v2';
if (modelUrl) {
	env.allowLocalModels = true;
	env.allowRemoteModels = false;
	finalModelUrl = modelUrl;
}

/**
 * App component
 *
 * @returns {WPElement} App component.
 */
const App = () => {
	const [message, setMessage] = useState('');
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		if (searchTermEmbeddingMethod === 'client-side') {
			pipeline('feature-extraction', finalModelUrl).then((pipe) => {
				pipe(searchQuery, { pooling: 'mean', normalize: true }).then((features) => {
					apiFetch({
						path: restApiEndpoint,
						method: 'POST',
						data: {
							search_query: searchQuery,
							search_vectors: features.data,
						},
					})
						.then((response) => {
							setMessage(response.html);
						})
						.finally(() => {
							setIsLoading(false);
						});
				});
			});
		} else {
			apiFetch({
				path: `${restApiEndpoint}?search_query=${searchQuery}`,
			})
				.then((response) => {
					setMessage(response.html);
				})
				.finally(() => {
					setIsLoading(false);
				});
		}
	}, []);

	return isLoading ? (
		<Placeholder>
			<Skeleton count={5} />
		</Placeholder>
	) : (
		// eslint-disable-next-line react/no-danger
		<p dangerouslySetInnerHTML={{ __html: message }} />
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
