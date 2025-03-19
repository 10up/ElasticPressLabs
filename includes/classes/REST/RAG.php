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
		$routes = [
			'client-side' => [
				'callback'            => [ $this, 'get_rag_response' ],
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => [
					'search_query'   => [
						'description'       => __( 'The search query.', 'elasticpress-labs' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'search_vectors' => [
						'description'       => __( 'The search vectors.', 'elasticpress-labs' ),
						'type'              => 'array',
						'sanitize_callback' => [ $this, 'sanitize_vectors_array' ],
					],
				],
			],
			'server-side' => [
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
			],
		];

		register_rest_route(
			'elasticpress-labs/v1',
			'rag',
			$routes[ $this->get_embed_method() ],
		);
	}

	/**
	 * Get the AI response for a given query.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|\WP_Error
	 */
	public function get_rag_response( \WP_REST_Request $request ) {
		return [ 'html' => $this->feature->get_ai_response( $request['search_query'], $request['search_vectors'] ?? null ) ];
	}

	/**
	 * Sanitize vectors array.
	 *
	 * @param array $array Array to be sanitized
	 * @return array
	 */
	public function sanitize_vectors_array( array $array ): array {
		return array_map( 'floatval', $array );
	}

	/**
	 * Get the embedding method of the search term.
	 *
	 * @return string
	 */
	protected function get_embed_method(): string {
		return (string) $this->feature->get_setting( 'ep_rag_search_term_embed_method' );
	}
}
