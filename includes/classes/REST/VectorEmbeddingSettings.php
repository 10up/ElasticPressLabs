<?php
/**
 * Vector Embedding Configuration REST API Controller.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPress\Utils;

/**
 * Vector Embedding Configuration API controller class.
 */
class VectorEmbeddingSettings {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'elasticpress-labs/v1',
			'vector-embeddings',
			[
				'callback'            => [ $this, 'update_vector_embeddings' ],
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Check that the request has permission to manage search templates.
	 *
	 * @return boolean
	 */
	public function check_permission() {
		$capability = Utils\get_capability( 'search_templates' );

		return current_user_can( $capability );
	}

	/**
	 * Update vector embeddings.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function update_vector_embeddings( \WP_REST_Request $request ) {

		$post_type_configs = $request->get_param( 'postTypeConfig' );
		// each contional has a content type (post type or taxonomy), the content selection (terms or post types), and any post meta fields to include

		$settings = [
			'postTypeConfig' => $post_type_configs,
		];

		update_option( 'ep_vector_embeddings_settings', $settings );

		return rest_ensure_response( [ 'success' => true ] );
	}
}
