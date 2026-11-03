/**
 * WordPress Dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Styles.
 */
import './prompt-preview.css';

/**
 * A button that opens the answer prompt, read-only, in a modal. The prompt comes from the
 * prompt-preview REST route with the form's current (unsaved) values.
 *
 * @param {object} props Component props.
 * @param {string} props.path REST path of the prompt-preview route.
 * @param {object} props.data Request body: bot_id, and the unsaved answer_instructions and optional_gates.
 * @returns {WPElement}
 */
export default ({ path, data }) => {
	const [isOpen, setIsOpen] = useState(false);
	const [prompt, setPrompt] = useState('');
	const [error, setError] = useState('');

	useEffect(() => {
		if (!isOpen) {
			return undefined;
		}

		let current = true;

		apiFetch({ path, method: 'POST', data })
			.then((response) => current && setPrompt(response.prompt))
			.catch(
				(e) =>
					current &&
					setError(
						e.message || __('The preview could not be loaded.', 'elasticpress-labs'),
					),
			);

		return () => {
			current = false;
		};
		// The form cannot change while the modal is open, so the values are read once, on open.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [isOpen]);

	return (
		<div className="ep-chat-prompt-preview">
			<Button
				onClick={() => {
					// Never show the previous prompt while the new one loads.
					setPrompt('');
					setError('');
					setIsOpen(true);
				}}
				variant="secondary"
			>
				{__('Preview the full prompt', 'elasticpress-labs')}
			</Button>
			{isOpen ? (
				<Modal
					className="ep-chat-prompt-preview__modal"
					onRequestClose={() => setIsOpen(false)}
					size="large"
					title={__('Full prompt', 'elasticpress-labs')}
				>
					<p className="description">
						{__(
							'What the answer model receives, with the unsaved changes in the form and the gates that run. The built-in rules are part of the plugin and cannot be edited here. Code filters and gates can still change the prompt when a question is asked.',
							'elasticpress-labs',
						)}
					</p>
					{error ? (
						<Notice isDismissible={false} status="error">
							{error}
						</Notice>
					) : null}
					{!prompt && !error ? <Spinner /> : null}
					{prompt ? (
						<pre
							aria-label={__('Full prompt', 'elasticpress-labs')}
							className="ep-chat-prompt-preview__prompt"
						>
							{prompt}
						</pre>
					) : null}
				</Modal>
			) : null}
		</div>
	);
};
