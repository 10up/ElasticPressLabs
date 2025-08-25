import {
	goToAdminPage,
	wpCli,
	maybeEnableFeature,
	test,
	expect,
	maybeDisableFeature,
} from 'elasticpress-playwright-utils';

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

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Semantic Search' }).click();
		await loggedInPage.getByRole('checkbox', { name: 'Enable' }).click();
		await loggedInPage.getByRole('button', { name: 'Save' }).click();

		const result = await wpCli('elasticpress list-features');
		expect(result.toString()).toContain('semantic_search');

		await loggedInPage.reload();

		await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();

		await expect(loggedInPage.getByLabel('kNN')).toHaveCount(3);
		await expect(loggedInPage.getByLabel('kNN Cosine')).toBeVisible();
		await expect(loggedInPage.getByLabel('Hybrid (kNN + Regular ES)')).toBeVisible();
	});
});
