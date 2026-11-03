/**
 * WordPress Dependencies.
 */
import { Notice, Panel, Spinner } from '@wordpress/components';
import { useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useChatBots, useChatBotsDispatch } from '../provider';
import useRequest from '../use-request';
import AddBot from '../components/add-bot';
import BotRow from '../components/bot-row';

/**
 * Chat bots: "Add chat bot", then the list.
 *
 * @returns {WPElement} App element.
 */
export default () => {
	const { isLoading, error, bots } = useChatBots();
	const { reorderBots } = useChatBotsDispatch();
	const { run } = useRequest();
	const [addedId, setAddedId] = useState(0);

	const onMove = (fromId, toId) => {
		if (fromId === toId) {
			return;
		}

		const ids = bots.map((bot) => bot.id);
		const from = ids.indexOf(fromId);
		const to = ids.indexOf(toId);

		if (from < 0 || to < 0) {
			return;
		}

		ids.splice(from, 1);
		ids.splice(to, 0, fromId);

		run(reorderBots(ids), {
			failure: __('Could not reorder the chat bots. Please try again.', 'elasticpress-labs'),
		});
	};

	return (
		<>
			<p className="ep-chat-bots-intro">
				{__(
					'People pick a chat bot on the Ask screen. The first one is the default: drag the handle to reorder.',
					'elasticpress-labs',
				)}
			</p>
			<div className="ep-chat-bots-add-wrap">
				<AddBot onCreated={(bot) => setAddedId(bot.id)} />
			</div>
			<Panel className="ep-chat-bots-panel">
				{isLoading ? (
					<div className="ep-chat-bots-loading">
						{__('Loading…', 'elasticpress-labs')}
						<Spinner />
					</div>
				) : null}
				{error ? (
					<Notice className="ep-chat-bots-error" isDismissible={false} status="error">
						{error}
					</Notice>
				) : null}
				{!isLoading && !error ? (
					<>
						<h2 className="ep-chat-bots-list-heading">
							{__('Chat bots', 'elasticpress-labs')}
						</h2>
						{bots.length ? (
							<div className="ep-chat-bots-list" role="list">
								{bots.map((bot, index) => (
									<BotRow
										key={bot.id}
										bot={bot}
										canReorder={bots.length > 1}
										initialOpen={bot.id === addedId}
										isDefault={index === 0}
										onMove={onMove}
									/>
								))}
							</div>
						) : (
							<p className="ep-chat-bots-list-empty">
								{__('No chat bots yet.', 'elasticpress-labs')}
							</p>
						)}
					</>
				) : null}
			</Panel>
		</>
	);
};
