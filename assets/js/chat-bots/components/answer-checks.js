/**
 * WordPress dependencies.
 */
import { Icon, ToggleControl } from '@wordpress/components';
import { WPElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { lock } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { gateStages } from '../config';
import FieldBox from './field-box';

/**
 * The checks (gates) of a bot, by step in the order they run. An optional check is a switch; a
 * required one is locked on, with why.
 *
 * @param {object} props Component props.
 * @param {Array} props.gates Gate rows.
 * @param {Array} props.optionalGates Optional gates on.
 * @param {Function} props.setOptionalGates Change handler.
 * @returns {WPElement|null}
 */
export default ({ gates, optionalGates, setOptionalGates }) => {
	if (!gates.length) {
		return null;
	}

	const enabled = Array.isArray(optionalGates) ? optionalGates : [];
	const optional = gates.filter((row) => !row.required);
	const on = optional.filter((row) => enabled.includes(row.slug)).length;

	// Steps in the order they run, then any step without a heading.
	const stages = [
		...Object.keys(gateStages),
		...gates.map((row) => row.stage).filter((stage) => !(stage in gateStages)),
	].filter(
		(stage, index, all) =>
			all.indexOf(stage) === index && gates.some((row) => row.stage === stage),
	);

	return (
		<FieldBox
			badge={sprintf(
				/* translators: 1: optional checks on, 2: optional checks */
				__('%1$d of %2$d optional on', 'elasticpress-labs'),
				on,
				optional.length,
			)}
			title={__('Answer checks', 'elasticpress-labs')}
		>
			<p className="description">
				{__(
					'Each check runs at one step. A check that finds a problem declines the question with a reason, instead of showing an answer that may be wrong.',
					'elasticpress-labs',
				)}
			</p>
			{stages.map((stage, index) => (
				<div className="ep-chat-bot-checks__stage" key={stage}>
					<h4 className="ep-chat-bot-checks__heading">
						{sprintf(
							/* translators: 1: step number, 2: step name */
							__('%1$d. %2$s', 'elasticpress-labs'),
							index + 1,
							gateStages[stage] ||
								gates.find((row) => row.stage === stage)?.stage_label ||
								stage,
						)}
					</h4>
					{gates
						.filter((row) => row.stage === stage)
						.map((row) =>
							row.required ? (
								<div className="ep-chat-bot-check is-required" key={row.slug}>
									<div className="ep-chat-bot-check__title">
										<Icon icon={lock} size={20} />
										<span>{row.label || row.slug}</span>
									</div>
									<p className="ep-chat-bot-check__description">
										{row.description}{' '}
										<strong>
											{sprintf(
												/* translators: %s: why the check cannot be turned off */
												__('Always on: %s', 'elasticpress-labs'),
												row.reason ||
													__(
														'Required by this site.',
														'elasticpress-labs',
													),
											)}
										</strong>
									</p>
								</div>
							) : (
								<ToggleControl
									checked={enabled.includes(row.slug)}
									help={row.description}
									key={row.slug}
									label={row.label || row.slug}
									onChange={(next) =>
										setOptionalGates(
											next
												? [...enabled, row.slug]
												: enabled.filter((slug) => slug !== row.slug),
										)
									}
									__nextHasNoMarginBottom
								/>
							),
						)}
				</div>
			))}
		</FieldBox>
	);
};
