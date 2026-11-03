/**
 * WordPress dependencies.
 */
import { WPElement } from '@wordpress/element';

/**
 * Quote with its figures in `<mark>`.
 *
 * @param {string} quote Source quote.
 * @param {Array} marks `[start, end]` ranges of the figures, in characters (code points).
 * @returns {Array}
 */
const highlight = (quote, marks) => {
	const chars = Array.from(String(quote));
	const ranges = (Array.isArray(marks) ? marks : [])
		.filter((range) => Array.isArray(range) && range[1] > range[0])
		.map(([start, end]) => [Number(start), Number(end)])
		.sort((a, b) => a[0] - b[0]);
	const parts = [];
	let cursor = 0;

	ranges.forEach(([start, end]) => {
		if (start < cursor || end > chars.length) {
			return;
		}

		parts.push(chars.slice(cursor, start).join(''));
		parts.push(<mark key={start}>{chars.slice(start, end).join('')}</mark>);
		cursor = end;
	});

	parts.push(chars.slice(cursor).join(''));

	return parts;
};

/**
 * A table quote (header row, then rows) as a table, with marked figures in `<mark>`.
 *
 * @param {object} props Component props.
 * @param {string} props.quote Source quote: Markdown table lines.
 * @param {Array} props.marks `[start, end]` ranges of the figures, in characters (code points).
 * @returns {WPElement}
 */
const QuoteTable = ({ quote, marks }) => {
	const ranges = Array.isArray(marks) ? marks : [];
	let offset = 0;

	// A table figure is a whole cell: a cell holding a mark range is marked.
	const rows = String(quote)
		.split('\n')
		.map((line) => {
			const chars = Array.from(line);
			const cells = [];
			let start = null;

			chars.forEach((char, index) => {
				if (char !== '|') {
					return;
				}

				if (start !== null) {
					const text = chars
						.slice(start + 1, index)
						.join('')
						.trim();
					const isMarked = ranges.some(
						([from, to]) => from > offset + start && to <= offset + index,
					);

					cells.push(isMarked ? <mark>{text}</mark> : text);
				}

				start = index;
			});

			offset += chars.length + 1;

			return cells;
		});

	const [header, ...body] = rows;

	return (
		<table className="ep-ai-chat-provenance__table">
			<thead>
				<tr>
					{(header || []).map((cell, index) => (
						// eslint-disable-next-line react/no-array-index-key -- Cells are static, so their position is a stable key.
						<th key={index}>{cell}</th>
					))}
				</tr>
			</thead>
			<tbody>
				{body.map((cells, row) => (
					// eslint-disable-next-line react/no-array-index-key -- Rows are static, so their position is a stable key.
					<tr key={row}>
						{cells.map((cell, index) => (
							// eslint-disable-next-line react/no-array-index-key -- Cells are static, so their position is a stable key.
							<td key={index}>{cell}</td>
						))}
					</tr>
				))}
			</tbody>
		</table>
	);
};

/**
 * Source quote of one citation marker, with its figures in `<mark>`. A table quote (header row,
 * then rows) renders as a table.
 *
 * @param {object} props Component props.
 * @param {object} props.entry Provenance entry: `quote`, `marks`.
 * @returns {WPElement|null}
 */
export default ({ entry }) => {
	const quote = String(entry?.quote || '');

	if (!quote) {
		return null;
	}

	if (quote.startsWith('|')) {
		return <QuoteTable marks={entry.marks} quote={quote} />;
	}

	return (
		<blockquote className="ep-ai-chat-provenance__quote">
			{highlight(quote, entry.marks)}
		</blockquote>
	);
};
