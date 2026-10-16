<?php
/**
 * Test the "Test connection" buttons and Chat's AI provider requirement
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\FeatureRequirementsStatus;
use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\ConnectionTest;
use ElasticPressLabs\Feature\Chat\Models;

/**
 * Connection tests of Chat's re-ranker, Vector Embeddings, and AI Search Summary.
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
	 * The re-ranker test sends the form's unsaved connection fields, and nothing else of the form.
	 *
	 * @group connection-test
	 */
	public function test_chat_rerank() {
		$this->reply(
			200,
			[
				'results' => [
					[
						'index'           => 0,
						'relevance_score' => 0.9,
					],
					[
						'index'           => 1,
						'relevance_score' => 0.1,
					],
				],
			]
		);

		$response = $this->test(
			'chat',
			[
				'rerank_api_url' => 'https://rerank.test/v1/rerank',
				'rerank_api_key' => 'unsaved-key',
				'rerank_model'   => 'rerank-model',
				'retrieve_count' => '99',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'Connected. The re-ranker scored the test passages.', $response->get_data()['message'] );
		$this->assertSame( 'https://rerank.test/v1/rerank', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer unsaved-key', $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( 'rerank-model', $this->requests[0]['body']['model'] );
		$this->assertSame( 20, $this->requests[0]['timeout'], 'The test sends what a re-rank request sends.' );

		// Nothing was saved.
		$this->assertSame( '', (string) Features::factory()->get_registered_feature( 'chat' )->get_setting( 'rerank_api_url' ) );
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
			'chat'              => 'chat_rerank',
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

	/**
	 * Without an AI provider Chat is temporarily disabled (not turned off), with a link to set
	 * one up; the check reads stored credentials.
	 *
	 * @group chat
	 * @group connection-test
	 */
	public function test_chat_needs_an_ai_provider() {
		add_filter(
			'ep_feature_active',
			static fn( $active, $settings, $feature ) => $feature && 'vector_embeddings' === $feature->slug ? true : $active,
			10,
			3
		);

		$chat = Features::factory()->get_registered_feature( 'chat' );

		$this->assertSame( FeatureRequirementsStatus::MANUALLY_ENABLED, $chat->requirements_status()->code );

		add_filter( 'ep_chat_ai_available', '__return_false', 20 );

		$status = $chat->requirements_status();

		$this->assertSame( FeatureRequirementsStatus::TEMPORARILY_DISABLED, $status->code );
		$this->assertStringContainsString( 'options-connectors.php', $status->message );

		remove_filter( 'ep_chat_ai_available', '__return_false', 20 );

		// Before WordPress registers its connectors, nothing is known: Chat stays set up, as
		// ElasticPress sets up features before that (on every request).
		remove_filter( 'ep_chat_ai_available', '__return_true' );

		global $wp_actions;

		$fired = $wp_actions['wp_connectors_init'] ?? null;
		unset( $wp_actions['wp_connectors_init'] );

		$this->assertTrue( Models::is_ai_available() );

		if ( null !== $fired ) {
			$wp_actions['wp_connectors_init'] = $fired; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		add_filter( 'ep_chat_ai_available', '__return_true' );

		// The credential check behind it.
		$set_up = new \ReflectionMethod( Models::class, 'is_connector_set_up' );
		$set_up->setAccessible( true );

		$key = [
			'type'           => 'ai_provider',
			'authentication' => [
				'method'        => 'api_key',
				'setting_name'  => 'connectors_ai_fixture_api_key',
				'constant_name' => 'EPL_TEST_FIXTURE_API_KEY',
				'env_var_name'  => 'EPL_TEST_FIXTURE_API_KEY',
			],
		];

		$this->assertFalse( $set_up->invoke( null, $key ) );

		update_option( 'connectors_ai_fixture_api_key', 'stored' );

		$this->assertTrue( $set_up->invoke( null, $key ) );

		$key['plugin']['is_active'] = '__return_false';

		$this->assertFalse( $set_up->invoke( null, $key ) );
		$this->assertTrue( $set_up->invoke( null, [ 'authentication' => [ 'method' => 'none' ] ] ) );

		unset( $key['plugin'] );
		Utils\update_option( 'connectors_ai_fixture_api_key', '' );
		delete_option( 'connectors_ai_fixture_api_key' );

		$this->assertFalse( $set_up->invoke( null, $key ) );
	}
}
