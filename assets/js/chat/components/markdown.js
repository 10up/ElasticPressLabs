/* eslint-disable react/no-array-index-key -- Parsed Markdown is static and never reordered, so a token's position is a stable key. */

/**
 * External dependencies.
 */
import { Lexer } from 'marked';

/**
 * WordPress dependencies.
 */
import { useMemo, WPElement } from '@wordpress/element';

/**
 * Link protocols an answer may use. Anything else renders as plain text.
 */
const SAFE_URL = /^(https?:|mailto:)/i;

/**
 * Read-only task list checkbox.
 *
 * @param {object} props Component props.
 * @param {boolean} props.checked Whether the task is done.
 * @returns {WPElement}
 */
const Checkbox = ({ checked }) => (
	<input
		checked={!!checked}
		className="ep-ai-chat-markdown__checkbox"
		disabled
		readOnly
		type="checkbox"
	/>
);

/**
 * Render inline tokens (emphasis, links, code, text).
 *
 * @param {Array} tokens Inline tokens from `marked`.
 * @param {Function} renderText Renders a text leaf, e.g. to add citation buttons.
 * @param {string} key Key prefix.
 * @returns {Array}
 */
const renderInline = (tokens, renderText, key) =>
	(tokens || []).map((token, index) => {
		const id = `${key}-${index}`;

		switch (token.type) {
			case 'strong':
				return <strong key={id}>{renderInline(token.tokens, renderText, id)}</strong>;
			case 'em':
				return <em key={id}>{renderInline(token.tokens, renderText, id)}</em>;
			case 'del':
				return <del key={id}>{renderInline(token.tokens, renderText, id)}</del>;
			case 'codespan':
				return <code key={id}>{token.text}</code>;
			case 'br':
				return <br key={id} />;
			case 'checkbox':
				return <Checkbox checked={token.checked} key={id} />;
			case 'link':
				if (!SAFE_URL.test(token.href || '')) {
					return renderInline(token.tokens, renderText, id);
				}

				return (
					<a href={token.href} key={id} rel="noreferrer noopener" target="_blank">
						{renderInline(token.tokens, renderText, id)}
					</a>
				);
			case 'text':
				return token.tokens
					? renderInline(token.tokens, renderText, id)
					: renderText(token.text, id);
			default:
				// `escape`, `html`, `image`, and anything new: show the raw text, never markup.
				return renderText(token.text ?? token.raw ?? '', id);
		}
	});

/**
 * Render block tokens (paragraphs, lists, headings, code blocks).
 *
 * @param {Array} tokens Block tokens from `marked`.
 * @param {Function} renderText Renders a text leaf.
 * @param {string} key Key prefix.
 * @returns {Array}
 */
const renderBlocks = (tokens, renderText, key) =>
	(tokens || []).map((token, index) => {
		const id = `${key}-${index}`;

		switch (token.type) {
			case 'space':
				return null;
			case 'paragraph':
				return <p key={id}>{renderInline(token.tokens, renderText, id)}</p>;
			case 'heading': {
				// Answers sit inside a card, so start below the screen's own headings.
				const Tag = `h${Math.min(6, token.depth + 2)}`;

				return <Tag key={id}>{renderInline(token.tokens, renderText, id)}</Tag>;
			}
			case 'list': {
				const Tag = token.ordered ? 'ol' : 'ul';

				return (
					<Tag
						key={id}
						start={token.ordered && token.start !== 1 ? token.start : undefined}
					>
						{token.items.map((item, itemIndex) => (
							<li key={`${id}-${itemIndex}`}>
								{renderBlocks(item.tokens, renderText, `${id}-${itemIndex}`)}
							</li>
						))}
					</Tag>
				);
			}
			case 'blockquote':
				return (
					<blockquote key={id}>{renderBlocks(token.tokens, renderText, id)}</blockquote>
				);
			case 'code':
				return (
					<pre key={id}>
						<code>{token.text}</code>
					</pre>
				);
			case 'hr':
				return <hr key={id} />;
			case 'checkbox':
				// Task list item: `- [ ] …` / `- [x] …`.
				return <Checkbox checked={token.checked} key={id} />;
			case 'table':
				return (
					<table key={id}>
						<thead>
							<tr>
								{token.header.map((cell, cellIndex) => (
									<th key={`${id}-h${cellIndex}`}>
										{renderInline(
											cell.tokens,
											renderText,
											`${id}-h${cellIndex}`,
										)}
									</th>
								))}
							</tr>
						</thead>
						<tbody>
							{token.rows.map((row, rowIndex) => (
								<tr key={`${id}-r${rowIndex}`}>
									{row.map((cell, cellIndex) => (
										<td key={`${id}-r${rowIndex}-${cellIndex}`}>
											{renderInline(
												cell.tokens,
												renderText,
												`${id}-r${rowIndex}-${cellIndex}`,
											)}
										</td>
									))}
								</tr>
							))}
						</tbody>
					</table>
				);
			case 'text':
				// Tight list items hold `text` tokens directly.
				return token.tokens
					? renderInline(token.tokens, renderText, id)
					: renderText(token.text, id);
			default:
				return <p key={id}>{renderText(token.raw || '', id)}</p>;
		}
	});

/**
 * Answer text as Markdown, rendered to React elements. No HTML string is
 * ever injected: raw HTML in the answer shows as text.
 *
 * @param {object} props Component props.
 * @param {string} props.content Markdown text.
 * @param {Function} [props.renderText] Renders a text leaf. Defaults to the text itself.
 * @returns {WPElement}
 */
export default ({ content, renderText = (text) => text }) => {
	// Parse only when the text changes: the thread re-renders on every poll.
	const tokens = useMemo(() => Lexer.lex(content || '', { gfm: true, breaks: true }), [content]);

	return <div className="ep-ai-chat-markdown">{renderBlocks(tokens, renderText, 'md')}</div>;
};
