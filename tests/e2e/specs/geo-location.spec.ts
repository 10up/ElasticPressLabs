import {
	test,
	expect,
	goToAdminPage,
	wpCli,
	wpCliEval,
	activatePlugin,
	deactivatePlugin,
	getEditorFrame,
	maybeDisableFeature,
	maybeEnableFeature,
} from 'elasticpress-playwright-utils';

test.describe('Geo Location Feature', { tag: '@geo-location' }, () => {
	test('Can activate the feature and sync automatically', async ({ loggedInPage }) => {
		await maybeDisableFeature('geo_location');

		// Can see the warning if using custom proxy
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		// Wait for API request
		const apiResponsePromise = loggedInPage.waitForResponse(
			'**/wp-json/elasticpress/v1/features*',
		);

		await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Geo Location' }).click();

		await loggedInPage.getByLabel('Enable').click();

		// Handle confirmation dialog
		loggedInPage.on('dialog', (dialog) => dialog.accept());
		await loggedInPage.getByRole('button', { name: 'Save and sync now' }).click();

		await apiResponsePromise;

		// Wait for sync messages
		await loggedInPage.getByRole('button', { name: 'Log' }).click();
		const syncMessages = loggedInPage.locator('.ep-sync-messages');
		await expect(syncMessages).toContainText('Mapping sent');
		await expect(syncMessages).toContainText('Sync complete');

		const result = await wpCli('elasticpress list-features');
		expect(result.toString()).toContain('geo_location');
	});

	test('Shows the address field when the Google Maps API exists', async ({ loggedInPage }) => {
		await maybeEnableFeature('geo_location');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		// Wait for API request
		const apiRequestPromise = loggedInPage.waitForResponse(
			'/wp-json/elasticpress/v1/features*',
		);

		await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Geo Location' }).click();

		// Add Google Maps API Key
		const apiKeyLabel = loggedInPage.locator('label:has-text("Google Maps API Key")');
		const apiKeyId = await apiKeyLabel.getAttribute('for');
		if (apiKeyId) {
			await loggedInPage.locator(`#${apiKeyId}`).clear();
			await loggedInPage.locator(`#${apiKeyId}`).fill(process.env.GOOGLE_MAPS_API_KEY || '');
		}

		await loggedInPage.getByRole('button', { name: 'Save changes' }).click();
		await apiRequestPromise;

		await goToAdminPage(loggedInPage, 'post-new.php');

		// Wait for Google Maps API request
		const mapApiRequestPromise = loggedInPage.waitForResponse(
			'https://maps.googleapis.com/maps/api/place/js/AutocompletionService*',
		);

		const geoLocationButton = loggedInPage.getByRole('button', {
			name: 'ElasticPress Geo Location',
		});
		const isExpanded = await geoLocationButton.getAttribute('aria-expanded');

		if (isExpanded === 'false') {
			await geoLocationButton.click();
		}

		// Add address
		const addressLabel = loggedInPage.locator('label:has-text("Address")');
		const addressId = await addressLabel.getAttribute('for');
		if (addressId) {
			await loggedInPage.locator(`#${addressId}`).fill('california');

			await mapApiRequestPromise;
			await loggedInPage.locator(`#${addressId}`).press('ArrowDown');
			await loggedInPage.locator(`#${addressId}`).press('Enter');
		}

		// Check if fields are not empty
		const latitudeLabel = loggedInPage.locator('label:has-text("Latitude")');
		const latitudeId = await latitudeLabel.getAttribute('for');
		if (latitudeId) {
			await expect(loggedInPage.locator(`#${latitudeId}`)).not.toHaveValue('');
		}

		const longitudeLabel = loggedInPage.locator('label:has-text("Longitude")');
		const longitudeId = await longitudeLabel.getAttribute('for');
		if (longitudeId) {
			await expect(loggedInPage.locator(`#${longitudeId}`)).not.toHaveValue('');
		}
	});

	test("Can show posts that are near the user's location", async ({ context, loggedInPage }) => {
		await context.grantPermissions(['geolocation']);
		await context.setGeolocation({ latitude: 40.712776, longitude: -74.005974 });

		await maybeEnableFeature('geo_location');

		// Delete all posts
		await wpCliEval(`
			$posts = new \\WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1 ] );
			foreach ( $posts->posts as $post ) {
				wp_delete_post( $post->ID, true );
			}
			WP_CLI::runcommand( 'elasticpress sync --setup --yes', [ 'return' => true ] );
		`);

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

		for await (const post of posts) {
			// Create a post
			await goToAdminPage(loggedInPage, 'post-new.php');
			const editorFrame = await getEditorFrame(loggedInPage);
			await editorFrame
				.locator('h1.editor-post-title__input, #post-title-0')
				.fill(post.title);

			const geoLocationButton = loggedInPage.getByRole('button', {
				name: 'ElasticPress Geo Location',
			});
			const isExpanded = await geoLocationButton.getAttribute('aria-expanded');

			if (isExpanded === 'false') {
				await geoLocationButton.click();
			}

			const latitudeLabel = loggedInPage.locator('label:has-text("Latitude")');
			const latitudeId = await latitudeLabel.getAttribute('for');
			if (latitudeId) {
				await loggedInPage.locator(`#${latitudeId}`).clear();
				await loggedInPage.locator(`#${latitudeId}`).fill(post.latitude.toString());
			}

			const longitudeLabel = loggedInPage.locator('label:has-text("Longitude")');
			const longitudeId = await longitudeLabel.getAttribute('for');
			if (longitudeId) {
				await loggedInPage.locator(`#${longitudeId}`).clear();
				await loggedInPage.locator(`#${longitudeId}`).fill(post.longitude.toString());
			}

			// Publish post
			await loggedInPage.locator('.editor-post-publish-panel__toggle').click();
			await loggedInPage.locator('.editor-post-publish-button').click();
			await expect(
				loggedInPage.locator('.components-snackbar, .components-notice.is-success'),
			).toBeVisible();
		}

		// Verify coordinates persist after reload
		await loggedInPage.reload();

		const geoLocationButton = loggedInPage.getByRole('button', {
			name: 'ElasticPress Geo Location',
		});
		const isExpanded = await geoLocationButton.getAttribute('aria-expanded');

		if (isExpanded === 'false') {
			await geoLocationButton.click();
		}

		const latitudeLabel = loggedInPage.locator('label:has-text("Latitude")');
		const latitudeId = await latitudeLabel.getAttribute('for');
		if (latitudeId) {
			await expect(loggedInPage.locator(`#${latitudeId}`)).toHaveValue('40.717754');
		}

		const longitudeLabel = loggedInPage.locator('label:has-text("Longitude")');
		const longitudeId = await longitudeLabel.getAttribute('for');
		if (longitudeId) {
			await expect(loggedInPage.locator(`#${longitudeId}`)).toHaveValue('-74');
		}

		// Search ordering by distance
		await loggedInPage.goto('/?s=Test+Geo+Location+Post&orderby=geo_distance');

		// Check if only 3 posts are displayed
		await expect(loggedInPage.locator('article.post')).toHaveCount(3);

		// Check if the posts are sorted by distance
		await expect(loggedInPage.locator('article.post')).toHaveCount(3);
		await expect(loggedInPage.locator('article.post:nth-of-type(1) h2')).toContainText(
			'Jersey City',
		);
		await expect(loggedInPage.locator('article.post:nth-of-type(2) h2')).toContainText(
			'Stamford',
		);
		await expect(loggedInPage.locator('article.post:nth-of-type(3) h2')).toContainText(
			'Chicago',
		);
	});

	test('Does not display the field when coordinates are pre-set via a filter', async ({
		loggedInPage,
	}) => {
		await maybeEnableFeature('geo_location');

		// Activate plugin
		await goToAdminPage(loggedInPage, 'plugins.php');
		await activatePlugin(loggedInPage, 'geo-location-pre-geo-points', 'wpCli');

		await goToAdminPage(loggedInPage, 'post-new.php');
		await expect(
			loggedInPage.getByRole('button', { name: 'ElasticPress Geo Location' }),
		).not.toBeVisible();

		await deactivatePlugin(loggedInPage, 'geo-location-pre-geo-points', 'wpCli');
	});

	test('Display an error message using the `epLabs.GeoLocation.currentPositionError` action', async ({
		loggedInPage,
	}) => {
		await maybeEnableFeature('geo_location');

		// Activate plugin
		await activatePlugin(loggedInPage, 'geo-location-js-action', 'wpCli');

		await loggedInPage.addInitScript(() => {
			Object.defineProperty(window.navigator, 'geolocation', {
				value: {
					getCurrentPosition: (success: any, error: any) => {
						error({ code: 1, message: 'User denied' });
					},
				},
				writable: true,
			});
		});

		// Search ordering by distance
		await loggedInPage.goto('/?s=test&orderby=geo_distance');

		await expect(loggedInPage.locator('.ep-geo-location-error')).toContainText('User denied');

		await deactivatePlugin(loggedInPage, 'geo-location-js-action', 'wpCli');
	});
});
