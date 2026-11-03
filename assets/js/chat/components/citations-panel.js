/**
 * WordPress dependencies.
 */
import { Button } from '@wordpress/components';
import { useEffect, useRef, useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close, copy } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { copyText } from '../copy-citations';
import ProvenanceQuote from './provenance-quote';

/**
 * One note chip: a button that opens the note's text, when it has one. The site supplies notes
 * (`ep_chat_source_notes`); each has a `label`, a `description` (hover and screen readers), an
 * optional `text`, and a `tone`.
 *
 * @param {object} props Component props.
 * @param {object} props.note Note.
 * @param {string} props.id Unique ID for the note text.
 * @returns {WPElement}
 */
const SourceNote = ({ note, id }) => {
	const [isOpen, setIsOpen] = useState(false);
	const tone = note.tone === 'warning' ? 'warning' : 'info';
	const chip = (
		<>
			{tone === 'warning' ? <span aria-hidden="true">⚠ </span> : null}
			{note.label}
		</>
	);

	return (
		<span className={`ep-ai-chat-citations__note is-${tone}`}>
			{note.text ? (
				<button
					aria-controls={isOpen ? `${id}-text` : undefined}
					aria-describedby={note.description ? `${id}-description` : undefined}
					aria-expanded={isOpen}
					className={`ep-ai-chat-citations__note-chip is-${tone}`}
					onClick={() => setIsOpen(!isOpen)}
					title={note.description || undefined}
					type="button"
				>
					{chip}
				</button>
			) : (
				<span
					className={`ep-ai-chat-citations__note-chip is-${tone}`}
					title={note.description || undefined}
				>
					{chip}
					{note.description ? (
						<span className="screen-reader-text">{` ${note.description}`}</span>
					) : null}
				</span>
			)}
			{note.text && note.description ? (
				<span className="screen-reader-text" id={`${id}-description`}>
					{note.description}
				</span>
			) : null}
			{note.text && isOpen ? (
				<span className="ep-ai-chat-citations__note-text" id={`${id}-text`}>
					{note.text}
				</span>
			) : null}
		</span>
	);
};

/**
 * One source card. The title is a link: to the source, or, with a selected marker, to the quoted
 * text in it (a text fragment, where the browser supports it). The rest of the card is not a
 * link, so its notes can open in place. The selected card shows the marker's quote and a button
 * that clears the selection.
 *
 * @param {object} props Component props.
 * @param {object} props.citation Source: `number`, `title`, `url`, `excerpt`, `notes`.
 * @param {number|undefined} props.label Display number (cited sources only).
 * @param {boolean} props.isActive Whether the source is highlighted.
 * @param {object|undefined} props.provenance Provenance entry of the selected marker.
 * @param {Function} props.onClear Clear the selection.
 * @param {Function} [props.onHover] Called with the passage number while the card is hovered
 * or focused, and with null after. Cited sources only.
 * @returns {WPElement}
 */
const SourceCard = ({ citation, label, isActive, provenance, onClear, onHover }) => {
	const verifyUrl = String(provenance?.verify_url || '');
	const opensAtQuote = verifyUrl.includes('#:~:text=');
	const href = opensAtQuote ? verifyUrl : citation.url;
	const notes = Array.isArray(citation.notes) ? citation.notes : [];
	const hover = label && onHover ? onHover : null;

	return (
		<li
			className={`ep-ai-chat-citations__item${isActive ? ' is-active' : ''}${
				label ? '' : ' is-uncited'
			}`}
			data-citation-id={citation.number}
			onBlur={hover ? () => hover(null) : undefined}
			onFocus={hover ? () => hover(citation.number) : undefined}
			onMouseEnter={hover ? () => hover(citation.number) : undefined}
			onMouseLeave={hover ? () => hover(null) : undefined}
		>
			<div className="ep-ai-chat-citations__card">
				{label ? <span className="ep-ai-chat-citations__index">{label}</span> : null}
				<span className="ep-ai-chat-citations__body">
					<a
						className="ep-ai-chat-citations__open"
						href={href}
						rel="noopener noreferrer"
						target="_blank"
					>
						<strong>{citation.title}</strong>
						<span aria-hidden="true" className="ep-ai-chat-citations__arrow">
							{' ↗'}
						</span>
						<span className="screen-reader-text">
							{opensAtQuote
								? __(
										'(opens the source at the quote, in a new tab)',
										'elasticpress-labs',
									)
								: __('(opens in a new tab)', 'elasticpress-labs')}
						</span>
					</a>
					{provenance?.date ? (
						<span className="ep-ai-chat-citations__date">{provenance.date}</span>
					) : null}
					{notes.length > 0 ? (
						<span className="ep-ai-chat-citations__notes">
							{notes.map((note, index) => (
								<SourceNote
									id={`ep-ai-chat-source-${citation.number}-note-${index}`}
									// eslint-disable-next-line react/no-array-index-key -- Notes have no ID and do not reorder.
									key={index}
									note={note}
								/>
							))}
						</span>
					) : null}
					{provenance?.quote ? (
						<span className="ep-ai-chat-citations__quote">
							<ProvenanceQuote entry={provenance} />
						</span>
					) : (
						<span className="ep-ai-chat-citations__excerpt">{citation.excerpt}</span>
					)}
					{opensAtQuote ? (
						// The same link as the title, for the mouse; keyboard users have the title.
						<a
							aria-hidden="true"
							className="ep-ai-chat-citations__hint"
							href={href}
							rel="noopener noreferrer"
							tabIndex={-1}
							target="_blank"
						>
							{__('Opens the source at this quote ↗', 'elasticpress-labs')}
						</a>
					) : null}
				</span>
			</div>
			{isActive ? (
				<Button
					className="ep-ai-chat-citations__clear"
					icon={close}
					label={__('Clear selection', 'elasticpress-labs')}
					onClick={onClear}
					size="small"
				/>
			) : null}
		</li>
	);
};

/**
 * Sources rail for the active assistant answer.
 *
 * Sources the answer cites come first, numbered in the order the answer first cites them (see
 * `getSourceOrder`); the others are listed under "Also searched". Answers without provenance
 * (stored before citations) list their sources by passage number.
 *
 * @param {object} props Component props.
 * @param {number|null} props.activeCitation Highlighted source (passage number).
 * @param {object|undefined} props.activeProvenance Provenance entry of the selected marker.
 * @param {Array} props.citations Sources, numbered from 1 (see `toCitations`).
 * @param {Function} props.onClear Clear the selected marker and source.
 * @param {Function} [props.onHover] Called with the hovered or focused cited source
 * (passage number), or null.
 * @param {Map<number, number>} props.order Display number by passage number, cited sources only.
 * @returns {WPElement}
 */
export default ({ activeCitation, activeProvenance, citations, onClear, onHover, order }) => {
	const listRef = useRef(null);
	const [copyState, setCopyState] = useState('');
	const copyTimer = useRef(0);

	useEffect(() => () => window.clearTimeout(copyTimer.current), []);
	const hasOrder = order instanceof Map && order.size > 0;
	const cited = hasOrder
		? citations
				.filter((citation) => order.has(citation.number))
				.sort((a, b) => order.get(a.number) - order.get(b.number))
		: citations;
	const uncited = hasOrder ? citations.filter((citation) => !order.has(citation.number)) : [];

	useEffect(() => {
		if (!activeCitation || !listRef.current) {
			return;
		}

		const activeItem = listRef.current.querySelector(`[data-citation-id="${activeCitation}"]`);

		activeItem?.scrollIntoView({ block: 'nearest' });
	}, [activeCitation, activeProvenance]);

	const renderCard = (citation, label) => {
		const isActive = activeCitation === citation.number;

		return (
			<SourceCard
				citation={citation}
				isActive={isActive}
				key={citation.number}
				label={label}
				onClear={onClear}
				onHover={onHover}
				provenance={
					isActive && Number(activeProvenance?.n) === citation.number
						? activeProvenance
						: undefined
				}
			/>
		);
	};

	// The server's reference line of each cited source (`ep_chat_source_citation`), in order.
	const references = cited.map((citation) => citation.citation).filter(Boolean);

	const copyCitations = async () => {
		try {
			await copyText(references.join('\n'));
			setCopyState('copied');
		} catch (e) {
			setCopyState('failed');
		}

		window.clearTimeout(copyTimer.current);
		copyTimer.current = window.setTimeout(() => setCopyState(''), 2500);
	};

	let copyLabel = __('Copy citations', 'elasticpress-labs');

	if (copyState === 'copied') {
		copyLabel = __('Copied', 'elasticpress-labs');
	} else if (copyState === 'failed') {
		copyLabel = __('Could not copy', 'elasticpress-labs');
	}

	return (
		<aside
			aria-labelledby="ep-ai-chat-sources-title"
			className="ep-ai-chat-panel ep-ai-chat-citations"
		>
			<div className="ep-ai-chat-panel__header">
				<h2 className="ep-ai-chat-panel__title" id="ep-ai-chat-sources-title">
					{__('Sources', 'elasticpress-labs')}
				</h2>
				{hasOrder && references.length > 0 ? (
					<Button
						className="ep-ai-chat-citations__copy"
						icon={copy}
						onClick={copyCitations}
						size="compact"
						variant="tertiary"
					>
						{copyLabel}
					</Button>
				) : null}
				<span aria-live="polite" className="screen-reader-text">
					{copyState ? copyLabel : ''}
				</span>
			</div>
			<div className="ep-ai-chat-panel__body" ref={listRef}>
				{cited.length > 0 && (
					<ol className="ep-ai-chat-citations__list">
						{cited.map((citation) =>
							renderCard(
								citation,
								hasOrder ? order.get(citation.number) : citation.number,
							),
						)}
					</ol>
				)}
				{uncited.length > 0 && (
					<>
						<h3 className="ep-ai-chat-citations__group" id="ep-ai-chat-also-searched">
							{__('Also searched', 'elasticpress-labs')}
						</h3>
						<ul
							aria-labelledby="ep-ai-chat-also-searched"
							className="ep-ai-chat-citations__list"
						>
							{uncited.map((citation) => renderCard(citation, undefined))}
						</ul>
					</>
				)}
			</div>
		</aside>
	);
};
