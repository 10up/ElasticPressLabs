/**
 * WordPress dependencies.
 */
import { Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState, WPElement } from '@wordpress/element';
import { chevronDown, chevronUp } from '@wordpress/icons';

/**
 * A titled, collapsible box that groups a bot's fields. The box is a group named by its title,
 * and the title is the toggle. A badge says something about the box while it is closed ("2
 * changes").
 *
 * @param {object} props Component props.
 * @param {string} props.title Box title.
 * @param {string} [props.badge] Short status on the header.
 * @param {boolean} [props.initialOpen] Whether the box starts open. Default false.
 * @param {WPElement} props.children Fields.
 * @returns {WPElement}
 */
export default ({ title, badge = '', initialOpen = false, children }) => {
	const [isOpen, setIsOpen] = useState(initialOpen);
	const id = useInstanceId(Button, 'ep-chat-bot-box');

	return (
		<div aria-labelledby={`${id}-name`} className="ep-chat-bot-box" role="group">
			<h3 className="ep-chat-bot-box__title">
				<Button
					aria-controls={isOpen ? `${id}-body` : undefined}
					aria-expanded={isOpen}
					className="ep-chat-bot-box__toggle"
					icon={isOpen ? chevronUp : chevronDown}
					iconPosition="right"
					id={`${id}-title`}
					onClick={() => setIsOpen(!isOpen)}
				>
					<span className="ep-chat-bot-box__name" id={`${id}-name`}>
						{title}
					</span>
					{badge ? <span className="ep-chat-bot-box__badge">{badge}</span> : null}
				</Button>
			</h3>
			{isOpen ? (
				<div className="ep-chat-bot-box__body" id={`${id}-body`}>
					{children}
				</div>
			) : null}
		</div>
	);
};
