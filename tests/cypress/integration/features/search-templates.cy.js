/* global isEpIo */

describe('Search Templates Feature', () => {
	before(() => {
		cy.wpCli('wp elasticpress-tests delete-all-search-templates');
	});

	const enableFeature = () => {
		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.intercept('/wp-json/elasticpress/v1/features*').as('apiRequest');

		cy.contains('button', 'Search Templates').click();
		cy.contains('label', 'Enable')
			.closest('.components-base-control__field')
			.find('.components-form-toggle')
			.as('toggle');

		cy.get('@toggle').then(($el) => {
			if ($el.hasClass('is-checked')) {
				return;
			}
			cy.get('@toggle').click();
			cy.contains('button', 'Save changes').click();

			cy.wait('@apiRequest');
		});
	};

	/**
	 * Test that the feature cannot be activated when not in ElasticPress.io.
	 */
	it("Can't activate the feature if not in ElasticPress.io", () => {
		if (isEpIo) {
			return;
		}

		cy.login();
		cy.visitAdminPage('admin.php?page=elasticpress');

		cy.contains('button', 'Search Templates').click();
		cy.contains('.components-notice', 'You need an ElasticPress.io account').should('exist');
		cy.get('.components-form-toggle__input').should('be.disabled');
	});

	it('Can manage search templates', () => {
		if (!isEpIo) {
			return;
		}

		cy.login();
		enableFeature();

		/**
		 * Can go to the Search Templates page through the features section
		 */
		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.contains('button', 'Search Templates').click();
		cy.contains('a', 'Manage search templates').click();

		cy.url().should('include', 'elasticpress-search-templates');

		/**
		 * Can add a new template
		 */
		cy.contains('.components-panel__body-title', 'Add New Template')
			.closest('.components-panel__body')
			.as('addNewTemplatePanel');

		cy.get('@addNewTemplatePanel').get('input[type="text"]').type('new-template');
		cy.get('@addNewTemplatePanel')
			.get('textarea')
			.type('{"a": "b"},', { parseSpecialCharSequences: false });
		cy.contains('.components-notice', 'This does not seem to be a valid JSON object.').should(
			'exist',
		);

		cy.get('@addNewTemplatePanel').get('textarea').as('addNewTemplateTextarea');
		cy.get('@addNewTemplateTextarea').clear();
		cy.get('@addNewTemplateTextarea').type('{"a": "b"}', { parseSpecialCharSequences: false });
		cy.contains('.components-notice', 'This does not seem to be a valid JSON object.').should(
			'not.exist',
		);

		cy.get('@addNewTemplatePanel').contains('button', 'Save Template').click();
		cy.contains('Template saved.').should('exist');

		cy.contains('.components-panel__body-title', 'new-template')
			.closest('.components-panel__body')
			.as('NewTemplatePanel');
		cy.get('@NewTemplatePanel').should('exist');

		cy.get('@NewTemplatePanel').click();

		cy.get('@NewTemplatePanel')
			.find('input[type="text"]')
			.should('have.value', 'new-template')
			.should('be.disabled');
		cy.get('@NewTemplatePanel')
			.find('textarea')
			.invoke('val')
			.then((val) => expect(JSON.stringify(JSON.parse(val))).to.equal('{"a":"b"}'));

		/**
		 * Can edit a template
		 */
		cy.get('@NewTemplatePanel').find('textarea').as('newTemplateTextarea');
		cy.get('@newTemplateTextarea').clear();
		cy.get('@newTemplateTextarea').type('{"a": "c"}', { parseSpecialCharSequences: false });
		cy.get('@NewTemplatePanel').contains('button', 'Save changes').click();
		cy.contains('Template saved.').should('exist');

		cy.visitAdminPage('admin.php?page=elasticpress-search-templates');
		cy.contains('.components-panel__body-title', 'new-template')
			.closest('.components-panel__body')
			.as('NewTemplatePanel');
		cy.get('@NewTemplatePanel').should('exist');

		cy.contains('.components-panel__body-title', 'Add New Template')
			.closest('.components-panel__body')
			.as('addNewTemplatePanel');
		cy.get('@addNewTemplatePanel').get('input[type="text"]').type('new-template');
		cy.contains('.components-notice', 'This name is already in use.').should('exist');

		cy.intercept('/wp-json/elasticpress-labs/v1/search-templates/new-template*').as(
			'loadTemplateRequest',
		);
		cy.get('@NewTemplatePanel').click();
		cy.wait('@loadTemplateRequest');

		cy.get('@NewTemplatePanel')
			.find('textarea')
			.invoke('val')
			.then((val) => expect(JSON.stringify(JSON.parse(val))).to.equal('{"a":"c"}'));

		/**
		 * Can delete a template
		 */
		cy.visitAdminPage('admin.php?page=elasticpress-search-templates');
		cy.contains('.components-panel__body-title', 'new-template')
			.closest('.components-panel__body')
			.as('NewTemplatePanel');
		cy.get('@NewTemplatePanel').click();

		cy.get('@NewTemplatePanel').contains('button', 'Delete template').click();
		cy.contains('Template deleted.').should('exist');
	});

	it('Can see a message if above limits', () => {
		if (!isEpIo) {
			return;
		}

		cy.login();
		enableFeature();

		cy.wpCli('wp elasticpress-tests delete-all-search-templates');

		cy.request('/wp-admin/admin-ajax.php?action=rest-nonce').then((response) => {
			const nonce = response.body;
			// The test account already has a template created under a different index prefix.
			for (let index = 1; index <= 10; index++) {
				cy.request({
					method: 'PUT',
					url: `/wp-json/elasticpress-labs/v1/search-templates/template-${index}`,
					body: '{"a": "b"}',
					headers: { 'x-wp-nonce': nonce },
				});
				// Give the server a small break between requests
				// eslint-disable-next-line cypress/no-unnecessary-waiting
				cy.wait(200);
			}
		});

		cy.visitAdminPage('admin.php?page=elasticpress-search-templates');
		cy.contains('.components-panel__body-title', 'Add New Template')
			.closest('.components-panel__body')
			.as('addNewTemplatePanel');
		cy.get('@addNewTemplatePanel').get('input[type="text"]').type('new-template');
		cy.get('@addNewTemplatePanel')
			.get('textarea')
			.type('{"a": "b"}', { parseSpecialCharSequences: false });

		cy.intercept('/wp-json/elasticpress-labs/v1/search-templates/new-template*').as(
			'loadTemplateRequest',
		);
		cy.get('@addNewTemplatePanel').contains('button', 'Save Template').click();
		cy.wait('@loadTemplateRequest');

		cy.contains('It seems you have reached the limit of search').should('exist');
	});
});
