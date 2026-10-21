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

		$interval = $this->get_interval();
		$wait_key = 'ep_labs_connection_test_' . get_current_user_id();

		if ( $interval > 0 ) {
			if ( get_transient( $wait_key ) ) {
				return new \WP_Error(
					'ep_labs_connection_test_too_soon',
					esc_html__( 'Wait a few seconds before testing again.', 'elasticpress-labs' ),
					[ 'status' => 429 ]
				);
			}

			set_transient( $wait_key, 1, $interval );
		}

		$unsaved         = is_array( $request['settings'] ) ? $request['settings'] : [];
		$saved           = $feature->get_settings();
		$settings        = $saved;
		$check_redirects = false;

		// Only the connection fields: the test never sees (or saves) anything else from the form.
		foreach ( $feature->get_connection_fields() as $key ) {
			if ( ! array_key_exists( $key, $unsaved ) || ! is_scalar( $unsaved[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $unsaved[ $key ] );

			if ( ! preg_match( '#^https?://#i', $value ) ) {
				$settings[ $key ] = sanitize_text_field( $value );
				continue;
			}

			$value = esc_url_raw( $value );

			// The saved URL is tested as it is: the feature's own requests already go there.
			if ( (string) ( $saved[ $key ] ?? '' ) !== $value ) {
				$is_public = false !== wp_http_validate_url( $value );

				if ( ! $this->is_url_allowed( $value, $is_public, $feature ) ) {
					return rest_ensure_response(
						[
							'success' => false,
							'message' => __( 'Save the settings before testing this URL. A URL that is not saved yet is only tested when it is a public address on a standard port.', 'elasticpress-labs' ),
						]
					);
				}

				// A public address could still redirect to an internal one.
				$check_redirects = $check_redirects || $is_public;
			}

			$settings[ $key ] = $value;
		}

		$reject_unsafe_urls = static function ( $args ) {
			$args['reject_unsafe_urls'] = true;
			return $args;
		};

		if ( $check_redirects ) {
			add_filter( 'http_request_args', $reject_unsafe_urls );
		}

		$result = $feature->test_connection( $settings );

		remove_filter( 'http_request_args', $reject_unsafe_urls );

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

	/**
	 * Whether the test may call a URL that is not saved yet.
	 *
	 * By default, only a public address on a standard port (see wp_http_validate_url()), so the
	 * test cannot be used to reach the site's internal network with values nobody saved.
	 *
	 * @param string             $url       URL from the form.
	 * @param bool               $is_public Whether the URL is a public address on a standard port.
	 * @param ConnectionTestable $feature   Feature being tested.
	 * @return bool
	 */
	protected function is_url_allowed( string $url, bool $is_public, ConnectionTestable $feature ): bool {
		/**
		 * Filters whether the connection test may call a URL that is not saved yet.
		 *
		 * @since 2.6.0
		 * @hook ep_labs_connection_test_url_allowed
		 * @param {bool}               $allowed Whether the URL is a public address on a standard port.
		 * @param {string}             $url     URL from the form.
		 * @param {ConnectionTestable} $feature Feature being tested.
		 * @return {bool} New value.
		 */
		return (bool) apply_filters( 'ep_labs_connection_test_url_allowed', $is_public, $url, $feature );
	}

	/**
	 * Seconds a user waits between two tests.
	 *
	 * @return int
	 */
	protected function get_interval(): int {
		/**
		 * Filters how many seconds a user waits between two connection tests. 0 turns it off.
		 *
		 * @since 2.6.0
		 * @hook ep_labs_connection_test_interval
		 * @param {int} $interval Seconds. Default 3.
		 * @return {int} New value.
		 */
		return max( 0, (int) apply_filters( 'ep_labs_connection_test_interval', 3 ) );
	}
}
