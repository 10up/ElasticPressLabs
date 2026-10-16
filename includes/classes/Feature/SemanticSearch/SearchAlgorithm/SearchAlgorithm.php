<?php
/**
 * abstract knnSearch algorithm class
 *
 * @since 2.4.0
 * @package elasticpress
 */

namespace ElasticPressLabs\Feature\SemanticSearch\SearchAlgorithm;

/**
 * Abstract knnSearch algorithm class
 */
abstract class SearchAlgorithm extends \ElasticPress\SearchAlgorithm {
	/**
	 * Generate the whole ES query
	 *
	 * @param array     $formatted_args Formatted Elasticsearch query
	 * @param array     $args           The WP_Query variables
	 * @param \WP_Query $query          The WP_Query object
	 * @return array
	 */
	abstract public function get_es_query( $formatted_args, $args, $query ): array;

	/**
	 * Return the Elasticsearch `query` clause.
	 *
	 * @param string $indexable_slug Indexable slug
	 * @param string $search_term    Search term(s)
	 * @param array  $search_fields  Search fields
	 * @param array  $query_vars     Query vars
	 * @return array ES `query`
	 */
	protected function get_raw_query( string $indexable_slug, string $search_term, array $search_fields, array $query_vars ): array {
		add_filter( 'ep_post_formatted_args', [ $this, 'get_es_query' ], 10, 3 );

		$search_algorithm = new \ElasticPress\SearchAlgorithm\Version_400();
		return $search_algorithm->get_raw_query( $indexable_slug, $search_term, $search_fields, $query_vars );
	}

	/**
	 * Given a search term, gets its vector embedding
	 *
	 * @param array  $query_args  The query args
	 * @param string $search_term The search term
	 * @return array
	 */
	public function get_search_term_vector( $query_args, $search_term = '' ) {
		if ( isset( $query_args['ep_vectors'] ) ) {
			return $query_args['ep_vectors'];
		}

		if ( ! empty( $query_args['ep_facet_adding_agg_filters'] ) ) {
			return '{{ep_search_term_vectors_placeholder}}';
		}

		$vector_embeddings = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );
		if ( 'epio' === $vector_embeddings->get_setting( 'ep_embeddings_generator' ) ) {
			return '{{ep_search_term_vectors_placeholder}}';
		}

		$autosuggest = \ElasticPress\Features::factory()->get_registered_feature( 'autosuggest' );
		if ( $autosuggest->is_active() ) {
			$autosuggest_placeholder = apply_filters( 'ep_autosuggest_query_placeholder', 'ep_autosuggest_placeholder' );
			if ( $autosuggest_placeholder === $search_term ) {
				return '{{ep_search_term_vectors_placeholder}}';
			}
		}

		$search_term_vector = $vector_embeddings->generate_embedding( $search_term );
		if ( is_wp_error( $search_term_vector ) ) {
			return [];
		}

		return array_map( 'floatval', $search_term_vector );
	}

	/**
	 * `inner_hits` for the `chunks` field: the best matching chunks of each post, with
	 * their text and type (never their vectors).
	 *
	 * @since 2.6.0
	 * @return array
	 */
	protected function get_chunks_inner_hits(): array {
		/**
		 * Filter how many matching chunks are returned per post.
		 *
		 * @hook ep_semantic_search_matched_chunks
		 * @since 2.6.0
		 * @param {int} $size Matching chunks per post. Default 3.
		 * @return {int} New size.
		 */
		$size = (int) apply_filters( 'ep_semantic_search_matched_chunks', 3 );

		return [
			'size'    => max( 1, $size ),
			'_source' => [ 'chunks.text', 'chunks.type' ],
		];
	}

	/**
	 * Whether the top-level `knn` search can return `inner_hits` (Elasticsearch 8.11+).
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	protected function supports_knn_inner_hits(): bool {
		return version_compare( (string) \ElasticPress\Elasticsearch::factory()->get_elasticsearch_version(), '8.11', '>=' );
	}
}
