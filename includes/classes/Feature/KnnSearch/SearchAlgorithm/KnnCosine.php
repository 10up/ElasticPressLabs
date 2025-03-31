<?php
/**
 * kNN search algorithm
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
 * kNN search algorithm class.
 */
class KnnCosine extends SearchAlgorithm {
	/**
	 * Search algorithm slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'knn_cosine';
	}

	/**
	 * Search algorithm name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return esc_html__( 'kNN Cosine', 'elasticpress-labs' );
	}

	/**
	 * Search algorithm description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return esc_html__( 'Search using Elasticsearch kNN Cosine.', 'elasticpress-labs' );
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

		$knn_search_feature = \ElasticPress\Features::factory()->get_registered_feature( 'knn_search' );

		return [
			'from'        => $formatted_args['from'],
			'size'        => $formatted_args['size'],
			'post_filter' => $formatted_args['post_filter'],
			'min_score'   => $knn_search_feature->get_min_score(),
			'query'       => [
				'bool' => [
					'must' => [
						[
							'nested' => [
								'path'  => 'chunks',
								'query' => [
									'script_score' => [
										'query'  => [
											'match_all' => (object) [],
										],
										'script' => [
											'source' => 'cosineSimilarity(params.query_vector, "chunks.vector") + 1.0',
											'params' => [
												'query_vector' => array_map( 'floatval', $query_embedding ),
											],
										],
									],
								],
							],
						],
					],
				],
			],
		];
	}
}
