import { readFileSync } from 'node:fs';
import {
	goToAdminPage,
	maybeEnableFeature,
	test,
	expect,
	wpCliEval,
	maybeDisableFeature,
} from 'elasticpress-playwright-utils';
import {
	deleteAllChatBots,
	deleteAllChatConversations,
	ensureChatBot,
	hasAiClient,
	resetChatRerankE2e,
	resetVectorEmbeddingsFailures,
} from './utils';

/**
 * Store a conversation as the admin user and return its ID.
 *
 * @param {string[]} questions Questions. The first starts the conversation.
 * @returns {Promise<number>}
 */
const seedConversation = async (questions) => {
	const list = questions.map((question) => `'${question.replace(/'/g, "\\'")}'`).join(', ');
	const output = await wpCliEval(`
		wp_set_current_user( 1 );
		$storage    = new \\ElasticPressLabs\\Feature\\Chat\\Storage();
		$ep_chat_id = 0;
		foreach ( array( ${list} ) as $question ) {
			$accepted   = $storage->accept_question( $question, $ep_chat_id );
			$ep_chat_id = (int) $accepted['ep_chat_id'];
			$storage->complete_question( $ep_chat_id, $accepted['question_id'], 'Answer to ' . $question );
		}
		echo 'ep_chat_id=' . $ep_chat_id;
	`);

	return Number(String(output).match(/ep_chat_id=(\d+)/)?.[1]);
};

/**
 * Chat ID in the current Ask URL.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @returns {string|null}
 */
const chatIdInUrl = (page) => new URL(page.url()).searchParams.get('chat');

test.describe('Chat History', { tag: '@chat' }, () => {
	// Chat needs the AI client from WordPress 7.0. The e2e mu-plugin fakes it being available,
	// so on older versions these would test a setup no real site can reach.
	test.beforeAll(async () => {
		test.skip(!(await hasAiClient()), 'Chat needs WordPress 7.0');
	});

	// Specs that run after these expect Vector Embeddings off: with it on, their syncs embed
	// every post through the real service.
	test.afterAll(async () => {
		await resetChatRerankE2e();
		await maybeDisableFeature('chat');
		await maybeDisableFeature('vector_embeddings');
	});

	test.beforeEach(async () => {
		await resetVectorEmbeddingsFailures();
		await maybeEnableFeature('vector_embeddings');
		await maybeEnableFeature('chat');
		await deleteAllChatConversations();
	});

	test('Follow-ups stay in one conversation, and New chat starts another', async ({
		loggedInPage,
	}) => {
		await resetChatRerankE2e();
		await deleteAllChatBots();
		await ensureChatBot('Sync', 'sync');

		await wpCliEval(`
			$post_id = wp_insert_post(
				array(
					'post_title'   => 'ElasticPress Guide',
					'post_content' => 'ElasticPress is a WordPress search plugin.',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			);
			update_option( 'epl_chat_e2e_retrieve_ids', array( (int) $post_id ) );
			update_option( 'epl_chat_e2e_fake_llm', 1 );
		`);

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat');

		const question = loggedInPage.getByRole('textbox', { name: 'Question' });
		const ask = loggedInPage.getByRole('button', { name: 'Ask', exact: true });
		const answers = loggedInPage.getByText('Mocked grounded answer.');

		await question.fill('What is ElasticPress?');
		await ask.click();
		await expect(answers).toHaveCount(1);

		const epChatId = chatIdInUrl(loggedInPage);
		expect(epChatId).toBeTruthy();

		// A thread collapses Previous questions; a manual open is remembered on reload.
		await expect(
			loggedInPage.getByRole('button', { name: 'Show previous questions' }),
		).toHaveAttribute('aria-expanded', 'false');
		await loggedInPage.getByRole('button', { name: 'Show previous questions' }).click();
		await expect(loggedInPage.locator('.ep-ai-chat-item[aria-current="page"]')).toContainText(
			'What is ElasticPress?',
		);

		// Ctrl+Enter sends the question.
		await question.fill('Is it free?');
		await question.press('Control+Enter');
		await expect(answers).toHaveCount(2);
		expect(chatIdInUrl(loggedInPage)).toBe(epChatId);

		await loggedInPage.reload();
		await expect(
			loggedInPage.getByRole('button', { name: 'Hide previous questions' }),
		).toHaveAttribute('aria-expanded', 'true');
		await expect(
			loggedInPage.locator('.ep-ai-chat-thread').getByText('What is ElasticPress?'),
		).toBeVisible();
		await expect(loggedInPage.getByText('Is it free?')).toBeVisible();
		await expect(answers).toHaveCount(2);

		await loggedInPage.getByRole('button', { name: 'New chat' }).click();
		await expect(
			loggedInPage.locator('.ep-ai-chat-thread').getByText('What is ElasticPress?'),
		).toHaveCount(0);
		expect(chatIdInUrl(loggedInPage)).toBeNull();

		await question.fill('A new topic?');
		await ask.click();
		await expect(answers).toHaveCount(1);
		expect(chatIdInUrl(loggedInPage)).not.toBe(epChatId);

		await resetChatRerankE2e();
	});

	test('A superseded late answer stays hidden in the thread', async ({ loggedInPage }) => {
		const output = await wpCliEval(`
			wp_set_current_user( 1 );
			$storage = new \\ElasticPressLabs\\Feature\\Chat\\Storage();
			$first   = $storage->accept_question( 'Slow question?' );
			$storage->claim_question( $first['question_id'] );
			$second = $storage->accept_question( 'Slow question, again?', $first['ep_chat_id'] );
			$storage->complete_question( $first['ep_chat_id'], $second['question_id'], 'Retried answer.' );
			$storage->complete_question( $first['ep_chat_id'], $first['question_id'], 'Late answer.' );
			echo 'ep_chat_id=' . (int) $first['ep_chat_id'];
		`);
		const epChatId = String(output).match(/ep_chat_id=(\d+)/)?.[1];

		await goToAdminPage(loggedInPage, `admin.php?page=ep-ai-chat&chat=${epChatId}`);

		await expect(
			loggedInPage.getByText('Slow question, again?', { exact: true }),
		).toBeVisible();
		await expect(loggedInPage.getByText('Retried answer.')).toBeVisible();
		await expect(loggedInPage.getByText('Stopped waiting for this answer.')).toBeVisible();
		await expect(loggedInPage.getByText('Late answer.')).toHaveCount(0);
	});

	test('History lists, searches, and opens conversations', async ({ loggedInPage }) => {
		const phones = await seedConversation(['Who owns a smartphone?', 'And a tablet, by age?']);
		await seedConversation(['How do adults get news?']);

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat-history');

		await expect(loggedInPage.getByRole('heading', { name: 'History' })).toBeVisible();

		const rows = loggedInPage.locator('#the-list tr');

		await expect(rows).toHaveCount(2);
		await expect(rows.filter({ hasText: 'Who owns a smartphone?' })).toContainText('Answered');
		await expect(
			rows.filter({ hasText: 'Who owns a smartphone?' }).locator('.column-questions'),
		).toHaveText('2');

		await loggedInPage.getByLabel('Search questions').fill('tablet');
		await loggedInPage.getByRole('button', { name: 'Search questions' }).click();
		await expect(rows).toHaveCount(1);
		await expect(rows).toContainText('Who owns a smartphone?');

		await loggedInPage.getByRole('link', { name: 'Who owns a smartphone?' }).click();
		await expect(loggedInPage).toHaveURL(new RegExp(`page=ep-ai-chat&chat=${phones}`));
		await expect(
			loggedInPage.getByText('And a tablet, by age?', { exact: true }),
		).toBeVisible();

		// Ask lists the user's conversations, newest first, and links to History.
		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat');
		await expect(loggedInPage.locator('.ep-ai-chat-item__title')).toHaveText([
			'How do adults get news?',
			'Who owns a smartphone?',
		]);
		await loggedInPage.getByRole('link', { name: 'View all' }).click();
		await expect(loggedInPage).toHaveURL(/page=ep-ai-chat-history/);
		await expect(rows).toHaveCount(2);
	});

	test('Previous questions searches the conversations and links the search to History', async ({
		loggedInPage,
	}) => {
		const phones = await seedConversation(['Who owns a smartphone?', 'And a tablet, by age?']);
		await seedConversation(['How do adults get news?']);

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat');

		const titles = loggedInPage.locator('.ep-ai-chat-previous .ep-ai-chat-item__title');
		const search = loggedInPage.getByRole('searchbox', { name: 'Search previous questions' });

		await expect(titles).toHaveCount(2);

		// A follow-up question matches its conversation.
		await search.fill('tablet');
		await expect(titles).toHaveText(['Who owns a smartphone?']);
		await expect(
			loggedInPage.locator('.ep-ai-chat-previous').getByRole('link', { name: 'View all' }),
		).toHaveAttribute('href', /[?&]s=tablet(&|$)/);

		await search.fill('nothing matches this');
		await expect(loggedInPage.getByText('No questions match your search.')).toBeVisible();
		await expect(titles).toHaveCount(0);

		// Clearing the search lists the latest conversations again.
		await search.fill('');
		await expect(titles).toHaveCount(2);

		await search.fill('tablet');
		await loggedInPage.getByRole('link', { name: 'Who owns a smartphone?' }).click();
		await expect(loggedInPage).toHaveURL(new RegExp(`page=ep-ai-chat&chat=${phones}`));
	});

	test('History moves conversations to the Trash, restores, and deletes them', async ({
		loggedInPage,
	}) => {
		const epChatId = await seedConversation(['Please trash me?']);

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat-history');

		const row = loggedInPage.locator('#the-list tr', { hasText: 'Please trash me?' });

		await row.hover();
		await row.getByRole('link', { name: 'Trash', exact: true }).click();
		await expect(loggedInPage.getByText('1 conversation moved to the Trash.')).toBeVisible();
		await expect(loggedInPage.locator('#the-list')).toContainText('No questions yet');

		await goToAdminPage(loggedInPage, `admin.php?page=ep-ai-chat&chat=${epChatId}`);
		await expect(loggedInPage.locator('.components-notice__content')).toContainText(
			'That conversation is not available.',
		);
		expect(chatIdInUrl(loggedInPage)).toBeNull();

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat-history&post_status=trash');
		await row.hover();
		await row.getByRole('link', { name: 'Restore' }).click();
		await expect(
			loggedInPage.getByText('1 conversation restored from the Trash.'),
		).toBeVisible();
		await expect(row).toBeVisible();

		await loggedInPage.locator(`#ep-chat-${epChatId}`).check();
		await loggedInPage.locator('#bulk-action-selector-top').selectOption('trash');
		await loggedInPage.locator('#doaction').click();
		await expect(loggedInPage.getByText('1 conversation moved to the Trash.')).toBeVisible();

		await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat-history&post_status=trash');
		await row.hover();
		await row.getByRole('link', { name: 'Delete permanently' }).click();
		await expect(loggedInPage.getByText('1 conversation permanently deleted.')).toBeVisible();

		const exists = await wpCliEval(`echo get_post( ${epChatId} ) ? 'yes' : 'no';`);
		expect(String(exists)).toContain('no');
	});

	test('A conversation summary is searchable, and Export downloads the listed conversations as JSON', async ({
		loggedInPage,
	}) => {
		await resetChatRerankE2e();
		await deleteAllChatBots();
		await ensureChatBot('Sync', 'sync');

		await wpCliEval(`
			$post_id = wp_insert_post(
				array(
					'post_title'   => 'ElasticPress Guide',
					'post_content' => 'ElasticPress is a WordPress search plugin.',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			);
			update_option( 'epl_chat_e2e_retrieve_ids', array( (int) $post_id ) );
			update_option( 'epl_chat_e2e_fake_llm', 1 );
			update_option( 'epl_chat_e2e_summary', 1 );
			$settings = get_option( 'ep_feature_settings', array() );
			$settings['chat']['summary_enabled'] = '1';
			update_option( 'ep_feature_settings', $settings );
		`);
		// Summaries stay on for this test only: turned off even when an assertion fails, so later
		// tests start from the defaults.
		try {
			await seedConversation(['How do adults get news?']);

			// Asking writes the summary after the answer.
			await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat');
			await loggedInPage
				.getByRole('textbox', { name: 'Question' })
				.fill('What is ElasticPress?');
			await loggedInPage.getByRole('button', { name: 'Ask', exact: true }).click();
			await expect(loggedInPage.getByText('Mocked grounded answer.')).toHaveCount(1);

			// "search engine" is only in the summary.
			await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat-history&s=search+engine');

			const rows = loggedInPage.locator('#the-list tr');

			await expect(rows).toHaveCount(1);
			await expect(rows).toContainText('What is ElasticPress?');

			// Export follows the search.
			const [download] = await Promise.all([
				loggedInPage.waitForEvent('download'),
				loggedInPage.locator('.wrap .page-title-action', { hasText: 'Export' }).click(),
			]);

			expect(download.suggestedFilename()).toMatch(
				/^ai-chat-export-.+-\d{4}-\d\d-\d\d\.json$/,
			);

			const data = JSON.parse(readFileSync(await download.path(), 'utf8'));

			expect(data.schema_version).toBe(1);
			expect(data.filters.search).toBe('search engine');
			expect(data.count).toBe(1);
			expect(data.conversations[0].title).toBe('What is ElasticPress?');
			expect(data.conversations[0].summary.keywords).toContain('search engine');
			expect(data.conversations[0].turns.map((turn) => turn.role)).toEqual([
				'user',
				'assistant',
			]);
			expect(data.conversations[0].turns[1].text).toBe('Mocked grounded answer.');

			// Without the "see everyone's questions" capability: no button, and the URL is refused.
			const exportUrl = await loggedInPage
				.locator('.wrap .page-title-action', { hasText: 'Export' })
				.getAttribute('href');

			await wpCliEval(
				`update_option( 'epl_chat_e2e_capabilities', array( 'others' => 'do_not_allow' ) );`,
			);
			await goToAdminPage(loggedInPage, 'admin.php?page=ep-ai-chat-history');
			await expect(
				loggedInPage.locator('.wrap .page-title-action', { hasText: 'Export' }),
			).toHaveCount(0);

			const refused = await loggedInPage.request.get(exportUrl);

			expect(refused.status()).toBe(403);
		} finally {
			await wpCliEval(`
				$settings = get_option( 'ep_feature_settings', array() );
				$settings['chat']['summary_enabled'] = '0';
				update_option( 'ep_feature_settings', $settings );
			`);
			await resetChatRerankE2e();
		}
	});
});
