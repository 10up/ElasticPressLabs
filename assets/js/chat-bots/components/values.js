/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { answering, availableGates, executionModes } from '../config';

/**
 * Every shared setting a bot can change (from PHP, Settings::FIELDS). An empty value uses the
 * Chat feature's.
 */
export const OVERRIDE_KEYS = Object.keys(answering.fields);

/**
 * The settings of each box, in order.
 */
export const BOX_KEYS = {
	answers: ['answer_model', 'verifier_model', 'answer_instructions', 'decline_display'],
	search: [
		'retrieve_count',
		'rerank_floor',
		'date_range_enabled',
		'search_algorithm',
		'search_fallback',
		'query_model',
	],
	conversation: ['history_messages', 'intent_enabled', 'clarify_enabled', 'summary_enabled'],
};

/**
 * Whether a search method calls a model (an AI search method), so the fallback search and the
 * query model apply.
 *
 * @param {string} method Search method value.
 * @returns {boolean}
 */
export const isAiMethod = (method) => (answering.aiMethods || []).includes(method);

/**
 * The value a bot uses for a setting: its own, else the one it inherits.
 *
 * @param {object} overrides Form overrides (key => string; empty inherits).
 * @param {string} key Setting key.
 * @returns {string}
 */
export const getEffectiveValue = (overrides, key) =>
	overrides[key] || String(answering.fields[key]?.inherited ?? '');

/**
 * A bot's overrides as field values: empty string for "use the site default".
 *
 * @param {object} bot Chat bot from REST (empty for a new bot).
 * @returns {object} Key => string.
 */
export const toOverrideValues = (bot = {}) =>
	Object.fromEntries(
		OVERRIDE_KEYS.map((key) => {
			let value = bot[key] === null || bot[key] === undefined ? '' : String(bot[key]);

			if (typeof bot[key] === 'boolean') {
				value = bot[key] ? '1' : '0';
			}

			return [key, value];
		}),
	);

/**
 * The gates of the site (a bot from REST has its own rows).
 *
 * @param {object|null} bot Chat bot.
 * @returns {Array}
 */
export const getGateRows = (bot = null) =>
	Array.isArray(bot?.gates) && bot.gates.length ? bot.gates : availableGates;

/**
 * Optional gates a new bot starts with: the ones on by default.
 *
 * @returns {Array} Gate slugs.
 */
export const defaultOptionalGates = () =>
	availableGates.filter((row) => !row.required && row.default).map((row) => row.slug);

/**
 * Form values of a bot, or of a new bot.
 *
 * @param {object|null} bot Chat bot from REST, or null for a new bot.
 * @returns {object} name, executionMode, rerankEnabled, optionalGates, and overrides.
 */
export const toFormValues = (bot) => {
	let optionalGates = defaultOptionalGates();

	if (bot) {
		optionalGates = Array.isArray(bot.optional_gates) ? bot.optional_gates : [];
	}

	return {
		name: bot?.name ?? '',
		executionMode: bot?.execution_mode ?? 'sync',
		rerankEnabled: Boolean(bot?.rerank_enabled),
		optionalGates,
		overrides: toOverrideValues(bot || {}),
	};
};

/**
 * How many of these settings the bot changes.
 *
 * @param {object} overrides Key => string.
 * @param {Array} keys Setting keys.
 * @returns {number}
 */
export const countChanges = (overrides, keys = OVERRIDE_KEYS) =>
	keys.filter((key) => (overrides[key] ?? '') !== '').length;

/**
 * "2 changes", or nothing.
 *
 * @param {number} count Changes.
 * @returns {string}
 */
export const describeChanges = (count) =>
	count
		? sprintf(
				/* translators: %d: number of settings the chat bot changes */
				_n('%d change', '%d changes', count, 'elasticpress-labs'),
				count,
			)
		: '';

/**
 * The model name of a model label ("OpenAI Compatible – nova-pro" is "nova-pro").
 *
 * @param {string} value Model value.
 * @returns {string}
 */
export const shortModelName = (value) => {
	const label = answering.models.find((model) => model.value === value)?.label || value;
	const parts = label.split(' – ');

	return parts[parts.length - 1];
};

/**
 * The one-line summary of a bot on its closed row.
 *
 * @param {object} values Form values.
 * @param {Array} gates Gate rows.
 * @returns {string}
 */
export const summarize = (values, gates) => {
	const parts = [];
	const mode = executionModes.find((item) => item.value === values.executionMode);

	parts.push(
		values.executionMode === 'sync'
			? __('Waits for the answer', 'elasticpress-labs')
			: sprintf(
					/* translators: %s: background runner, such as WP Async */
					__('In the background (%s)', 'elasticpress-labs'),
					mode?.label || values.executionMode,
				),
	);

	parts.push(
		values.overrides.answer_model
			? shortModelName(values.overrides.answer_model)
			: __('site default model', 'elasticpress-labs'),
	);

	const checks = gates.filter(
		(row) => row.required || values.optionalGates.includes(row.slug),
	).length;

	parts.push(
		sprintf(
			/* translators: %d: number of answer checks on */
			_n('%d check on', '%d checks on', checks, 'elasticpress-labs'),
			checks,
		),
	);

	if (values.rerankEnabled) {
		parts.push(__('re-ranking on', 'elasticpress-labs'));
	}

	const changes = describeChanges(countChanges(values.overrides));

	if (changes) {
		parts.push(changes);
	}

	return parts.join(' · ');
};
