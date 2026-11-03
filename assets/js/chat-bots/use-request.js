/**
 * WordPress Dependencies.
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { useSettingsScreen } from '../settings-screen';

/**
 * A request that shows a notice when it ends: its own error message, or the failure copy.
 * `run( promise, { success, failure, onSuccess } )` takes the notice copy on success (or a
 * function of the result; none shows no notice), and calls `onSuccess` with the result.
 *
 * @returns {{isBusy: boolean, run: Function}}
 */
export default () => {
	const { createNotice } = useSettingsScreen();
	const [isBusy, setIsBusy] = useState(false);

	const run = (promise, { success, failure, onSuccess }) => {
		setIsBusy(true);

		return promise
			.then((result) => {
				onSuccess?.(result);

				if (success) {
					createNotice(
						'success',
						typeof success === 'function' ? success(result) : success,
					);
				}
			})
			.catch((error) => createNotice('error', error?.message || failure))
			.finally(() => setIsBusy(false));
	};

	return { isBusy, run };
};
