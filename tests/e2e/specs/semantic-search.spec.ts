import {
	goToAdminPage,
	wpCli,
	maybeEnableFeature,
	test,
	expect,
	maybeDisableFeature,
	isEpIo,
} from 'elasticpress-playwright-utils';
import { setEpLabsDefaultFeatures } from './utils';

test.describe('Semantic Search Feature', () => {
	test('Can not turn the feature on if vector embeddings is not enabled', async ({
		loggedInPage,
	}) => {
		await maybeDisableFeature('vector_embeddings');
		await maybeDisableFeature('semantic_search');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Semantic Search' }).click();

		await expect(
			loggedInPage.locator('.components-notice.is-error').filter({
				hasText: 'The Vector Embeddings feature must be enabled to use this feature.',
			}),
		).toBeVisible();
	});

	test('Can turn the feature on', async ({ loggedInPage }) => {
		await maybeEnableFeature('vector_embeddings');
		await maybeDisableFeature('semantic_search');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		// Wait for API request
		const apiRequestPromise = loggedInPage.waitForResponse(
			'/wp-json/elasticpress/v1/features*',
		);

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Semantic Search' }).click();
		await loggedInPage.getByRole('checkbox', { name: 'Enable' }).click();
		await loggedInPage.getByRole('button', { name: 'Save' }).click();

		const apiRequestResponse = await apiRequestPromise;
		const jsonResponse = await apiRequestResponse.json();
		expect(JSON.stringify(jsonResponse)).toContain('"success":true');

		const result = await wpCli('elasticpress list-features');
		expect(result.toString()).toContain('semantic_search');

		await loggedInPage.reload();

		await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();

		if (process.env.ES_VERSION === '7.10.1') {
			await expect(loggedInPage.getByLabel('kNN')).toHaveCount(1);
			await expect(loggedInPage.getByLabel('kNN Cosine')).toBeVisible();
			await expect(loggedInPage.getByLabel('kNN', { exact: true })).not.toBeVisible();
			await expect(loggedInPage.getByLabel('Hybrid (kNN + Regular ES)')).not.toBeVisible();
		} else {
			await expect(loggedInPage.getByLabel('kNN')).toHaveCount(3);
			await expect(loggedInPage.getByLabel('kNN Cosine')).toBeVisible();
			await expect(loggedInPage.getByLabel('Hybrid (kNN + Regular ES)')).toBeVisible();
		}
	});

	test('Search algorithms disable Autosuggest and Instant Results', async ({ loggedInPage }) => {
		const saveFeatures = async () => {
			const apiResponsePromise = loggedInPage.waitForResponse(
				'**/wp-json/elasticpress/v1/features*',
			);
			await loggedInPage.getByRole('button', { name: 'Save changes' }).click();
			await apiResponsePromise;
		};

		// Check if Autosuggest and Instant Results are enabled
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.getByRole('button', { name: 'Live Search' }).click();

		await loggedInPage.getByRole('button', { name: 'Autosuggest' }).click();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByRole('checkbox', { name: 'Enable' }),
		).toBeEnabled();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByRole('checkbox', { name: 'Enable' }),
		).toBeChecked();

		await loggedInPage.getByRole('button', { name: 'Instant Results' }).click();
		if (isEpIo()) {
			await expect(
				loggedInPage
					.locator('#instant-results-view')
					.getByRole('checkbox', { name: 'Enable' }),
			).toBeEnabled();
		}

		// Select a semantic search algorithm
		await maybeEnableFeature('vector_embeddings');
		await maybeEnableFeature('semantic_search');
		await maybeEnableFeature('search_algorithm');

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.getByRole('button', { name: 'Other' }).click();
		await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();
		await loggedInPage.getByLabel('kNN Cosine').check();
		await saveFeatures();

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.getByRole('button', { name: 'Live Search' }).click();
		await loggedInPage.getByRole('button', { name: 'Autosuggest' }).click();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByRole('checkbox', { name: 'Enable' }),
		).toBeDisabled();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByRole('checkbox', { name: 'Enable' }),
		).not.toBeChecked();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByText('This feature is temporarily'),
		).toBeVisible();

		await loggedInPage.getByRole('button', { name: 'Instant Results' }).click();
		await expect(
			loggedInPage.locator('#instant-results-view').getByRole('checkbox', { name: 'Enable' }),
		).toBeDisabled();
		await expect(
			loggedInPage.locator('#instant-results-view').getByText('This feature is temporarily'),
		).toBeVisible();

		// If another algorithm is selected, Autosuggest and Instant Results should be enabled again
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.getByRole('button', { name: 'Other' }).click();
		await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();
		await loggedInPage.getByLabel('Version 4.0').check();
		await saveFeatures();

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.getByRole('button', { name: 'Live Search' }).click();
		await loggedInPage.getByRole('button', { name: 'Autosuggest' }).click();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByRole('checkbox', { name: 'Enable' }),
		).toBeEnabled();
		await expect(
			loggedInPage.locator('#autosuggest-view').getByRole('checkbox', { name: 'Enable' }),
		).toBeChecked();

		await loggedInPage.getByRole('button', { name: 'Instant Results' }).click();
		if (isEpIo()) {
			await expect(
				loggedInPage
					.locator('#instant-results-view')
					.getByRole('checkbox', { name: 'Enable' }),
			).toBeEnabled();
		}
	});

	test.describe('Settings Schema Updates on Save', () => {
		test.afterEach(async () => {
			await setEpLabsDefaultFeatures();
		});

		test('Search Algorithm options update without page refresh when Semantic Search changes', async ({
			loggedInPage,
		}) => {
			await maybeEnableFeature('vector_embeddings');
			await maybeEnableFeature('search_algorithm');
			await maybeDisableFeature('semantic_search');

			// Navigate to the settings page.
			await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
			await expect(loggedInPage.locator('.ep-settings-page form')).toBeVisible();
			await loggedInPage.waitForFunction(() => {
				return !!(window as any).epDashboard && !!(window as any).epDashboard.features;
			});

			// Start with Semantic Search disabled so semantic algorithms are hidden.
			await loggedInPage.getByRole('button', { name: 'Other' }).click();
			await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();
			await expect(loggedInPage.locator('div[id*="search_algorithm-view"]')).toBeVisible();
			await loggedInPage.waitForTimeout(500);

			await expect(loggedInPage.getByLabel('kNN Cosine')).not.toBeVisible();
			await expect(loggedInPage.getByLabel('kNN', { exact: true })).not.toBeVisible();
			await expect(loggedInPage.getByLabel('Hybrid (kNN + Regular ES)')).not.toBeVisible();

			// Navigate to Semantic Search and enable it.
			await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
			await loggedInPage.getByRole('button', { name: 'Semantic Search' }).click();
			await expect(loggedInPage.locator('div[id*="semantic_search-view"]')).toBeVisible();
			await loggedInPage.waitForTimeout(500);
			await loggedInPage.getByRole('checkbox', { name: 'Enable' }).setChecked(true);

			// Save and wait for the save operation to complete.
			const saveButton = loggedInPage.getByRole('button', { name: 'Save changes' });
			await saveButton.click();
			await expect(
				loggedInPage.locator('.components-snackbar').filter({
					hasText: 'Feature settings saved',
				}),
			).toBeVisible({ timeout: 10000 });

			// Navigate back to Search Algorithm Version (no page refresh).
			await loggedInPage.getByRole('button', { name: 'Other' }).click();
			await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();
			await expect(loggedInPage.locator('div[id*="search_algorithm-view"]')).toBeVisible();
			await loggedInPage.waitForTimeout(500);

			// Verify semantic algorithms now appear.
			await expect(loggedInPage.getByLabel('kNN Cosine')).toBeVisible();
			if (await loggedInPage.getByLabel('Hybrid (kNN + Regular ES)').isVisible()) {
				await expect(loggedInPage.getByLabel('kNN', { exact: true })).toBeVisible();
				await expect(loggedInPage.getByLabel('Hybrid (kNN + Regular ES)')).toBeVisible();
			} else {
				await expect(loggedInPage.getByLabel('kNN', { exact: true })).not.toBeVisible();
				await expect(
					loggedInPage.getByLabel('Hybrid (kNN + Regular ES)'),
				).not.toBeVisible();
			}

			// Disable Semantic Search and verify options disappear again.
			await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
			await loggedInPage.getByRole('button', { name: 'Semantic Search' }).click();
			await expect(loggedInPage.locator('div[id*="semantic_search-view"]')).toBeVisible();
			await loggedInPage.waitForTimeout(500);
			await loggedInPage.getByRole('checkbox', { name: 'Enable' }).setChecked(false);

			await saveButton.click();
			await expect(
				loggedInPage.locator('.components-snackbar').filter({
					hasText: 'Feature settings saved',
				}),
			).toBeVisible({ timeout: 10000 });

			await loggedInPage.getByRole('button', { name: 'Other' }).click();
			await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();
			await expect(loggedInPage.locator('div[id*="search_algorithm-view"]')).toBeVisible();
			await loggedInPage.waitForTimeout(500);

			await expect(loggedInPage.getByLabel('kNN Cosine')).not.toBeVisible();
			await expect(loggedInPage.getByLabel('kNN', { exact: true })).not.toBeVisible();
			await expect(loggedInPage.getByLabel('Hybrid (kNN + Regular ES)')).not.toBeVisible();
		});
	});
});
