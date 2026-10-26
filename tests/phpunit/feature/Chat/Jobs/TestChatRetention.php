<?php
/**
 * Test the Chat conversation retention
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Jobs\Reaper;
use ElasticPressLabs\Feature\Chat\Jobs\Retention;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Chat retention test class
 */
class TestChatRetention extends \WP_UnitTestCase {
	/**
	 * Storage instance.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->storage = new Storage();
		$this->storage->register_post_type();

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Keep conversations for a number of days.
	 *
	 * @param int $days Days.
	 * @return void
	 */
	protected function keep_for( int $days ) {
		add_filter(
			'ep_chat_retention_days',
			static function () use ( $days ) {
				return $days;
			}
		);
	}

	/**
	 * A conversation with one question, finished unless told otherwise.
	 *
	 * @param int  $days_ago Days since its last activity.
	 * @param bool $finished Whether the question is answered.
	 * @return int Conversation post ID.
	 */
	protected function conversation( int $days_ago, bool $finished = true ): int {
		$accepted = $this->storage->accept_question( 'What is ElasticPress?' );

		if ( $finished ) {
			$this->storage->complete_question( $accepted['ep_chat_id'], $accepted['question_id'], 'A search plugin.' );
		}

		$this->age( $accepted['ep_chat_id'], $days_ago );

		return $accepted['ep_chat_id'];
	}

	/**
	 * Move a conversation's last activity into the past.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @param int $days_ago   Days ago.
	 * @return void
	 */
	protected function age( int $ep_chat_id, int $days_ago ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			[
				'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
			],
			[ 'ID' => $ep_chat_id ]
		);

		clean_post_cache( $ep_chat_id );
	}

	/**
	 * An old conversation is deleted with its questions, answers, and their meta; a recent
	 * one is kept.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_old_conversation_is_deleted_and_recent_is_kept() {
		$old    = $this->conversation( 40 );
		$recent = $this->conversation( 5 );

		$comment_ids = get_comments(
			[
				'post_id' => $old,
				'type'    => '',
				'fields'  => 'ids',
				'status'  => 'any',
			]
		);

		$this->assertCount( 2, $comment_ids );

		$this->keep_for( 30 );

		$this->assertSame( 1, ( new Retention() )->sweep() );
		$this->assertNull( get_post( $old ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $recent ) );

		foreach ( $comment_ids as $comment_id ) {
			$this->assertNull( get_comment( $comment_id ) );
			$this->assertSame( [], get_comment_meta( $comment_id ) );
		}

		$this->assertCount(
			1,
			get_comments(
				[
					'post_id' => $recent,
					'type'    => Storage::COMMENT_TYPE_QUESTION,
				]
			)
		);
	}

	/**
	 * Without a number of days, nothing is deleted.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_zero_keeps_everything() {
		$old = $this->conversation( 4000 );

		$this->assertSame( 0, ( new Retention() )->get_days() );
		$this->assertSame( 0, ( new Retention() )->sweep() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $old ) );

		$this->keep_for( -5 );

		$this->assertSame( 0, ( new Retention() )->get_days(), 'A negative number keeps everything.' );
		$this->assertSame( 0, ( new Retention() )->sweep() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $old ) );
	}

	/**
	 * The Chat setting is the default, and the filter has the last word.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_setting_and_filter() {
		update_option( 'ep_feature_settings', [ 'chat' => [ 'retention_days' => '30' ] ] );

		$retention = new Retention();

		$this->assertSame( 30, $retention->get_days() );

		$old = $this->conversation( 40 );

		$this->assertSame( 1, $retention->sweep() );
		$this->assertNull( get_post( $old ) );

		$this->keep_for( 7 );

		$this->assertSame( 7, $retention->get_days() );
	}

	/**
	 * A conversation with a question that is queued or running is kept, however old; once
	 * the question is finished, it can go.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_conversation_with_active_question_is_kept() {
		$queued  = $this->conversation( 100, false );
		$running = $this->conversation( 100, false );

		$this->storage->claim_question( $this->get_question_id( $running ) );
		$this->age( $running, 100 );

		$this->keep_for( 30 );

		$this->assertSame( 0, ( new Retention() )->sweep() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $queued ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $running ) );

		$this->storage->complete_question( $queued, $this->get_question_id( $queued ), 'Done.' );
		$this->age( $queued, 100 );

		$this->assertSame( 1, ( new Retention() )->sweep() );
		$this->assertNull( get_post( $queued ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $running ) );
	}

	/**
	 * A new question or answer is activity: an old conversation with a recent follow-up is kept.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_follow_up_keeps_the_conversation() {
		$ep_chat_id = $this->conversation( 100 );

		$this->keep_for( 30 );

		$follow_up = $this->storage->accept_question( 'And the Labs plugin?', $ep_chat_id );
		$this->storage->complete_question( $ep_chat_id, $follow_up['question_id'], 'More features.' );

		$this->assertSame( 0, ( new Retention() )->sweep() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $ep_chat_id ) );
	}

	/**
	 * Adding a question or an answer moves the conversation's modified date to now.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_turns_touch_the_conversation() {
		$ep_chat_id = $this->conversation( 100, false );

		$this->assertEqualsWithDelta( time() - 100 * DAY_IN_SECONDS, strtotime( get_post( $ep_chat_id )->post_modified_gmt . ' UTC' ), 5 );

		$this->storage->add_question( $ep_chat_id, 'Another question?' );

		$this->assertEqualsWithDelta( time(), strtotime( get_post( $ep_chat_id )->post_modified_gmt . ' UTC' ), 5 );

		$this->age( $ep_chat_id, 100 );
		$this->storage->add_answer( $ep_chat_id, 'An answer.' );

		$this->assertEqualsWithDelta( time(), strtotime( get_post( $ep_chat_id )->post_modified_gmt . ' UTC' ), 5 );
	}

	/**
	 * Trashed conversations are deleted too.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_trashed_conversation_is_deleted() {
		$ep_chat_id = $this->conversation( 60 );

		wp_trash_post( $ep_chat_id );
		$this->age( $ep_chat_id, 60 );

		$this->keep_for( 30 );

		$this->assertSame( 1, ( new Retention() )->sweep() );
		$this->assertNull( get_post( $ep_chat_id ) );
	}

	/**
	 * One sweep deletes at most the batch size, the oldest first, and the next one continues.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_batch_size() {
		$oldest = $this->conversation( 90 );
		$older  = $this->conversation( 80 );
		$old    = $this->conversation( 70 );

		$this->keep_for( 30 );

		add_filter(
			'ep_chat_retention_batch_size',
			static function () {
				return 2;
			}
		);

		$retention = new Retention();

		$this->assertSame( 2, $retention->sweep() );
		$this->assertNull( get_post( $oldest ) );
		$this->assertNull( get_post( $older ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $old ) );

		$this->assertSame( 1, $retention->sweep() );
		$this->assertNull( get_post( $old ) );
		$this->assertSame( 0, $retention->sweep() );
	}

	/**
	 * Other post types are never touched.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_only_conversations_are_deleted() {
		$post = self::factory()->post->create( [ 'post_date' => '2000-01-01 00:00:00' ] );
		$this->age( $post, 400 );

		$this->keep_for( 30 );

		$this->assertSame( 0, ( new Retention() )->sweep() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post ) );
	}

	/**
	 * The sweep runs after the Reaper's, on its schedule.
	 *
	 * @group chat
	 * @group chat-retention
	 */
	public function test_runs_after_the_reaper_sweep() {
		$retention = new Retention();
		$retention->setup();

		$this->assertSame( 20, has_action( Reaper::HOOK, [ $retention, 'sweep' ] ) );
	}

	/**
	 * The question of a conversation.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return int
	 */
	protected function get_question_id( int $ep_chat_id ): int {
		$ids = get_comments(
			[
				'post_id' => $ep_chat_id,
				'type'    => Storage::COMMENT_TYPE_QUESTION,
				'fields'  => 'ids',
			]
		);

		return (int) $ids[0];
	}
}
