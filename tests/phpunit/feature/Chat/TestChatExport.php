<?php
/**
 * Test the Chat JSON export
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Export;
use ElasticPressLabs\Feature\Chat\History;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Chat export test class
 */
class TestChatExport extends BaseTestCase {
	/**
	 * Storage.
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
		( new Bots() )->register_post_type();
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
		$user_id = $this->factory()->user->create(
			[
				'role'         => $role,
				'display_name' => 'Name ' . $role,
			]
		);
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Export and decode, counting the pieces written.
	 *
	 * @param array $filters History filters.
	 * @param bool  $see_all Whether the user sees everyone's conversations.
	 * @param int   $pieces  Set to the number of writes.
	 * @return array
	 */
	protected function export( array $filters = [ 'view' => 'all' ], bool $see_all = true, &$pieces = 0 ): array {
		$json   = '';
		$pieces = 0;

		( new Export(
			static function ( $piece ) use ( &$json, &$pieces ) {
				$json .= $piece;
				++$pieces;
			}
		) )->stream( $filters, $see_all, get_current_user_id() );

		$data = json_decode( $json, true );

		$this->assertIsArray( $data, 'The export is valid JSON.' );

		return $data;
	}

	/**
	 * The envelope, a conversation, its question and answer, and nothing an audit does not need.
	 *
	 * @group chat
	 */
	public function test_shape() {
		$user_id = $this->login_as( 'editor' );
		$bot_id  = ( new Bots() )->create( 'Researcher', Bots::MODE_SYNC );

		$accepted = $this->storage->accept_question( 'What share of adults use TikTok?' );
		update_comment_meta( $accepted['question_id'], Storage::META_BOT_ID, $bot_id );
		update_comment_meta( $accepted['question_id'], Storage::META_HEARTBEAT, time() );

		$this->storage->complete_question(
			$accepted['ep_chat_id'],
			$accepted['question_id'],
			'About 37%[[1]] of adults use TikTok.',
			[
				'hits'         => [
					[
						'id'      => 11,
						'number'  => 1,
						'title'   => 'Facts about TikTok',
						'url'     => 'https://example.test/tiktok/',
						'date'    => 'March 2, 2026',
						'excerpt' => 'A long passage that is not exported.',
					],
					[
						'id'      => 12,
						'number'  => 2,
						'title'   => 'Other',
						'url'     => 'https://example.test/other/',
						'excerpt' => 'Also searched.',
					],
				],
				'provenance'   => [
					[
						'k'          => 1,
						'n'          => 1,
						'figure'     => '37%',
						'quote'      => '37% of U.S. adults use TikTok',
						'marks'      => [ [ 0, 3 ] ],
						'title'      => 'Facts about TikTok',
						'url'        => 'https://example.test/tiktok/',
						'date'       => 'March 2, 2026',
						'verify_url' => 'https://example.test/tiktok/#:~:text=37%25',
					],
				],
				'models'       => [
					'answer'   => 'fixture-ai::fixture-large',
					'verifier' => '',
				],
				'instructions' => [
					'source' => 'none',
					'hash'   => '',
				],
				'intent'       => [
					'kind'     => 'question',
					'model'    => 'fixture-ai::fixture-small',
					'fallback' => false,
				],
				'search'       => [
					'query'           => 'What share of U.S. adults use TikTok?',
					'published_after' => null,
					'recent'          => false,
				],
			]
		);

		wp_update_post(
			[
				'ID'           => $accepted['ep_chat_id'],
				'post_content' => "Asked about TikTok.\n\nKeywords: TikTok, social media",
			]
		);

		$data = $this->export();

		$this->assertSame( 1, $data['schema_version'] );
		$this->assertSame( $user_id, $data['exported_by']['id'] );
		$this->assertSame( home_url( '/' ), $data['site'] );
		$this->assertSame(
			[
				'view'   => 'all',
				'author' => null,
				'month'  => null,
				'search' => null,
			],
			$data['filters']
		);
		$this->assertSame( 1, $data['count'] );
		$this->assertSame( 'count', array_key_last( $data ) );

		$conversation = $data['conversations'][0];

		$this->assertSame( $accepted['ep_chat_id'], $conversation['id'] );
		$this->assertSame( 'What share of adults use TikTok?', $conversation['title'] );
		$this->assertSame(
			[
				'text'     => 'Asked about TikTok.',
				'keywords' => [ 'TikTok', 'social media' ],
			],
			$conversation['summary']
		);
		$this->assertSame( [ 'id', 'login', 'display_name' ], array_keys( $conversation['author'] ) );
		$this->assertSame( 'open', $conversation['status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $conversation['created'] );

		[ $question, $answer ] = $conversation['turns'];

		$this->assertSame( 'user', $question['role'] );
		$this->assertSame(
			[
				'id'   => $bot_id,
				'name' => 'Researcher',
			],
			$question['bot']
		);
		$this->assertSame( 'done', $question['status'] );

		$this->assertSame( 'assistant', $answer['role'] );
		$this->assertSame( $accepted['question_id'], $answer['in_reply_to'] );
		$this->assertSame( 'answered', $answer['outcome'] );
		$this->assertSame( 'About 37%[[1]] of adults use TikTok.', $answer['text'] );
		$this->assertNull( $answer['models']['verifier'] );
		$this->assertSame( 'question', $answer['intent']['kind'] );
		$this->assertSame( 'What share of U.S. adults use TikTok?', $answer['search']['query'] );
		$this->assertSame(
			[
				[
					'k'       => 1,
					'post_id' => 11,
					'title'   => 'Facts about TikTok',
					'url'     => 'https://example.test/tiktok/',
					'date'    => 'March 2, 2026',
					'figure'  => '37%',
					'quote'   => '37% of U.S. adults use TikTok',
					'notes'   => [],
				],
			],
			$answer['citations']
		);
		$this->assertSame(
			[
				[
					'post_id' => 11,
					'url'     => 'https://example.test/tiktok/',
				],
				[
					'post_id' => 12,
					'url'     => 'https://example.test/other/',
				],
			],
			$answer['sources_retrieved']
		);

		$json = wp_json_encode( $data );

		foreach ( [ wp_get_current_user()->user_email, 'A long passage', 'Also searched', 'verify_url', 'marks', 'heartbeat' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $json, $absent );
		}
	}

	/**
	 * The export follows the History filters and scope, and marks refusals and errors.
	 *
	 * @group chat
	 */
	public function test_filters_and_outcomes() {
		$alice = $this->login_as( 'author' );
		$mine  = $this->storage->accept_question( 'Alice on tablets?' );
		$this->storage->complete_question( $mine['ep_chat_id'], $mine['question_id'], 'Not covered.', [ 'refusal_reason' => 'not_in_sources' ], Storage::STATUS_REFUSED );

		$this->login_as( 'author' );
		$bob = $this->storage->accept_question( 'Bob on phones?' );
		$this->storage->complete_question( $bob['ep_chat_id'], $bob['question_id'], 'Failed.', [], Storage::STATUS_ERROR );

		$trashed = $this->storage->accept_question( 'Trashed tablets?' )['ep_chat_id'];
		wp_trash_post( $trashed );

		// Bob sees only his own.
		$this->assertSame( [ $bob['ep_chat_id'] ], wp_list_pluck( $this->export( [ 'view' => 'all' ], false )['conversations'], 'id' ) );

		$this->login_as( 'editor' );

		$all = $this->export();

		$this->assertEqualsCanonicalizing( [ $mine['ep_chat_id'], $bob['ep_chat_id'] ], wp_list_pluck( $all['conversations'], 'id' ) );
		$this->assertSame( [ $mine['ep_chat_id'] ], wp_list_pluck( $this->export( [ 'author' => $alice ] )['conversations'], 'id' ) );
		$this->assertSame( [ $mine['ep_chat_id'] ], wp_list_pluck( $this->export( [ 's' => 'tablets' ] )['conversations'], 'id' ) );
		$this->assertSame( 'tablets', $this->export( [ 's' => 'tablets' ] )['filters']['search'] );

		$trash = $this->export( [ 'view' => 'trash' ] );

		$this->assertSame( [ $trashed ], wp_list_pluck( $trash['conversations'], 'id' ) );
		$this->assertSame( 'trashed', $trash['conversations'][0]['status'] );
		$this->assertCount( 1, $trash['conversations'][0]['turns'], 'A trashed conversation keeps its turns.' );

		$by_id = array_column( $all['conversations'], null, 'id' );

		$this->assertSame( 'refused', $by_id[ $mine['ep_chat_id'] ]['turns'][1]['outcome'] );
		$this->assertSame( 'not_in_sources', $by_id[ $mine['ep_chat_id'] ]['turns'][1]['refusal_reason'] );
		$this->assertSame( 'error', $by_id[ $bob['ep_chat_id'] ]['turns'][1]['outcome'] );
		$this->assertSame( 'error', $by_id[ $bob['ep_chat_id'] ]['turns'][0]['status'] );
	}

	/**
	 * A question the reaper timed out is `timed_out`, and its timeout answer carries the code.
	 *
	 * @group chat
	 */
	public function test_timed_out_question() {
		$this->login_as( 'editor' );

		$asked = $this->storage->accept_question( 'Slow question?' );

		$this->assertTrue( $this->storage->time_out_question( $asked['question_id'] ) );

		$turns = $this->export()['conversations'][0]['turns'];

		$this->assertSame( 'timed_out', $turns[0]['status'] );
		$this->assertSame( 'error', $turns[1]['outcome'] );
		$this->assertSame( Storage::ERROR_TIMEOUT, $turns[1]['error_code'] );
	}

	/**
	 * Conversations are read in chunks and each is written on its own.
	 *
	 * @group chat
	 */
	public function test_streams_in_chunks() {
		$this->login_as( 'editor' );

		$ids = [];

		for ( $i = 0; $i < Export::CHUNK + 5; $i++ ) {
			$ids[] = $this->storage->accept_question( "Question $i?" )['ep_chat_id'];
		}

		$queries = 0;

		add_action(
			'pre_get_comments',
			static function ( $query ) use ( &$queries ) {
				if ( [ Storage::COMMENT_TYPE_QUESTION, Storage::COMMENT_TYPE_ANSWER ] === ( $query->query_vars['type__in'] ?? null ) && ! empty( $query->query_vars['post__in'] ) ) {
					++$queries;
				}
			}
		);

		$pieces = 0;
		$data   = $this->export( [ 'view' => 'all' ], true, $pieces );

		$this->assertSame( Export::CHUNK + 5, $data['count'] );
		$this->assertEqualsCanonicalizing( $ids, wp_list_pluck( $data['conversations'], 'id' ) );
		$this->assertSame( 2, $queries, 'One comment query per chunk.' );
		$this->assertSame( Export::CHUNK + 5 + 2, $pieces, 'The envelope, each conversation, and the end.' );
	}

	/**
	 * The History screen links the export only for users who see everyone's conversations, and
	 * the download refuses anyone else.
	 *
	 * @group chat
	 */
	public function test_history_download_access() {
		$this->login_as( 'editor' );
		set_current_screen( 'ai-chat_page_' . History::PAGE_SLUG );

		$_REQUEST = [
			'author' => '5',
			's'      => 'tablets',
		];
		$_GET     = $_REQUEST;

		$history = new History();
		$history->load();

		$url = $history->export_url();

		$this->assertStringContainsString( History::EXPORT_ARG . '=1', $url );
		$this->assertStringContainsString( 'author=5', $url );
		$this->assertStringContainsString( 's=tablets', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );

		ob_start();
		$history->render();
		$this->assertStringContainsString( '>Export</a>', ob_get_clean() );

		// Without a valid nonce.
		$_GET = [ History::EXPORT_ARG => '1' ];

		try {
			( new History() )->load();
			$this->fail( 'The download needs a nonce.' );
		} catch ( \WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		}

		// A user who sees only their own conversations.
		$this->login_as( 'author' );
		$_REQUEST = [];
		$_GET     = [
			History::EXPORT_ARG => '1',
			'_wpnonce'          => wp_create_nonce( History::EXPORT_ARG ),
		];

		ob_start();
		( new History() )->render();
		$this->assertStringNotContainsString( '>Export</a>', ob_get_clean() );

		try {
			( new History() )->load();
			$this->fail( 'The download needs the capability.' );
		} catch ( \WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed to export', $exception->getMessage() );
		}
	}

	/**
	 * The download file name.
	 *
	 * @group chat
	 */
	public function test_filename() {
		$this->assertMatchesRegularExpression( '/^ai-chat-export-[a-z0-9-]+-\d{4}-\d\d-\d\d\.json$/', Export::get_filename() );
	}
}
