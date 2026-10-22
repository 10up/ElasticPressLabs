<?php
/**
 * Test Chat storage primitives
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Chat;
use ElasticPressLabs\Feature\Chat\Storage;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Chat storage test class
 */
class TestChatStorage extends \WP_UnitTestCase {
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

		Features::factory()->register_feature( new VectorEmbeddings() );
		Features::factory()->register_feature( new Chat() );

		$this->storage = new Storage();
		$this->storage->setup();
		$this->storage->register_post_type();

		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Utils\get_capability( 'chat' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Test the conversation post type is private and has no UI.
	 *
	 * @group chat
	 */
	public function test_registers_private_conversation_post_type() {
		$post_type = get_post_type_object( Storage::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->publicly_queryable );
		$this->assertFalse( $post_type->show_ui );
		$this->assertTrue( $post_type->exclude_from_search );
		$this->assertTrue( $post_type->map_meta_cap );
		$this->assertSame( 'edit_posts', $post_type->cap->create_posts );
		$this->assertSame( 'read_private_posts', $post_type->cap->read_private_posts );
		$this->assertSame( 'edit_others_posts', $post_type->cap->edit_others_posts );
	}

	/**
	 * Test a conversation stores no feature configuration.
	 *
	 * @group chat
	 */
	public function test_create_conversation_does_not_store_configuration() {
		$conversation_id = $this->storage->create_conversation( 'Climate thread' );

		$this->assertIsInt( $conversation_id );
		$this->assertSame( Storage::POST_TYPE, get_post_type( $conversation_id ) );
		$this->assertSame( 'private', get_post_status( $conversation_id ) );
		$this->assertSame( 'Climate thread', get_post( $conversation_id )->post_title );
		$this->assertSame( [], get_post_meta( $conversation_id ) );
	}

	/**
	 * Test questions and answers are stored as comments.
	 *
	 * @group chat
	 */
	public function test_stores_turns_as_comments_with_question_status() {
		$conversation_id = $this->storage->create_conversation();
		$question_id     = $this->storage->add_question( $conversation_id, 'What is climate change?' );

		$this->assertIsInt( $question_id );
		$this->assertSame( Storage::STATUS_QUEUED, $this->storage->get_status( $question_id ) );

		$answer_id = $this->storage->add_answer(
			$conversation_id,
			'A long-term shift in temperatures.',
			[ 'citations' => [ 1 ] ],
			$question_id
		);

		$this->assertIsInt( $answer_id );
		$this->assertTrue( $this->storage->set_status( $question_id, Storage::STATUS_DONE ) );
		$this->assertSame( Storage::STATUS_DONE, $this->storage->get_status( $question_id ) );

		$question = get_comment( $question_id );
		$answer   = get_comment( $answer_id );

		$this->assertSame( Storage::COMMENT_TYPE_QUESTION, $question->comment_type );
		$this->assertSame( Storage::COMMENT_TYPE_ANSWER, $answer->comment_type );
		$this->assertSame( $question_id, (int) $answer->comment_parent );
		$this->assertSame( [ 'citations' => [ 1 ] ], get_comment_meta( $answer_id, Storage::META_PAYLOAD, true ) );

		$turns = $this->storage->get_turns( $conversation_id );

		$this->assertCount( 2, $turns );
		$this->assertSame( 'user', $turns[0]['role'] );
		$this->assertSame( 'assistant', $turns[1]['role'] );
		$this->assertSame( Storage::STATUS_DONE, $turns[0]['status'] );
		$this->assertSame( Storage::STATUS_DONE, $turns[1]['status'] );
	}

	/**
	 * Test Chat comments are omitted from default comment queries.
	 *
	 * @group chat
	 */
	public function test_chat_comments_are_excluded_from_default_queries() {
		$conversation_id = $this->storage->create_conversation();
		$this->storage->add_question( $conversation_id, 'What is climate change?' );

		$post_id = $this->factory()->post->create();
		$this->factory()->comment->create( [ 'comment_post_ID' => $post_id ] );

		$default = get_comments( [ 'status' => 'approve' ] );
		$types   = wp_list_pluck( $default, 'comment_type' );

		$this->assertNotContains( Storage::COMMENT_TYPE_QUESTION, $types );
		$this->assertNotContains( Storage::COMMENT_TYPE_ANSWER, $types );

		$explicit = get_comments(
			[
				'post_id' => $conversation_id,
				'type'    => Storage::COMMENT_TYPE_QUESTION,
			]
		);

		$this->assertCount( 1, $explicit );
	}

	/**
	 * Test empty turns and invalid conversations are rejected.
	 *
	 * @group chat
	 */
	public function test_rejects_empty_turns_and_invalid_conversations() {
		$empty = $this->storage->add_question( 1, '   ' );
		$this->assertWPError( $empty );
		$this->assertSame( 'ep_chat_empty_turn', $empty->get_error_code() );

		$post_id = $this->factory()->post->create();
		$wrong   = $this->storage->add_question( $post_id, 'What is climate change?' );
		$this->assertWPError( $wrong );
		$this->assertSame( 'ep_chat_invalid_conversation', $wrong->get_error_code() );
	}

	/**
	 * Test conversations are not an indexable post type.
	 *
	 * @group chat
	 */
	public function test_conversations_are_not_indexable() {
		$types = apply_filters( 'ep_indexable_post_types', [ Storage::POST_TYPE => Storage::POST_TYPE ] );

		$this->assertArrayNotHasKey( Storage::POST_TYPE, $types );
	}

	/**
	 * Test accepting a question creates a titled conversation and queued comment.
	 *
	 * @group chat
	 */
	public function test_accept_question_uses_question_as_title() {
		$accepted = $this->storage->accept_question( 'What share of adults use social media?' );

		$this->assertIsArray( $accepted );
		$this->assertSame(
			'What share of adults use social media?',
			get_post( $accepted['ep_chat_id'] )->post_title
		);
		$this->assertSame(
			Storage::STATUS_QUEUED,
			$this->storage->get_status( $accepted['question_id'] )
		);

		$loaded = $this->storage->get( $accepted['ep_chat_id'] );

		$this->assertSame( $accepted['ep_chat_id'], $loaded['ep_chat_id'] );
		$this->assertCount( 1, $loaded['turns'] );
		$this->assertSame( [], $loaded['turns'][0]['payload'] );
	}

	/**
	 * Test completing a question stores the answer payload and status.
	 *
	 * @group chat
	 */
	public function test_complete_question_stores_answer_payload() {
		$accepted  = $this->storage->accept_question( 'What is climate change?' );
		$answer_id = $this->storage->complete_question(
			$accepted['ep_chat_id'],
			$accepted['question_id'],
			'A long-term shift in temperatures.',
			[ 'hits' => [ [ 'id' => 1 ] ] ],
			Storage::STATUS_DONE
		);

		$this->assertIsInt( $answer_id );
		$this->assertSame(
			Storage::STATUS_DONE,
			$this->storage->get_status( $accepted['question_id'] )
		);

		$loaded = $this->storage->get( $accepted['ep_chat_id'] );

		$this->assertCount( 2, $loaded['turns'] );
		// Loading adds each source's notes (none here); nothing is cited, so no citation.
		$this->assertSame(
			[
				'hits' => [
					[
						'id'    => 1,
						'notes' => [],
					],
				],
			],
			$loaded['turns'][1]['payload']
		);
	}

	/**
	 * Test worker context is stored on the question comment.
	 *
	 * @group chat
	 */
	public function test_job_context_is_stored_on_the_question() {
		$accepted = $this->storage->accept_question( 'What is ElasticPress?' );
		$history  = [
			[
				'role'    => 'user',
				'content' => 'Earlier question',
			],
		];

		$this->assertTrue(
			$this->storage->set_job_context( $accepted['question_id'], 42, $history )
		);

		$context = $this->storage->get_job_context( $accepted['question_id'] );

		$this->assertSame( $accepted['ep_chat_id'], $context['ep_chat_id'] );
		$this->assertSame( 'What is ElasticPress?', $context['question'] );
		$this->assertSame( 42, $context['bot_id'] );
		$this->assertSame( $history, $context['messages'] );
		$this->assertSame( 0, $context['answer_id'] );
	}

	/**
	 * Test get rejects unknown IDs.
	 *
	 * @group chat
	 */
	public function test_get_rejects_unknown_conversation() {
		$missing = $this->storage->get( 999999 );

		$this->assertWPError( $missing );
		$this->assertSame( 'ep_chat_invalid_conversation', $missing->get_error_code() );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}
}
