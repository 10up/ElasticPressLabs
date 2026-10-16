<?php
/**
 * Test source details (notes, citations) on Chat sources
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Export;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Handle;
use ElasticPressLabs\Feature\Chat\Sources;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Source details test class
 */
class TestChatSources extends BaseTestCase {
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

		Sources::reset();

		$this->storage = new Storage();
		$this->storage->register_post_type();
		$this->storage->setup();

		wp_set_current_user( $this->ep_factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Reset the cache.
	 */
	public function tear_down() {
		Sources::reset();

		parent::tear_down();
	}

	/**
	 * Notes are cleaned: tags stripped, a note without a label dropped, an unknown tone is
	 * `info`; the filter runs once per source and request.
	 *
	 * @group chat
	 */
	public function test_notes_are_cleaned_and_cached() {
		$this->assertSame( [], Sources::get_notes( 11 ) );

		Sources::reset();

		$calls = 0;

		add_filter(
			'ep_chat_source_notes',
			static function () use ( &$calls ) {
				++$calls;

				return [
					[
						'label'       => '<b>Heads up</b>',
						'description' => 'Read this first.',
						'text'        => "A <em>longer</em>\nnote.",
						'tone'        => 'shouting',
					],
					[ 'text' => 'No label.' ],
					'not an array',
				];
			}
		);

		$this->assertSame(
			[
				[
					'label'       => 'Heads up',
					'description' => 'Read this first.',
					'text'        => 'A longer note.',
					'tone'        => 'info',
				],
			],
			Sources::get_notes( 11 )
		);
		Sources::get_notes( 11 );

		$this->assertSame( 1, $calls );
		$this->assertSame( [], Sources::get_notes( 0 ) );
	}

	/**
	 * The default citation is neutral: site name, date, title, URL; a site can replace it.
	 *
	 * @group chat
	 */
	public function test_citation() {
		update_option( 'blogname', 'Example &amp; Co' );

		$post_id = $this->factory()->post->create( [ 'post_date' => '2026-03-02 10:00:00' ] );
		$hit     = [
			'id'    => $post_id,
			'title' => 'Facts about TikTok.',
			'url'   => 'https://example.test/tiktok/',
		];

		$this->assertSame( 'Example & Co. (2026, March 2). Facts about TikTok. https://example.test/tiktok/', Sources::get_citation( $hit ) );
		$this->assertSame( 'Example & Co. (n.d.). No post.', Sources::get_citation( [ 'title' => 'No post' ] ) );

		add_filter(
			'ep_chat_source_citation',
			static function ( $citation, $filtered_id, $filtered_hit ) {
				return "Custom: {$filtered_hit['title']} ({$filtered_id})";
			},
			10,
			3
		);

		$this->assertSame( "Custom: Facts about TikTok. ($post_id)", Sources::get_citation( $hit ) );
	}

	/**
	 * Loading a conversation, a sync reply, and the export carry each source's notes (read now,
	 * not stored with the answer); loading and the reply give cited sources a citation. Declined
	 * answers get no details.
	 *
	 * @group chat
	 */
	public function test_details_on_read_reply_and_export() {
		$hits = [
			[
				'id'     => 11,
				'number' => 1,
				'title'  => 'Noted report',
				'url'    => 'https://example.test/noted/',
			],
			[
				'id'     => 12,
				'number' => 2,
				'title'  => 'Other report',
				'url'    => 'https://example.test/other/',
			],
		];

		$accepted = $this->storage->accept_question( 'Q?' );
		$this->storage->complete_question(
			$accepted['ep_chat_id'],
			$accepted['question_id'],
			'Answer [[1]].',
			[
				'hits'       => $hits,
				'provenance' => [
					[
						'k'     => 1,
						'n'     => 1,
						'quote' => 'Quote.',
					],
				],
			]
		);

		// The note is added after the answer was written.
		add_filter(
			'ep_chat_source_notes',
			static function ( $notes, $post_id ) {
				if ( 11 === $post_id ) {
					$notes[] = [
						'label' => 'Heads up',
						'text'  => 'A note.',
						'tone'  => 'warning',
					];
				}

				return $notes;
			},
			10,
			2
		);

		$expected = [
			[
				'label'       => 'Heads up',
				'description' => '',
				'text'        => 'A note.',
				'tone'        => 'warning',
			],
		];

		$turns = $this->storage->get( $accepted['ep_chat_id'] )['turns'];
		$hit   = $turns[1]['payload']['hits'][0];

		$this->assertSame( $expected, $hit['notes'] );
		$this->assertSame( [], $turns[1]['payload']['hits'][1]['notes'] );
		$this->assertStringContainsString( 'Noted report. https://example.test/noted/', $hit['citation'] );
		$this->assertArrayNotHasKey( 'citation', $turns[1]['payload']['hits'][1], 'Only cited sources get a citation.' );

		$stored = get_comment_meta( $turns[1]['id'], Storage::META_PAYLOAD, true );

		$this->assertArrayNotHasKey( 'notes', $stored['hits'][0], 'Details are not stored with the answer.' );
		$this->assertArrayNotHasKey( 'citation', $stored['hits'][0] );

		$reply = ( new Job_Handle( $accepted['ep_chat_id'], $accepted['question_id'], Storage::STATUS_DONE, 'sync', 'sync' ) )
			->with_result(
				[
					'status'     => Storage::STATUS_DONE,
					'hits'       => $hits,
					'provenance' => [ [ 'n' => 2 ] ],
				],
				1
			)
			->to_rest();

		$this->assertSame( $expected, $reply['hits'][0]['notes'] );
		$this->assertArrayNotHasKey( 'citation', $reply['hits'][0] );
		$this->assertArrayHasKey( 'citation', $reply['hits'][1] );

		$declined = $this->storage->accept_question( 'Declined?' );
		$this->storage->complete_question( $declined['ep_chat_id'], $declined['question_id'], 'No answer.', [ 'hits' => $hits ], Storage::STATUS_REFUSED );

		$this->assertSame( $hits, $this->storage->get( $declined['ep_chat_id'] )['turns'][1]['payload']['hits'] );

		$json = '';

		( new Export(
			static function ( $piece ) use ( &$json ) {
				$json .= $piece;
			}
		) )->stream( [ 'view' => 'all' ], true, get_current_user_id() );

		$data = json_decode( $json, true );

		$this->assertSame( $expected, $data['conversations'][0]['turns'][1]['citations'][0]['notes'] );
	}
}
