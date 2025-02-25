// ***********************************************
// This example commands.js shows you how to
// create various custom commands and overwrite
// existing commands.
//
// For more comprehensive examples of custom
// commands please read more here:
// https://on.cypress.io/custom-commands
// ***********************************************
//
//
// -- This is a parent command --
// Cypress.Commands.add('login', (email, password) => { ... })
//
//
// -- This is a child command --
// Cypress.Commands.add('drag', { prevSubject: 'element'}, (subject, options) => { ... })
//
//
// -- This is a dual command --
// Cypress.Commands.add('dismiss', { prevSubject: 'optional'}, (subject, options) => { ... })
//
//
// -- This will overwrite an existing command --
// Cypress.Commands.overwrite('visit', (originalFn, url, options) => { ... })

Cypress.Commands.add('visitAdminPage', (page = 'index.php') => {
	cy.login();
	if (page.includes('http')) {
		cy.visit(page);
	} else {
		cy.visit(`/wp-admin/${page.replace(/^\/|\/$/g, '')}`);
	}
});

Cypress.Commands.add('clearThenType', { prevSubject: true }, (subject, text, force = false) => {
	/**
	 * Typing 'x' and immediately deleting it, as sometimes Cypress is too fast and
	 * does not type the first character(s) correctly.
	 *
	 * @see https://github.com/cypress-io/cypress/issues/3817
	 */
	cy.wrap(subject).type('x');
	cy.wrap(subject).clear();
	cy.wrap(subject).type(text, { force });
});

Cypress.Commands.add('wpCliEval', (command) => {
	const fileName = (Math.random() + 1).toString(36).substring(7);

	// this will be written "local" plugin directory
	const escapedCommand = command.replace(/^<\?php /, '');
	cy.writeFile(fileName, `<?php ${escapedCommand}`);

	// which is read from it's proper location in the plugins directory
	cy.exec(
		`./bin/wp-env-cli tests-wordpress "wp --allow-root eval-file wp-content/plugins/elasticpress-labs/${fileName}"`,
	).then((result) => {
		cy.exec(`rm ${fileName}`);
		cy.wrap(result);
	});
});

Cypress.Commands.add('openWidgetsPage', () => {
	cy.login();
	cy.visitAdminPage('widgets.php');
	cy.get('body').then(($body) => {
		const $button = $body.find('.edit-widgets-welcome-guide .components-modal__header button');
		if ($button.is(':visible')) {
			$button.click();
		}
	});
});

Cypress.Commands.add('openBlockInserter', () => {
	cy.get('body').then(($body) => {
		// If already open, skip.
		if ($body.find('.edit-widgets-layout__inserter-panel-content').length > 0) {
			return;
		}
		if ($body.hasClass('widgets-php')) {
			cy.get('.edit-widgets-header-toolbar__inserter-toggle').click();
		} else {
			cy.get(
				'.edit-post-header-toolbar__inserter-toggle,.editor-document-tools__inserter-toggle',
			).click();
		}
	});
});

Cypress.Commands.add('emptyWidgets', () => {
	cy.wpCliEval(
		`
		WP_CLI::runcommand('widget reset --all');

		$inactive_widgets = WP_CLI::runcommand('widget list wp_inactive_widgets --format=ids', [ 'return' => true ] );
		if ( $inactive_widgets ) {
			WP_CLI::runcommand("widget delete {$inactive_widgets}" );
		}
		`,
	);
});
