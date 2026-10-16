<?php
/**
 * Test Cohere-compatible re-ranker provider
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Cohere_Compatible_Reranker;

/**
 * Cohere-compatible re-ranker test class
 */
class TestCohereCompatibleReranker extends \WP_UnitTestCase {
	/**
	 * Documents in request order.
	 *
	 * @return array
	 */
	protected function documents(): array {
		return [
			[
				'id'   => 20,
				'text' => 'Second retrieved',
			],
			[
				'id'   => 10,
				'text' => 'First retrieved',
			],
		];
	}

	/**
	 * Test result indexes map back to the document ID that was sent.
	 *
	 * @group chat
	 */
	public function test_normalize_results_binds_score_to_passage_id() {
		$provider = new Cohere_Compatible_Reranker(
			'https://rerank.test/v1/rerank',
			'test-key',
			'test-model'
		);

		$ranked = $provider->normalize_results(
			[
				'results' => [
					[
						'index'           => 1,
						'relevance_score' => 0.91,
					],
					[
						'index'           => 0,
						'relevance_score' => 0.12,
					],
				],
			],
			$this->documents()
		);

		$this->assertSame(
			[
				[
					'id'    => 10,
					'score' => 0.91,
				],
				[
					'id'    => 20,
					'score' => 0.12,
				],
			],
			$ranked
		);
	}

	/**
	 * Test out-of-range indexes are dropped instead of becoming IDs.
	 *
	 * @group chat
	 */
	public function test_normalize_results_ignores_invalid_index() {
		$provider = new Cohere_Compatible_Reranker(
			'https://rerank.test/v1/rerank',
			'test-key',
			'test-model'
		);

		$ranked = $provider->normalize_results(
			[
				'results' => [
					[
						'index'           => 9,
						'relevance_score' => 0.99,
					],
					[
						'index'           => 0,
						'relevance_score' => 0.4,
					],
				],
			],
			$this->documents()
		);

		$this->assertSame(
			[
				[
					'id'    => 20,
					'score' => 0.4,
				],
			],
			$ranked
		);
	}

	/**
	 * Test a recorded HTTP fixture is normalized.
	 *
	 * @group chat
	 */
	public function test_http_fixture_is_normalized() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				unset( $pre, $args );

				if ( 'https://rerank.test/v1/rerank' !== $url ) {
					return false;
				}

				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'results' => [
								[
									'index'           => 1,
									'relevance_score' => 0.73,
								],
								[
									'index'           => 0,
									'relevance_score' => 0.11,
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

		$provider = new Cohere_Compatible_Reranker(
			'https://rerank.test/v1/rerank',
			'test-key',
			'test-model'
		);

		$ranked = $provider->rerank( 'What is ElasticPress?', $this->documents() );

		$this->assertSame( 10, $ranked[0]['id'] );
		$this->assertSame( 0.73, $ranked[0]['score'] );
		$this->assertSame( 20, $ranked[1]['id'] );
	}

	/**
	 * Test a missing results key is an error.
	 *
	 * @group chat
	 */
	public function test_invalid_body_is_error() {
		$provider = new Cohere_Compatible_Reranker(
			'https://rerank.test/v1/rerank',
			'test-key',
			'test-model'
		);

		$error = $provider->normalize_results( [ 'error' => 'nope' ], $this->documents() );

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_rerank_invalid_response', $error->get_error_code() );
	}
}
