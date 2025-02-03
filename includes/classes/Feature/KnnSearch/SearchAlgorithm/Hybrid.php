<?php
/**
 * Hybrid search algorithm
 *
 * @since 2.4.0
 * @package elasticpress
 */

namespace ElasticPressLabs\Feature\KnnSearch\SearchAlgorithm;

if ( ! defined( 'ABSPATH' ) ) {
	// @codeCoverageIgnoreStart
	exit; // Exit if accessed directly.
	// @codeCoverageIgnoreEnd
}

/**
 * Hybrid search algorithm class.
 */
class Hybrid extends SearchAlgorithm {
	/**
	 * Search algorithm slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'hybrid_knn';
	}

	/**
	 * Search algorithm name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return esc_html__( 'Hybrid (kNN + Regular ES)', 'elasticpress-labs' );
	}

	/**
	 * Search algorithm description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return esc_html__( 'Search using a mix of Elasticsearch kNN and a regular query.', 'elasticpress-labs' );
	}

	/**
	 * Return the whole ES query
	 *
	 * @param array     $formatted_args Formatted Elasticsearch query
	 * @param array     $args           The WP_Query variables
	 * @param \WP_Query $query          The WP_Query object
	 * @return array
	 */
	public function get_es_query( $formatted_args, $args, $query ): array {
		if ( ! $query->is_search() ) {
			return $formatted_args;
		}

		$query_embedding = $this->get_search_term_vector( $query->query_vars['s'] );
		if ( empty( $query_embedding ) ) {
			return $formatted_args;
		}

		return [
			'from'        => $formatted_args['from'],
			'size'        => $formatted_args['size'],
			'post_filter' => $formatted_args['post_filter'],
			'query'       => $formatted_args['query'],
			'knn'         => [
				'field'          => 'chunks.vector',
				'query_vector'   => array_map( 'floatval', $query_embedding ),
				'num_candidates' => 200,
				'k'              => (int) $formatted_args['size'],
			],
		];
	}
}
