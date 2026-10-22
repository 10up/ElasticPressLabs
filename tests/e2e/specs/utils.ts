// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { Page } from '@playwright/test';
import { defaultFeatures, wpCliEval } from 'elasticpress-playwright-utils';

export const setVectorEmbeddingsSettings = async (loggedInPage: Page) => {
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
};

export const setEpLabsDefaultFeatures = async () => {
	const epLabsDefaultFeatures = {
		...defaultFeatures,
		search_algorithm: {
			active: false,
			search_algorithm_version: '4.0',
		},
	};

	await wpCliEval(
		`
		update_option( 'ep_feature_settings', json_decode( '${JSON.stringify(epLabsDefaultFeatures)}', true ) );
		`,
	);
};

export const deleteAllChatBots = async () => {
	await wpCliEval(
		`
		if ( ! post_type_exists( 'ep_chat_bot' ) ) {
			( new \\ElasticPressLabs\\Feature\\Chat\\Bots() )->register_post_type();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'ep_chat_bot',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		`,
	);
};

export const ensureDefaultChatBot = async () => {
	await wpCliEval(
		`
		$bots = new \\ElasticPressLabs\\Feature\\Chat\\Bots();
		$bots->ensure_default();
		`,
	);
};

export const ensureChatBot = async (
	name = 'E2E Bot',
	mode = 'sync',
	settings: { rerankEnabled?: boolean; rerankFloor?: string | number } = {},
) => {
	const safeName = name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
	const rerankEnabled = settings.rerankEnabled ? 'true' : 'false';
	const rerankFloor =
		settings.rerankFloor === undefined || settings.rerankFloor === null
			? ''
			: String(settings.rerankFloor).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

	await wpCliEval(
		`
		if ( ! post_type_exists( 'ep_chat_bot' ) ) {
			( new \\ElasticPressLabs\\Feature\\Chat\\Bots() )->register_post_type();
		}
		$bots = new \\ElasticPressLabs\\Feature\\Chat\\Bots();
		$settings = array(
			'rerank_enabled' => ${rerankEnabled},
			'rerank_floor'   => '${rerankFloor}',
		);
		$existing = get_posts(
			array(
				'post_type'      => 'ep_chat_bot',
				'title'          => '${safeName}',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			)
		);
		if ( empty( $existing ) ) {
			$bots->create( '${safeName}', '${mode}', $settings );
		} else {
			$bots->update( (int) $existing[0]->ID, '${safeName}', '${mode}', $settings );
		}
		`,
	);
};

/**
 * Whether WordPress has the AI client Chat needs (WordPress 7.0+). Throws when WP-CLI does not
 * answer, so a failed call is not read as "no" and the Chat specs are not skipped by mistake.
 *
 * @returns {Promise<boolean>}
 */
export const hasAiClient = async () => {
	const output = String(
		await wpCliEval(
			`echo function_exists( 'wp_ai_client_prompt' ) ? 'ai-client:yes' : 'ai-client:no';`,
		),
	);

	if (!output.includes('ai-client:yes') && !output.includes('ai-client:no')) {
		throw new Error(`Could not check for the WordPress AI client. WP-CLI said: ${output}`);
	}

	return output.includes('ai-client:yes');
};

export const resetChatRerankE2e = async () => {
	await wpCliEval(
		`
		delete_option( 'epl_chat_e2e_retrieve_ids' );
		delete_option( 'epl_chat_e2e_fake_llm' );
		delete_option( 'epl_chat_e2e_fake_llm_text' );
		delete_option( 'epl_chat_e2e_mock_embeddings' );
		delete_option( 'epl_chat_e2e_mock_rerank' );
		delete_option( 'epl_chat_e2e_fail_search' );
		delete_option( 'epl_chat_e2e_verifier' );
		delete_option( 'epl_chat_e2e_capabilities' );
		delete_option( 'epl_chat_e2e_models' );
		delete_option( 'epl_chat_e2e_admin_notice' );
		delete_option( 'epl_chat_e2e_summary' );
		delete_option( 'epl_chat_e2e_note' );
		delete_option( 'epl_chat_e2e_intent' );
		delete_option( 'epl_chat_e2e_clarify' );
		`,
	);
};

/**
 * Reset the Vector Embeddings failure counter.
 *
 * Chat requires Vector Embeddings. Posts that Chat tests insert trigger
 * embedding calls, which fail without API credentials. After a few
 * failures Vector Embeddings turns itself off, and Chat goes with it.
 */
export const resetVectorEmbeddingsFailures = async () => {
	await wpCliEval(
		`
		\\ElasticPress\\Features::factory()->get_registered_feature( 'vector_embeddings' )->reset_failures_count();
		`,
	);
};

/**
 * Permanently delete every Chat conversation, including trashed ones.
 */
export const deleteAllChatConversations = async () => {
	await wpCliEval(
		`
		$ids = get_posts(
			array(
				'post_type'      => 'ep_chat',
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		`,
	);
};
