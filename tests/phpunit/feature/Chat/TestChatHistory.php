<?php
/**
 * Test the Chat History screen, follow-up history, and conversation access
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Admin;
use ElasticPressLabs\Feature\Chat\History;
use ElasticPressLabs\Feature\Chat\History_List_Table;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Chat History test class
 */
class TestChatHistory extends \WP_UnitTestCase {
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
		$this->storage->setup();

		set_current_screen( 'ai-chat_page_' . History::PAGE_SLUG );
	}

	/**
	 * Clean up request globals.
	 */
	public function tear_down() {
		$_REQUEST = [];
		$_GET     = [];

		parent::tear_down();
	}

	/**
	 * Log in as a new user with a role.
	 *
	 * @param string $role Role slug.
	 * @return int
	 */
	protected function login_as( string $role ): int {
		$user_id = $this->factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Store a finished exchange.
	 *
	 * @param int    $ep_chat_id Conversation post ID.
	 * @param string $question   Question.
	 * @param string $answer     Answer.
	 * @param string $status     Question status.
	 * @return int Question comment ID.
	 */
	protected function exchange( int $ep_chat_id, string $question, string $answer, string $status = Storage::STATUS_DONE ): int {
		$accepted = $this->storage->accept_question( $question, $ep_chat_id );
		$this->storage->complete_question( $accepted['ep_chat_id'], $accepted['question_id'], $answer, [], $status );

		return $accepted['question_id'];
	}

	/**
	 * Conversation IDs the list table shows for the current request.
	 *
	 * @param array $request Request args.
	 * @return array
	 */
	protected function listed( array $request = [] ): array {
		$_REQUEST = $request;
		$_GET     = $request;

		$table = new History_List_Table();
		$table->prepare_items();

		return array_map( 'intval', wp_list_pluck( $table->items, 'ID' ) );
	}

	/**
	 * Test reading a conversation needs the same access as editing it.
	 *
	 * @group chat
	 */
	public function test_see_equals_edit() {
		$owner      = $this->login_as( 'contributor' );
		$ep_chat_id = $this->storage->accept_question( 'Mine?' )['ep_chat_id'];

		$this->assertTrue( current_user_can( 'read_post', $ep_chat_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $ep_chat_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $ep_chat_id ) );

		$this->login_as( 'author' );
		$this->assertFalse( current_user_can( 'read_post', $ep_chat_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $ep_chat_id ) );

		$this->login_as( 'editor' );
		$this->assertTrue( current_user_can( 'read_post', $ep_chat_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $ep_chat_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $ep_chat_id ) );

		$post_id = $this->factory()->post->create( [ 'post_author' => $owner ] );
		$this->login_as( 'subscriber' );
		$this->assertTrue( current_user_can( 'read_post', $post_id ) );
	}

	/**
	 * Test history holds the last 4 finished messages, without markers or
	 * failed answers, and only turns before the question.
	 *
	 * @group chat
	 */
	public function test_history_is_last_finished_messages() {
		$this->login_as( 'editor' );

		$first      = $this->storage->accept_question( 'Q1?' );
		$ep_chat_id = $first['ep_chat_id'];
		$this->storage->complete_question( $ep_chat_id, $first['question_id'], 'A1 [[1]].' );

		$second = $this->exchange( $ep_chat_id, 'Q2?', 'A2 is 32% [[2]] and [[3]].' );
		$this->exchange( $ep_chat_id, 'Failed?', 'Provider failed.', Storage::STATUS_ERROR );

		$timed_out = $this->storage->accept_question( 'Timed out?', $ep_chat_id );
		$this->storage->claim_question( $timed_out['question_id'] );
		$this->storage->time_out_question( $timed_out['question_id'] );

		$this->exchange( $ep_chat_id, 'Q3?', 'Nothing found.', Storage::STATUS_REFUSED );

		$current = $this->storage->accept_question( 'Q4?', $ep_chat_id );

		$this->assertSame(
			[
				[
					'role'    => 'user',
					'content' => 'Q2?',
				],
				[
					'role'    => 'assistant',
					'content' => 'A2 is 32% and.',
				],
				[
					'role'    => 'user',
					'content' => 'Q3?',
				],
				[
					'role'    => 'assistant',
					'content' => 'Nothing found.',
				],
			],
			$this->storage->get_history( $ep_chat_id, $current['question_id'] )
		);

		$this->assertSame(
			[
				[
					'role'    => 'user',
					'content' => 'Q1?',
				],
				[
					'role'    => 'assistant',
					'content' => 'A1.',
				],
			],
			$this->storage->get_history( $ep_chat_id, $second )
		);
	}

	/**
	 * Test the history of a long conversation reads comments a page at a
	 * time, and still finds finished exchanges behind many failed ones.
	 *
	 * @group chat
	 * @group chat-bounded-queries
	 */
	public function test_history_of_long_conversation_is_paged() {
		$this->login_as( 'editor' );

		$first      = $this->storage->accept_question( 'Q1?' );
		$ep_chat_id = $first['ep_chat_id'];
		$this->storage->complete_question( $ep_chat_id, $first['question_id'], 'A1.' );

		foreach ( range( 2, 48 ) as $i ) {
			$this->exchange( $ep_chat_id, "Q$i?", "A$i." );
		}

		// More failed exchanges than fit in one page of comments.
		foreach ( range( 49, 60 ) as $i ) {
			$this->exchange( $ep_chat_id, "Q$i?", 'Provider failed.', Storage::STATUS_ERROR );
		}

		$current = $this->storage->accept_question( 'Q61?', $ep_chat_id );

		$limits = [];

		add_filter(
			'comments_clauses',
			static function ( $clauses ) use ( &$limits ) {
				$limits[] = $clauses['limits'];
				return $clauses;
			}
		);

		$expected = static function ( array $numbers ): array {
			$messages = [];

			foreach ( $numbers as $i ) {
				$messages[] = [
					'role'    => 'user',
					'content' => "Q$i?",
				];
				$messages[] = [
					'role'    => 'assistant',
					'content' => "A$i.",
				];
			}

			return $messages;
		};

		$this->assertSame( $expected( [ 47, 48 ] ), $this->storage->get_history( $ep_chat_id, $current['question_id'] ) );

		// Every query is bounded, and the second page was needed.
		$this->assertGreaterThan( 1, count( $limits ) );
		$this->assertNotContains( '', $limits );

		// An odd limit drops the oldest answer.
		add_filter(
			'ep_chat_history_messages',
			static function () {
				return 3;
			}
		);

		$this->assertSame( $expected( [ 48 ] ), $this->storage->get_history( $ep_chat_id, $current['question_id'] ) );

		// A question in the middle only sees what came before it (limit 3 drops the answer of Q1).
		$this->assertSame( [ 'Q2?', 'A2.' ], array_column( $this->storage->get_history( $ep_chat_id, $this->get_question_id( $ep_chat_id, 'Q3?' ) ), 'content' ) );
	}

	/**
	 * Question comment ID by its text.
	 *
	 * @param int    $ep_chat_id Conversation post ID.
	 * @param string $text       Question text.
	 * @return int
	 */
	protected function get_question_id( int $ep_chat_id, string $text ): int {
		$comments = get_comments(
			[
				'post_id' => $ep_chat_id,
				'type'    => Storage::COMMENT_TYPE_QUESTION,
				'search'  => $text,
			]
		);

		return (int) $comments[0]->comment_ID;
	}

	/**
	 * Test the history depth filter.
	 *
	 * @group chat
	 */
	public function test_history_depth_filter() {
		$this->login_as( 'editor' );

		$first      = $this->storage->accept_question( 'Q1?' );
		$ep_chat_id = $first['ep_chat_id'];
		$this->storage->complete_question( $ep_chat_id, $first['question_id'], 'A1.' );
		$this->exchange( $ep_chat_id, 'Q2?', 'A2.' );
		$this->exchange( $ep_chat_id, 'Q3?', 'A3.' );

		$this->assertCount( 4, $this->storage->get_history( $ep_chat_id ) );

		add_filter(
			'ep_chat_history_messages',
			static function () {
				return 6;
			}
		);
		$this->assertCount( 6, $this->storage->get_history( $ep_chat_id ) );

		add_filter(
			'ep_chat_history_messages',
			static function () {
				return 3;
			},
			15
		);
		$odd = $this->storage->get_history( $ep_chat_id );
		$this->assertCount( 2, $odd );
		$this->assertSame( 'user', $odd[0]['role'] );
		$this->assertSame( 'Q3?', $odd[0]['content'] );

		add_filter(
			'ep_chat_history_messages',
			static function () {
				return 0;
			},
			20
		);
		$this->assertSame( [], $this->storage->get_history( $ep_chat_id ) );
	}

	/**
	 * Test a follow-up times out an unfinished question in the same
	 * conversation, and its late answer is hidden as superseded.
	 *
	 * @group chat
	 */
	public function test_follow_up_supersedes_unfinished_question() {
		$this->login_as( 'editor' );

		$first = $this->storage->accept_question( 'Slow?' );
		$this->storage->claim_question( $first['question_id'] );

		$second = $this->storage->accept_question( 'Retry: slow?', $first['ep_chat_id'] );

		$this->assertSame( $first['ep_chat_id'], $second['ep_chat_id'] );
		$this->assertTrue( $this->storage->is_timed_out( $first['question_id'] ) );
		$this->assertSame(
			'Stopped waiting for this answer.',
			get_comments(
				[
					'parent' => $first['question_id'],
					'type'   => Storage::COMMENT_TYPE_ANSWER,
				]
			)[0]->comment_content
		);

		$late = $this->storage->complete_question( $first['ep_chat_id'], $first['question_id'], 'Late reply.' );

		$this->assertSame( Storage::STATUS_ERROR, $this->storage->get_status( $first['question_id'] ) );
		$this->assertTrue( get_comment_meta( $late, Storage::META_PAYLOAD, true )['superseded'] );
		$this->assertSame( Storage::STATUS_QUEUED, $this->storage->get_status( $second['question_id'] ) );
	}

	/**
	 * Test restore brings a conversation back as private, and delete
	 * permanently removes its turns.
	 *
	 * @group chat
	 */
	public function test_trash_restore_and_delete() {
		$this->login_as( 'author' );

		$accepted   = $this->storage->accept_question( 'Keep?' );
		$ep_chat_id = $accepted['ep_chat_id'];
		$history    = new History();

		$this->assertSame( 1, $history->apply_action( 'trash', [ $ep_chat_id ] ) );
		$this->assertSame( 'trash', get_post_status( $ep_chat_id ) );
		$this->assertWPError( $this->storage->get( $ep_chat_id ) );

		$this->assertSame( 1, $history->apply_action( 'untrash', [ $ep_chat_id ] ) );
		$this->assertSame( 'private', get_post_status( $ep_chat_id ) );
		$this->assertCount( 1, $this->storage->get( $ep_chat_id )['turns'] );

		$this->assertSame( 1, $history->apply_action( 'delete', [ $ep_chat_id ] ) );
		$this->assertNull( get_post( $ep_chat_id ) );
		$this->assertNull( get_comment( $accepted['question_id'] ) );
	}

	/**
	 * Test users cannot trash conversations they cannot edit.
	 *
	 * @group chat
	 */
	public function test_actions_check_access() {
		$this->login_as( 'author' );
		$ep_chat_id = $this->storage->accept_question( 'Mine?' )['ep_chat_id'];
		$post_id    = $this->factory()->post->create();

		$this->login_as( 'author' );
		$this->assertSame( 0, ( new History() )->apply_action( 'trash', [ $ep_chat_id, $post_id ] ) );
		$this->assertSame( 'private', get_post_status( $ep_chat_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Test the list shows users their own conversations, and editors
	 * everyone's with an author filter.
	 *
	 * @group chat
	 */
	public function test_list_scopes_by_author() {
		$alice      = $this->login_as( 'author' );
		$alice_chat = $this->storage->accept_question( 'Alice question?' )['ep_chat_id'];

		$this->login_as( 'author' );
		$bob_chat = $this->storage->accept_question( 'Bob question?' )['ep_chat_id'];

		$this->assertSame( [ $bob_chat ], $this->listed() );
		$this->assertSame( [ $bob_chat ], $this->listed( [ 'author' => (string) $alice ] ) );

		$this->login_as( 'editor' );
		$this->assertEqualsCanonicalizing( [ $alice_chat, $bob_chat ], $this->listed() );
		$this->assertSame( [ $alice_chat ], $this->listed( [ 'author' => (string) $alice ] ) );
	}

	/**
	 * Test search matches follow-up questions, and the Trash view lists
	 * only trashed conversations.
	 *
	 * @group chat
	 */
	public function test_list_search_and_trash_view() {
		$this->login_as( 'editor' );

		$first = $this->storage->accept_question( 'What about smartphones?' );
		$this->storage->complete_question( $first['ep_chat_id'], $first['question_id'], 'Answer.' );
		$this->storage->accept_question( 'And tablets in 2024?', $first['ep_chat_id'] );

		$other   = $this->storage->accept_question( 'Unrelated question?' )['ep_chat_id'];
		$trashed = $this->storage->accept_question( 'Trashed tablets?' )['ep_chat_id'];
		wp_trash_post( $trashed );

		$this->assertSame( [ $first['ep_chat_id'] ], $this->listed( [ 's' => 'tablets' ] ) );
		$this->assertSame( [], $this->listed( [ 's' => 'nothing matches' ] ) );
		$this->assertSame( [], $this->listed( [ 's' => wp_get_current_user()->user_email ] ) );
		$this->assertEqualsCanonicalizing( [ $first['ep_chat_id'], $other ], $this->listed() );
		$this->assertSame( [ $trashed ], $this->listed( [ 'post_status' => 'trash' ] ) );
		$this->assertSame(
			[ $trashed ],
			$this->listed(
				[
					'post_status' => 'trash',
					's'           => 'tablets',
				]
			)
		);
	}

	/**
	 * Test the History search is capped, newest conversations first, and
	 * the cap is filterable.
	 *
	 * @group chat
	 * @group chat-bounded-queries
	 */
	public function test_search_is_capped() {
		$this->login_as( 'editor' );

		$conversations = [];

		foreach ( range( 1, 3 ) as $i ) {
			$conversations[] = $this->storage->accept_question( "Tablets question $i?" )['ep_chat_id'];
		}

		// Conversations only matched through their summary.
		$summarized = [];

		foreach ( range( 1, 3 ) as $i ) {
			$summarized[] = $this->factory()->post->create(
				[
					'post_type'    => Storage::POST_TYPE,
					'post_content' => "Summary about tablets $i.",
				]
			);
		}

		$all = array_merge( $conversations, $summarized );

		$this->assertEqualsCanonicalizing( $all, History_List_Table::search_conversation_ids( 'tablets', 0 ) );

		$limits = [];
		$sql    = [];

		add_filter(
			'ep_chat_history_search_limit',
			static function () {
				return 2;
			}
		);
		add_filter(
			'comments_clauses',
			static function ( $clauses ) use ( &$limits ) {
				$limits[] = $clauses['limits'];
				return $clauses;
			}
		);
		add_filter(
			'query',
			static function ( $query ) use ( &$sql ) {
				if ( false !== strpos( $query, 'post_content LIKE' ) ) {
					$sql[] = $query;
				}
				return $query;
			}
		);

		$found = History_List_Table::search_conversation_ids( 'tablets', 0 );

		// The two newest questions, and the two newest summaries.
		$this->assertEqualsCanonicalizing(
			array_merge( array_slice( $conversations, -2 ), array_slice( $summarized, -2 ) ),
			$found
		);
		$this->assertNotEmpty( $limits );
		$this->assertSame( 'LIMIT 0,2', $limits[0] );
		$this->assertCount( 1, $sql );
		$this->assertMatchesRegularExpression( '/LIMIT 2$/', trim( $sql[0] ) );
	}

	/**
	 * Test the Questions and Status columns.
	 *
	 * @group chat
	 */
	public function test_question_count_and_latest_status() {
		$this->login_as( 'editor' );

		$first      = $this->storage->accept_question( 'Q1?' );
		$ep_chat_id = $first['ep_chat_id'];
		$this->storage->complete_question( $ep_chat_id, $first['question_id'], 'A1.' );
		$this->exchange( $ep_chat_id, 'Q2?', 'Nothing found.', Storage::STATUS_REFUSED );

		$stale = $this->storage->accept_question( 'Stale?' );
		$this->storage->claim_question( $stale['question_id'] );
		$this->storage->time_out_question( $stale['question_id'] );

		$table = new History_List_Table();
		$table->prepare_items();

		$rows = [];

		foreach ( $table->items as $item ) {
			$rows[ (int) $item->ID ] = [ $table->column_questions( $item ), $table->column_status( $item ) ];
		}

		$this->assertSame( [ '2', 'Refused' ], $rows[ $ep_chat_id ] );
		$this->assertSame( [ '1', 'Timed out' ], $rows[ $stale['ep_chat_id'] ] );
	}

	/**
	 * Test the month filter only lists months of conversations the user
	 * can see.
	 *
	 * @group chat
	 */
	public function test_months_are_scoped_to_the_user() {
		$this->login_as( 'author' );
		$old = $this->storage->accept_question( 'Old?' )['ep_chat_id'];
		wp_update_post(
			[
				'ID'        => $old,
				'post_date' => '2024-03-10 10:00:00',
			]
		);

		$this->login_as( 'author' );
		$mine = $this->storage->accept_question( 'Mine?' )['ep_chat_id'];
		wp_update_post(
			[
				'ID'        => $mine,
				'post_date' => '2025-07-01 10:00:00',
			]
		);

		$table  = new History_List_Table();
		$months = $table->get_months( false, Storage::POST_TYPE );

		$this->assertCount( 1, $months );
		$this->assertSame( [ '2025', '7' ], [ (string) $months[0]->year, (string) $months[0]->month ] );

		$this->login_as( 'editor' );
		$this->assertCount( 2, $table->get_months( false, Storage::POST_TYPE ) );
		$this->assertFalse( $table->get_months( false, 'post' ) );
	}

	/**
	 * Test Ask's Previous questions: the user's own latest conversations,
	 * newest first, as plain text, with the count filter.
	 *
	 * @group chat
	 */
	public function test_recent_conversations() {
		$this->login_as( 'editor' );
		$this->storage->accept_question( 'Someone else?' );

		$this->login_as( 'author' );
		$old = $this->storage->accept_question( 'Old & first?' )['ep_chat_id'];
		wp_update_post(
			[
				'ID'        => $old,
				'post_date' => '2024-01-01 10:00:00',
			]
		);
		$new = $this->storage->accept_question( 'Newer?' )['ep_chat_id'];

		$trashed = $this->storage->accept_question( 'Trashed?' )['ep_chat_id'];
		wp_trash_post( $trashed );

		$recent = ( new Admin() )->get_recent_conversations();

		$this->assertSame( [ $new, $old ], wp_list_pluck( $recent, 'id' ) );
		$this->assertSame( 'Old & first?', $recent[1]['title'] );
		$this->assertStringContainsString( 'chat=' . $new, $recent[0]['url'] );
		$this->assertNotEmpty( $recent[0]['date'] );

		add_filter(
			'ep_chat_recent_conversations_count',
			static function () {
				return 1;
			}
		);
		$this->assertSame( [ $new ], wp_list_pluck( ( new Admin() )->get_recent_conversations(), 'id' ) );

		add_filter(
			'ep_chat_recent_conversations_count',
			static function () {
				return 0;
			},
			20
		);
		$this->assertSame( [], ( new Admin() )->get_recent_conversations() );
	}

	/**
	 * Test searching Ask's Previous questions: the user's own conversations whose
	 * question, follow-up, or summary matches, up to the count filter.
	 *
	 * @group chat
	 */
	public function test_recent_conversations_search() {
		$this->login_as( 'editor' );
		$this->storage->accept_question( 'Tablets for someone else?' );

		$this->login_as( 'author' );
		$first = $this->storage->accept_question( 'What about smartphones?' );
		$this->storage->complete_question( $first['ep_chat_id'], $first['question_id'], 'Answer.' );
		$this->storage->accept_question( 'And tablets in 2024?', $first['ep_chat_id'] );

		$summarized = $this->storage->accept_question( 'Devices at home?' )['ep_chat_id'];
		wp_update_post(
			[
				'ID'           => $summarized,
				'post_content' => 'The user asked about tablets and laptops.',
			]
		);

		$this->storage->accept_question( 'Unrelated question?' );

		$trashed = $this->storage->accept_question( 'Trashed tablets?' )['ep_chat_id'];
		wp_trash_post( $trashed );

		$admin = new Admin();

		$this->assertEqualsCanonicalizing(
			[ $first['ep_chat_id'], $summarized ],
			wp_list_pluck( $admin->get_recent_conversations( 'tablets' ), 'id' )
		);
		$this->assertSame( [], $admin->get_recent_conversations( 'nothing matches' ) );
		$this->assertCount( 3, $admin->get_recent_conversations( '  ' ) );

		add_filter(
			'ep_chat_recent_conversations_count',
			static function () {
				return 1;
			}
		);
		$this->assertCount( 1, $admin->get_recent_conversations( 'tablets' ) );
	}

	/**
	 * Test History is a submenu of AI Chat, after Ask.
	 *
	 * @group chat
	 */
	public function test_history_submenu() {
		global $submenu;

		$this->login_as( 'administrator' );

		// Other tests register the same menu.
		$submenu = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		( new Admin() )->register_menu();

		$slugs = wp_list_pluck( $submenu[ Admin::PAGE_SLUG ], 2 );

		$this->assertSame( [ Admin::PAGE_SLUG, History::PAGE_SLUG ], array_values( $slugs ) );
		$this->assertStringContainsString( 'page=' . History::PAGE_SLUG, History::url() );
		$this->assertStringContainsString( 'chat=7', Admin::ask_url( 7 ) );
	}
}
