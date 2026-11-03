/**
 * WordPress Dependencies.
 */
import { ExternalLink, TextControl, ToggleControl } from '@wordpress/components';
import { createInterpolateElement, useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import PromptPreview from '../../chat-shared/prompt-preview';
import { answering, restApiEndpoint } from '../config';
import AnswerChecks from './answer-checks';
import AnswerModeFields from './answer-mode-fields';
import FieldBox from './field-box';
import InheritedField from './inherited-field';
import {
	BOX_KEYS,
	countChanges,
	describeChanges,
	getEffectiveValue,
	isAiMethod,
	toFormValues,
} from './values';

/**
 * State of one bot's form.
 *
 * @param {object|null} [bot] Chat bot from REST, or null for a new bot.
 * @returns {{values: object, set: Function, setOverride: Function, reset: Function, settings: object, isDirty: boolean}}
 */
export const useBotForm = (bot = null) => {
	const [values, setValues] = useState(() => toFormValues(bot));

	return {
		values,
		set: (key, value) => setValues((previous) => ({ ...previous, [key]: value })),
		setOverride: (key, value) =>
			setValues((previous) => ({
				...previous,
				overrides: { ...previous.overrides, [key]: value },
			})),
		reset: () => setValues(toFormValues(bot)),
		// Whether the form differs from the saved bot.
		isDirty: JSON.stringify(values) !== JSON.stringify(toFormValues(bot)),
		// What createBot() and saveBot() take besides the name and execution mode.
		settings: {
			rerankEnabled: values.rerankEnabled,
			optionalGates: values.optionalGates,
			overrides: values.overrides,
		},
	};
};

/**
 * The name and how answers arrive: all a new bot needs.
 *
 * @param {object} props Component props.
 * @param {object} props.form From useBotForm().
 * @returns {WPElement}
 */
export const GeneralFields = ({ form }) => {
	const { values, set } = form;

	return (
		<>
			<TextControl
				className="ep-chat-bot-name"
				help={values.name.trim() ? '' : __('Required.', 'elasticpress-labs')}
				label={__('Name (required)', 'elasticpress-labs')}
				onChange={(value) => set('name', value)}
				required
				value={values.name}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<AnswerModeFields
				executionMode={values.executionMode}
				setExecutionMode={(value) => set('executionMode', value)}
			/>
		</>
	);
};

/**
 * A bot's settings in boxes: General, Answers (with what a declined question shows), Search,
 * Conversation, and Answer checks. Each box counts the settings the bot changes.
 *
 * @param {object} props Component props.
 * @param {object} props.form From useBotForm().
 * @param {Array} props.gates The bot's gate rows.
 * @param {number} props.botId Bot ID, for the prompt preview.
 * @returns {WPElement}
 */
export default ({ form, gates, botId }) => {
	const { values, set, setOverride } = form;
	const field = (key, children = null) => (
		<InheritedField
			fieldKey={key}
			key={key}
			onChange={(next) => setOverride(key, next)}
			value={values.overrides[key] ?? ''}
		>
			{children}
		</InheritedField>
	);
	const badge = (box) => describeChanges(countChanges(values.overrides, BOX_KEYS[box]));

	return (
		<>
			<FieldBox initialOpen title={__('General', 'elasticpress-labs')}>
				<GeneralFields form={form} />
			</FieldBox>
			<FieldBox badge={badge('answers')} title={__('Answers', 'elasticpress-labs')}>
				{BOX_KEYS.answers.map((key) =>
					field(
						key,
						key === 'answer_instructions' ? (
							<PromptPreview
								data={{
									bot_id: botId,
									optional_gates: values.optionalGates,
									answer_instructions: values.overrides.answer_instructions,
								}}
								path={`${restApiEndpoint}/prompt-preview`}
							/>
						) : null,
					),
				)}
			</FieldBox>
			<FieldBox badge={badge('search')} title={__('Search', 'elasticpress-labs')}>
				{field('retrieve_count')}
				<div className="ep-chat-bot-field">
					<ToggleControl
						checked={values.rerankEnabled}
						disabled={!answering.rerankSetUp && !values.rerankEnabled}
						help={
							answering.rerankSetUp
								? __(
										'Re-ranks the search results by relevance before the model reads them.',
										'elasticpress-labs',
									)
								: createInterpolateElement(
										__(
											'Set up the re-ranker in the <a>Chat settings</a> first.',
											'elasticpress-labs',
										),
										{ a: <ExternalLink href={answering.featureUrl} /> },
									)
						}
						label={__('Re-rank the results', 'elasticpress-labs')}
						onChange={(next) => set('rerankEnabled', next)}
						__nextHasNoMarginBottom
					/>
				</div>
				{values.rerankEnabled ? field('rerank_floor') : null}
				{field('date_range_enabled')}
				{field('search_algorithm')}
				{isAiMethod(getEffectiveValue(values.overrides, 'search_algorithm')) ? (
					<>
						{field('search_fallback')}
						{field('query_model')}
					</>
				) : null}
			</FieldBox>
			<FieldBox badge={badge('conversation')} title={__('Conversation', 'elasticpress-labs')}>
				{BOX_KEYS.conversation.map((key) => field(key))}
			</FieldBox>
			<AnswerChecks
				gates={gates}
				optionalGates={values.optionalGates}
				setOptionalGates={(next) => set('optionalGates', next)}
			/>
		</>
	);
};
