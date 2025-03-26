// ***********************************************************
// This example support/index.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

import '@10up/cypress-wp-utils';
import './commands';
import './global-hooks';

/**
 * Ignore ResizeObserver error.
 *
 * @see {@link https://stackoverflow.com/questions/49384120/resizeobserver-loop-limit-exceeded}
 */
Cypress.on('uncaught:exception', (err) => {
	if (err.message?.includes('ResizeObserver loop limit exceeded')) {
		return false;
	}

	return err;
});
