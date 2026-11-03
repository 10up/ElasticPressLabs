/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, SearchControl } from '@wordpress/components';
import { Icon, chevronRight, drawerLeft } from '@wordpress/icons';
import { WPElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { chatEndpoint, historyUrl } from '../config';

/**
 * How long typing must pause before the search runs.
 */
const SEARCH_DELAY_MS = 300;

/**
 * The current user's latest conversations, with a search and a link to
 * History. Collapses to a thin strip with a toggle.
 *
 * A search lists the conversations History finds for it, up to the same
 * number as the latest ones. Clearing it shows the latest ones again.
 *
 * @param {object} props Component props.
 * @param {number} props.activeChatId Conversation on screen, or 0.
 * @param {Array} props.items Conversations: `id`, `title`, `date`, `url`.
 * @param {boolean} props.isOpen Whether the panel is expanded.
 * @param {Function} props.onToggle Called to expand or collapse the panel.
 * @returns {WPElement}
 */
export default ({ activeChatId, items, isOpen, onToggle }) => {
	const [search, setSearch] = useState('');
	// Conversations that match the search, or null while there is no search.
	const [results, setResults] = useState(null);
	const [isSearching, setIsSearching] = useState(false);
	const [searchError, setSearchError] = useState('');

	const term = search.trim();

	useEffect(() => {
		setSearchError('');

		if (!term || !chatEndpoint) {
			setResults(null);
			setIsSearching(false);
			return undefined;
		}

		const controller = new AbortController();

		setIsSearching(true);

		const timer = setTimeout(() => {
			apiFetch({
				path: addQueryArgs(chatEndpoint, { search: term }),
				signal: controller.signal,
			})
				.then((response) => {
					if (controller.signal.aborted) {
						return;
					}

					setResults(Array.isArray(response) ? response : []);
					setIsSearching(false);
				})
				.catch((error) => {
					if (error?.name === 'AbortError' || controller.signal.aborted) {
						return;
					}

					setResults([]);
					setSearchError(
						error?.message ||
							__('Your questions could not be searched.', 'elasticpress-labs'),
					);
					setIsSearching(false);
				});
		}, SEARCH_DELAY_MS);

		return () => {
			clearTimeout(timer);
			controller.abort();
		};
	}, [term]);

	const toggle = (
		<Button
			aria-controls={isOpen ? 'ep-ai-chat-previous-body' : undefined}
			aria-expanded={isOpen}
			className="ep-ai-chat-previous__toggle"
			icon={drawerLeft}
			label={
				isOpen
					? __('Hide previous questions', 'elasticpress-labs')
					: __('Show previous questions', 'elasticpress-labs')
			}
			onClick={onToggle}
			size="compact"
		/>
	);

	if (!isOpen) {
		return (
			<nav
				aria-label={__('Previous questions', 'elasticpress-labs')}
				className="ep-ai-chat-panel ep-ai-chat-previous is-collapsed"
			>
				{toggle}
			</nav>
		);
	}

	const isSearch = term !== '';
	const listed = isSearch ? results || [] : items;
	const viewAllUrl = historyUrl && isSearch ? addQueryArgs(historyUrl, { s: term }) : historyUrl;

	let empty = __('No questions yet. Ask one to start.', 'elasticpress-labs');

	if (isSearch) {
		if (isSearching) {
			empty = __('Searching…', 'elasticpress-labs');
		} else if (searchError) {
			empty = searchError;
		} else {
			empty = __('No questions match your search.', 'elasticpress-labs');
		}
	}

	return (
		<nav
			aria-labelledby="ep-ai-chat-previous-title"
			className="ep-ai-chat-panel ep-ai-chat-previous"
		>
			<div className="ep-ai-chat-panel__header">
				<h2 className="ep-ai-chat-panel__title" id="ep-ai-chat-previous-title">
					{__('Previous questions', 'elasticpress-labs')}
				</h2>
				<div className="ep-ai-chat-panel__actions">
					{viewAllUrl && (
						<a className="ep-ai-chat-panel__action" href={viewAllUrl}>
							{__('View all', 'elasticpress-labs')}
						</a>
					)}
					{toggle}
				</div>
			</div>
			<div className="ep-ai-chat-panel__body" id="ep-ai-chat-previous-body">
				{(items.length > 0 || isSearch) && chatEndpoint && (
					<SearchControl
						__nextHasNoMarginBottom
						className="ep-ai-chat-previous__search"
						label={__('Search previous questions', 'elasticpress-labs')}
						onChange={setSearch}
						placeholder={__('Search questions', 'elasticpress-labs')}
						size="compact"
						value={search}
					/>
				)}
				{listed.length && !(isSearch && isSearching) ? (
					<ul className="ep-ai-chat-item-group">
						{listed.map((item) => {
							const isActive = Number(item.id) === Number(activeChatId);

							return (
								<li key={item.id}>
									<a
										aria-current={isActive ? 'page' : undefined}
										className={`ep-ai-chat-item${isActive ? ' is-active' : ''}`}
										href={item.url}
									>
										<span className="ep-ai-chat-item__text">
											<span className="ep-ai-chat-item__title">
												{item.title}
											</span>
											<span className="ep-ai-chat-item__meta">
												{item.date}
											</span>
										</span>
										<Icon
											className="ep-ai-chat-item__chevron"
											icon={chevronRight}
										/>
									</a>
								</li>
							);
						})}
					</ul>
				) : (
					<p className="ep-ai-chat-panel__empty" role={isSearch ? 'status' : undefined}>
						{empty}
					</p>
				)}
			</div>
		</nav>
	);
};
