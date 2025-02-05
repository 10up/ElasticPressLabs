describe('Geo Location Feature', () => {
	const enableFeature = () => {
		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.intercept('/wp-json/elasticpress/v1/features*').as('apiRequest');

		cy.contains('button', 'Geo Location').click();
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

	it('Should add coordinates to a post', () => {
		enableFeature();

		cy.visitAdminPage('post-new.php');

		cy.intercept('/wp-json/wp/v2/posts*').as('apiRequest');
		cy.getBlockEditor().find('h1.editor-post-title__input, #post-title-0').type('Test Post');

		cy.contains('button', 'ElasticPress Geo Location').then(($btn) => {
			if ($btn.attr('aria-expanded') === 'false') {
				cy.wrap($btn).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).type('12.34');
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).type('98.76');
			});

		cy.get('.editor-post-publish-panel__toggle').should('be.enabled').click();

		cy.get('.editor-post-publish-button').click();

		cy.get('.components-snackbar, .components-notice.is-success').should('be.visible');

		cy.wait('@apiRequest');

		cy.reload();

		cy.contains('button', 'ElasticPress Geo Location').then(($btn) => {
			if ($btn.attr('aria-expanded') === 'false') {
				cy.wrap($btn).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('have.value', '12.34');
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('have.value', '98.76');
			});
	});

	it('Shows the address field when the Google Maps API exists', () => {
		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.intercept('/wp-json/elasticpress/v1/features*').as('apiRequest');

		cy.contains('button', 'Geo Location').click();

		cy.contains('label', 'Google Maps API Key')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clear();
				cy.get(`#${id}`).type(Cypress.env('GOOGLE_MAP_API_KEY'));
			});

		cy.contains('button', 'Save changes').click();

		cy.wait('@apiRequest');

		cy.visitAdminPage('post-new.php');

		cy.intercept('https://maps.googleapis.com/maps/api/place/js/AutocompletionService*').as(
			'mapApiRequest',
		);

		cy.contains('button', 'ElasticPress Geo Location').then(($btn) => {
			if ($btn.attr('aria-expanded') === 'false') {
				cy.wrap($btn).click();
			}
		});

		cy.contains('label', 'Address')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).type('california');

				cy.wait('@mapApiRequest');
				cy.get(`#${id}`).type('{downarrow}{enter}');
			});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('not.have.value', '');
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('not.have.value', '');
			});
	});
});
