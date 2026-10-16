<?php
/**
 * Test Chat sync pipeline
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Pipeline;

/**
 * Chat pipeline test class
 */
class TestChatPipeline extends \WP_UnitTestCase {
	/**
	 * Pipeline instance.
	 *
	 * @var Pipeline
	 */
	protected $pipeline;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->pipeline = new Pipeline();
	}

	/**
	 * Build a bot array for pipeline runs.
	 *
	 * @param string $mode  Execution mode.
	 * @param array  $extra Extra bot fields.
	 * @return array
	 */
	protected function bot( $mode = Bots::MODE_SYNC, array $extra = [] ): array {
		return array_merge(
			[
				'id'             => 1,
				'name'           => 'Sync',
				'execution_mode' => $mode,
				'rerank_enabled' => false,
				'rerank_floor'   => null,
			],
			$extra
		);
	}

	/**
	 * Test the pipeline runs regardless of bot execution mode.
	 *
	 * @group chat
	 */
	public function test_pipeline_runs_for_async_bot() {
		add_filter( 'ep_chat_retrieve', '__return_empty_array' );

		$result = $this->pipeline->run(
			'What is ElasticPress?',
			[],
			$this->bot( Bots::MODE_WP_ASYNC )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'no_supporting_passage', $result['refusal_reason'] );
	}

	/**
	 * Test empty retrieve declines without calling the LLM.
	 *
	 * @group chat
	 */
	public function test_empty_retrieve_declines_without_llm() {
		add_filter( 'ep_chat_retrieve', '__return_empty_array' );

		$called = false;

		add_filter(
			'ep_chat_generate_text',
			static function () use ( &$called ) {
				$called = true;

				return 'should not run';
			}
		);

		$result = $this->pipeline->run( 'What is ElasticPress?', [], $this->bot() );

		$this->assertFalse( $called );
		$this->assertSame( [], $result['hits'] );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'no_supporting_passage', $result['refusal_reason'] );
		$this->assertSame(
			'No relevant sources were found for that question.',
			$result['content']
		);
	}

	/**
	 * Every outcome gives `ep_chat_message_meta` the same context: the pipeline context so far,
	 * with the bot, status, and reply (and the code of a decline).
	 *
	 * @group chat
	 */
	public function test_message_meta_context() {
		$seen = [];

		add_filter(
			'ep_chat_message_meta',
			static function ( $payload, $context ) use ( &$seen ) {
				$seen[] = $context;

				return $payload;
			},
			10,
			2
		);

		$post_id = $this->factory()->post->create(
			[
				'post_title'  => 'ElasticPress Guide',
				'post_status' => 'publish',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'ElasticPress is a search plugin.';
			}
		);

		$this->pipeline->run( 'What is ElasticPress?', [], $this->bot() );

		remove_all_filters( 'ep_chat_retrieve' );
		add_filter( 'ep_chat_retrieve', '__return_empty_array' );

		$this->pipeline->run( 'What is ElasticPress?', [], $this->bot() );

		$this->assertSame( [ 'done', 'refused' ], wp_list_pluck( $seen, 'status' ) );
		$this->assertSame( [ 'What is ElasticPress?', 'What is ElasticPress?' ], wp_list_pluck( $seen, 'question' ) );
		$this->assertSame( 'ElasticPress is a search plugin.', $seen[0]['content'] );
		$this->assertArrayNotHasKey( 'refusal_reason', $seen[0] );
		$this->assertSame( 'no_supporting_passage', $seen[1]['refusal_reason'] );
	}

	/**
	 * Test retrieved passages are passed to synthesize.
	 *
	 * @group chat
	 */
	public function test_passages_are_passed_to_synthesize() {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'ElasticPress Guide',
				'post_content' => 'ElasticPress is a WordPress search plugin.',
				'post_excerpt' => '',
				'post_status'  => 'publish',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);

		$seen = null;

		add_filter(
			'ep_chat_generate_text',
			static function ( $pre, $question, $history, $passages ) use ( &$seen ) {
				$seen = $passages;

				return 'Grounded: ' . $question;
			},
			10,
			4
		);

		$result = $this->pipeline->run( 'What is ElasticPress?', [], $this->bot() );

		$this->assertSame( 'Grounded: What is ElasticPress?', $result['content'] );
		$this->assertCount( 1, $result['hits'] );
		$this->assertSame( $post_id, $result['hits'][0]['id'] );
		$this->assertSame( 'ElasticPress Guide', $result['hits'][0]['title'] );
		$this->assertSame( get_permalink( $post_id ), $result['hits'][0]['url'] );
		$this->assertStringContainsString( 'WordPress search plugin', $result['hits'][0]['excerpt'] );
		$this->assertSame( 1, $result['hits'][0]['number'] );
		$this->assertSame( $result['hits'], $seen );
	}

	/**
	 * Test passages are plain text: no HTML entities in titles or excerpts.
	 *
	 * @group chat
	 */
	public function test_passages_are_plain_text() {
		$post = $this->factory()->post->create_and_get(
			[
				'post_title'   => 'Religion & Public Life',
				'post_content' => str_repeat( 'The team\'s survey of religion &amp; politics. ', 40 ),
				'post_excerpt' => '',
				'post_status'  => 'publish',
			]
		);

		$passage = $this->pipeline->to_passages( [ $post ] )[0];

		$this->assertSame( 'Religion & Public Life', $passage['title'] );
		$this->assertStringContainsString( 'religion & politics', $passage['excerpt'] );
		$this->assertStringEndsWith( '…', $passage['excerpt'] );
		$this->assertStringNotContainsString( '&', str_replace( ' & ', '', $passage['excerpt'] ) );
		$this->assertStringNotContainsString( '&#', $passage['title'] . $passage['excerpt'] );
	}

	/**
	 * Test the re-rank filter can drop all passages.
	 *
	 * @group chat
	 */
	public function test_rerank_filter_can_clear_passages() {
		$post_id = $this->factory()->post->create(
			[
				'post_title'  => 'Keep',
				'post_status' => 'publish',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter( 'ep_chat_rerank', '__return_empty_array' );

		$called = false;

		add_filter(
			'ep_chat_generate_text',
			static function () use ( &$called ) {
				$called = true;

				return 'should not run';
			}
		);

		$result = $this->pipeline->run( 'What is ElasticPress?', [], $this->bot() );

		$this->assertFalse( $called );
		$this->assertSame( [], $result['hits'] );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'no_supporting_passage', $result['refusal_reason'] );
	}

	/**
	 * Test a fixture provider re-orders passages when the bot has re-rank on.
	 *
	 * @group chat
	 */
	public function test_reranker_reorders_passages_when_bot_enabled() {
		$first  = $this->factory()->post->create(
			[
				'post_title'  => 'First',
				'post_status' => 'publish',
			]
		);
		$second = $this->factory()->post->create(
			[
				'post_title'  => 'Second',
				'post_status' => 'publish',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $first, $second ) {
				return [ get_post( $first ), get_post( $second ) ];
			}
		);

		add_filter(
			'ep_chat_rerank_provider',
			static function () use ( $first, $second ) {
				return new ReverseRerankProvider( $first, $second );
			}
		);

		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'Grounded';
			}
		);

		$off = $this->pipeline->run( 'What is ElasticPress?', [], $this->bot() );
		$this->assertSame( [ $first, $second ], wp_list_pluck( $off['hits'], 'id' ) );

		$on = $this->pipeline->run(
			'What is ElasticPress?',
			[],
			$this->bot( Bots::MODE_SYNC, [ 'rerank_enabled' => true ] )
		);
		$this->assertSame( [ $second, $first ], wp_list_pluck( $on['hits'], 'id' ) );
		$this->assertSame( 0.9, $on['hits'][0]['rerank_score'] );
	}
}
