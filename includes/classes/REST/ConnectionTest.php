<?php
/**
 * Connection test REST controller.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPressLabs\ConnectionTestable;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * `POST elasticpress-labs/v1/connection-test`: test a feature's connection with the settings
 * screen's values, saved or not. Nothing is saved.
 */
class ConnectionTest {
	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'elasticpress-labs/v1',
			'connection-test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'feature'  => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'settings' => [
						'type'    => 'object',
						'default' => [],
					],
				],
			]
		);
	}

	/**
	 * Only who can change ElasticPress settings.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( \ElasticPress\Utils\get_capability() );
	}

	/**
	 * Run the test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$feature = \ElasticPress\Features::factory()->get_registered_feature( (string) $request['feature'] );

		if ( ! $feature instanceof ConnectionTestable ) {
			return new \WP_Error(
				'ep_labs_connection_test_unsupported',
				esc_html__( 'This feature has no connection test.', 'elasticpress-labs' ),
				[ 'status' => 400 ]
			);
		}

		$unsaved  = is_array( $request['settings'] ) ? $request['settings'] : [];
		$settings = $feature->get_settings();

		// Only the connection fields: the test never sees (or saves) anything else from the form.
		foreach ( $feature->get_connection_fields() as $key ) {
			if ( array_key_exists( $key, $unsaved ) && is_scalar( $unsaved[ $key ] ) ) {
				$settings[ $key ] = sanitize_text_field( (string) $unsaved[ $key ] );
			}
		}

		$result = $feature->test_connection( $settings );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				[
					'success' => false,
					'message' => $result->get_error_message(),
				]
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => (string) $result,
			]
		);
	}
}
