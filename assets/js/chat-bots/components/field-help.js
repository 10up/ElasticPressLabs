/**
 * WordPress dependencies.
 */
import { WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * One line of help, then the longer details behind "More".
 *
 * @param {object} props Component props.
 * @param {string} props.help Short help.
 * @param {string} [props.details] Details (may be empty).
 * @param {string} [props.id] ID, for aria-describedby.
 * @returns {WPElement|null}
 */
export default ({ help, details = '', id }) => {
	if (!help && !details) {
		return null;
	}

	return (
		<div className="ep-chat-bot-help" id={id}>
			{help}
			{details ? (
				<>
					{' '}
					<details className="ep-chat-help-more">
						<summary>{__('More', 'elasticpress-labs')}</summary>
						{details}
					</details>
				</>
			) : null}
		</div>
	);
};
