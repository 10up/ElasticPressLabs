describe('Geo Location Feature', () => {
	it('Can activate the feature and sync automatically', () => {
		// Can see the warning if using custom proxy
		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.intercept('/wp-json/elasticpress/v1/features*').as('apiRequest');

		cy.contains('button', 'Geo Location').click();
		cy.contains('label', 'Enable')
			.closest('.components-base-control__field')
			.find('.components-form-toggle')
			.as('toggle');

		cy.get('@toggle').then((element) => {
			if (element.hasClass('is-checked')) {
				return;
			}
			cy.get('@toggle').click();
			cy.contains('button', 'Save and sync now').click();

			cy.wait('@apiRequest');

			cy.on('window:confirm', () => true);

			cy.get('.ep-sync-progress strong', {
				timeout: Cypress.config('elasticPressIndexTimeout'),
			}).should('contain.text', 'Sync complete');

			cy.wpCli('elasticpress list-features').its('stdout').should('contain', 'geo_location');
		});
	});

	it('Shows the address field when the Google Maps API exists', () => {
		cy.visitAdminPage('admin.php?page=elasticpress');
		cy.intercept('/wp-json/elasticpress/v1/features*').as('apiRequest');

		cy.contains('button', 'Geo Location').click();

		// Add Google Maps API Key
		cy.contains('label', 'Google Maps API Key')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clear();
				cy.get(`#${id}`).type(Cypress.env('GOOGLE_MAPS_API_KEY'));
			});

		cy.contains('button', 'Save changes').click();
		cy.wait('@apiRequest');

		cy.visitAdminPage('post-new.php');
		cy.intercept('https://maps.googleapis.com/maps/api/place/js/AutocompletionService*').as(
			'mapApiRequest',
		);

		cy.contains('button', 'ElasticPress Geo Location').then((button) => {
			if (button.attr('aria-expanded') === 'false') {
				cy.wrap(button).click();
			}
		});

		// Add address.
		cy.contains('label', 'Address')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).type('california');

				cy.wait('@mapApiRequest');
				cy.get(`#${id}`).type('{downarrow}{enter}');
			});

		// Check if fields are not empty
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

	it("Can show posts that are near the user's location.", () => {
		// Delete all posts
		cy.wpCli('wp post list --format=ids').then((wpCliResponse) => {
			if (wpCliResponse.stdout !== '') {
				cy.wpCli(`wp post delete ${wpCliResponse.stdout}`);
			}
		});

		// Sync posts
		cy.wpCli('wp elasticpress sync --setup --yes');

		const posts = [
			{
				title: 'Test Geo Location Post - Stamford',
				latitude: 41.05343,
				longitude: -73.538734,
			},
			{
				title: 'Test Geo Location Post - Chicago',
				latitude: 41.878113,
				longitude: -87.629799,
			},
			{
				title: 'Test Geo Location Post - Jersey City',
				latitude: 40.717754,
				longitude: -74,
			},
		];

		posts.forEach((post) => {
			// Create a post.
			cy.visitAdminPage('post-new.php');
			cy.getBlockEditor().find('h1.editor-post-title__input, #post-title-0').type(post.title);

			cy.contains('button', 'ElasticPress Geo Location').then((button) => {
				if (button.attr('aria-expanded') === 'false') {
					cy.wrap(button).click();
				}
			});

			cy.contains('label', 'Latitude')
				.invoke('attr', 'for')
				.then((id) => {
					cy.get(`#${id}`).clearThenType(post.latitude);
				});

			cy.contains('label', 'Longitude')
				.invoke('attr', 'for')
				.then((id) => {
					cy.get(`#${id}`).clearThenType(post.longitude);
				});

			// Publish post
			cy.get('.editor-post-publish-panel__toggle').should('be.enabled').click();
			cy.get('.editor-post-publish-button').click();
			cy.get('.components-snackbar, .components-notice.is-success').should('be.visible');
		});

		// Verify coordinates persist after reload
		cy.reload();

		cy.contains('button', 'ElasticPress Geo Location').then((button) => {
			if (button.attr('aria-expanded') === 'false') {
				cy.wrap(button).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('have.value', 40.717754);
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('have.value', -74);
			});

		// Search ordering by distance.
		cy.visit('/?s=Test+Geo+Location+Post&orderby=geo_distance', {
			onBeforeLoad(win) {
				cy.stub(win.navigator.geolocation, 'getCurrentPosition').callsFake((cb) => {
					return cb({
						coords: {
							latitude: 40.712776,
							longitude: -74.005974,
							accuracy: 100,
						},
					});
				});
			},
		});

		// Check if only 3 posts are displayed.
		cy.get('article.post').should('have.length', 3);

		// Check if the posts are sorted by distance.
		cy.get('article.post').should('have.length', 3);
		cy.get('article.post:nth-of-type(1) h2').contains('Jersey City');
		cy.get('article.post:nth-of-type(2) h2').contains('Stamford');
		cy.get('article.post:nth-of-type(3) h2').contains('Chicago');
	});

	it('Does not display the field when coordinates are pre-set via a filter', () => {
		// Activate plugin
		cy.visitAdminPage('plugins.php');
		cy.activatePlugin('set-geo-location-coordinates');

		cy.visitAdminPage('post-new.php');
		cy.contains('button', 'ElasticPress Geo Location').should('not.exist');

		cy.deactivatePlugin('set-geo-location-coordinates');
	});
});
