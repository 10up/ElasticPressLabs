/**
 * WordPress Dependencies.
 */
import { Button, Draggable, Flex, PanelBody } from '@wordpress/components';
import { useEffect, useRef, WPElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { dragHandle } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { useChatBotsDispatch } from '../provider';
import useRequest from '../use-request';
import BotFields, { useBotForm } from './bot-form';
import { getGateRows, summarize } from './values';

/**
 * A chat bot: its name and a one-line summary, and its settings when open.
 *
 * @param {object} props Component props.
 * @param {object} props.bot Chat bot.
 * @param {boolean} props.canReorder Whether this row can be dragged.
 * @param {boolean} props.isDefault Whether this is the Ask default.
 * @param {boolean} [props.initialOpen] Whether the row starts open (a bot just added).
 * @param {Function} props.onMove Move one bot before another.
 * @returns {WPElement}
 */
export default ({ bot, canReorder, isDefault, initialOpen = false, onMove }) => {
	const { deleteBot, duplicateBot, saveBot } = useChatBotsDispatch();
	const { isBusy: isSaving, run } = useRequest();
	const form = useBotForm(bot);
	const ref = useRef(null);
	const gates = getGateRows(bot);

	// A bot just added opens, and comes into view.
	useEffect(() => {
		if (initialOpen && ref.current?.scrollIntoView) {
			ref.current.scrollIntoView({ block: 'start', behavior: 'smooth' });
		}
	}, [initialOpen]);

	const onSave = () =>
		run(saveBot(bot.id, form.values.name, form.values.executionMode, form.settings), {
			success: __('Chat bot saved.', 'elasticpress-labs'),
			failure: __('Could not save the chat bot. Please try again.', 'elasticpress-labs'),
		});

	const onDuplicate = () =>
		run(duplicateBot(bot.id), {
			/* translators: %s: new chat bot name */
			success: (copy) =>
				sprintf(__('Chat bot duplicated as “%s”.', 'elasticpress-labs'), copy.name),
			failure: __('Could not duplicate the chat bot. Please try again.', 'elasticpress-labs'),
		});

	const onDelete = () =>
		run(deleteBot(bot.id), {
			success: __('Chat bot deleted.', 'elasticpress-labs'),
			failure: __('Could not delete the chat bot. Please try again.', 'elasticpress-labs'),
		});

	const elementId = `ep-chat-bot-${bot.id}`;

	const title = (
		<span className="ep-chat-bot-row__title">
			<span className="ep-chat-bot-row__name">
				{bot.name}
				{isDefault ? (
					<span className="ep-chat-bot-row__tag">
						{__('Default', 'elasticpress-labs')}
					</span>
				) : null}
				{form.isDirty ? (
					<span className="ep-chat-bot-row__tag is-unsaved">
						{__('Unsaved changes', 'elasticpress-labs')}
					</span>
				) : null}
			</span>
			<span className="ep-chat-bot-row__summary">{summarize(form.values, gates)}</span>
		</span>
	);

	const onDrop = (event) => {
		event.preventDefault();

		if (!canReorder) {
			return;
		}

		try {
			const data = JSON.parse(event.dataTransfer.getData('text'));

			if (data?.id) {
				onMove(Number(data.id), bot.id);
			}
		} catch (error) {
			// Ignore malformed drag payloads.
		}
	};

	return (
		<div
			className="ep-chat-bot-row"
			id={elementId}
			onDragOver={(event) => {
				event.preventDefault();
			}}
			onDrop={onDrop}
			ref={ref}
			role="listitem"
		>
			{canReorder ? (
				<Draggable elementId={elementId} transferData={{ id: bot.id }}>
					{({ onDraggableStart, onDraggableEnd }) => (
						<Button
							className="ep-chat-bot-row__handle"
							draggable
							icon={dragHandle}
							label={__('Drag to reorder', 'elasticpress-labs')}
							onDragEnd={onDraggableEnd}
							onDragStart={onDraggableStart}
							showTooltip
							type="button"
						/>
					)}
				</Draggable>
			) : null}
			<PanelBody initialOpen={initialOpen} title={title}>
				<div className="ep-chat-bot-row__body">
					<BotFields botId={bot.id} form={form} gates={gates} />
					<Flex className="ep-chat-bot-actions" justify="flex-start">
						<Button
							disabled={!form.values.name.trim() || isSaving}
							isBusy={isSaving}
							onClick={onSave}
							type="button"
							variant="primary"
						>
							{__('Save changes', 'elasticpress-labs')}
						</Button>
						<Button
							disabled={isSaving}
							onClick={onDuplicate}
							type="button"
							variant="secondary"
						>
							{__('Duplicate', 'elasticpress-labs')}
						</Button>
						<Button
							disabled={isSaving}
							isDestructive
							onClick={onDelete}
							type="button"
							variant="tertiary"
						>
							{__('Delete', 'elasticpress-labs')}
						</Button>
					</Flex>
				</div>
			</PanelBody>
		</div>
	);
};
