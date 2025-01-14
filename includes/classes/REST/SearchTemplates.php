<?php
/**
 * Search Templates REST API Controller.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPress\Utils;
use ElasticPressLabs\Feature\SearchTemplates as SearchTemplatesFeature;

/**
 * Search Templates API controller class.
 */
class SearchTemplates {
	/**
	 * The SearchTemplatesFeature instance.
	 *
	 * @var SearchTemplatesFeature
	 */
	protected $feature;

	/**
	 * Class constructor
	 *
	 * @param SearchTemplatesFeature $feature The feature instance.
	 */
	public function __construct( SearchTemplatesFeature $feature ) {
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
			'search-templates',
			[
				'callback'            => [ $this, 'get_search_templates' ],
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
		register_rest_route(
			'elasticpress-labs/v1',
			'search-templates/(?P<template_name>[\w-]+)',
			[
				'args' => [
					'template_name' => [
						'description'       => __( 'Template name.', 'elasticpress-labs' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
				[
					'callback'            => [ $this, 'get_search_template' ],
					'methods'             => 'GET',
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'callback'            => [ $this, 'update_search_template' ],
					'methods'             => 'PUT',
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'callback'            => [ $this, 'delete_search_template' ],
					'methods'             => 'DELETE',
					'permission_callback' => [ $this, 'check_permission' ],
				],
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
	 * List search templates handler.
	 *
	 * @return array|\WP_Error
	 */
	public function get_search_templates() {
		$response = \ElasticPress\Elasticsearch::factory()->remote_request( $this->feature->get_search_templates_endpoint() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'invalid_response', wp_remote_retrieve_response_message( $response ) );
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$index_name    = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();

		return $response_body[ $index_name ] ?? [];
	}

	/**
	 * Get a single search template.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|\WP_Error
	 */
	public function get_search_template( \WP_REST_Request $request ) {
		$path     = $this->feature->get_search_template_endpoint() . '?template_name=' . $request['template_name'];
		$response = \ElasticPress\Elasticsearch::factory()->remote_request( $path );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'invalid_response', wp_remote_retrieve_response_message( $response ) );
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Update a search template.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|\WP_Error
	 */
	public function update_search_template( \WP_REST_Request $request ) {
		$path     = $this->feature->get_search_template_endpoint() . '?template_name=' . $request['template_name'];
		$response = \ElasticPress\Elasticsearch::factory()->remote_request(
			$path,
			[
				'method'  => 'PUT',
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => $request->get_body(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		if ( 201 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( $status_code, $response_body );
		}

		$response_data = json_decode( $response_body );
		$rest_response = rest_ensure_response( $response_data );
		$rest_response->set_status( $status_code );
		return $rest_response;
	}

	/**
	 * Delete a search template.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|\WP_Error
	 */
	public function delete_search_template( \WP_REST_Request $request ) {
		$path     = $this->feature->get_search_template_endpoint() . '?template_name=' . $request['template_name'];
		$response = \ElasticPress\Elasticsearch::factory()->remote_request(
			$path,
			[
				'method' => 'DELETE',
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 204 !== $status_code ) {
			return new \WP_Error( 'invalid_response', wp_remote_retrieve_response_message( $response ) );
		}

		$response_data = wp_remote_retrieve_body( $response );
		$rest_response = rest_ensure_response( $response_data );
		$rest_response->set_status( $status_code );
		return $rest_response;
	}
}
