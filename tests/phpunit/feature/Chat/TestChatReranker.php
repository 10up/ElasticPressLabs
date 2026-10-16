<?php
/**
 * Test Chat re-ranker
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Reranker;

/**
 * Chat re-ranker test class
 */
class TestChatReranker extends \WP_UnitTestCase {
	/**
	 * Reranker instance.
	 *
	 * @var Reranker
	 */
	protected $reranker;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->reranker = new Reranker();
	}

	/**
	 * Sample passages in retrieve order.
	 *
	 * @return array
	 */
	protected function passages(): array {
		return [
			[
				'id'      => 10,
				'title'   => 'Alpha',
				'url'     => 'https://example.test/alpha',
				'excerpt' => 'First retrieved',
			],
			[
				'id'      => 20,
				'title'   => 'Beta',
				'url'     => 'https://example.test/beta',
				'excerpt' => 'Second retrieved',
			],
			[
				'id'      => 30,
				'title'   => 'Gamma',
				'url'     => 'https://example.test/gamma',
				'excerpt' => 'Third retrieved',
			],
		];
	}

	/**
	 * Install a fixture provider.
	 *
	 * @param array $ranked Provider rows.
	 * @return void
	 */
	protected function use_provider( array $ranked ) {
		add_filter(
			'ep_chat_rerank_provider',
			static function () use ( $ranked ) {
				return new FixtureRerankProvider( $ranked );
			}
		);
	}

	/**
	 * Test the bot off switch skips the provider.
	 *
	 * @group chat
	 */
	public function test_skips_when_bot_rerank_is_off() {
		$called = false;

		add_filter(
			'ep_chat_rerank_provider',
			static function () use ( &$called ) {
				$called = true;

				return new FixtureRerankProvider( [] );
			}
		);

		$result = $this->reranker->apply(
			$this->passages(),
			'What is ElasticPress?',
			[
				'rerank_enabled' => false,
			]
		);

		$this->assertFalse( $called );
		$this->assertSame( [ 10, 20, 30 ], wp_list_pluck( $result, 'id' ) );
	}

	/**
	 * Test empty Chat connection settings skip the HTTP provider.
	 *
	 * @group chat
	 */
	public function test_skips_when_connection_settings_are_empty() {
		$result = $this->reranker->apply(
			$this->passages(),
			'What is ElasticPress?',
			[
				'rerank_enabled' => true,
			]
		);

		$this->assertSame( [ 10, 20, 30 ], wp_list_pluck( $result, 'id' ) );
		$this->assertArrayNotHasKey( 'rerank_score', $result[0] );
	}

	/**
	 * Test localhost connection URLs are used, not rejected as private, and a re-ranker without a
	 * key gets no Authorization header.
	 *
	 * @group chat
	 */
	public function test_accepts_localhost_connection_url() {
		$settings         = Utils\get_option( 'ep_feature_settings', [] );
		$settings['chat'] = array_merge(
			is_array( $settings['chat'] ?? null ) ? $settings['chat'] : [],
			[
				'rerank_api_url' => 'http://127.0.0.1:4000/v1/rerank',
				'rerank_api_key' => '',
				'rerank_model'   => 'local-model',
			]
		);
		Utils\update_option( 'ep_feature_settings', $settings );

		$seen    = '';
		$headers = null;

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen, &$headers ) {
				$seen    = (string) $url;
				$headers = $args['headers'] ?? [];

				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'results' => [
								[
									'index'           => 1,
									'relevance_score' => 0.8,
								],
								[
									'index'           => 0,
									'relevance_score' => 0.2,
								],
								[
									'index'           => 2,
									'relevance_score' => 0.1,
								],
							],
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
				];
			},
			10,
			3
		);

		$result = $this->reranker->apply(
			$this->passages(),
			'What is ElasticPress?',
			[
				'rerank_enabled' => true,
			]
		);

		$this->assertSame( 'http://127.0.0.1:4000/v1/rerank', $seen );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
		$this->assertSame( [ 20, 10, 30 ], wp_list_pluck( $result, 'id' ) );
	}

	/**
	 * Test scores bind to passage ID, not the request list index.
	 *
	 * @group chat
	 */
	public function test_scores_bind_to_passage_id() {
		$this->use_provider(
			[
				[
					'id'    => 30,
					'score' => 0.2,
				],
				[
					'id'    => 10,
					'score' => 0.9,
				],
				[
					'id'    => 20,
					'score' => 0.4,
				],
			]
		);

		$result = $this->reranker->apply(
			$this->passages(),
			'What is ElasticPress?',
			[
				'rerank_enabled' => true,
			]
		);

		$this->assertSame( [ 30, 10, 20 ], wp_list_pluck( $result, 'id' ) );
		$this->assertSame( 'Gamma', $result[0]['title'] );
		$this->assertSame( 0.9, $result[1]['rerank_score'] );
	}

	/**
	 * Test the optional floor drops weak passages.
	 *
	 * @group chat
	 */
	public function test_floor_drops_passages_below_cutoff() {
		$this->use_provider(
			[
				[
					'id'    => 10,
					'score' => 0.8,
				],
				[
					'id'    => 20,
					'score' => 0.2,
				],
				[
					'id'    => 30,
					'score' => 0.4,
				],
			]
		);

		$result = $this->reranker->apply(
			$this->passages(),
			'What is ElasticPress?',
			[
				'rerank_enabled' => true,
				'rerank_floor'   => 0.35,
			]
		);

		$this->assertSame( [ 10, 30 ], wp_list_pluck( $result, 'id' ) );
	}

	/**
	 * Test unknown IDs and a provider error leave retrieve order.
	 *
	 * @group chat
	 */
	public function test_provider_error_keeps_retrieve_order() {
		add_filter(
			'ep_chat_rerank_provider',
			static function () {
				return new FixtureRerankProvider( new \WP_Error( 'ep_chat_rerank_http_502', 'fail' ) );
			}
		);

		$result = $this->reranker->apply(
			$this->passages(),
			'What is ElasticPress?',
			[
				'rerank_enabled' => true,
			]
		);

		$this->assertSame( [ 10, 20, 30 ], wp_list_pluck( $result, 'id' ) );
	}
}
