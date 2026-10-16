import {
	goToAdminPage,
	maybeEnableFeature,
	test,
	expect,
	wpCliEval,
} from 'elasticpress-playwright-utils';
import {
	deleteAllChatBots,
	ensureChatBot,
	resetChatRerankE2e,
	resetVectorEmbeddingsFailures,
} from './utils';

/**
 * Turn the AI-generated query's e2e switches off and clear the search settings.
 *
 * @returns {Promise<void>}
 */
const resetAiQueryE2e = async () => {
	await wpCliEval(`
		delete_option( 'epl_chat_e2e_register_ai_query' );
		delete_option( 'epl_chat_e2e_search' );
		delete_option( 'ep_feature_settings_draft' );
		$settings = get_option( 'ep_feature_settings', array() );
		unset( $settings['chat']['search_algorithm'], $settings['chat']['search_fallback'] );
		update_option( 'ep_feature_settings', $settings );
	`);
};

test.describe('Chat AI-generated query', { tag: '@chat' }, () => {
	test.beforeEach(async () => {
		await resetVectorEmbeddingsFailures();
		await resetAiQueryE2e();
		await maybeEnableFeature('vector_embeddings');
		await maybeEnableFeature('chat');
	});

	test.afterEach(async () => {
		await resetAiQueryE2e();
		await resetChatRerankE2e();
	});

	test('the Search Algorithm screen hides the AI-generated query; the Chat settings offer it, with the fallback and model', async ({
		loggedInPage,
	}) => {
		await wpCliEval(`update_option( 'epl_chat_e2e_register_ai_query', 1 );`);
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress');

		// Site search: not offered.
		await loggedInPage.getByRole('button', { name: 'Other', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Search Algorithm Version' }).click();

		const siteAlgorithms = loggedInPage.locator('#search_algorithm-view');

		await expect(siteAlgorithms.getByRole('radio')).not.toHaveCount(0);
		await expect(siteAlgorithms.getByLabel(/AI-generated query/)).toHaveCount(0);

		// Chat: offered as a search method. The fallback and the query model apply to the AI search
		// methods: from ElasticPress 5.4.0 they show only for those; before, they are always there.
		const epVersion = String(await wpCliEval('echo EP_VERSION;')).trim();
		const showsOnlyForAi = epVersion.localeCompare('5.4.0', undefined, { numeric: true }) >= 0;

		await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
		await loggedInPage.getByRole('button', { name: 'Chat', exact: true }).click();
		await loggedInPage.getByRole('button', { name: /^Chat bot defaults/ }).click();

		await expect(
			loggedInPage.getByRole('radio', { name: /Same as site search/ }).first(),
		).toBeChecked();
		await expect(loggedInPage.getByRole('radio', { name: /AI-generated query/ })).toHaveCount(
			1,
		);
		await expect(loggedInPage.getByText('Fallback search', { exact: true })).toHaveCount(
			showsOnlyForAi ? 0 : 1,
		);
		await expect(loggedInPage.getByRole('combobox', { name: 'Query model' })).toHaveCount(
			showsOnlyForAi ? 0 : 1,
		);

		await loggedInPage.getByRole('radio', { name: /AI-generated query/ }).check();

		await expect(loggedInPage.getByText('Fallback search', { exact: true })).toBeVisible();
		await expect(loggedInPage.getByRole('radio', { name: /^Automatic/ })).toBeChecked();
		await expect(loggedInPage.getByRole('radio', { name: /^None/ })).toBeVisible();
		await expect(loggedInPage.getByRole('combobox', { name: 'Query model' })).toHaveCount(1);
	});

	test('a chat bot shows the fallback and the query model only for an AI search method', async ({
		loggedInPage,
	}) => {
		await wpCliEval(`update_option( 'epl_chat_e2e_register_ai_query', 1 );`);
		await deleteAllChatBots();
		await ensureChatBot('Sync', 'sync');
		await goToAdminPage(loggedInPage, 'admin.php?page=elasticpress-chat-bots');

		await loggedInPage.getByRole('button', { name: /^Sync/ }).click();
		await loggedInPage.getByRole('button', { name: /^Search/ }).click();

		const method = loggedInPage
			.locator('.ep-chat-bot-field')
			.filter({ hasText: 'Search method' });

		await expect(method).toContainText('Site default: Same as site search');
		await expect(loggedInPage.getByText('Fallback search', { exact: true })).toHaveCount(0);

		await method.getByRole('button', { name: 'Change' }).click();
		await method.getByRole('radio', { name: /AI-generated query/ }).check();

		await expect(loggedInPage.getByText('Fallback search', { exact: true })).toBeVisible();
		await expect(loggedInPage.getByText('Query model', { exact: true })).toBeVisible();
		await expect(
			loggedInPage
				.locator('.ep-chat-bot-field')
				.filter({ hasText: 'Query model' })
				.getByText('same as the question model'),
		).toBeVisible();

		// The site search does not use them.
		await method.getByRole('radio', { name: /Same as site search/ }).check();
		await expect(loggedInPage.getByText('Fallback search', { exact: true })).toHaveCount(0);
	});

	test('Ask shows how an answer was made, and a note when the fallback search ran', async ({
		loggedInPage,
	}) => {
		await deleteAllChatBots();
		await ensureChatBot('Sync', 'sync');

		await wpCliEval(`
			update_option( 'epl_chat_e2e_register_ai_query', 1 );
			$post_id = wp_insert_post(
				array(
					'post_title'   => 'ElasticPress Guide',
					'post_content' => 'ElasticPress is a free WordPress search plugin.',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			);
			update_option( 'epl_chat_e2e_retrieve_ids', array( (int) $post_id ) );
			update_option( 'epl_chat_e2e_fake_llm', 1 );
			update_option(
				'epl_chat_e2e_search',
				array(
					'algorithm'           => '3.5',
					'requested_algorithm' => 'ai_query',
					'fallback'            => 'zero_hits',
					'generated_query'     => array( 'query' => array( 'match' => array( 'post_title' => 'zebrafinch' ) ) ),
				)
			);
		`);

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat');

		const ask = async (question) => {
			await loggedInPage.getByRole('textbox', { name: 'Question' }).fill(question);
			await loggedInPage.getByRole('button', { name: 'Ask', exact: true }).click();
			await expect(loggedInPage.locator('.ep-ai-chat-message--loading')).toHaveCount(0);
		};

		await ask('Is ElasticPress free?');
		await expect(loggedInPage.getByText('Mocked grounded answer.')).toBeVisible();

		// The fallback note is on the answer; the details are behind the toggle.
		const answer = loggedInPage.locator('.ep-ai-chat-message--assistant').last();

		await expect(answer.locator('.ep-ai-chat-message__fallback')).toHaveText(
			'We used a simpler search for this question, so the answer may be less precise.',
		);
		await expect(answer.locator('.ep-ai-chat-message__details')).toBeHidden();

		await answer.getByRole('button', { name: 'Show how this answer was made' }).click();

		await expect(answer.locator('.ep-ai-chat-message__search-method')).toHaveText(
			'Search method: Version 3.5 (instead of AI-generated query)',
		);

		const technical = answer.locator('.ep-ai-chat-message__technical');

		await expect(technical.locator('dl')).toBeHidden();
		await technical.getByText('Technical details').click();
		await expect(technical).toContainText('The AI-generated query found nothing.');
		await expect(technical.locator('.ep-ai-chat-message__query')).toContainText(
			'"post_title": "zebrafinch"',
		);

		// The AI query ran: no note.
		await wpCliEval(`
			update_option(
				'epl_chat_e2e_search',
				array(
					'algorithm'       => '3.5',
					'generated_query' => array( 'query' => array( 'match' => array( 'post_title' => 'guide' ) ) ),
				)
			);
		`);

		await ask('Is it free?');
		await expect(loggedInPage.locator('.ep-ai-chat-message__fallback')).toHaveCount(1);

		// A restored thread keeps them.
		await loggedInPage.reload();
		await expect(loggedInPage.locator('.ep-ai-chat-message__fallback')).toHaveCount(1);
		await expect(
			loggedInPage.getByRole('button', { name: 'Show how this answer was made' }),
		).toHaveCount(2);

		await wpCliEval(`
			foreach ( (array) get_option( 'epl_chat_e2e_retrieve_ids', array() ) as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
			delete_option( 'epl_chat_e2e_retrieve_ids' );
			delete_option( 'epl_chat_e2e_fake_llm' );
		`);
	});
});
