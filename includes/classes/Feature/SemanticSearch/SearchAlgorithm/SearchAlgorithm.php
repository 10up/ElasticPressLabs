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
	 * @param string $search_term The search term
	 * @return array
	 */
	public function get_search_term_vector( $search_term ) {
		$vector_embeddings = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );

		$search_term_vector = $vector_embeddings->generate_embedding( $search_term );
		if ( is_wp_error( $search_term_vector ) ) {
			return [];
		}

		return $search_term_vector;
	}
}
