describe('Geo Location Feature', () => {
	/**
	 * Delete all widgets and ensure Classic Widgets is deactivated.
	 */
	beforeEach(() => {
		cy.emptyWidgets();
	});

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

	it('Should add coordinates to a post', () => {
		const coordinates = {
			latitude: '12.34',
			longitude: '98.76',
		};

		cy.visitAdminPage('post-new.php');
		cy.getBlockEditor().find('h1.editor-post-title__input, #post-title-0').type('Test Post');

		cy.contains('button', 'ElasticPress Geo Location').then((button) => {
			if (button.attr('aria-expanded') === 'false') {
				cy.wrap(button).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).type(coordinates.latitude);
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).type(coordinates.longitude);
			});

		// Publish post
		cy.get('.editor-post-publish-panel__toggle').should('be.enabled').click();
		cy.get('.editor-post-publish-button').click();
		cy.get('.components-snackbar, .components-notice.is-success').should('be.visible');

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
				cy.get(`#${id}`).should('have.value', coordinates.latitude);
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).should('have.value', coordinates.longitude);
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

	it('Can insert, configure, and use the Geo Location block', () => {
		/**
		 * Add a Block.
		 */
		cy.openWidgetsPage();
		cy.openBlockInserter();
		cy.insertBlock('ElasticPress Geo Location').then(() => {
			cy.openDocumentSettingsSidebar('Block');

			cy.contains('label', 'Text when location is not set').then((label) => {
				cy.get(`#${label.attr('for')}`).clearThenType(
					'Show posts closest to your location first – Updated.',
				);
			});

			/**
			 * Save widgets and visit the front page.
			 */
			cy.intercept('/wp-json/wp/v2/sidebars*').as('sidebarsRest');
			cy.get('.edit-widgets-header__actions button').contains('Update').click();
			cy.wait('@sidebarsRest');
			cy.visit('/');
		});

		// Check if the block has updated text
		cy.get('.ep-geo-location__label').should(
			'contain.text',
			'Show posts closest to your location first – Updated.',
		);

		// Mock the geolocation API to set the location to New York
		cy.window().then((win) => {
			cy.stub(win.navigator.geolocation, 'getCurrentPosition').callsFake((cb) => {
				cb({
					coords: {
						latitude: 40.712776,
						longitude: -74.005974,
						accuracy: 100,
					},
				});
			});
		});

		cy.get('.ep-geo-location__submit-button').click();

		cy.get('.ep-geo-location__label').should(
			'contain.text',
			'Posts closest to your location are listed first.',
		);

		// Mock the geolocation API to set the location to San Francisco
		cy.window().then((win) => {
			cy.stub(win.navigator.geolocation, 'getCurrentPosition').callsFake((cb) => {
				cb({
					coords: {
						latitude: 40.712776,
						longitude: -74.005974,
						accuracy: 100,
					},
				});
			});
		});

		// Click the button again and unset the location.
		cy.get('.ep-geo-location__submit-button').click();
		cy.get('.ep-geo-location__label').should(
			'contain.text',
			'Show posts closest to your location first – Updated.',
		);
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

		// Create a post.
		cy.visitAdminPage('post-new.php');
		cy.getBlockEditor()
			.find('h1.editor-post-title__input, #post-title-0')
			.type('Test Geo Location Post - Stamford');

		cy.contains('button', 'ElasticPress Geo Location').then((button) => {
			if (button.attr('aria-expanded') === 'false') {
				cy.wrap(button).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clearThenType(41.05343);
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clearThenType(-73.538734);
			});

		// Publish post
		cy.get('.editor-post-publish-panel__toggle').should('be.enabled').click();
		cy.get('.editor-post-publish-button').click();
		cy.get('.components-snackbar, .components-notice.is-success').should('be.visible');

		// Create a post.
		cy.visitAdminPage('post-new.php');
		cy.getBlockEditor()
			.find('h1.editor-post-title__input, #post-title-0')
			.type('Test Geo Location Post - Chicago');

		cy.contains('button', 'ElasticPress Geo Location').then((button) => {
			if (button.attr('aria-expanded') === 'false') {
				cy.wrap(button).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clearThenType(41.878113);
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clearThenType(-87.629799);
			});

		// Publish post
		cy.get('.editor-post-publish-panel__toggle').should('be.enabled').click();
		cy.get('.editor-post-publish-button').click();
		cy.get('.components-snackbar, .components-notice.is-success').should('be.visible');

		cy.visitAdminPage('post-new.php');
		cy.getBlockEditor()
			.find('h1.editor-post-title__input, #post-title-0')
			.type('Test Geo Location Post - Jersey City');

		cy.contains('button', 'ElasticPress Geo Location').then((button) => {
			if (button.attr('aria-expanded') === 'false') {
				cy.wrap(button).click();
			}
		});

		cy.contains('label', 'Latitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clearThenType(40.717754);
			});

		cy.contains('label', 'Longitude')
			.invoke('attr', 'for')
			.then((id) => {
				cy.get(`#${id}`).clearThenType(-74.043143);
			});

		// Publish post
		cy.get('.editor-post-publish-panel__toggle').should('be.enabled').click();
		cy.get('.editor-post-publish-button').click();
		cy.get('.components-snackbar, .components-notice.is-success').should('be.visible');

		// Open widgets page and add the Geo Location block.
		cy.openWidgetsPage();
		cy.openBlockInserter();
		cy.insertBlock('ElasticPress Geo Location').then(() => {
			cy.openDocumentSettingsSidebar('Block');

			/**
			 * Save widgets and visit the search page.
			 */
			cy.intercept('/wp-json/wp/v2/sidebars*').as('sidebarsRest');
			cy.get('.edit-widgets-header__actions button').contains('Update').click();
			cy.wait('@sidebarsRest');
			cy.visit('/?s=Test+Geo+Location+Post');
		});

		// Check if only 3 posts are displayed.
		cy.get('article.post').should('have.length', 3);

		// Mock the geolocation API to set the location to New York.
		cy.window().then((win) => {
			cy.stub(win.navigator.geolocation, 'getCurrentPosition').callsFake((cb) => {
				cb({
					coords: {
						latitude: 40.712776,
						longitude: -74.005974,
						accuracy: 100,
					},
				});
			});
		});

		// Click the button to set the location.
		cy.get('.ep-geo-location__submit-button').click();

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
