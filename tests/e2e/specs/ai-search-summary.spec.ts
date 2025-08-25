import {
	goToAdminPage,
	wpCli,
	maybeEnableFeature,
	test,
	expect,
	maybeDisableFeature,
} from 'elasticpress-playwright-utils';

test.describe('AI Search Summary Feature', () => {
	test('Can not turn the feature on if vector embeddings is not enabled', async ({ loggedInPage }) => {
		await maybeDisableFeature('vector_embeddings');
		await maybeDisableFeature('ai_search_summary');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'AI Search Summary' }).click();

 		await expect(
			loggedInPage.locator('.components-notice.is-error').filter({
				hasText: 'The Vector Embeddings feature must be enabled to use this feature.',
			}),
		).toBeVisible();
	});

	test('Can turn the feature on', async ({ loggedInPage }) => {
		await maybeEnableFeature('vector_embeddings');
		await maybeDisableFeature('ai_search_summary');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'AI Search Summary' }).click();
		await loggedInPage.getByRole('checkbox', { name: 'Enable' }).click();
		await loggedInPage.getByRole('button', { name: 'Save' }).click();

		const result = await wpCli('elasticpress list-features');
		expect(result.toString()).toContain('ai_search_summary');
	});
});
