<?php
/**
 * RAG REST API Controller.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPress\Utils;
use ElasticPressLabs\Feature\RAG as RAGFeature;

/**
 * RAG REST API controller class.
 */
class RAG {
	/**
	 * The RAGFeature instance.
	 *
	 * @var RAGFeature
	 */
	protected $feature;

	/**
	 * Class constructor
	 *
	 * @param RAGFeature $feature The feature instance.
	 */
	public function __construct( RAGFeature $feature ) {
		$this->feature = $feature;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'elasticpress-labs/v1',
			'rag',
			[
				'callback'            => [ $this, 'get_rag_response' ],
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => [
					'search_query' => [
						'description'       => __( 'The search query.', 'elasticpress-labs' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Get the AI response for a given query.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|\WP_Error
	 */
	public function get_rag_response( \WP_REST_Request $request ) {
		return $this->feature->get_ai_response( $request['search_query'] );
	}
}
