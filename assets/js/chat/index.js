/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import {
	createRoot,
	render,
	useEffect,
	useMemo,
	useRef,
	useState,
	WPElement,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SettingsScreenProvider } from '../settings-screen';
import AskForm from './components/ask-form';
import { getSourceOrder } from './citation-order';
import CitationsPanel from './components/citations-panel';
import PreviousQuestions from './components/previous-questions';
import Thread from './components/thread';
import {
	askUrl,
	botsEndpoint,
	chatEndpoint,
	initialChatId,
	recentChats,
	recentChatsLimit,
	requestTimeoutMs,
	todayLabel,
} from './config';
import { pollChat } from './poll';
import { ACTIVE_STATUSES, hasNoSources, toCardStatus } from './status';

/**
 * Styles.
 */
import './style.css';

/**
 * Browser storage key for the Previous questions panel preference.
 */
const PREVIOUS_PANEL_KEY = 'epAiChat.previousQuestions';

/**
 * A random key for a new conversation. The server stores it on the conversation it creates, so a
 * retry after a timed-out request continues that conversation. `crypto.randomUUID()` needs a
 * secure context; `getRandomValues()` does not.
 *
 * @returns {string}
 */
const newClientKey = () =>
	Array.from(window.crypto.getRandomValues(new Uint8Array(16)), (byte) =>
		byte.toString(16).padStart(2, '0'),
	).join('');

/**
 * The stored panel preference: `open`, `closed`, or null (automatic).
 * Storage can be unavailable, for example in a private window.
 *
 * @returns {string|null}
 */
const readPanelPreference = () => {
	try {
		const value = window.localStorage.getItem(PREVIOUS_PANEL_KEY);

		return value === 'open' || value === 'closed' ? value : null;
	} catch {
		return null;
	}
};

/**
 * Store the panel preference.
 *
 * @param {string} value `open` or `closed`.
 * @returns {void}
 */
const writePanelPreference = (value) => {
	try {
		window.localStorage.setItem(PREVIOUS_PANEL_KEY, value);
	} catch {
		// Not stored: the panel falls back to automatic.
	}
};

/**
 * Decode HTML entities (`&hellip;`, `&amp;`) into plain text. DOMParser builds
 * an inert document (no scripts run, nothing loads), so this is safe for
 * untrusted text.
 *
 * @param {string} text Text that may contain entities.
 * @returns {string}
 */
const decodeEntities = (text) => {
	if (!text || !text.includes('&')) {
		return text || '';
	}

	return new window.DOMParser().parseFromString(text, 'text/html').documentElement.textContent;
};

/**
 * Passage text as a readable excerpt for the Sources rail.
 *
 * A passage may join several indexed chunks: each opens with its context line ("Title ›
 * Heading", or the title alone), headings are Markdown (`## Methods`), tables are Markdown rows,
 * and a meta chunk lists `## key: value` fields under `# Metadata`. The rail shows the text only:
 * no context lines, headings, or table rules; meta fields as their values; table cells joined
 * with " · ". The model still reads the passage as indexed.
 *
 * @param {string} excerpt Passage text.
 * @param {string} title Source title.
 * @returns {string}
 */
const toReadableExcerpt = (excerpt, title) => {
	const heading = String(title || '').trim();
	const parts = [];
	let inMeta = false;

	String(excerpt || '')
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean)
		.forEach((line) => {
			if (line.includes(' › ') || line === heading) {
				// A context line opens the next chunk.
				inMeta = false;
				return;
			}

			if (line === '# Metadata') {
				inMeta = true;
				return;
			}

			const field = inMeta ? line.match(/^##\s*[^:]+:\s*(.+)$/) : null;

			if (field) {
				parts.push(field[1].trim());
			} else if (line.startsWith('|')) {
				if (!/^\|?[\s:|-]+\|?$/.test(line)) {
					parts.push(
						line
							.split('|')
							.map((cell) => cell.trim())
							.filter(Boolean)
							.join(' · '),
					);
				}
			} else if (!/^#{1,6}\s/.test(line)) {
				parts.push(line);
			}
		});

	return parts.join(' ');
};

/**
 * Sources rail entries for an answer. The server numbers each passage
 * before synthesis (the prompt's "Source N"), so an inline `[[N]]` marker
 * points at it. Answers stored before that have no number and still hold
 * HTML entities: they are numbered by position and decoded.
 *
 * @param {Array} hits Stored answer hits.
 * @returns {Array}
 */
const toCitations = (hits) =>
	(Array.isArray(hits) ? hits : []).map((hit, index) => {
		if (Number(hit.number) > 0) {
			return {
				...hit,
				number: Number(hit.number),
				excerpt: toReadableExcerpt(hit.excerpt, hit.title),
			};
		}

		const title = decodeEntities(hit.title);

		return {
			...hit,
			number: index + 1,
			title,
			excerpt: toReadableExcerpt(decodeEntities(hit.excerpt), title),
		};
	});

/**
 * Related research for a refused answer: the passages retrieved for it, shown apart from
 * sources so a near miss never looks like a citation. Other answers have none.
 *
 * @param {object|undefined} payload Answer payload (stored or REST): `hits`.
 * @param {string|undefined} status Card status.
 * @returns {Array}
 */
const toRelated = (payload, status) =>
	status === 'refused' && payload?.refusal?.related !== false && Array.isArray(payload?.hits)
		? toCitations(payload.hits)
		: [];

/**
 * How a declined (or clarify) answer is shown: `kind`, `next_step`, `related`, and with decline
 * details on, `label` and `description`.
 *
 * @param {object|undefined} payload Answer payload (stored or REST).
 * @returns {object|null}
 */
const toRefusal = (payload) =>
	payload?.refusal && typeof payload.refusal === 'object' ? payload.refusal : null;

/**
 * The options of a clarify answer.
 *
 * @param {object|undefined} payload Answer payload (stored or REST).
 * @param {string|undefined} status Card status.
 * @returns {string[]}
 */
const toClarifyOptions = (payload, status) =>
	status === 'clarify' && Array.isArray(payload?.clarify?.options)
		? payload.clarify.options.filter((option) => typeof option === 'string' && option)
		: [];

/**
 * Provenance entries of an answer (one per `[[k]]` marker), or none for refusals and errors.
 *
 * @param {object|undefined} payload Answer payload (stored or REST).
 * @param {string|undefined} status Card status.
 * @returns {Array}
 */
const toProvenance = (payload, status) =>
	hasNoSources(status) || !Array.isArray(payload?.provenance) ? [] : payload.provenance;

/**
 * How an answer was made, for its "How this answer was made" panel: what was searched for (when
 * question intent rewrote the question) and how dates limited the search, the search method
 * (and the fallback that ran instead, if any), the AI-generated query, the models, what question
 * intent read, and where the answer instructions came from.
 *
 * @param {object|undefined} payload Answer payload (stored or REST): `search`, `models`, `intent`, `instructions`.
 * @param {string} question The question the answer replies to.
 * @returns {object|null} Null when there is nothing to show.
 */
const toDetails = (payload, question) => {
	const plan = payload?.search || {};
	const query = typeof plan.query === 'string' ? plan.query.trim() : '';
	const asked = String(question || '')
		.trim()
		.toLowerCase();
	const text = (value) => (typeof value === 'string' ? value : '');
	const object = (value) =>
		value && typeof value === 'object' && !Array.isArray(value) ? value : null;
	const details = {
		query: query && query.toLowerCase() !== asked ? query : '',
		after: text(plan.published_after),
		recent: Boolean(plan.recent),
		algorithm: text(plan.algorithm),
		requestedAlgorithm: text(plan.requested_algorithm),
		fallback: text(plan.fallback),
		fallbackError: text(plan.fallback_error),
		generatedQuery: object(plan.generated_query),
		models: object(payload?.models),
		intent: object(payload?.intent),
		instructions: object(payload?.instructions),
	};

	return details.query ||
		details.after ||
		details.recent ||
		details.algorithm ||
		details.models ||
		details.intent
		? details
		: null;
};

/**
 * Reader-safe copy when the pipeline stored no body.
 *
 * @param {string|undefined} status Card status.
 * @param {string} content Reply body.
 * @returns {string}
 */
const fallbackContent = (status, content) => {
	if (content) {
		return content;
	}

	if (status === 'refused') {
		return __('No relevant sources were found for that question.', 'elasticpress-labs');
	}

	if (status === 'error') {
		return __('The assistant could not generate a reply.', 'elasticpress-labs');
	}

	return '';
};

/**
 * An assistant message's fields for a finished answer (stored, polled, or a sync reply).
 *
 * @param {object|undefined} payload Answer payload: `hits`, `provenance`, `refusal`, `clarify`, `search`, `models`, `intent`, `instructions`.
 * @param {string} status Stored or REST status.
 * @param {string} content Reply body.
 * @param {string} question The question the answer replies to.
 * @returns {object}
 */
const toAnswerFields = (payload, status, content, question) => {
	const card = toCardStatus(status);

	return {
		content: fallbackContent(card, content || ''),
		status: card,
		slow: false,
		citations: hasNoSources(card) || !Array.isArray(payload?.hits) ? [] : payload.hits,
		provenance: toProvenance(payload, card),
		related: toRelated(payload, card),
		refusal: toRefusal(payload),
		clarifyOptions: toClarifyOptions(payload, card),
		details: toDetails(payload, question),
	};
};

/**
 * An assistant message's fields for a failed answer.
 *
 * @param {string} content What went wrong.
 * @returns {object}
 */
const toErrorFields = (content) => ({
	content,
	status: 'error',
	slow: false,
	citations: [],
});

/**
 * Map stored turns into thread messages.
 *
 * Late answers superseded by a newer question are hidden.
 *
 * @param {Array} turns Storage turns.
 * @returns {{messages: Array, lastAssistantId: string|null, pending: object|null, lastQuestion: string}}
 */
const turnsToMessages = (turns) => {
	let lastAssistantId = null;
	let lastQuestion = '';
	const visible = (Array.isArray(turns) ? turns : []).filter(
		(turn) => !(turn.role === 'assistant' && turn.payload?.superseded),
	);
	const messages = visible.map((turn) => {
		if (turn.role === 'user') {
			lastQuestion = turn.content || '';

			return {
				id: `question-${turn.id}`,
				role: 'user',
				content: turn.content || '',
			};
		}

		lastAssistantId = `answer-${turn.id}`;

		return {
			id: lastAssistantId,
			role: 'assistant',
			...toAnswerFields(turn.payload, turn.status, turn.content, lastQuestion),
		};
	});

	const lastTurn = visible.length ? visible[visible.length - 1] : null;
	let pending = null;

	if (lastTurn?.role === 'user' && ACTIVE_STATUSES.includes(lastTurn.status)) {
		const assistantId = `assistant-pending-${lastTurn.id}`;
		messages.push({
			id: assistantId,
			role: 'assistant',
			content: '',
			status: 'loading',
			progress: lastTurn.step_label || '',
		});
		lastAssistantId = assistantId;
		pending = {
			questionId: lastTurn.id,
			question: lastTurn.content || '',
		};
	}

	return { messages, lastAssistantId, pending, lastQuestion };
};

/**
 * Merge fields into one thread message.
 *
 * @param {Function} setMessages Messages state setter.
 * @param {string} id Message ID.
 * @param {object} patch Fields to set.
 * @returns {void}
 */
const patchMessage = (setMessages, id, patch) => {
	setMessages((previous) =>
		previous.map((message) => (message.id === id ? { ...message, ...patch } : message)),
	);
};

/**
 * Replace a loading card with the answer a poll found.
 *
 * @param {Function} setMessages Messages state setter.
 * @param {string} assistantId Loading card ID.
 * @param {object} polled From pollChat(): `status`, `answer`.
 * @param {string} question The question the answer replies to.
 * @returns {string} The answer's message ID.
 */
const showPolledAnswer = (setMessages, assistantId, polled, question) => {
	const nextId = polled.answer?.id ? `answer-${polled.answer.id}` : assistantId;

	setMessages((previous) =>
		previous.map((message) =>
			message.id === assistantId
				? {
						...message,
						id: nextId,
						...toAnswerFields(
							polled.answer?.payload,
							polled.status,
							polled.answer?.content,
							question,
						),
					}
				: message,
		),
	);

	return nextId;
};

/**
 * Poll callbacks that show the step label and the slow notice.
 *
 * @param {Function} setMessages Messages state setter.
 * @param {string} assistantId Loading card ID.
 * @returns {{onProgress: Function, onSlow: Function}}
 */
const pollCallbacks = (setMessages, assistantId) => ({
	onProgress: (question) =>
		patchMessage(setMessages, assistantId, { progress: question.step_label || '' }),
	onSlow: () => patchMessage(setMessages, assistantId, { slow: true }),
});

/**
 * Whether a poll still owns the loading card.
 *
 * @param {object} pendingRef Ref holding { controller, assistantId }.
 * @param {AbortController} controller Poll controller.
 * @returns {boolean}
 */
const ownsPending = (pendingRef, controller) => pendingRef.current?.controller === controller;

/**
 * Put the conversation ID on the Ask URL without a navigation.
 *
 * @param {number} epChatId Conversation post ID.
 * @returns {void}
 */
const setChatQuery = (epChatId) => {
	if (!epChatId || typeof window === 'undefined' || !window.history?.replaceState) {
		return;
	}

	const url = new URL(window.location.href);

	if (url.searchParams.get('chat') === String(epChatId)) {
		return;
	}

	url.searchParams.set('chat', String(epChatId));
	window.history.replaceState({}, '', url.toString());
};

/**
 * Remove the conversation ID from the Ask URL without a navigation.
 *
 * @returns {void}
 */
const clearChatQuery = () => {
	if (typeof window === 'undefined' || !window.history?.replaceState) {
		return;
	}

	const url = new URL(window.location.href);

	if (!url.searchParams.has('chat')) {
		return;
	}

	url.searchParams.delete('chat');
	window.history.replaceState({}, '', url.toString());
};

/**
 * Ask screen: one conversation thread. Follow-up questions stay in it.
 *
 * @returns {WPElement}
 */
const AskScreen = () => {
	const [messages, setMessages] = useState([]);
	const [chatId, setChatId] = useState(Number(initialChatId) || 0);
	const [recent, setRecent] = useState(Array.isArray(recentChats) ? recentChats : []);
	const [panelPreference, setPanelPreference] = useState(readPanelPreference);
	const [isRestoring, setIsRestoring] = useState(Number(initialChatId) > 0);
	const [notice, setNotice] = useState('');
	const [activeAssistantId, setActiveAssistantId] = useState(null);
	const [activeMarker, setActiveMarker] = useState(null);
	// Passage number of the source card under the pointer or keyboard focus.
	const [hoveredSource, setHoveredSource] = useState(null);

	// Another answer in the Sources rail: its cards are new, so nothing is hovered yet.
	useEffect(() => {
		setHoveredSource(null);
	}, [activeAssistantId]);
	const [isBusy, setIsBusy] = useState(false);
	const [bots, setBots] = useState([]);
	const [botId, setBotId] = useState('');
	const [isLoadingBots, setIsLoadingBots] = useState(true);
	const [lastQuestion, setLastQuestion] = useState('');

	/* The poll that owns the loading card: { controller, assistantId }. */
	const pendingRef = useRef(null);

	/* Loads the conversation from the `chat` query arg. New chat cancels it. */
	const restoreRef = useRef(null);

	/* Client key of the new conversation, until New chat. */
	const clientKeyRef = useRef(null);

	/**
	 * Merge fields into one thread message.
	 *
	 * @param {string} id Message ID.
	 * @param {object} patch Fields to set.
	 * @returns {void}
	 */
	const updateMessage = (id, patch) => patchMessage(setMessages, id, patch);

	/**
	 * Put a new conversation at the top of Previous questions.
	 *
	 * @param {number} epChatId Conversation post ID.
	 * @param {string} question Its first question, which is its title.
	 * @returns {void}
	 */
	const rememberConversation = (epChatId, question) => {
		const id = Number(epChatId);

		if (!id) {
			return;
		}

		const limit = Number(recentChatsLimit);

		if (!(limit > 0)) {
			return;
		}

		setRecent((previous) => {
			if (previous.some((item) => Number(item.id) === id)) {
				return previous;
			}

			const url = new URL(askUrl || window.location.href, window.location.href);
			url.searchParams.set('chat', String(id));

			return [
				{
					id,
					title: question,
					date: todayLabel,
					url: url.toString(),
				},
				...previous,
			].slice(0, limit);
		});
	};

	/**
	 * Stop waiting on the current poll, for example before a retry.
	 *
	 * @returns {void}
	 */
	const stopWaiting = () => {
		const pending = pendingRef.current;

		if (!pending) {
			return;
		}

		pendingRef.current = null;
		updateMessage(
			pending.assistantId,
			toErrorFields(__('Stopped waiting for this answer.', 'elasticpress-labs')),
		);
		pending.controller.abort();
	};

	useEffect(() => {
		if (!botsEndpoint) {
			setIsLoadingBots(false);
			return;
		}

		apiFetch({ path: botsEndpoint })
			.then((response) => {
				const nextBots = Array.isArray(response) ? response : [];

				setBots(nextBots);

				if (nextBots.length) {
					setBotId(String(nextBots[0].id));
				}
			})
			.catch(() => {
				setBots([]);
			})
			.finally(() => {
				setIsLoadingBots(false);
			});
	}, []);

	useEffect(() => {
		const epChatId = Number(initialChatId);

		if (!epChatId || !chatEndpoint) {
			setIsRestoring(false);
			return undefined;
		}

		const controller = new AbortController();
		restoreRef.current = controller;

		apiFetch({ path: `${chatEndpoint}/${epChatId}`, signal: controller.signal })
			.then(async (response) => {
				// New chat was clicked while the thread loaded.
				if (controller.signal.aborted) {
					return;
				}

				setIsRestoring(false);

				const {
					messages: nextMessages,
					lastAssistantId,
					pending,
					lastQuestion: restoredQuestion,
				} = turnsToMessages(response?.turns);

				if (!nextMessages.length) {
					return;
				}

				setMessages(nextMessages);
				setLastQuestion(restoredQuestion);

				if (lastAssistantId) {
					setActiveAssistantId(lastAssistantId);
				}

				if (!pending?.questionId) {
					return;
				}

				pendingRef.current = { controller, assistantId: lastAssistantId };
				setIsBusy(true);

				try {
					const polled = await pollChat({
						epChatId,
						questionId: pending.questionId,
						signal: controller.signal,
						...pollCallbacks(setMessages, lastAssistantId),
					});
					const nextAssistantId = showPolledAnswer(
						setMessages,
						lastAssistantId,
						polled,
						pending.question,
					);

					setActiveAssistantId(nextAssistantId);
				} catch (error) {
					if (error?.name === 'AbortError' || !ownsPending(pendingRef, controller)) {
						return;
					}

					patchMessage(
						setMessages,
						lastAssistantId,
						toErrorFields(
							__('The assistant could not generate a reply.', 'elasticpress-labs'),
						),
					);
				} finally {
					if (ownsPending(pendingRef, controller)) {
						pendingRef.current = null;
						setIsBusy(false);
					}
				}
			})
			.catch((error) => {
				if (error?.name === 'AbortError' || controller.signal.aborted) {
					return;
				}

				setIsRestoring(false);

				const status = error?.data?.status;

				if (status === 404 || status === 403) {
					/* Asking again would fail on this conversation, so start a new one. */
					setChatId(0);
					clearChatQuery();
					setNotice(
						status === 404
							? __(
									'That conversation is not available. It may have been moved to the Trash.',
									'elasticpress-labs',
								)
							: __(
									'You do not have access to that conversation.',
									'elasticpress-labs',
								),
					);
					return;
				}

				setNotice(
					__(
						'The conversation could not be loaded. Reload the page to try again.',
						'elasticpress-labs',
					),
				);
			});

		return () => {
			controller.abort();
		};
	}, []);

	const activeMessage = messages.find((message) => message.id === activeAssistantId);
	const activeHits = activeMessage?.citations;
	const activeCitations = useMemo(() => toCitations(activeHits), [activeHits]);
	const sourceOrder = useMemo(() => getSourceOrder(activeMessage), [activeMessage]);

	const activeProvenance =
		activeMarker && activeMarker.messageId === activeAssistantId
			? (activeMessage?.provenance || []).find((entry) => Number(entry.k) === activeMarker.k)
			: undefined;
	// The selected marker's source is the highlighted source card.
	const activeCitation = activeProvenance ? Number(activeProvenance.n) : null;

	const clearSelection = () => setActiveMarker(null);

	// Escape clears the selected marker.
	useEffect(() => {
		if (!activeMarker) {
			return undefined;
		}

		const onKeyDown = (event) => {
			if (event.key === 'Escape') {
				clearSelection();
			}
		};

		document.addEventListener('keydown', onKeyDown);

		return () => document.removeEventListener('keydown', onKeyDown);
	}, [activeMarker]);

	/**
	 * Send the question to the Chat REST route and show the reply.
	 *
	 * @param {string} question Submitted question.
	 * @returns {Promise<void>}
	 */
	const handleAsk = async (question) => {
		stopWaiting();

		const stamp = Date.now();
		const assistantId = `assistant-${stamp}`;
		// Only a question without a conversation starts a new one for Previous questions.
		const startsConversation = !chatId;

		if (startsConversation && !clientKeyRef.current) {
			clientKeyRef.current = newClientKey();
		}

		setNotice('');
		setLastQuestion(question);
		setMessages((previous) => [
			...previous,
			{
				id: `user-${stamp}`,
				role: 'user',
				content: question,
			},
			{
				id: assistantId,
				role: 'assistant',
				content: '',
				status: 'loading',
			},
		]);
		setActiveAssistantId(assistantId);
		setActiveMarker(null);
		setIsBusy(true);

		const controller = new AbortController();
		pendingRef.current = { controller, assistantId };
		const timeout = Number(requestTimeoutMs) > 0 ? Number(requestTimeoutMs) : 55000;
		let timedOut = false;
		const timeoutId = window.setTimeout(() => {
			timedOut = true;
			controller.abort();
		}, timeout);

		try {
			const response = await apiFetch({
				path: chatEndpoint,
				method: 'POST',
				signal: controller.signal,
				data: {
					question,
					bot_id: Number(botId),
					...(chatId ? { ep_chat_id: chatId } : { client_key: clientKeyRef.current }),
				},
			});

			window.clearTimeout(timeoutId);

			if (response?.ep_chat_id) {
				setChatId(Number(response.ep_chat_id));
				setChatQuery(response.ep_chat_id);

				if (startsConversation) {
					rememberConversation(response.ep_chat_id, question);
				}
			}

			// A question that joined a running job is polled even if that job just finished.
			if (ACTIVE_STATUSES.includes(response?.status) || response?.attached) {
				setMessages((previous) =>
					previous.map((message) => {
						if (message.id === `user-${stamp}` && response?.question_id) {
							return {
								...message,
								id: `question-${response.question_id}`,
							};
						}

						return message;
					}),
				);

				const polled = await pollChat({
					epChatId: response.ep_chat_id,
					questionId: response.question_id,
					signal: controller.signal,
					...pollCallbacks(setMessages, assistantId),
				});
				const nextAssistantId = showPolledAnswer(
					setMessages,
					assistantId,
					polled,
					question,
				);

				setActiveAssistantId(nextAssistantId);
			} else {
				const nextAssistantId = response?.answer_id
					? `answer-${response.answer_id}`
					: assistantId;
				// A sync reply has the stored payload as `meta`, with the sources (and their
				// notes) and citations at the top level.
				const payload = {
					...(response?.meta || {}),
					hits: response?.hits,
					provenance: response?.provenance,
					refusal: response?.refusal ?? response?.meta?.refusal,
				};

				setMessages((previous) =>
					previous.map((message) => {
						if (message.id === `user-${stamp}` && response?.question_id) {
							return {
								...message,
								id: `question-${response.question_id}`,
							};
						}

						if (message.id !== assistantId) {
							return message;
						}

						return {
							...message,
							id: nextAssistantId,
							...toAnswerFields(
								payload,
								response?.status,
								response?.content,
								question,
							),
						};
					}),
				);
				setActiveAssistantId(nextAssistantId);
			}
		} catch (error) {
			if (error?.data?.ep_chat_id) {
				setChatId(Number(error.data.ep_chat_id));
				setChatQuery(error.data.ep_chat_id);

				if (startsConversation) {
					rememberConversation(error.data.ep_chat_id, question);
				}
			}

			if (!ownsPending(pendingRef, controller)) {
				return;
			}

			if (error?.name === 'AbortError' && !timedOut) {
				return;
			}

			const fallback = timedOut
				? error?.message || __('The request timed out. Try again.', 'elasticpress-labs')
				: __('The assistant could not generate a reply.', 'elasticpress-labs');

			patchMessage(
				setMessages,
				assistantId,
				toErrorFields(error?.message && !timedOut ? error.message : fallback),
			);
		} finally {
			window.clearTimeout(timeoutId);

			if (ownsPending(pendingRef, controller)) {
				pendingRef.current = null;
				setIsBusy(false);
			}
		}
	};

	/**
	 * Leave the current conversation and start an empty one.
	 *
	 * @returns {void}
	 */
	const handleNewChat = () => {
		const pending = pendingRef.current;

		pendingRef.current = null;
		pending?.controller.abort();
		restoreRef.current?.abort();
		clientKeyRef.current = null;

		setMessages([]);
		setChatId(0);
		setNotice('');
		setLastQuestion('');
		setActiveAssistantId(null);
		setActiveMarker(null);
		setIsBusy(false);
		setIsRestoring(false);
		clearChatQuery();
	};

	const isSlow = messages.some((message) => message.status === 'loading' && message.slow);
	const isEmptyChat = messages.length === 0 && chatId === 0;

	/* Automatic: open on an empty chat, collapsed on a thread. A manual toggle wins. */
	const isPanelOpen = panelPreference ? panelPreference === 'open' : isEmptyChat;

	/**
	 * Expand or collapse Previous questions, and remember the choice.
	 *
	 * @returns {void}
	 */
	const togglePanel = () => {
		const next = isPanelOpen ? 'closed' : 'open';

		setPanelPreference(next);
		writePanelPreference(next);
	};

	return (
		<div className="ep-ai-chat">
			{!isEmptyChat && (
				<div className="ep-ai-chat__toolbar">
					<Button onClick={handleNewChat} variant="secondary" __next40pxDefaultSize>
						{__('New chat', 'elasticpress-labs')}
					</Button>
				</div>
			)}
			{notice && (
				<Notice isDismissible onRemove={() => setNotice('')} status="warning">
					{notice}
				</Notice>
			)}
			<div className="ep-ai-chat__workspace">
				<PreviousQuestions
					activeChatId={chatId}
					isOpen={isPanelOpen}
					items={recent}
					onToggle={togglePanel}
				/>
				<div className="ep-ai-chat__main">
					{messages.length > 0 && (
						<Thread
							activeMarker={activeMarker}
							highlightedSource={
								hoveredSource
									? { messageId: activeAssistantId, n: hoveredSource }
									: null
							}
							messages={messages}
							onCiteClick={(messageId, entry) => {
								// Selecting the selected marker again clears the selection.
								if (
									activeMarker?.messageId === messageId &&
									activeMarker?.k === Number(entry.k)
								) {
									clearSelection();
									return;
								}

								setActiveAssistantId(messageId);
								setActiveMarker({ messageId, k: Number(entry.k) });
							}}
							isBusy={isBusy || isRestoring}
							onAsk={handleAsk}
							onRetry={
								lastQuestion && (!isBusy || isSlow)
									? () => {
											handleAsk(lastQuestion);
										}
									: undefined
							}
						/>
					)}
					<AskForm
						botId={botId}
						bots={bots}
						isBusy={isBusy || isRestoring}
						isLoadingBots={isLoadingBots}
						onAsk={handleAsk}
						onBotChange={setBotId}
					/>
				</div>
				{activeCitations.length > 0 && (
					<CitationsPanel
						activeCitation={activeCitation}
						activeProvenance={activeProvenance}
						citations={activeCitations}
						onClear={clearSelection}
						onHover={setHoveredSource}
						order={sourceOrder}
					/>
				)}
			</div>
		</div>
	);
};

/**
 * App component.
 *
 * @returns {WPElement}
 */
const App = () => (
	<SettingsScreenProvider headerEnd title={__('Ask', 'elasticpress-labs')}>
		<AskScreen />
	</SettingsScreenProvider>
);

/**
 * Root element.
 */
const el = document.getElementById('ep-ai-chat');

/**
 * Render.
 */
if (el) {
	if (typeof createRoot === 'function') {
		const root = createRoot(el);

		root.render(<App />);
	} else {
		render(<App />, el);
	}
}
