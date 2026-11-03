/**
 * WordPress dependencies.
 */
import { WPElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * One `[[k]]` marker of an answer.
 *
 * With provenance, a button labelled with its source's number (see `getSourceOrder`);
 * selecting it shows the marker's quote in that source's card. Without provenance (answers
 * stored before citations), plain text: never a guessed source.
 *
 * @param {object} props Component props.
 * @param {number} props.k Marker number in the answer text.
 * @param {object|undefined} props.entry Provenance entry for the marker.
 * @param {number} props.label Display number of the marker's source.
 * @param {boolean} props.isActive Whether the marker is selected.
 * @param {boolean} [props.isHighlighted] Whether its source card is hovered or focused.
 * @param {Function} props.onSelect Called with the entry when the marker is selected.
 * @returns {WPElement}
 */
export default ({ k, entry, label, isActive, isHighlighted = false, onSelect }) => {
	if (!entry) {
		return (
			<span
				aria-label={sprintf(
					/* translators: %d: citation number */
					__('Citation %d: no source for this marker', 'elasticpress-labs'),
					k,
				)}
				className="ep-ai-chat-citation-ref is-dead"
				role="note"
				title={__('No source for this marker', 'elasticpress-labs')}
			>
				{k}
			</span>
		);
	}

	const source = Number(label) || Number(entry.n);

	return (
		<button
			aria-label={
				entry.figure
					? sprintf(
							/* translators: 1: figure, 2: source number */
							__('%1$s, source %2$d', 'elasticpress-labs'),
							entry.figure,
							source,
						)
					: sprintf(
							/* translators: %d: source number */
							__('Source %d', 'elasticpress-labs'),
							source,
						)
			}
			aria-pressed={isActive}
			className={`ep-ai-chat-citation-ref${isActive ? ' is-active' : ''}${
				isHighlighted ? ' is-highlighted' : ''
			}`}
			data-marker={k}
			onClick={() => onSelect(entry)}
			type="button"
		>
			{source}
		</button>
	);
};
