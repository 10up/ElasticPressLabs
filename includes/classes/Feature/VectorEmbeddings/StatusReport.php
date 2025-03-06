<?php
/**
 * Vector Embeddings Status Report
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

use ElasticPress\StatusReport\Report;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Vector Embeddings feature
 */
class StatusReport extends Report {

	/**
	 * Return the report title
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'ElasticPress.io Vector Embeddings', 'elasticpress-labs' );
	}

	/**
	 * Return the report fields
	 *
	 * @return array
	 */
	public function get_groups(): array {
		return [
			[
				'title'  => __( 'Vector Embeddings', 'elasticpress-labs' ),
				'fields' => [
					[
						'label' => __( 'Content in the queue', 'elasticpress-labs' ),
						'value' => $this->get_content_in_queue(),
					],
					[
						'label' => __( 'Content with errors', 'elasticpress-labs' ),
						'value' => $this->get_content_with_errors(),
					],
				],
			],
		];
	}

	/**
	 * Return the number of content items in the queue
	 *
	 * @return string
	 */
	protected function get_content_in_queue(): string {
		$query = [
			'size'             => 0,
			'track_total_hits' => true,
			'query'            => [
				'term' => [
					'ep_embeddings_control.is_processing' => true,
				],
			],
		];

		$post_indexable = \ElasticPress\Indexables::factory()->get( 'post' );
		$es_response    = $post_indexable->query_es( $query, [] );

		return $es_response && isset( $es_response['found_documents']['value'] )
			? (string) $es_response['found_documents']['value']
			: 'N/A';
	}

	/**
	 * Return the number of content items with errors
	 *
	 * @return string
	 */
	protected function get_content_with_errors(): string {
		$query = [
			'size'             => 0,
			'track_total_hits' => true,
			'query'            => [
				'exists' => [ 'field' => 'ep_embeddings_control.errors' ],
			],
		];

		$post_indexable = \ElasticPress\Indexables::factory()->get( 'post' );
		$es_response    = $post_indexable->query_es( $query, [] );

		return $es_response && isset( $es_response['found_documents']['value'] )
			? (string) $es_response['found_documents']['value']
			: 'N/A';
	}
}
