/**
 * WordPress dependencies.
 */
import { Button, Notice, SelectControl, TextareaControl } from '@wordpress/components';
import { createInterpolateElement, useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { botsAdminUrl, executionModes } from '../config';

/**
 * Label for a stored runner slug.
 *
 * @param {string} slug Bot execution_mode.
 * @returns {string}
 */
const runnerLabel = (slug) => executionModes.find((mode) => mode.value === slug)?.label || slug;

/**
 * Whether the keyboard shortcut uses ⌘ (macOS) instead of Ctrl.
 *
 * @returns {boolean}
 */
const usesMetaKey = () =>
	typeof window !== 'undefined' &&
	/Mac|iPhone|iPad/.test(window.navigator?.platform || window.navigator?.userAgent || '');

/**
 * Ask composer: the question box, with the chat bot picker and the Ask
 * button under it. Ctrl+Enter (⌘+Enter on macOS) sends the question.
 *
 * @param {object} props Component props.
 * @param {string} props.botId Selected chat bot ID.
 * @param {Array} props.bots Available chat bots.
 * @param {boolean} props.isBusy Whether a request is in flight.
 * @param {boolean} props.isLoadingBots Whether bots are still loading.
 * @param {Function} props.onAsk Called with the trimmed question.
 * @param {Function} props.onBotChange Called with the selected bot ID.
 * @returns {WPElement}
 */
export default ({ botId, bots, isBusy, isLoadingBots, onAsk, onBotChange }) => {
	const [question, setQuestion] = useState('');
	const hasBots = bots.length > 0;
	const canSubmit = Boolean(question.trim() && botId && hasBots && !isBusy && !isLoadingBots);
	const showBotSelect = bots.length > 1;
	const shortcut = usesMetaKey()
		? __('⌘ + Enter to send', 'elasticpress-labs')
		: __('Ctrl + Enter to send', 'elasticpress-labs');

	/**
	 * Send the question if it can be sent.
	 *
	 * @returns {void}
	 */
	const submit = () => {
		const nextQuestion = question.trim();

		if (!nextQuestion || !canSubmit) {
			return;
		}

		onAsk(nextQuestion);
		setQuestion('');
	};

	return (
		<form
			className="ep-ai-chat-ask"
			onSubmit={(event) => {
				event.preventDefault();
				submit();
			}}
		>
			{!isLoadingBots && !hasBots && (
				<Notice status="warning" isDismissible={false}>
					{botsAdminUrl
						? createInterpolateElement(
								__(
									'Create a chat bot on the <a>AI Chat Bots</a> screen before asking a question.',
									'elasticpress-labs',
								),
								{
									a: (
										// eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/control-has-associated-label
										<a href={botsAdminUrl} />
									),
								},
							)
						: __(
								'No chat bot is available yet. Ask an administrator to create one.',
								'elasticpress-labs',
							)}
				</Notice>
			)}
			<div className="ep-ai-chat-composer">
				<TextareaControl
					aria-describedby="ep-ai-chat-ask-shortcut"
					className="ep-ai-chat-composer__input"
					disabled={isBusy || !hasBots}
					hideLabelFromVision
					label={__('Question', 'elasticpress-labs')}
					onChange={setQuestion}
					onKeyDown={(event) => {
						if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
							event.preventDefault();
							submit();
						}
					}}
					placeholder={__('Ask a question about indexed content…', 'elasticpress-labs')}
					rows={3}
					value={question}
					__nextHasNoMarginBottom
				/>
				<div className="ep-ai-chat-ask__footer">
					<span className="ep-ai-chat-ask__shortcut" id="ep-ai-chat-ask-shortcut">
						{shortcut}
					</span>
					<div className="ep-ai-chat-ask__controls">
						{showBotSelect && (
							<SelectControl
								className="ep-ai-chat-ask__bot"
								disabled={isBusy || isLoadingBots}
								hideLabelFromVision
								label={__('Chat bot', 'elasticpress-labs')}
								onChange={onBotChange}
								options={bots.map((bot) => ({
									label: `${bot.name} (${runnerLabel(bot.execution_mode)})`,
									value: String(bot.id),
								}))}
								size="compact"
								value={botId}
								__nextHasNoMarginBottom
							/>
						)}
						<Button
							disabled={!canSubmit}
							isBusy={isBusy}
							type="submit"
							variant="primary"
							__next40pxDefaultSize
						>
							{__('Ask', 'elasticpress-labs')}
						</Button>
					</div>
				</div>
			</div>
		</form>
	);
};
