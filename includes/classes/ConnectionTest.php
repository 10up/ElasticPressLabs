<?php
/**
 * "Test connection" buttons on the ElasticPress features screen.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs;

use ElasticPress\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The field type, the script, and a request helper for features that implement
 * ConnectionTestable. The button sends the form's unsaved connection fields to
 * `POST elasticpress-labs/v1/connection-test`, which runs one small request and says whether
 * it worked, or the service's own error.
 */
class ConnectionTest {
	/**
	 * Field type of the button.
	 *
	 * @var string
	 */
	public const FIELD_TYPE = 'ep_labs_connection_test';

	/**
	 * Setting key suffix of a feature's button (never saved: the button has no value).
	 *
	 * @var string
	 */
	public const KEY_SUFFIX = '_connection_test';

	/**
	 * Hook the field type, the script, and the REST route.
	 *
	 * @return void
	 */
	public function setup() {
		add_filter( 'ep_settings_field_types', [ $this, 'add_field_type' ] );
		// Before ElasticPress (10), so the control is registered when the screen renders.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_script' ], 9 );
		add_action( 'rest_api_init', [ new REST\ConnectionTest(), 'register_routes' ] );
	}

	/**
	 * Add the button's field type.
	 *
	 * @param string[] $types Field types.
	 * @return string[]
	 */
	public function add_field_type( $types ) {
		$types   = is_array( $types ) ? $types : [];
		$types[] = self::FIELD_TYPE;

		return $types;
	}

	/**
	 * Whether this ElasticPress version renders the button.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return function_exists( '\ElasticPress\Utils\get_settings_field_types' )
			&& in_array( self::FIELD_TYPE, \ElasticPress\Utils\get_settings_field_types(), true );
	}

	/**
	 * The button's settings schema entry for a feature, or null when ElasticPress cannot render it.
	 *
	 * @param Feature $feature          Feature.
	 * @param string  $field_group_slug Field group (empty for none).
	 * @return array|null
	 */
	public static function get_field( Feature $feature, string $field_group_slug = '' ): ?array {
		if ( ! $feature instanceof ConnectionTestable || ! self::is_supported() ) {
			return null;
		}

		$field = [
			'key'   => $feature->slug . self::KEY_SUFFIX,
			'label' => __( 'Test connection', 'elasticpress-labs' ),
			'help'  => __( 'Sends one small request with the values above, saved or not.', 'elasticpress-labs' ),
			'type'  => self::FIELD_TYPE,
		];

		if ( '' !== $field_group_slug ) {
			$field['field_group_slug'] = $field_group_slug;
		}

		return $field;
	}

	/**
	 * Enqueue the button on the features screen, with each feature's fields.
	 *
	 * @return void
	 */
	public function enqueue_script() {
		if ( ! class_exists( '\ElasticPress\Screen' ) || 'dashboard' !== \ElasticPress\Screen::factory()->get_current_screen() ) {
			return;
		}

		$asset = Utils\get_asset_info( 'connection-test-script' );

		if ( empty( $asset ) ) {
			return;
		}

		$fields = [];

		foreach ( \ElasticPress\Features::factory()->registered_features as $feature ) {
			if ( $feature instanceof ConnectionTestable ) {
				$fields[ $feature->slug . self::KEY_SUFFIX ] = [
					'feature' => $feature->slug,
					'fields'  => array_values( $feature->get_connection_fields() ),
				];
			}
		}

		wp_enqueue_script(
			'ep_labs_connection_test',
			ELASTICPRESS_LABS_URL . 'dist/js/connection-test-script.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ep_labs_connection_test', 'elasticpress-labs' );

		wp_enqueue_style(
			'ep_labs_connection_test',
			ELASTICPRESS_LABS_URL . 'dist/css/connection-test-script.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'ep_labs_connection_test',
			'epLabsConnectionTest',
			[
				'restApiEndpoint' => 'elasticpress-labs/v1/connection-test',
				'fieldType'       => self::FIELD_TYPE,
				'fields'          => $fields,
			]
		);
	}

	/**
	 * Send a JSON request and read the reply. A failure says what the service said, so the
	 * admin can fix a URL, key, or model name.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $options wp_remote_post options (headers and body).
	 * @return array|\WP_Error Decoded reply.
	 */
	public static function send( string $url, array $options ) {
		if ( '' === trim( $url ) ) {
			return new \WP_Error( 'ep_labs_connection_no_url', __( 'Fill in the API URL first.', 'elasticpress-labs' ) );
		}

		$options['timeout'] = $options['timeout'] ?? 20;

		$response = wp_remote_post( $url, $options );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'ep_labs_connection_failed',
				/* translators: %s: error message */
				sprintf( __( 'Could not reach the service: %s', 'elasticpress-labs' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = self::get_error_detail( is_array( $data ) ? $data : [] );

			return new \WP_Error(
				'ep_labs_connection_http_' . $code,
				'' !== $detail
					/* translators: 1: HTTP status code, 2: the service's error message */
					? sprintf( __( 'The service answered with HTTP %1$d: %2$s', 'elasticpress-labs' ), $code, $detail )
					/* translators: %d: HTTP status code */
					: sprintf( __( 'The service answered with HTTP %d.', 'elasticpress-labs' ), $code )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'ep_labs_connection_invalid', __( 'The service did not answer with JSON. Check the API URL.', 'elasticpress-labs' ) );
		}

		return $data;
	}

	/**
	 * The service's own error message, from the usual JSON shapes. Never the raw body: the
	 * test must not become a way to read what an address answers.
	 *
	 * @param array $data Decoded body.
	 * @return string Empty when the reply has no error message.
	 */
	protected static function get_error_detail( array $data ): string {
		$candidates = [
			$data['error']['message'] ?? null,
			$data['error'] ?? null,
			$data['message'] ?? null,
			$data['detail'] ?? null,
		];

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return self::shorten( $candidate );
			}
		}

		return '';
	}

	/**
	 * One line, at most 300 characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function shorten( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 299 ) . '…' : $text;
	}
}
