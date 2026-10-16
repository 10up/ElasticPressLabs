/**
 * WordPress dependencies.
 */
import { RadioControl } from '@wordpress/components';
import { useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { executionModes } from '../config';

/**
 * How answers arrive: while the person waits (sync), or in the background, with the runner.
 *
 * @param {object} props Component props.
 * @param {string} props.executionMode Execution mode slug.
 * @param {Function} props.setExecutionMode Change handler.
 * @returns {WPElement}
 */
export default ({ executionMode, setExecutionMode }) => {
	const runners = executionModes.filter((mode) => mode.value !== 'sync');
	const hasSync = executionModes.some((mode) => mode.value === 'sync');
	const isBackground = executionMode !== 'sync' || !hasSync;

	// Switching to "while the person waits" and back keeps the runner picked before.
	const [lastRunner, setLastRunner] = useState(
		executionMode !== 'sync' ? executionMode : runners[0]?.value,
	);

	const arrival = [];

	if (hasSync) {
		arrival.push({
			value: 'sync',
			label: __('While the person waits', 'elasticpress-labs'),
			description: __('The fastest for most models.', 'elasticpress-labs'),
		});
	}

	if (runners.length) {
		arrival.push({
			value: 'background',
			label: __('In the background', 'elasticpress-labs'),
			description: __(
				'For slow models or long answers. The answer shows up when it is ready.',
				'elasticpress-labs',
			),
		});
	}

	return (
		<>
			<RadioControl
				className="ep-chat-bot-arrival"
				label={__('Answers arrive', 'elasticpress-labs')}
				onChange={(next) =>
					setExecutionMode(next === 'sync' ? 'sync' : lastRunner || runners[0]?.value)
				}
				options={arrival}
				selected={isBackground ? 'background' : 'sync'}
			/>
			{isBackground && runners.length ? (
				<RadioControl
					className="ep-chat-bot-runner"
					label={__('Background runner', 'elasticpress-labs')}
					onChange={(next) => {
						setLastRunner(next);
						setExecutionMode(next);
					}}
					options={runners.map((mode) => ({
						value: mode.value,
						label: mode.label,
						description: mode.description || undefined,
					}))}
					selected={
						runners.some((mode) => mode.value === executionMode)
							? executionMode
							: runners[0].value
					}
				/>
			) : null}
		</>
	);
};
