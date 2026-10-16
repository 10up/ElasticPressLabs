<?php
/**
 * A search algorithm whose query a model writes for each question.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * A search algorithm for Chat that writes what it searches with for each question, before the
 * search (for example, AI_Query: a model writes the whole Elasticsearch query).
 *
 * Chat's Retriever, for a search method that implements this:
 *
 * 1. calls write_query(); an error runs the bot's fallback search, with no Elasticsearch request;
 * 2. runs one search with the algorithm, passing what was written in the QUERY_VAR query var (the
 *    algorithm's get_es_query() or get_raw_query() reads it);
 * 3. runs the fallback when the search fails or finds nothing;
 * 4. records what ran for the answer: the algorithm, the `model`, and the written `query`, or the
 *    one the algorithm sets on the WP_Query as RESULT_PROPERTY `query` (what it applied).
 *
 * The algorithm also sets `public $uses_model = true;` (it is then never a fallback, and the Chat
 * screens show the fallback search and the query model for it), and usually `public $hidden =
 * true;` (not offered to site search). Register it in `\ElasticPress\SearchAlgorithms` as any
 * algorithm: it becomes a Chat search method.
 */
interface Query_Writing_Algorithm {
	/**
	 * WP_Query var with what write_query() returned as `query`.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'ep_chat_ai_query';

	/**
	 * WP_Query var with the context of the question: `question`, `bot`, `plan`.
	 *
	 * @var string
	 */
	public const CONTEXT_VAR = 'ep_chat_ai_query_context';

	/**
	 * WP_Query property where the algorithm may report what happened: `query` (what it applied)
	 * and `model`, or `error` (WP_Error: the fallback search runs).
	 *
	 * @var string
	 */
	public const RESULT_PROPERTY = 'ep_chat_ai_query';

	/**
	 * Write what this algorithm searches with for a question.
	 *
	 * @param string $search  What to search for: the question, or its rewrite by question intent.
	 * @param array  $context `question` (as asked), `bot` (selected chat bot), and `plan` (search
	 *                        plan: `published_after`, `recent`).
	 * @return array|\WP_Error `query` (any shape the algorithm reads back from QUERY_VAR) and `model`
	 *                         (`provider::model` that wrote it, or empty); or why nothing can be
	 *                         searched, with the `model` in the error data when known.
	 */
	public function write_query( string $search, array $context );
}
