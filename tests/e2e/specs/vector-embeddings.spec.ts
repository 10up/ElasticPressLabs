import {
	goToAdminPage,
	wpCli,
	test,
	expect,
	maybeDisableFeature,
	wpCliEval,
} from 'elasticpress-playwright-utils';

test.describe('Vector Embeddings Feature', () => {
	test.afterAll('Disable feature', async () => {
		await maybeDisableFeature('vector_embeddings');
	});

	test('Can enable and configure the feature', async ({ loggedInPage }) => {
		await maybeDisableFeature('vector_embeddings');
		await wpCli('option delete ep_vector_embeddings_settings', true);

		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		// Wait for API request
		const apiResponsePromise = loggedInPage.waitForResponse(
			'**/wp-json/elasticpress/v1/features*',
		);

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Vector Embeddings' }).click();
		await loggedInPage.getByRole('checkbox', { name: 'Enable' }).check();
		await loggedInPage
			.getByLabel('OpenAI API Key')
			.fill(process.env.VECTOR_EMBEDDINGS_API_KEY || '');
		await loggedInPage
			.getByLabel('OpenAI Embeddings API Url')
			.fill(process.env.VECTOR_EMBEDDINGS_API_URL || '');
		await loggedInPage
			.getByLabel('The name of the embedding model to use')
			.fill(process.env.VECTOR_EMBEDDINGS_MODEL || '');

		// Handle confirmation dialog
		loggedInPage.on('dialog', (dialog) => dialog.accept());
		await loggedInPage.getByRole('button', { name: 'Save and sync later' }).click();

		await apiResponsePromise;

		const wpCliEvalResult = await wpCliEval(`
			$posts = new \\WP_Query(
				[
					'post_type'      => [ 'post', 'page' ],
					'posts_per_page' => -1,
					'meta_key'       => 'ep_test',
					'meta_value'     => 'vector_embeddings',
				]
			);
			foreach ( $posts->posts as $post ) {
				wp_delete_post( $post->ID, true );
			}

			// wp_insert_post is not working here
			$post_id = WP_CLI::runcommand( 'post create --post_title="Test Post" --post_content="Lorem ipsum veritas dolor" --post_author=1 --post_status="publish" --meta_input="{\\"ep_test\\":\\"vector_embeddings\\"}" --porcelain', [ 'return' => true ] );
			$page_id = WP_CLI::runcommand( 'post create --post_title="Test Page" --post_content="Lorem ipsum veritas dolor" --post_author=1 --post_status="publish"  --post_type="page" --meta_input="{\\"ep_test\\":\\"vector_embeddings\\"}" --porcelain', [ 'return' => true ] );

			$post_id = absint( $post_id );
			$page_id = absint( $page_id );

			$return = WP_CLI::runcommand( 'elasticpress sync --setup --yes --show-errors --include=' . $post_id . ',' . $page_id, [ 'return' => true ] );

			echo json_encode( [ 'return' => $return, 'post_id' => $post_id, 'page_id' => $page_id ] );
		`);

		const {
			post_id: postId,
			page_id: pageId,
			return: returnValue,
		} = JSON.parse(wpCliEvalResult.toString());

		expect(returnValue).not.toContain('Number of posts index errors');

		const result = await wpCli('elasticpress list-features');
		expect(result.toString()).toContain('vector_embeddings');

		await goToAdminPage(loggedInPage, `post.php?post=${postId}&action=edit`);
		if (!(await loggedInPage.locator('#wpadminbar').isVisible())) {
			await loggedInPage.keyboard.press('Control+Shift+Alt+F'); // Disable fullscreen mode
		}

		await expect(loggedInPage.locator('#wp-admin-bar-ep-basic-status-summary')).toContainText(
			'Content in sync: WordPress and Elasticsearch content match.',
		);

		const postInEs = await wpCli(`elasticpress get post ${postId}`);
		expect(postInEs.toString()).toContain('"chunks":[{"vector":[');

		const pageInEs = await wpCli(`elasticpress get post ${pageId}`);
		expect(pageInEs.toString()).toContain('"chunks":[{"vector":[');

		await goToAdminPage(loggedInPage, `admin.php?page=elasticpress-vector-embeddings`);
		await loggedInPage.getByRole('tab', { name: 'Pages' }).click();
		await loggedInPage
			.getByRole('checkbox', { name: 'Allow Vector Embedding' })
			.setChecked(false);
		await loggedInPage.getByRole('button', { name: 'Save settings' }).click();

		const syncResult = await wpCli(
			`elasticpress sync --setup --yes --show-errors --include=${postId},${pageId}`,
		);
		expect(syncResult).not.toContain('Number of posts index errors');

		const postInEsAfterSync = await wpCli(`elasticpress get post ${postId}`);
		expect(postInEsAfterSync.toString()).toContain('"chunks":[{"vector":[');

		const pageInEsAfterSync = await wpCli(`elasticpress get post ${pageId}`);
		expect(pageInEsAfterSync.toString()).not.toContain('"chunks":[{"vector":[');
	});
});
