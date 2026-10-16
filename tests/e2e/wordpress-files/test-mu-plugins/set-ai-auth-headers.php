<?php
/**
 * Plugin Name: Set AI Auth Headers
 * Description: Set the AI Auth Headers for the AI-Related features
 * Version:     1.0.0
 * Author:      10up Inc.
 * License:     GPLv2 or later
 *
 * @package ElasticPress_Tests_E2e
 */

/**
 * Add the Litellm headers to the AI-Related requests
 *
 * @param array $options Current options
 * @return array
 */
function add_litellm_headers( $options ) {
	if ( empty( $options['headers'] ) ) {
		$options['headers'] = [];
	}

	$options['headers']['CF-Access-Client-Id']     = defined( 'CF_ACCESS_CLIENT_ID' ) ? CF_ACCESS_CLIENT_ID : '';
	$options['headers']['CF-Access-Client-Secret'] = defined( 'CF_ACCESS_CLIENT_SECRET' ) ? CF_ACCESS_CLIENT_SECRET : '';

	return $options;
}
add_filter( 'ep_embeddings_options', __NAMESPACE__ . '\add_litellm_headers' );
add_filter( 'ep_ai_search_summary_request_options', __NAMESPACE__ . '\add_litellm_headers' );
add_filter( 'ep_chat_rerank_request_options', __NAMESPACE__ . '\add_litellm_headers' );

/**
 * Optional e2e retrieve override. Empty unless a test sets the option.
 *
 * @param mixed  $pre      Short-circuit value.
 * @param string $question User question.
 * @return mixed
 */
function epl_chat_e2e_retrieve( $pre, $question ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$ids = get_option( 'epl_chat_e2e_retrieve_ids' );

	if ( ! is_array( $ids ) || [] === $ids ) {
		return $pre;
	}

	$posts = [];

	foreach ( $ids as $id ) {
		$post = get_post( (int) $id );

		if ( $post instanceof \WP_Post ) {
			$posts[] = $post;
		}
	}

	return $posts;
}
add_filter( 'ep_chat_retrieve', 'epl_chat_e2e_retrieve', 10, 2 );

/**
 * Optional e2e search outage: Chat's Elasticsearch query is skipped, as if it failed.
 *
 * @param bool      $skip  Whether to skip integration.
 * @param \WP_Query $query Query.
 * @return bool
 */
function epl_chat_e2e_fail_search( $skip, $query ) {
	if ( get_option( 'epl_chat_e2e_fail_search' ) && $query instanceof \WP_Query && $query->get( 'ep_labs_chat' ) ) {
		return true;
	}

	return $skip;
}
add_filter( 'ep_skip_query_integration', 'epl_chat_e2e_fail_search', 10, 2 );

/**
 * Optional e2e claim verifier: `supported`, `unsupported`, or `fail`. Without the option, the
 * verifier is not called in e2e (there is no model to call): it passes.
 *
 * @param mixed $pre Short-circuit value.
 * @return mixed
 */
function epl_chat_e2e_claim_verifier( $pre ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The verifier is always faked in e2e.
	$mode = get_option( 'epl_chat_e2e_verifier' );

	if ( 'fail' === $mode ) {
		return new \WP_Error( 'e2e_verifier_down', 'Fake verifier failure.' );
	}

	return 'unsupported' === $mode ? false : true;
}
add_filter( 'ep_chat_claim_verifier_verdict', 'epl_chat_e2e_claim_verifier' );

/**
 * Optional e2e model list: two fake models (e2e has no AI providers).
 *
 * @param array $models_list Models and errors.
 * @return array
 */
function epl_chat_e2e_models( $models_list ) {
	if ( ! get_option( 'epl_chat_e2e_models' ) ) {
		return $models_list;
	}

	$models_list['models'] = [
		[
			'value'    => 'e2e-ai::e2e-small',
			'label'    => 'E2E AI – e2e-small',
			'provider' => 'e2e-ai',
			'model'    => 'e2e-small',
		],
		[
			'value'    => 'e2e-ai::e2e-large',
			'label'    => 'E2E AI – e2e-large',
			'provider' => 'e2e-ai',
			'model'    => 'e2e-large',
		],
	];

	return $models_list;
}
add_filter( 'ep_chat_models', 'epl_chat_e2e_models' );

/**
 * Optional e2e admin notice, to check where screens place WordPress notices.
 *
 * @return void
 */
function epl_chat_e2e_admin_notice() {
	if ( get_option( 'epl_chat_e2e_admin_notice' ) ) {
		echo '<div class="notice notice-info"><p>E2E admin notice</p></div>';
	}
}
add_action( 'admin_notices', 'epl_chat_e2e_admin_notice' );

/**
 * Optional e2e Chat capability overrides: an array of action => capability.
 *
 * @param array $map Action => capability.
 * @return array
 */
function epl_chat_e2e_capabilities( $map ) {
	$overrides = get_option( 'epl_chat_e2e_capabilities' );

	return is_array( $overrides ) ? array_merge( $map, $overrides ) : $map;
}
add_filter( 'ep_chat_capabilities', 'epl_chat_e2e_capabilities' );

/**
 * Optional e2e LLM short-circuit.
 *
 * @param mixed $pre Short-circuit value.
 * @return mixed
 */
function epl_chat_e2e_generate_text( $pre ) {
	if ( ! get_option( 'epl_chat_e2e_fake_llm' ) ) {
		return $pre;
	}

	$text = get_option( 'epl_chat_e2e_fake_llm_text' );

	return is_string( $text ) && '' !== $text ? $text : 'Mocked grounded answer.';
}
add_filter( 'ep_chat_generate_text', 'epl_chat_e2e_generate_text' );

/**
 * The e2e site fakes every model call, so it has no AI provider: tell Chat it has one, unless a
 * test sets the option to check the "needs an AI provider" state.
 *
 * @return bool
 */
function epl_chat_e2e_ai_available() {
	return ! get_option( 'epl_chat_e2e_no_ai' );
}
add_filter( 'ep_chat_ai_available', 'epl_chat_e2e_ai_available' );

/**
 * Optional e2e conversation summary: a fixed record instead of a model call.
 *
 * @param mixed $pre Short-circuit value.
 * @return mixed
 */
function epl_chat_e2e_summary( $pre ) {
	if ( ! get_option( 'epl_chat_e2e_summary' ) ) {
		return $pre;
	}

	return "The user asked what ElasticPress is and whether it is free.\n\nKeywords: search engine, WordPress plugin, pricing";
}
add_filter( 'ep_chat_summary_pre', 'epl_chat_e2e_summary' );

/**
 * Optional e2e question intent: `epl_chat_e2e_intent` maps a question to the intent reply.
 *
 * @param mixed  $pre      Short-circuit value.
 * @param array  $messages Intent messages.
 * @param string $question User question.
 * @return mixed
 */
function epl_chat_e2e_intent( $pre, $messages, $question ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed
	$replies = get_option( 'epl_chat_e2e_intent' );

	return is_array( $replies ) && isset( $replies[ $question ] ) ? (string) $replies[ $question ] : $pre;
}
add_filter( 'ep_chat_intent_pre', 'epl_chat_e2e_intent', 10, 3 );

/**
 * Optional e2e search record: `epl_chat_e2e_search` is merged into the answer payload's `search`,
 * as the Retriever records it (retrieval is stubbed in e2e, so nothing records it).
 *
 * @param array $payload Answer payload.
 * @return array
 */
function epl_chat_e2e_search( $payload ) {
	$search = get_option( 'epl_chat_e2e_search' );

	if ( is_array( $search ) && is_array( $payload ) ) {
		$payload['search'] = array_merge( is_array( $payload['search'] ?? null ) ? $payload['search'] : array(), $search );
	}

	return $payload;
}
add_filter( 'ep_chat_message_meta', 'epl_chat_e2e_search' );

/**
 * Optional e2e clarifying options: `epl_chat_e2e_clarify` is the clarify reply.
 *
 * @param mixed $pre Short-circuit value.
 * @return mixed
 */
function epl_chat_e2e_clarify( $pre ) {
	$reply = get_option( 'epl_chat_e2e_clarify' );

	return is_string( $reply ) && '' !== $reply ? $reply : $pre;
}
add_filter( 'ep_chat_clarify_pre', 'epl_chat_e2e_clarify' );

/**
 * Optional e2e source note on the first post in `epl_chat_e2e_retrieve_ids`.
 *
 * @param array $notes   Notes.
 * @param int   $post_id Source post ID.
 * @return array
 */
function epl_chat_e2e_notes( $notes, $post_id ) {
	$ids = array_map( 'intval', (array) get_option( 'epl_chat_e2e_retrieve_ids', array() ) );

	if ( get_option( 'epl_chat_e2e_note' ) && $ids && (int) $post_id === $ids[0] ) {
		$notes[] = array(
			'label'       => 'Heads up',
			'description' => 'This source has a note.',
			'text'        => 'A chart label in this source was updated.',
			'tone'        => 'warning',
		);
	}

	return $notes;
}
add_filter( 'ep_chat_source_notes', 'epl_chat_e2e_notes', 10, 2 );

/**
 * Mock Cohere-compatible /rerank for e2e. Reverses document order.
 *
 * @param mixed  $pre  Short-circuit value.
 * @param array  $args Request args.
 * @param string $url  Request URL.
 * @return mixed
 */
function epl_chat_e2e_mock_rerank( $pre, $args, $url ) {
	if ( ! get_option( 'epl_chat_e2e_mock_rerank' ) ) {
		return $pre;
	}

	if ( false === strpos( (string) $url, '/rerank' ) ) {
		return $pre;
	}

	$body      = json_decode( (string) ( $args['body'] ?? '' ), true );
	$documents = is_array( $body['documents'] ?? null ) ? $body['documents'] : [];
	$results   = [];
	$count     = count( $documents );

	for ( $i = $count - 1; $i >= 0; $i-- ) {
		$results[] = [
			'index'           => $i,
			'relevance_score' => 0.9 - ( ( $count - 1 - $i ) * 0.1 ),
		];
	}

	return [
		'headers'  => [],
		'body'     => wp_json_encode( [ 'results' => $results ] ),
		'response' => [
			'code'    => 200,
			'message' => 'OK',
		],
		'cookies'  => [],
		'filename' => null,
	];
}
add_filter( 'pre_http_request', 'epl_chat_e2e_mock_rerank', 10, 3 );

/**
 * Optional mock of the embeddings API for e2e: a fixed vector of the requested size per input,
 * so inserted posts are indexed (with chunks) without an API key.
 *
 * @param mixed  $pre  Short-circuit value.
 * @param array  $args Request args.
 * @param string $url  Request URL.
 * @return mixed
 */
function epl_chat_e2e_mock_embeddings( $pre, $args, $url ) {
	if ( ! get_option( 'epl_chat_e2e_mock_embeddings' ) || false === strpos( (string) $url, '/embeddings' ) ) {
		return $pre;
	}

	$body       = json_decode( (string) ( $args['body'] ?? '' ), true );
	$inputs     = (array) ( $body['input'] ?? [] );
	$dimensions = max( 1, (int) ( $body['dimensions'] ?? 512 ) );
	$data       = [];

	foreach ( array_values( $inputs ) as $index => $input ) {
		$data[] = [
			'index'     => $index,
			'embedding' => array_fill( 0, $dimensions, 0.1 ),
		];
	}

	return [
		'headers'  => [ 'content-type' => 'application/json' ],
		'body'     => wp_json_encode( [ 'data' => $data ] ),
		'response' => [
			'code'    => 200,
			'message' => 'OK',
		],
		'cookies'  => [],
		'filename' => null,
	];
}
add_filter( 'pre_http_request', 'epl_chat_e2e_mock_embeddings', 10, 3 );
