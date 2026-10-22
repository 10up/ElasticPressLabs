<?php
/**
 * AI-generated query search algorithm.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\SemanticSearch\SearchAlgorithm\SearchAlgorithm;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * A model writes the query (see Query_Generator); code runs it inside the search ElasticPress
 * built, so ElasticPress's post filter (post types, status, dates), size, and returned fields
 * stay. A kNN search gets the question's vector, the matching chunks as inner hits (so passages
 * keep their best chunks), and the post filter, so it only picks posts that can be found.
 *
 * Chat writes the query before the search (to fall back without a wasted request) and passes it
 * in the `ep_chat_ai_query` query var; without it, the query is written here. What happened is set
 * on the WP_Query as `ep_chat_ai_query`: the `query` that ran and the `model`, or the `error`.
 *
 * Hidden from the Search Algorithm feature: it calls a model for every search, so it is for Chat
 * only.
 */
class AI_Query extends SearchAlgorithm implements Query_Writing_Algorithm {
	/**
	 * Algorithm slug.
	 *
	 * @var string
	 */
	public const SLUG = 'ai_query';

	/**
	 * Not listed on the Search Algorithm screen.
	 *
	 * @var bool
	 */
	public $hidden = true;

	/**
	 * Calls a model: never a fallback for another algorithm.
	 *
	 * @var bool
	 */
	public $uses_model = true;

	/**
	 * Register the algorithm where it can run: Semantic Search on (the index has chunk vectors,
	 * and their matched chunks reach the posts) and Elasticsearch 8 or later.
	 *
	 * @return bool Whether it was registered.
	 */
	public static function register(): bool {
		$semantic_search = \ElasticPress\Features::factory()->get_registered_feature( 'semantic_search' );

		if ( ! $semantic_search || ! $semantic_search->is_active() ) {
			return false;
		}

		if ( version_compare( (string) \ElasticPress\Elasticsearch::factory()->get_elasticsearch_version(), '8.0', '<' ) ) {
			return false;
		}

		\ElasticPress\SearchAlgorithms::factory()->register( new self() );

		return true;
	}

	/**
	 * Search algorithm slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * Search algorithm name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return esc_html__( 'AI-generated query', 'elasticpress-labs' );
	}

	/**
	 * Search algorithm description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return esc_html__( 'A model reads the question and the search index, and writes the Elasticsearch query.', 'elasticpress-labs' );
	}

	/**
	 * Write the query for a question (see Query_Generator): `query` (checked: `query`, `knn`,
	 * `sort`) and `model`.
	 *
	 * @param string $search  What to search for.
	 * @param array  $context `question`, `bot`, `plan`.
	 * @return array|\WP_Error
	 */
	public function write_query( string $search, array $context ) {
		return ( new Query_Generator() )->generate( $search, $context );
	}

	/**
	 * Run the written query inside the search ElasticPress built.
	 *
	 * The hook is removed first: it was added for this query only (see get_raw_query()).
	 *
	 * @param array     $formatted_args Elasticsearch query ElasticPress built.
	 * @param array     $args           WP_Query vars.
	 * @param \WP_Query $query          WP_Query.
	 * @return array
	 */
	public function get_es_query( $formatted_args, $args, $query ): array {
		remove_filter( 'ep_post_formatted_args', [ $this, 'get_es_query' ], 10 );

		if ( ! $query instanceof \WP_Query || ! $query->is_search() ) {
			return $formatted_args;
		}

		$written = $args[ self::QUERY_VAR ] ?? null;
		$model   = '';

		if ( ! is_array( $written ) ) {
			$context = is_array( $args[ self::CONTEXT_VAR ] ?? null ) ? $args[ self::CONTEXT_VAR ] : [];
			$result  = $this->write_query( (string) ( $query->query_vars['s'] ?? '' ), $context );

			if ( is_wp_error( $result ) ) {
				$query->{self::RESULT_PROPERTY} = [ 'error' => $result ];

				return $formatted_args;
			}

			$written = $result['query'];
			$model   = $result['model'];
		}

		$body = $this->wrap( $formatted_args, $written, $args, (string) ( $query->query_vars['s'] ?? '' ) );

		if ( is_wp_error( $body ) ) {
			$query->{self::RESULT_PROPERTY} = [ 'error' => $body ];

			return $formatted_args;
		}

		// Record what applies: a sort without a topic filter is left out of the search.
		if ( isset( $written['sort'] ) && ! isset( $body['sort'] ) ) {
			unset( $written['sort'] );
		}

		$query->{self::RESULT_PROPERTY} = [
			'query' => $written,
			'model' => $model,
		];

		return $body;
	}

	/**
	 * The search ElasticPress built, with the written query, kNN search, and sort.
	 *
	 * - `query` replaces ElasticPress's text query; without one, there is no `query`.
	 * - `sort` replaces ElasticPress's sort, with the score last to break ties, only when the
	 *   written query has a term filter (see has_filter()). A sort on a broad match puts loosely
	 *   matching posts first; without it, results are in score order.
	 * - `knn` gets the question's vector, `k` of at least the page size, the matching chunks as
	 *   inner hits, ElasticPress's post filter, and the filters of the written query (a kNN search
	 *   picks its posts before the post filter applies, and never reads the query's filters).
	 * - A kNN search alone gets Semantic Search's minimum score, as the kNN algorithm does. Text
	 *   scores have no fixed scale, and filter-only queries score 0, so no minimum is set when a
	 *   query is written.
	 *
	 * @param array  $formatted_args Elasticsearch query ElasticPress built.
	 * @param array  $written        Checked query: `query`, `knn`, `sort`.
	 * @param array  $args           WP_Query vars.
	 * @param string $search         Search text (for the vector).
	 * @return array|\WP_Error
	 */
	public function wrap( array $formatted_args, array $written, array $args, string $search ) {
		$body = $formatted_args;

		unset( $body['query'], $body['sort'], $body['knn'], $body['min_score'] );

		if ( isset( $written['query'] ) ) {
			$body['query'] = $written['query'];
		}

		if ( isset( $written['sort'] ) && $this->has_filter( $written['query'] ?? [] ) ) {
			$body['sort'] = $written['sort'];

			if ( ! in_array( '_score', array_map( 'key', $body['sort'] ), true ) ) {
				$body['sort'][] = [ '_score' => [ 'order' => 'desc' ] ];
			}
		}

		if ( isset( $written['knn'] ) ) {
			$vector = $this->get_search_term_vector( $args, $search );

			if ( $vector ) {
				$size = max( 1, (int) ( $formatted_args['size'] ?? 10 ) );
				$knn  = $written['knn'];

				$knn['query_vector']   = $vector;
				$knn['k']              = max( $size, (int) ( $knn['k'] ?? 0 ) );
				$knn['num_candidates'] = max( $knn['k'], (int) ( $knn['num_candidates'] ?? 0 ) );

				$filters = array_merge(
					isset( $knn['filter'] ) ? (array) $knn['filter'] : [],
					$this->get_filters( $written['query'] ?? [] ),
					empty( $formatted_args['post_filter'] ) ? [] : [ $formatted_args['post_filter'] ]
				);

				if ( $filters ) {
					$knn['filter'] = $filters;
				}

				if ( $this->supports_knn_inner_hits() ) {
					$knn['inner_hits'] = $this->get_chunks_inner_hits();
				}

				$body['knn'] = $knn;
			}
		}

		if ( ! isset( $body['query'] ) && ! isset( $body['knn'] ) ) {
			return new \WP_Error( 'ep_chat_ai_query_no_vector', __( 'The question could not be turned into a vector for the kNN search.', 'elasticpress-labs' ) );
		}

		if ( isset( $body['knn'] ) && ! isset( $body['query'] ) ) {
			$semantic_search = \ElasticPress\Features::factory()->get_registered_feature( 'semantic_search' );

			if ( $semantic_search ) {
				$body['min_score'] = $semantic_search->get_min_score();
			}
		}

		return $body;
	}

	/**
	 * The filters of a written query, for its kNN search: the top bool's `filter` clauses, and
	 * its `must_not` clauses as one bool. A query that is only a filter clause (term, terms,
	 * range, exists) is one.
	 *
	 * @param array $query Written `query`.
	 * @return array[] Filter clauses.
	 */
	protected function get_filters( array $query ): array {
		if ( isset( $query['bool'] ) && is_array( $query['bool'] ) ) {
			$filters = (array) ( $query['bool']['filter'] ?? [] );

			if ( ! empty( $query['bool']['must_not'] ) ) {
				$filters[] = [ 'bool' => [ 'must_not' => $query['bool']['must_not'] ] ];
			}

			return array_values( $filters );
		}

		return array_intersect_key( $query, array_flip( [ 'term', 'terms', 'range', 'exists' ] ) ) ? [ $query ] : [];
	}

	/**
	 * Whether a written query keeps the results on topic with a filter (a term, a content type; a
	 * date range alone does not), so a sort orders relevant posts.
	 *
	 * @param array $query Written `query`.
	 * @return bool
	 */
	protected function has_filter( array $query ): bool {
		$filters = isset( $query['bool'] ) ? (array) ( $query['bool']['filter'] ?? [] ) : [ $query ];

		foreach ( $filters as $filter ) {
			if ( is_array( $filter ) && array_intersect_key( $filter, array_flip( [ 'term', 'terms', 'exists' ] ) ) ) {
				return true;
			}
		}

		return false;
	}
}
