/**
 * WordPress dependencies.
 */
import { Button } from '@wordpress/components';
import { useState, WPElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { info } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import Markdown from './markdown';
import { getSourceOrder } from '../citation-order';
import { aiMethods, searchAlgorithms, technicalDetails } from '../config';
import { hasNoSources } from '../status';
import ProvenanceMarker from './provenance-marker';

/**
 * Text renderer that turns `[[k]]` markers into provenance markers.
 *
 * @param {object} message Assistant message, with `provenance` entries keyed by `k`.
 * @param {object|null} activeMarker Selected marker: `messageId`, `k`.
 * @param {Function} onCiteClick Marker click handler, called with the message id and entry.
 * @param {object|null} highlightedSource Hovered source: `messageId`, `n` (passage number).
 * @returns {Function} `(text, key) => Array`
 */
const citationText = (message, activeMarker, onCiteClick, highlightedSource) => {
	const entries = new Map(
		(Array.isArray(message.provenance) ? message.provenance : []).map((entry) => [
			Number(entry.k),
			entry,
		]),
	);
	const order = getSourceOrder(message);

	return (text, key) =>
		String(text)
			.split(/(\[\[\d+\]\])/g)
			.map((part, index) => {
				const match = part.match(/^\[\[(\d+)\]\]$/);

				if (!match) {
					return part;
				}

				const k = Number(match[1]);
				const entry = entries.get(k);

				return (
					<ProvenanceMarker
						entry={entry}
						label={entry ? order.get(Number(entry.n)) : undefined}
						isActive={
							Boolean(entry) &&
							activeMarker?.messageId === message.id &&
							activeMarker?.k === k
						}
						isHighlighted={
							Boolean(entry) &&
							highlightedSource?.messageId === message.id &&
							highlightedSource?.n === Number(entry.n)
						}
						k={k}
						// eslint-disable-next-line react/no-array-index-key -- Split text is static, so its position is a stable key.
						key={`${message.id}-${key}-${index}`}
						onSelect={(selected) => onCiteClick(message.id, selected)}
					/>
				);
			});
};

/**
 * Related sources in a refusal: what was retrieved for the question, as plain links. Not
 * numbered and not in the Sources panel, so a near miss never looks like a citation.
 *
 * @param {object} props Component props.
 * @param {string} props.id Message id.
 * @param {Array} props.items Retrieved sources: `title`, `url`.
 * @returns {WPElement}
 */
const RelatedResearch = ({ id, items }) => {
	const headingId = `${id}-related`;

	return (
		<section aria-labelledby={headingId} className="ep-ai-chat-related">
			<h3 className="ep-ai-chat-related__title" id={headingId}>
				{__('These sources may interest you', 'elasticpress-labs')}
			</h3>
			<ul className="ep-ai-chat-related__list">
				{items.map((item) => (
					<li key={item.number}>
						<a
							className="ep-ai-chat-related__link"
							href={item.url}
							rel="noopener noreferrer"
							target="_blank"
						>
							<span className="ep-ai-chat-related__name">{item.title}</span>
							<span aria-hidden="true" className="ep-ai-chat-related__arrow">
								↗
							</span>
							<span className="screen-reader-text">
								{__('(opens in a new tab)', 'elasticpress-labs')}
							</span>
						</a>
					</li>
				))}
			</ul>
		</section>
	);
};

/**
 * The "Why?" disclosure of a declined answer: the reason's description.
 *
 * @param {object} props Component props.
 * @param {string} props.id Message id.
 * @param {string} props.text Description.
 * @returns {WPElement}
 */
const DeclineWhy = ({ id, text }) => {
	const [isOpen, setIsOpen] = useState(false);

	return (
		<div className="ep-ai-chat-decline__why">
			<Button
				aria-controls={`${id}-why`}
				aria-expanded={isOpen}
				className="ep-ai-chat-decline__why-toggle"
				onClick={() => setIsOpen(!isOpen)}
				variant="link"
			>
				{__('Why?', 'elasticpress-labs')}
			</Button>
			<p className="ep-ai-chat-decline__why-text" hidden={!isOpen} id={`${id}-why`}>
				{text}
			</p>
		</div>
	);
};

/**
 * Clarify options: each one asks it as a new question. Options already asked say so; all wait
 * while a question runs.
 *
 * @param {object} props Component props.
 * @param {string[]} props.options Options.
 * @param {string[]} props.asked Questions asked after this card.
 * @param {boolean} props.isBusy Whether a question is running.
 * @param {Function} [props.onAsk] Ask handler, called with the option.
 * @returns {WPElement}
 */
const ClarifyOptions = ({ options, asked, isBusy, onAsk }) => (
	<div className="ep-ai-chat-clarify">
		<p className="ep-ai-chat-clarify__label">{__('Choose a direction', 'elasticpress-labs')}</p>
		<ul className="ep-ai-chat-clarify__options">
			{options.map((option) => {
				const wasAsked = asked.includes(option);

				return (
					<li key={option}>
						<Button
							className="ep-ai-chat-clarify__option"
							disabled={isBusy || typeof onAsk !== 'function'}
							onClick={() => onAsk(option)}
							variant="secondary"
							__next40pxDefaultSize
						>
							<span aria-hidden="true">→ </span>
							{option}
							{wasAsked ? (
								<span className="ep-ai-chat-clarify__asked">
									{__('Asked', 'elasticpress-labs')}
								</span>
							) : null}
						</Button>
					</li>
				);
			})}
		</ul>
	</div>
);

/**
 * A small button next to "Assistant" that shows how the answer was made.
 *
 * @param {object} props Component props.
 * @param {string} props.id Message id.
 * @param {boolean} props.isOpen Whether the details are shown.
 * @param {Function} props.onToggle Toggle handler.
 * @returns {WPElement}
 */
const DetailsToggle = ({ id, isOpen, onToggle }) => {
	const label = isOpen
		? __('Hide how this answer was made', 'elasticpress-labs')
		: __('Show how this answer was made', 'elasticpress-labs');

	return (
		<Button
			aria-controls={`${id}-details`}
			aria-expanded={isOpen}
			className="ep-ai-chat-message__search-toggle"
			icon={info}
			iconSize={16}
			label={label}
			onClick={onToggle}
			showTooltip
			size="small"
		/>
	);
};

/**
 * The date limit of a search, as words: "content published from 1990 on", "newer content
 * first", or both.
 *
 * @param {object} search From toSearch().
 * @param {string} search.after Earliest publish date (`Y-m-d`), or empty.
 * @param {boolean} search.recent Whether newer content ranks first.
 * @returns {string} Empty without dates.
 */
const describeDates = ({ after, recent }) => {
	// A year the question named is stored as its January 1.
	const from = after && after.endsWith('-01-01') ? after.slice(0, 4) : after;

	if (from && recent) {
		return sprintf(
			/* translators: %s: year or date */
			__('content published from %s on, newer first', 'elasticpress-labs'),
			from,
		);
	}

	if (from) {
		return sprintf(
			/* translators: %s: year or date */
			__('content published from %s on', 'elasticpress-labs'),
			from,
		);
	}

	return recent ? __('newer content first', 'elasticpress-labs') : '';
};

/**
 * Why the fallback search ran, in words.
 *
 * @param {string} fallback Fallback reason from the payload.
 * @returns {string}
 */
const describeFallback = (fallback) =>
	({
		generation_failed: __(
			'The AI-generated query could not be written, or it was not valid.',
			'elasticpress-labs',
		),
		es_error: __('Elasticsearch did not run the AI-generated query.', 'elasticpress-labs'),
		zero_hits: __('The AI-generated query found nothing.', 'elasticpress-labs'),
		unavailable: __('The chosen search method is not available.', 'elasticpress-labs'),
	})[fallback] || fallback;

/**
 * A search method by name, with its slug.
 *
 * @param {string} slug Search algorithm slug.
 * @returns {string}
 */
const algorithmName = (slug) =>
	searchAlgorithms[slug] && searchAlgorithms[slug] !== slug
		? `${searchAlgorithms[slug]} (${slug})`
		: slug;

/**
 * The technical side of how an answer was made: search, fallback, generated query, models,
 * question reading, and answer instructions. Collapsed by default, and only for users who can
 * manage chat bots (the server leaves these fields out for others).
 *
 * @param {object} props Component props.
 * @param {object} props.details From toDetails().
 * @returns {WPElement|null}
 */
const TechnicalDetails = ({ details }) => {
	const rows = [];
	const models = details.models || {};
	const modelLines = [
		[__('Answer', 'elasticpress-labs'), models.answer],
		[__('Claim checker', 'elasticpress-labs'), models.verifier],
		[__('Query', 'elasticpress-labs'), models.query],
	].filter(([, model]) => model);

	if (details.algorithm) {
		rows.push([__('Search algorithm', 'elasticpress-labs'), algorithmName(details.algorithm)]);
	}

	if (details.requestedAlgorithm) {
		rows.push([
			__('Requested', 'elasticpress-labs'),
			algorithmName(details.requestedAlgorithm),
		]);
	}

	if (details.fallback) {
		rows.push([
			__('Fallback reason', 'elasticpress-labs'),
			details.fallbackError
				? `${describeFallback(details.fallback)} (${details.fallbackError})`
				: describeFallback(details.fallback),
		]);
	}

	if (details.generatedQuery) {
		rows.push([
			__('Generated query', 'elasticpress-labs'),
			<pre className="ep-ai-chat-message__query" key="query">
				<code>{JSON.stringify(details.generatedQuery, null, 2)}</code>
			</pre>,
		]);
	}

	if (modelLines.length) {
		rows.push([
			__('Models', 'elasticpress-labs'),
			modelLines.map(([label, model]) => `${label}: ${model}`).join(' · '),
		]);
	}

	if (details.intent?.kind) {
		rows.push([
			__('Question reading', 'elasticpress-labs'),
			details.intent.fallback
				? __('Could not be read; the question was searched as asked.', 'elasticpress-labs')
				: [details.intent.kind, details.intent.model].filter(Boolean).join(' · '),
		]);
	}

	if (details.instructions?.source) {
		rows.push([
			__('Answer instructions', 'elasticpress-labs'),
			[details.instructions.source, details.instructions.hash].filter(Boolean).join(' · '),
		]);
	}

	if (!rows.length) {
		return null;
	}

	return (
		<details className="ep-ai-chat-message__technical">
			<summary>{__('Technical details', 'elasticpress-labs')}</summary>
			<dl>
				{rows.map(([label, value]) => (
					<div className="ep-ai-chat-message__technical-row" key={label}>
						<dt>{label}</dt>
						<dd>{value}</dd>
					</div>
				))}
			</dl>
		</details>
	);
};

/**
 * How an answer was made, under the reply's header: what it searched for (when the question was
 * rewritten) and how dates limited the search, the search method, then the technical details.
 *
 * @param {object} props Component props.
 * @param {string} props.id Message id.
 * @param {boolean} props.isOpen Whether it is shown.
 * @param {object} props.details From toDetails().
 * @returns {WPElement}
 */
const AnswerDetails = ({ id, isOpen, details }) => {
	const dates = describeDates(details);
	const hasPlan = details.query || dates;

	return (
		<div className="ep-ai-chat-message__details" hidden={!isOpen} id={`${id}-details`}>
			{hasPlan ? (
				<p className="ep-ai-chat-message__search">
					<span className="ep-ai-chat-message__search-label">
						{details.query
							? __('Searched for:', 'elasticpress-labs')
							: __('Search:', 'elasticpress-labs')}
					</span>{' '}
					{details.query ? <q>{details.query}</q> : null}
					{details.query && dates ? ' · ' : null}
					{dates ? (
						<span className="ep-ai-chat-message__search-dates">{dates}</span>
					) : null}
				</p>
			) : null}
			{details.algorithm ? (
				<p className="ep-ai-chat-message__search-method">
					<span className="ep-ai-chat-message__search-label">
						{__('Search method:', 'elasticpress-labs')}
					</span>{' '}
					{searchAlgorithms[details.algorithm] || details.algorithm}
					{details.requestedAlgorithm
						? ` ${sprintf(
								/* translators: %s: search method that could not be used */
								__('(instead of %s)', 'elasticpress-labs'),
								searchAlgorithms[details.requestedAlgorithm] ||
									details.requestedAlgorithm,
							)}`
						: null}
				</p>
			) : null}
			{technicalDetails ? <TechnicalDetails details={details} /> : null}
		</div>
	);
};

/**
 * Whether an answer was found by the fallback search because an AI search method could not be
 * used: the reader is told the answer may be less precise.
 *
 * @param {object} message Thread message.
 * @returns {boolean}
 */
const usedFallbackSearch = (message) =>
	aiMethods.includes(message.details?.requestedAlgorithm) &&
	Boolean(message.details?.fallback) &&
	message.details?.algorithm !== 'none';

/**
 * Title shown at the top of a refused or failed reply, inside its bubble.
 *
 * @param {object} message Thread message.
 * @returns {string} Empty for other replies.
 */
const statusTitle = (message) => {
	if (message.status === 'refused') {
		return __('Could not answer', 'elasticpress-labs');
	}

	if (message.status === 'error') {
		return __('Something went wrong', 'elasticpress-labs');
	}

	return '';
};

/**
 * User and assistant messages.
 *
 * @param {object} props Component props.
 * @param {object|null} props.activeMarker Selected marker: `messageId`, `k`.
 * @param {Array} props.messages Thread messages.
 * @param {Function} props.onCiteClick Marker click handler: `(messageId, entry)`.
 * @param {Function} [props.onRetry] Retry handler for timeout/error cards.
 * @param {object|null} [props.highlightedSource] Hovered source: `messageId`, `n`.
 * @param {boolean} [props.isBusy] Whether a question is running.
 * @param {Function} [props.onAsk] Ask handler for clarify options, called with the question.
 * @returns {WPElement}
 */
export default ({
	activeMarker,
	messages,
	onCiteClick,
	onRetry,
	highlightedSource = null,
	isBusy = false,
	onAsk,
}) => {
	// Answers whose details are shown.
	const [openDetails, setOpenDetails] = useState(() => new Set());

	const toggleDetails = (id) =>
		setOpenDetails((previous) => {
			const next = new Set(previous);

			if (next.has(id)) {
				next.delete(id);
			} else {
				next.add(id);
			}

			return next;
		});

	return (
		<ol className="ep-ai-chat-thread">
			{messages.map((message, index) => {
				const isUser = message.role === 'user';
				const statusClass = message.status ? ` ep-ai-chat-message--${message.status}` : '';
				const canCite =
					!isUser && message.status !== 'loading' && !hasNoSources(message.status);

				const title = isUser ? '' : statusTitle(message);
				const details =
					!isUser && message.status !== 'loading' && message.status !== 'error'
						? message.details || null
						: null;
				const isDetailsOpen = Boolean(details) && openDetails.has(message.id);
				let body = message.content;

				if (message.status === 'loading') {
					body = (
						<>
							<span className="ep-ai-chat-message__progress">
								{message.progress || __('Thinking…', 'elasticpress-labs')}
							</span>
							{message.slow && (
								<span className="ep-ai-chat-message__slow">
									{__(
										'This is taking longer than expected. You can keep waiting or retry.',
										'elasticpress-labs',
									)}
								</span>
							)}
						</>
					);
				} else if (message.status === 'clarify') {
					body = (
						<>
							<span className="ep-ai-chat-message__text">{message.content}</span>
							<ClarifyOptions
								asked={messages
									.slice(index + 1)
									.filter((later) => later.role === 'user')
									.map((later) => later.content)}
								isBusy={isBusy}
								onAsk={onAsk}
								options={message.clarifyOptions || []}
							/>
						</>
					);
				} else if (title) {
					const refusal = message.status === 'refused' ? message.refusal : null;

					body = (
						<>
							<span className="ep-ai-chat-message__status-row">
								<strong className="ep-ai-chat-message__status">{title}</strong>
								{refusal?.label ? (
									<span className="ep-ai-chat-decline__reason">
										{refusal.label}
									</span>
								) : null}
							</span>
							<span className="ep-ai-chat-message__text">{message.content}</span>
							{refusal?.next_step ? (
								<span className="ep-ai-chat-decline__next">
									{refusal.next_step}
								</span>
							) : null}
							{refusal?.description ? (
								<DeclineWhy id={message.id} text={refusal.description} />
							) : null}
							{message.status === 'refused' && message.related?.length > 0 && (
								<RelatedResearch id={message.id} items={message.related} />
							)}
						</>
					);
				} else if (canCite && message.content) {
					body = (
						<Markdown
							content={message.content}
							renderText={citationText(
								message,
								activeMarker,
								onCiteClick,
								highlightedSource,
							)}
						/>
					);
				}

				return (
					<li
						className={`ep-ai-chat-message ep-ai-chat-message--${message.role}${statusClass}`}
						key={message.id}
					>
						<div className="ep-ai-chat-message__header">
							<p className="ep-ai-chat-message__role">
								{isUser
									? __('You', 'elasticpress-labs')
									: __('Assistant', 'elasticpress-labs')}
							</p>
							{details ? (
								<DetailsToggle
									id={message.id}
									isOpen={isDetailsOpen}
									onToggle={() => toggleDetails(message.id)}
								/>
							) : null}
						</div>
						{details ? (
							<AnswerDetails
								details={details}
								id={message.id}
								isOpen={isDetailsOpen}
							/>
						) : null}
						<div className="ep-ai-chat-message__content">{body}</div>
						{details && usedFallbackSearch(message) ? (
							<p className="ep-ai-chat-message__fallback">
								{__(
									'We used a simpler search for this question, so the answer may be less precise.',
									'elasticpress-labs',
								)}
							</p>
						) : null}
						{(message.status === 'error' ||
							(message.status === 'loading' && message.slow)) &&
							typeof onRetry === 'function' && (
								<Button
									className="ep-ai-chat-message__retry"
									onClick={onRetry}
									variant="secondary"
									__next40pxDefaultSize
								>
									{__('Retry', 'elasticpress-labs')}
								</Button>
							)}
					</li>
				);
			})}
		</ol>
	);
};
