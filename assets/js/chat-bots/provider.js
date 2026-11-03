/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { createContext, useContext, useEffect, useReducer, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { restApiEndpoint } from './config';

/**
 * Chat bots contexts.
 */
/**
 * The REST body of a bot to create or save.
 *
 * @param {string} name Bot name.
 * @param {string} executionMode Execution mode slug.
 * @param {object} settings From useBotForm(): `rerankEnabled`, `optionalGates`, `overrides`.
 * @returns {object}
 */
const toBotRequest = (name, executionMode, settings = {}) => ({
	name,
	execution_mode: executionMode,
	rerank_enabled: Boolean(settings.rerankEnabled),
	optional_gates: Array.isArray(settings.optionalGates) ? settings.optionalGates : [],
	...(settings.overrides || {}),
});

const ChatBotsContext = createContext();
const ChatBotsDispatchContext = createContext();

/**
 * Chat Bots Provider.
 *
 * @param {object} props Component props.
 * @param {Function} props.children Component children.
 * @returns {WPElement} Element.
 */
export const ChatBotsProvider = ({ children }) => {
	const reducer = (state, action) => {
		switch (action.type) {
			case 'SET_BOTS':
				return { ...state, isLoading: false, error: null, bots: action.bots };
			case 'SET_ERROR':
				return { ...state, isLoading: false, error: action.error };
			case 'UPSERT_BOT': {
				const exists = state.bots.some((bot) => bot.id === action.bot.id);

				if (exists) {
					return {
						...state,
						bots: state.bots.map((bot) =>
							bot.id === action.bot.id ? action.bot : bot,
						),
					};
				}

				return {
					...state,
					bots: [...state.bots, action.bot],
				};
			}
			case 'DELETE_BOT':
				return {
					...state,
					bots: state.bots.filter((bot) => bot.id !== action.id),
				};
			default:
				return state;
		}
	};

	const [state, dispatch] = useReducer(reducer, {
		bots: [],
		isLoading: true,
		error: null,
	});

	const createBot = async (name, executionMode, settings = {}) => {
		const bot = await apiFetch({
			path: restApiEndpoint,
			method: 'POST',
			data: toBotRequest(name, executionMode, settings),
		});

		dispatch({ type: 'UPSERT_BOT', bot });

		return bot;
	};

	const saveBot = async (id, name, executionMode, settings = {}) => {
		const bot = await apiFetch({
			path: `${restApiEndpoint}/${id}`,
			method: 'PUT',
			data: toBotRequest(name, executionMode, settings),
		});

		dispatch({ type: 'UPSERT_BOT', bot });

		return bot;
	};

	const deleteBot = async (id) => {
		await apiFetch({
			path: `${restApiEndpoint}/${id}`,
			method: 'DELETE',
		});

		dispatch({ type: 'DELETE_BOT', id });
	};

	const duplicateBot = async (id) => {
		const bot = await apiFetch({
			path: `${restApiEndpoint}/${id}/duplicate`,
			method: 'POST',
		});

		dispatch({ type: 'UPSERT_BOT', bot });

		return bot;
	};

	const reorderBots = async (ids) => {
		const bots = await apiFetch({
			path: `${restApiEndpoint}/order`,
			method: 'PUT',
			data: { ids },
		});

		dispatch({ type: 'SET_BOTS', bots: Array.isArray(bots) ? bots : [] });

		return bots;
	};

	useEffect(() => {
		apiFetch({ path: restApiEndpoint })
			.then((bots) => {
				dispatch({ type: 'SET_BOTS', bots: Array.isArray(bots) ? bots : [] });
			})
			.catch((error) => {
				dispatch({
					type: 'SET_ERROR',
					error:
						error.message ||
						__(
							'Could not load the chat bots. Please reload the page.',
							'elasticpress-labs',
						),
				});
			});
	}, []);

	// eslint-disable-next-line react/jsx-no-constructed-context-values
	const customDispatch = {
		createBot,
		deleteBot,
		duplicateBot,
		reorderBots,
		saveBot,
	};

	return (
		<ChatBotsContext.Provider value={state}>
			<ChatBotsDispatchContext.Provider value={customDispatch}>
				{children}
			</ChatBotsDispatchContext.Provider>
		</ChatBotsContext.Provider>
	);
};

/**
 * Use the Chat Bots Context.
 *
 * @returns {object} Chat Bots Context.
 */
export const useChatBots = () => useContext(ChatBotsContext);

/**
 * Use the Chat Bots Dispatch Context.
 *
 * @returns {object} Chat Bots Dispatch Context.
 */
export const useChatBotsDispatch = () => useContext(ChatBotsDispatchContext);
