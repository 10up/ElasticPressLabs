/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { chatEndpoint } from './config';
import { ACTIVE_STATUSES } from './status';

/**
 * Wait before the next poll.
 *
 * @param {number} ms Delay.
 * @returns {Promise<void>}
 */
const sleep = (ms) =>
	new Promise((resolve) => {
		window.setTimeout(resolve, ms);
	});

/**
 * Find the question turn in a stored conversation.
 *
 * @param {Array} turns Storage turns.
 * @param {number} questionId Question comment ID.
 * @returns {object|undefined}
 */
const findQuestionTurn = (turns, questionId) =>
	(Array.isArray(turns) ? turns : []).find(
		(turn) => turn.role === 'user' && Number(turn.id) === Number(questionId),
	);

/**
 * Find the assistant turn after a question. Late answers superseded by a
 * newer question are skipped.
 *
 * @param {Array} turns Storage turns.
 * @param {number} questionId Question comment ID.
 * @returns {object|null}
 */
const findAnswerTurn = (turns, questionId) => {
	const list = Array.isArray(turns) ? turns : [];
	const index = list.indexOf(findQuestionTurn(list, questionId));

	if (index < 0) {
		return null;
	}

	return (
		list
			.slice(index + 1)
			.find((turn) => turn.role === 'assistant' && !turn.payload?.superseded) || null
	);
};

/**
 * Poll GET /chat/{id} until the question is terminal.
 *
 * The server times out stale jobs, so polling always ends. After the slow
 * ceiling, `onSlow` fires once and polling continues: a slow job that is
 * still working is allowed to finish.
 *
 * @param {object} args Poll args.
 * @param {number} args.epChatId Conversation ID.
 * @param {number} args.questionId Question comment ID.
 * @param {AbortSignal} args.signal Abort signal.
 * @param {Function} [args.onProgress] Called with the question turn on each poll.
 * @param {Function} [args.onSlow] Called once when the slow ceiling elapses.
 * @returns {Promise<{status: string, answer: object|null, question: object|undefined}>}
 */
export const pollChat = async ({ epChatId, questionId, signal, onProgress, onSlow }) => {
	// Read on each poll, not at load, so the timings can change on the page.
	const settings = window.epChat || {};
	const interval = Number(settings.pollIntervalMs) > 0 ? Number(settings.pollIntervalMs) : 2000;
	const slowAfter = Number(settings.pollTimeoutMs) > 0 ? Number(settings.pollTimeoutMs) : 300000;
	// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- The slow clock starts before the first poll.
	const started = Date.now();
	let slow = false;

	/* Sequential polls must wait for each GET before the next. */
	/* eslint-disable no-await-in-loop */
	for (;;) {
		if (signal?.aborted) {
			const aborted = new Error('Aborted');
			aborted.name = 'AbortError';
			throw aborted;
		}

		const response = await apiFetch({
			path: `${chatEndpoint}/${epChatId}`,
			signal,
		});
		const question = findQuestionTurn(response?.turns, questionId);
		const answer = findAnswerTurn(response?.turns, questionId);
		const status = question?.status || answer?.status || '';

		if (!question) {
			throw new Error(__('The question is no longer available.', 'elasticpress-labs'));
		}

		/* Only queued/running jobs are reaped, so any other status ends the poll. */
		if (!ACTIVE_STATUSES.includes(status)) {
			return { status, answer, question };
		}

		if (typeof onProgress === 'function' && question) {
			onProgress(question);
		}

		if (!slow && Date.now() - started >= slowAfter) {
			slow = true;

			if (typeof onSlow === 'function') {
				onSlow();
			}
		}

		await sleep(interval);
	}
	/* eslint-enable no-await-in-loop */
};
