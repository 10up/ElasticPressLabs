import {
	goToAdminPage,
	wpCli,
	test,
	expect,
	maybeDisableFeature,
} from 'elasticpress-playwright-utils';

test.describe('Vector Embeddings Feature', () => {
	test('Can turn the feature on', async ({ loggedInPage }) => {
		await maybeDisableFeature('vector_embeddings');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Vector Embeddings' }).click();
		await loggedInPage.getByRole('checkbox', { name: 'Enable' }).click();
		loggedInPage.on('dialog', (dialog) => dialog.accept());
		await loggedInPage.getByRole('button', { name: 'Save and sync now' }).click();

		await loggedInPage.getByRole('button', { name: 'Log' }).click();
		const syncMessages = loggedInPage.locator('.ep-sync-messages');
		await expect(syncMessages).toContainText('Mapping sent');
		await expect(syncMessages).toContainText('Sync complete');

		const result = await wpCli('elasticpress list-features');
		expect(result.toString()).toContain('vector_embeddings');

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress-status-report');
		const vectorEmbeddingsButton = loggedInPage
			.getByRole('button', { name: 'Vector Embeddings', exact: true })
			.first();
		const vectorEmbeddingsGroup = loggedInPage
			.locator('.components-panel__body', { has: vectorEmbeddingsButton })
			.first();
		await vectorEmbeddingsButton.click();
		await expect(vectorEmbeddingsGroup).toContainText('Content in the queue');
		await expect(vectorEmbeddingsGroup.locator('td').nth(1)).not.toContainText('0');

		await goToAdminPage(loggedInPage, 'post.php?post=1&action=edit');
		if (!(await loggedInPage.locator('#wpadminbar').isVisible())) {
			await loggedInPage.keyboard.press('Control+Shift+Alt+F'); // Disable fullscreen mode
		}

		await expect(loggedInPage.locator('.ep-status-indicator')).toContainText(
			'[EP] Processing vector embeddings',
		);

		// Wait for the queue to be processed
		await loggedInPage.waitForTimeout(10000);

		await loggedInPage.reload();
		expect(await loggedInPage.locator('.ep-status-indicator')).toContainText(
			'[EP] Content in sync',
		);
	});
});
