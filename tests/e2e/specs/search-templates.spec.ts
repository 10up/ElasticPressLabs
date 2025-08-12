import { 
	goToAdminPage, 
	wpCli,
	maybeEnableFeature,
	test,
	expect,
} from 'elasticpress-playwright-utils';

test.describe('Search Templates Feature', { tag: '@search-templates' }, () => {
	/**
	 * Test that the feature cannot be activated when not in ElasticPress.io.
	 */
	test("Can't activate the feature if not in ElasticPress.io", async ({ loggedInPage }) => {
		if (process.env.EP_IS_EPIO === '1') {
			return;
		}

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		await loggedInPage.locator('button', { hasText: 'Search Templates' }).click();
		await expect(loggedInPage.locator('.components-notice:has-text("You need an ElasticPress.io account")')).toBeVisible();
		await expect(loggedInPage.locator('.components-form-toggle__input')).toBeDisabled();
	});

	test('Can manage search templates', async ({ loggedInPage }) => {
		if (process.env.EP_IS_EPIO !== '1') {
			return;
		}

		await maybeEnableFeature('search_templates');

		await wpCli('wp elasticpress-tests delete-all-search-templates');

		/**
		 * Can go to the Search Templates page through the features section
		 */
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');
		await loggedInPage.locator('button', { hasText: 'Search Templates' }).click();
		await loggedInPage.getByRole('link', { name: 'Manage search templates' }).click();

		await expect(loggedInPage).toHaveURL(/elasticpress-search-templates/);

		/**
		 * Can add a new template
		 */
		const addNewTemplatePanel = loggedInPage.locator('.components-panel__body-title:has-text("Add New Template")')
			.locator('..');

		await addNewTemplatePanel.locator('input[type="text"]').fill('new-template');
		await addNewTemplatePanel.locator('textarea').fill('{"a": "b"},');
		await expect(loggedInPage.locator('.components-notice:has-text("This does not seem to be a valid JSON object.")')).toBeVisible();

		const addNewTemplateTextarea = addNewTemplatePanel.locator('textarea');
		await addNewTemplateTextarea.clear();
		await addNewTemplateTextarea.fill('{"a": "b"}');
		await expect(loggedInPage.locator('.components-notice:has-text("This does not seem to be a valid JSON object.")')).not.toBeVisible();

		await addNewTemplatePanel.getByRole('button', { name: 'Save Template' }).click();
		await expect(loggedInPage.locator('.components-notice:has-text("Template saved.")')).toBeVisible();

		const newTemplatePanel = loggedInPage.locator('.components-panel__body-title:has-text("new-template")')
			.locator('..');
		await expect(newTemplatePanel).toBeVisible();

		await newTemplatePanel.click();

		await expect(newTemplatePanel.locator('input[type="text"]')).toHaveValue('new-template');
		await expect(newTemplatePanel.locator('input[type="text"]')).toBeDisabled();
		
		const textareaValue = await newTemplatePanel.locator('textarea').inputValue();
		expect(JSON.stringify(JSON.parse(textareaValue))).toBe('{"a":"b"}');

		/**
		 * Can edit a template
		 */
		const newTemplateTextarea = newTemplatePanel.locator('textarea');
		await newTemplateTextarea.clear();
		await newTemplateTextarea.fill('{"a": "c"}');
		await newTemplatePanel.getByRole('button', { name: 'Save changes' }).click();
		await expect(loggedInPage.locator('.components-notice:has-text("Template saved.")')).toBeVisible();

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress-search-templates');
		const newTemplatePanel2 = loggedInPage.locator('.components-panel__body-title:has-text("new-template")')
			.locator('..');
		await expect(newTemplatePanel2).toBeVisible();

		const addNewTemplatePanel2 = loggedInPage.locator('.components-panel__body-title:has-text("Add New Template")')
			.locator('..');
		await addNewTemplatePanel2.locator('input[type="text"]').fill('new-template');
		await expect(loggedInPage.locator('.components-notice:has-text("This name is already in use.")')).toBeVisible();

		// Wait for template load request
		const loadTemplateRequestPromise = loggedInPage.waitForResponse(
			'**/wp-json/elasticpress-labs/v1/search-templates/new-template*'
		);
		await newTemplatePanel2.click();
		await loadTemplateRequestPromise;

		const textareaValue2 = await newTemplatePanel2.locator('textarea').inputValue();
		expect(JSON.stringify(JSON.parse(textareaValue2))).toBe('{"a":"c"}');

		/**
		 * Can delete a template
		 */
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress-search-templates');
		const newTemplatePanel3 = loggedInPage.locator('.components-panel__body-title:has-text("new-template")')
			.locator('..');
		await newTemplatePanel3.click();

		await newTemplatePanel3.getByRole('button', { name: 'Delete template' }).click();
		await expect(loggedInPage.locator('.components-notice:has-text("Template deleted.")')).toBeVisible();
	});

	test('Can see a message if above limits', async ({ loggedInPage }) => {
		if (process.env.EP_IS_EPIO !== '1') {
			return;
		}

		await maybeEnableFeature('search_templates');

		await wpCli('wp elasticpress-tests delete-all-search-templates');

		// Get nonce and create templates via API
		const nonceResponse = await loggedInPage.request.get('/wp-admin/admin-ajax.php?action=rest-nonce');
		const nonce = await nonceResponse.text();

		// The test account already has a template created under a different index prefix.
		for (let index = 1; index <= 10; index++) {
			await loggedInPage.request.put(`/wp-json/elasticpress-labs/v1/search-templates/template-${index}`, {
				data: '{"a": "b"}',
				headers: { 'x-wp-nonce': nonce },
			});
			// Give the server a small break between requests
			await loggedInPage.waitForTimeout(200);
		}

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress-search-templates');
		const addNewTemplatePanel = loggedInPage.locator('.components-panel__body-title:has-text("Add New Template")')
			.locator('..');
		await addNewTemplatePanel.locator('input[type="text"]').fill('new-template');
		await addNewTemplatePanel.locator('textarea').fill('{"a": "b"}');

		// Wait for template load request
		const loadTemplateRequestPromise = loggedInPage.waitForResponse(
			'**/wp-json/elasticpress-labs/v1/search-templates/new-template*'
		);
		await addNewTemplatePanel.getByRole('button', { name: 'Save Template' }).click();
		await loadTemplateRequestPromise;

		await expect(loggedInPage.locator('.components-notice:has-text("It seems you have reached the limit of search")')).toBeVisible();
	});
});
