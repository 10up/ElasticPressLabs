import {
	test,
	expect,
	goToAdminPage,
	wpCli,
	wpCliEval,
	activatePlugin,
	deactivatePlugin,
	getEditorFrame,
	refreshIndex,
	maybeDisableFeature,
	maybeEnableFeature,
} from 'elasticpress-playwright-utils';
import { setEpLabsDefaultFeatures } from './utils';

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

	test('Preserves the API key when switching integrations and saving', async ({
		loggedInPage,
	}) => {
		await maybeEnableFeature('geo_location');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Geo Location', exact: true }).click();

		const key = loggedInPage.getByLabel('Google Maps API Key', { exact: true });
		const legacy = loggedInPage.getByLabel('Use the legacy Google Maps integration', {
			exact: true,
		});

		await key.fill('shared-test-key');

		for await (const checked of [true, false]) {
			await legacy.setChecked(checked);
			await expect(key).toHaveValue('shared-test-key');

			const saved = loggedInPage.waitForResponse(
				(response) =>
					response.url().includes('/elasticpress/v1/features') &&
					response.request().method() === 'POST',
			);

			await loggedInPage.getByRole('button', { name: 'Save changes', exact: true }).click();
			await saved;
			await loggedInPage.reload();
			await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
			await loggedInPage.getByRole('button', { name: 'Geo Location', exact: true }).click();
			await expect(legacy).toBeChecked({ checked });
			await expect(key).toHaveValue('shared-test-key');
		}
	});

	for (const integration of ['Legacy', 'New']) {
		test(`Selects an address using Places API (${integration})`, async ({ loggedInPage }) => {
			test.skip(!process.env.GOOGLE_MAPS_API_KEY, 'Google Maps API key is not available');

			await maybeEnableFeature('geo_location');
			await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
			await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
			await loggedInPage.getByRole('button', { name: 'Geo Location', exact: true }).click();
			await loggedInPage
				.getByLabel('Google Maps API Key', { exact: true })
				.fill(process.env.GOOGLE_MAPS_API_KEY || '');
			await loggedInPage
				.getByLabel('Use the legacy Google Maps integration', { exact: true })
				.setChecked(integration === 'Legacy');

			const saved = loggedInPage.waitForResponse(
				(response) =>
					response.url().includes('/elasticpress/v1/features') &&
					response.request().method() === 'POST',
			);

			await loggedInPage.getByRole('button', { name: 'Save changes', exact: true }).click();
			await saved;
			await goToAdminPage(loggedInPage, 'post-new.php');

			const panel = loggedInPage.getByRole('button', {
				name: 'ElasticPress Geo Location',
				exact: true,
			});

			if ((await panel.getAttribute('aria-expanded')) === 'false') {
				await panel.click();
			}

			if (integration === 'Legacy') {
				const address = loggedInPage.getByRole('textbox', { name: 'Address', exact: true });
				await address.fill('california');
				await loggedInPage.locator('.pac-item').first().click();
			} else {
				// The new widget's input is in a closed shadow root.
				const address = loggedInPage.locator('gmp-place-autocomplete');
				await address.click();

				const predictionsPromise = loggedInPage.waitForResponse(
					(response) =>
						response.url().includes('/AutocompletePlaces') &&
						response.request().postData()?.includes('"california"'),
				);

				await address.pressSequentially('california');
				const predictions = await predictionsPromise;
				await predictions.finished();
				await address.press('ArrowDown');
				await address.press('Enter');
			}

			await expect(loggedInPage.getByLabel('Latitude', { exact: true })).not.toHaveValue('');
			await expect(loggedInPage.getByLabel('Latitude', { exact: true })).not.toHaveValue('0');
			await expect(loggedInPage.getByLabel('Longitude', { exact: true })).not.toHaveValue('');
			await expect(loggedInPage.getByLabel('Longitude', { exact: true })).not.toHaveValue(
				'0',
			);
		});
	}

	test("Can show posts that are near the user's location", async ({ context, loggedInPage }) => {
		await setEpLabsDefaultFeatures();

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
			// The editor iframe may appear after the page has finished navigating.
			await expect
				.poll(async () => {
					const frame = await getEditorFrame(loggedInPage);
					return frame.locator('h1.editor-post-title__input, #post-title-0').isVisible();
				})
				.toBe(true);
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
		await refreshIndex('post');
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
