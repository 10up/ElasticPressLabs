/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button } from '@wordpress/components';
import { useEffect, useState, WPElement } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Styles.
 */
import './style.css';

/**
 * Window dependencies.
 */
const { restApiEndpoint, fieldType, fields = {} } = window.epLabsConnectionTest || {};

/**
 * "Test connection" on a feature's settings: sends the form's connection fields, saved or not,
 * and shows whether one small request worked, or the service's error. A result is cleared when
 * one of those fields changes, so it never describes other values.
 *
 * @param {object} props Props from the ElasticPress settings control.
 * @param {boolean} props.disabled Whether the field is disabled.
 * @param {WPElement} props.help Help text.
 * @param {string} props.label Button text.
 * @param {string} props.name Setting key of the button.
 * @param {object} props.values The feature's current setting values.
 * @returns {WPElement}
 */
const ConnectionTestControl = ({ disabled, help, label, name, values = {} }) => {
	const config = fields[name] || { feature: '', fields: [] };
	const settings = Object.fromEntries(config.fields.map((key) => [key, values[key] ?? '']));
	const signature = JSON.stringify(settings);

	const [isBusy, setIsBusy] = useState(false);
	const [result, setResult] = useState(null);

	useEffect(() => setResult(null), [signature]);

	const onClick = async () => {
		setIsBusy(true);
		setResult(null);

		try {
			const response = await apiFetch({
				path: restApiEndpoint,
				method: 'POST',
				data: { feature: config.feature, settings },
			});

			setResult({ success: Boolean(response.success), message: response.message });
		} catch (e) {
			setResult({
				success: false,
				message: e.message || __('The test could not be run.', 'elasticpress-labs'),
			});
		} finally {
			setIsBusy(false);
		}
	};

	let resultClass = '';

	if (result) {
		resultClass = result.success ? ' is-success' : ' is-error';
	}

	return (
		<BaseControl className="ep-labs-connection-test" help={help} __nextHasNoMarginBottom>
			<div className="ep-labs-connection-test__row">
				<Button
					disabled={disabled || isBusy}
					isBusy={isBusy}
					onClick={onClick}
					variant="secondary"
				>
					{label}
				</Button>
				<span className={`ep-labs-connection-test__result${resultClass}`} role="status">
					{isBusy ? __('Testing…', 'elasticpress-labs') : result?.message}
				</span>
			</div>
		</BaseControl>
	);
};

addFilter('ep.Features.Control', 'elasticpress-labs/connection-test', (component, type) =>
	type === fieldType ? ConnectionTestControl : component,
);
