/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { createContext, useContext, useEffect, useReducer, WPElement } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { restApiEndpoint } from './config';

/**
 * Sync contexts.
 */
const SearchTemplateContext = createContext();
const SearchTemplateDispatchContext = createContext();

/**
 * Search Templates Provider.
 *
 * @param {object} props Component props.
 * @param {Function} props.children Component children.
 * @returns {WPElement} Element.
 */
export const SearchTemplatesProvider = ({ children }) => {
	const reducer = (state, action) => {
		switch (action.type) {
			case 'SET_TEMPLATES':
				return { ...state, isLoading: false, templates: action.templates };
			case 'SET_TEMPLATE':
				state.templates[action.templateName] = JSON.stringify(action.template);
				return { ...state };
			case 'DELETE_TEMPLATE':
				delete state.templates[action.templateName];
				return { ...state };
			default:
				return state;
		}
	};

	const [state, dispatch] = useReducer(reducer, {
		templates: {},
		isLoading: true,
		error: null,
	});

	const loadTemplate = async (template) => {
		return apiFetch({
			path: `${restApiEndpoint}/${template}`,
		}).then((response) => {
			dispatch({ type: 'SET_TEMPLATE', templateName: template, template: response });
			return response;
		});
	};

	const saveTemplate = async (name, template) => {
		return apiFetch({
			path: `${restApiEndpoint}/${name}`,
			method: 'PUT',
			body: template,
		}).then((response) => {
			dispatch({ type: 'SET_TEMPLATE', templateName: name, template: response });
			return response;
		});
	};

	const deleteTemplate = (name) => {
		const response = apiFetch({
			path: `${restApiEndpoint}/${name}`,
			method: 'DELETE',
		});

		dispatch({ type: 'DELETE_TEMPLATE', templateName: name });
		return response;
	};

	useEffect(() => {
		apiFetch({ path: restApiEndpoint }).then((response) => {
			const templates = response.reduce((acc, template) => {
				acc[template] = null;

				return acc;
			}, {});
			dispatch({ type: 'SET_TEMPLATES', templates });
		});
	}, []);

	// eslint-disable-next-line react/jsx-no-constructed-context-values
	const customDispatch = {
		deleteTemplate,
		loadTemplate,
		saveTemplate,
	};

	return (
		<SearchTemplateContext.Provider value={state}>
			<SearchTemplateDispatchContext.Provider value={customDispatch}>
				{children}
			</SearchTemplateDispatchContext.Provider>
		</SearchTemplateContext.Provider>
	);
};

/**
 * Use the Search Templates Context.
 *
 * @returns {object} Search Templates Context.
 */
export const useSearchTemplate = () => useContext(SearchTemplateContext);

/**
 * Use the Search Templates Dispatch Context.
 *
 * @returns {object} Search Templates Dispatch Context.
 */
export const useSearchTemplateDispatch = () => useContext(SearchTemplateDispatchContext);
