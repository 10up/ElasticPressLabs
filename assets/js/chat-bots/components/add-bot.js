/**
 * WordPress Dependencies.
 */
import { Button, Flex } from '@wordpress/components';
import { useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { useChatBotsDispatch } from '../provider';
import useRequest from '../use-request';
import { GeneralFields, useBotForm } from './bot-form';

/**
 * "Add chat bot": a short form with the name and how answers arrive. Everything else starts
 * from the site defaults and is set in the new bot, which opens after it is created.
 *
 * @param {object} props Component props.
 * @param {Function} props.onCreated Called with the new bot.
 * @returns {WPElement}
 */
export default ({ onCreated }) => {
	const { createBot } = useChatBotsDispatch();
	const { isBusy: isSaving, run } = useRequest();
	const form = useBotForm();
	const [isOpen, setIsOpen] = useState(false);

	const onCreate = () => {
		const name = form.values.name.trim();

		if (!name || isSaving) {
			return;
		}

		run(createBot(name, form.values.executionMode, form.settings), {
			success: __('Chat bot created.', 'elasticpress-labs'),
			failure: __('Could not create the chat bot. Please try again.', 'elasticpress-labs'),
			onSuccess: (bot) => {
				form.reset();
				setIsOpen(false);
				onCreated(bot);
			},
		});
	};

	if (!isOpen) {
		return (
			<Button
				className="ep-chat-bots-add"
				icon={plus}
				onClick={() => setIsOpen(true)}
				variant="primary"
			>
				{__('Add chat bot', 'elasticpress-labs')}
			</Button>
		);
	}

	return (
		<form
			aria-label={__('New chat bot', 'elasticpress-labs')}
			className="ep-chat-bots-new"
			onSubmit={(event) => {
				event.preventDefault();
				onCreate();
			}}
		>
			<h2 className="ep-chat-bots-new__heading">{__('New chat bot', 'elasticpress-labs')}</h2>
			<p className="description">
				{__(
					'It starts with the site defaults. You can change them after it is created.',
					'elasticpress-labs',
				)}
			</p>
			<GeneralFields form={form} />
			<Flex className="ep-chat-bot-actions" justify="flex-start">
				<Button
					disabled={!form.values.name.trim() || isSaving}
					isBusy={isSaving}
					type="submit"
					variant="primary"
				>
					{__('Create chat bot', 'elasticpress-labs')}
				</Button>
				<Button
					disabled={isSaving}
					onClick={() => {
						form.reset();
						setIsOpen(false);
					}}
					variant="tertiary"
				>
					{__('Cancel', 'elasticpress-labs')}
				</Button>
			</Flex>
		</form>
	);
};
