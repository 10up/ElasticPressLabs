<?php
/**
 * Test the "Test connection" buttons
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPressLabs\ConnectionTest;

/**
 * Connection tests of Vector Embeddings and AI Search Summary.
 */
class TestConnectionTest extends BaseTestCase {
	/**
	 * Requests sent: url => decoded body.
	 *
	 * @var array
	 */
	protected $requests = [];

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->requests = [];

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Answer every request with a status and a JSON body, and keep what was sent.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Reply.
	 */
	protected function reply( int $code, array $body ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $code, $body ) {
				$this->requests[] = [
					'url'     => $url,
					'headers' => $args['headers'] ?? [],
					'timeout' => $args['timeout'] ?? null,
					'body'    => json_decode( (string) ( $args['body'] ?? '' ), true ),
				];

				return [
					'headers'  => [],
					'body'     => wp_json_encode( $body ),
					'response' => [
						'code'    => $code,
						'message' => 'Status',
					],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	/**
	 * Call the route.
	 *
	 * @param string $feature  Feature slug.
	 * @param array  $settings Unsaved settings.
	 * @return \WP_REST_Response
	 */
	protected function test( string $feature, array $settings ) {
		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/connection-test' );
		$request->set_body_params(
			[
				'feature'  => $feature,
				'settings' => $settings,
			]
		);

		return rest_do_request( $request );
	}

	/**
	 * Only who can manage ElasticPress can run a test; a feature without one is refused.
	 *
	 * @group connection-test
	 */
	public function test_permissions_and_unsupported_feature() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, $this->test( 'vector_embeddings', [] )->get_status() );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 400, $this->test( 'search', [] )->get_status() );
	}

	/**
	 * A failure says what the service said; missing values are named before any request.
	 *
	 * @group connection-test
	 */
	public function test_failures_are_explained() {
		$settings = [
			'ep_embeddings_api_url'         => 'https://embed.test/v1/embeddings',
			'ep_embeddings_embedding_model' => 'embed-model',
		];

		$this->reply( 401, [ 'error' => [ 'message' => 'Invalid API key.' ] ] );

		$failed = $this->test( 'vector_embeddings', $settings )->get_data();

		$this->assertFalse( $failed['success'] );
		$this->assertSame( 'The service answered with HTTP 401: Invalid API key.', $failed['message'] );

		$this->assertSame( 'Fill in the API URL first.', $this->test( 'vector_embeddings', array_merge( $settings, [ 'ep_embeddings_api_url' => '' ] ) )->get_data()['message'] );
		$this->assertSame( 'Fill in the embedding model first.', $this->test( 'vector_embeddings', array_merge( $settings, [ 'ep_embeddings_embedding_model' => '' ] ) )->get_data()['message'] );

		remove_all_filters( 'pre_http_request' );
		$this->reply( 200, [ 'id' => 'no embedding' ] );

		$this->assertStringContainsString( 'without an embedding', $this->test( 'vector_embeddings', $settings )->get_data()['message'] );
	}

	/**
	 * The embeddings test checks the vector has the dimensions set, and a failed test does not
	 * count as an embedding failure.
	 *
	 * @group connection-test
	 */
	public function test_vector_embeddings() {
		$settings = [
			'ep_embeddings_api_url'         => 'https://embed.test/v1/embeddings',
			'ep_embeddings_api_key'         => 'key',
			'ep_embeddings_embedding_model' => 'embed-model',
			'ep_embeddings_dimensions'      => '3',
		];

		$this->reply( 200, [ 'data' => [ [ 'embedding' => [ 0.1, 0.2, 0.3 ] ] ] ] );

		$this->assertSame( 'Connected. The model returned 3 dimensions, as set.', $this->test( 'vector_embeddings', $settings )->get_data()['message'] );
		$this->assertSame( 3, $this->requests[0]['body']['dimensions'] );
		$this->assertSame( 'embed-model', $this->requests[0]['body']['model'] );
		$this->assertSame( 'Bearer key', $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( 60, $this->requests[0]['timeout'], 'The test sends what an embeddings request sends.' );

		// Nothing was saved.
		$this->assertNotSame( 'embed-model', Features::factory()->get_registered_feature( 'vector_embeddings' )->get_setting( 'ep_embeddings_embedding_model' ) );

		$settings['ep_embeddings_dimensions'] = '4';

		$this->assertSame( 'The model returned 3 dimensions, but 4 are set. Change the dimensions to match the model.', $this->test( 'vector_embeddings', $settings )->get_data()['message'] );

		remove_all_filters( 'pre_http_request' );
		$this->reply( 500, [ 'error' => 'Down' ] );

		$this->assertFalse( $this->test( 'vector_embeddings', $settings )->get_data()['success'] );
		$this->assertFalse( get_transient( 'ep_vector_embeddings_failures' ) );
	}

	/**
	 * The AI Search Summary test asks the chat model for a short reply.
	 *
	 * @group connection-test
	 */
	public function test_ai_search_summary() {
		$this->reply( 200, [ 'choices' => [ [ 'message' => [ 'content' => 'OK' ] ] ] ] );

		$response = $this->test(
			'ai_search_summary',
			[
				'api_url'    => 'https://chat.test/v1/chat/completions',
				'api_key'    => 'key',
				'chat_model' => 'chat-model',
			]
		)->get_data();

		$this->assertSame( 'Connected. The model replied.', $response['message'] );
		$this->assertSame( 'chat-model', $this->requests[0]['body']['model'] );
		$this->assertSame( 'Bearer key', $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( 60, $this->requests[0]['timeout'], 'The test sends what a summary request sends.' );
	}

	/**
	 * Each feature's button is in its schema, in the right box, when ElasticPress renders custom
	 * field types; older versions get no button.
	 *
	 * @group connection-test
	 */
	public function test_fields() {
		$supported = function_exists( '\ElasticPress\Utils\get_settings_field_types' );

		$this->assertSame( $supported, ConnectionTest::is_supported() );

		$where = [
			'vector_embeddings' => 'ep_embeddings_openai',
			'ai_search_summary' => '',
		];

		foreach ( $where as $slug => $group ) {
			$feature = Features::factory()->get_registered_feature( $slug );
			$feature->reset_settings_schema();

			$fields = [];

			foreach ( $feature->get_settings_schema() as $field ) {
				$fields[ $field['key'] ] = $field;
			}

			$field = $fields[ $slug . ConnectionTest::KEY_SUFFIX ] ?? null;

			if ( ! $supported ) {
				$this->assertNull( $field, $slug );
				continue;
			}

			$this->assertNotNull( $field, $slug );
			$this->assertSame( ConnectionTest::FIELD_TYPE, $field['type'] );
			$this->assertSame( $group, $field['field_group_slug'] ?? '', $slug );
		}
	}
}
