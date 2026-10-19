<?php
/**
 * Test LogRequest trait
 *
 * @since 2.5.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Traits\LogRequest;

/**
 * LogRequest trait test class
 */
class TestLogRequest extends \WP_UnitTestCase {
	/**
	 * Setup each test
	 */
	public function set_up() {
		parent::set_up();
		// Ensure no external HTTP requests are made during tests.
		add_filter( 'pre_http_request', [ $this, 'mock_http_response' ], 10, 3 );
	}

	/**
	 * Teardown each test
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http_response' ], 10 );
		parent::tear_down();
	}

	/**
	 * Provide a deterministic HTTP response for wp_remote_post
	 *
	 * @param false|array|\WP_Error $preempt A preempted response.
	 * @param array                 $args    HTTP request arguments.
	 * @param string                $url     The request URL.
	 * @return array
	 */
	public function mock_http_response( $preempt, $args, $url ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( [ 'ok' => true ] ),
		];
	}

	/**
	 * Test that send_request_and_log performs request, sets formatted data, and fires hooks
	 *
	 * @group log-request
	 */
	public function test_send_request_and_log_fires_hooks_and_logs() {
		$requester = new class() {
			use LogRequest;

			/**
			 * Proxy to call trait method.
			 *
			 * @param string $url URL.
			 * @param array  $options Options.
			 * @param string $type Type.
			 * @param string $slug Slug.
			 * @return array
			 */
			public function call( $url, $options, $type, $slug ) {
				return $this->send_request_and_log( $url, $options, $type, $slug );
			}
		};

		$url     = 'https://example.org/embeddings';
		$options = [ 'body' => wp_json_encode( [ 'text' => 'hello' ] ) ];
		$type    = 'vector_embeddings';
		$slug    = 'vector-embeddings';

		$before   = did_action( 'ep_remote_request' );
		$response = $requester->call( $url, $options, $type, $slug );

		$this->assertTrue( is_array( $response ) );
		$this->assertSame( 200, $response['response']['code'] );

		// Action ep_remote_request should have increased exactly by one.
		$this->assertSame( $before + 1, did_action( 'ep_remote_request' ) );

		// The filter ep_get_query_log should include the formatted request appended by the trait.
		$log = apply_filters( 'ep_get_query_log', [] );

		$this->assertTrue( is_array( $log ) );
		$this->assertCount( 1, $log );

		$entry = $log[0];
		$this->assertArrayHasKey( 'args', $entry );
		$this->assertArrayHasKey( 'url', $entry );
		$this->assertArrayHasKey( 'time_start', $entry );
		$this->assertArrayHasKey( 'time_finish', $entry );
		$this->assertSame( 'POST', $entry['args']['method'] );
		$this->assertSame( $type, $entry['args']['ep_query_type'] );
		$this->assertSame( $url, $entry['url'] );
		$this->assertGreaterThan( $entry['time_start'], $entry['time_finish'] );
	}

	/**
	 * Test add_vector_embeddings_to_query_log appends formatted request to provided log
	 *
	 * @group log-request
	 */
	public function test_add_vector_embeddings_to_query_log_appends_entry() {
		$requester = new class() {
			use LogRequest;

			/**
			 * Proxy to call trait method.
			 *
			 * @param string $url URL.
			 * @param array  $options Options.
			 * @param string $type Type.
			 * @param string $slug Slug.
			 * @return array
			 */
			public function call( $url, $options, $type, $slug ) {
				return $this->send_request_and_log( $url, $options, $type, $slug );
			}
		};

		// First perform a call to populate formatted_request in the trait.
		$url     = 'https://example.org/embeddings';
		$options = [ 'body' => wp_json_encode( [ 'text' => 'world' ] ) ];
		$type    = 'vector_embeddings';
		$slug    = 'vector-embeddings';
		$requester->call( $url, $options, $type, $slug );

		// Now apply the filter to get current log with the appended entry.
		$log = apply_filters( 'ep_get_query_log', [ [ 'existing' => true ] ] );

		$this->assertCount( 2, $log );
		$this->assertArrayHasKey( 'existing', $log[0] );
		$this->assertArrayHasKey( 'url', $log[1] );
		$this->assertSame( $url, $log[1]['url'] );
	}

	/**
	 * Requests are kept for the query log through one callback per class, so request objects
	 * are not kept alive; nothing is kept when query logging is off.
	 *
	 * @since 2.6.0
	 * @group log-request
	 */
	public function test_query_log_does_not_keep_request_objects() {
		global $wp_filter;

		$class = new class() {
			use LogRequest;

			/**
			 * Proxy to call trait method.
			 *
			 * @param string $url URL.
			 * @return array
			 */
			public function call( $url ) {
				return $this->send_request_and_log( $url, [], 'vector_embeddings', 'vector-embeddings' );
			}
		};

		$name = get_class( $class );

		for ( $i = 0; $i < 3; $i++ ) {
			( new $name() )->call( "https://example.org/embeddings/{$i}" );
		}

		$callbacks = count( $wp_filter['ep_get_query_log']->callbacks[10] ?? [] );
		$this->assertSame( 1, $callbacks );
		$this->assertCount( 3, apply_filters( 'ep_get_query_log', [] ) );

		add_filter( 'ep_disable_query_logging', '__return_true' );
		( new $name() )->call( 'https://example.org/embeddings/off' );

		$this->assertCount( 3, apply_filters( 'ep_get_query_log', [] ) );
	}

	/**
	 * Test the list is emptied when ElasticPress frees memory during a sync.
	 *
	 * @since 2.6.0
	 * @group log-request
	 */
	public function test_query_log_is_emptied_between_sync_batches() {
		$requester = new class() {
			use LogRequest;

			/**
			 * Proxy to call trait method.
			 *
			 * @param string $url URL.
			 * @return array
			 */
			public function call( $url ) {
				return $this->send_request_and_log( $url, [], 'vector_embeddings', 'vector-embeddings' );
			}
		};

		$requester->call( 'https://example.org/embeddings/1' );
		$requester->call( 'https://example.org/embeddings/2' );
		$this->assertCount( 2, apply_filters( 'ep_get_query_log', [] ) );

		do_action( 'ep_stop_the_insanity' );
		$this->assertCount( 0, apply_filters( 'ep_get_query_log', [] ) );

		$requester->call( 'https://example.org/embeddings/3' );
		$this->assertCount( 1, apply_filters( 'ep_get_query_log', [] ) );
	}

	/**
	 * Test the API key is not kept in the query log or passed to `ep_remote_request`.
	 *
	 * @since 2.6.0
	 * @group log-request
	 */
	public function test_authorization_header_is_redacted() {
		$requester = new class() {
			use LogRequest;

			/**
			 * Proxy to call trait method.
			 *
			 * @param array $options Options.
			 * @return array
			 */
			public function call( $options ) {
				return $this->send_request_and_log( 'https://example.org/embeddings', $options, 'vector_embeddings', 'vector-embeddings' );
			}
		};

		$sent = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$sent ) {
				$sent[] = $args['headers'];
				return $preempt;
			},
			5,
			2
		);

		$remote = [];
		add_action(
			'ep_remote_request',
			function ( $request ) use ( &$remote ) {
				$remote[] = $request['args']['headers'];
			}
		);

		$requester->call( [ 'headers' => [ 'Authorization' => 'Bearer secret-key' ] ] );
		$requester->call(
			[
				'headers' => [
					'authorization' => 'Bearer secret-key',
					'Content-Type'  => 'application/json',
				],
			]
		);

		$this->assertSame( 'Bearer secret-key', $sent[0]['Authorization'] );

		$log = apply_filters( 'ep_get_query_log', [] );
		$this->assertSame( '[redacted]', $log[0]['args']['headers']['Authorization'] );
		$this->assertSame( '[redacted]', $log[1]['args']['headers']['authorization'] );
		$this->assertSame( 'application/json', $log[1]['args']['headers']['Content-Type'] );
		$this->assertSame( '[redacted]', $remote[0]['Authorization'] );
		$this->assertStringNotContainsString( 'secret-key', wp_json_encode( $log ) );
	}
}
