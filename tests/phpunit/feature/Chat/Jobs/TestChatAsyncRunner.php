<?php
/**
 * Test the wp-async loopback worker authorization.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Jobs\EPLabs_Async_Runner;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Chat wp-async runner test class
 */
class TestChatAsyncRunner extends \WP_UnitTestCase {
	/**
	 * Storage instance.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Bots instance.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->storage = new Storage();
		$this->storage->register_post_type();

		$this->bots = new Bots();
		$this->bots->register_post_type();

		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
	}

	/**
	 * Create a bot and accept a question.
	 *
	 * @return array
	 */
	protected function accepted_job(): array {
		$bot      = $this->bots->get( $this->bots->create( 'Async bot', Bots::MODE_WP_ASYNC ) );
		$accepted = $this->storage->accept_question( 'What is ElasticPress?' );

		$this->storage->set_job_context(
			$accepted['question_id'],
			(int) $bot['id'],
			[]
		);

		return [
			'bot'      => $bot,
			'ids'      => $accepted,
			'question' => 'What is ElasticPress?',
		];
	}

	/**
	 * Dispatch and capture the loopback body.
	 *
	 * @param array $job Accepted job.
	 * @return array
	 */
	protected function loopback_body( array $job ): array {
		$body = [];

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$body ) {
				if ( false === strpos( $url, 'admin-post.php' ) ) {
					return $pre;
				}

				$body = $args['body'];

				return [
					'headers'  => [],
					'body'     => '',
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
				];
			},
			10,
			3
		);

		$runner = new EPLabs_Async_Runner();
		$runner->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);
		$runner->launch_on_shutdown();

		return $body;
	}

	/**
	 * Call handle() with no session, as the loopback does.
	 *
	 * @param array $body Posted body.
	 * @return void
	 */
	protected function call_handle( array $body ) {
		$runner = new EPLabs_Async_Runner();

		wp_set_current_user( 0 );
		$_POST = $body;

		try {
			$runner->handle();
		} catch ( \WPDieException $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// wp_die() from handle().
		}

		$_POST = [];
	}

	/**
	 * Build a token with the same hash as the runner.
	 *
	 * @param int $question_id Question comment ID.
	 * @param int $user_id     User ID.
	 * @return string
	 */
	protected function forge_token( int $question_id, int $user_id ): string {
		$runner = new EPLabs_Async_Runner();
		$method = new \ReflectionMethod( $runner, 'create_token' );
		$method->setAccessible( true );

		return $method->invoke( $runner, $question_id, $user_id );
	}

	/**
	 * Test a forged token for a regular comment does not run Chat.
	 *
	 * @group chat
	 */
	public function test_handle_rejects_non_question_comment() {
		$ran     = false;
		$user_id = get_current_user_id();
		$post_id = $this->factory()->post->create();

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Not a chat question.',
				'comment_type'     => 'comment',
				'comment_approved' => 1,
				'user_id'          => $user_id,
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( &$ran ) {
				$ran = true;

				return [];
			}
		);

		$this->call_handle(
			[
				'action'      => EPLabs_Async_Runner::ACTION,
				'_token'      => $this->forge_token( (int) $comment_id, $user_id ),
				'question_id' => (int) $comment_id,
				'user_id'     => $user_id,
			]
		);

		$this->assertFalse( $ran );
	}

	/**
	 * Test a bad token leaves the question queued.
	 *
	 * @group chat
	 */
	public function test_handle_rejects_invalid_token() {
		$job  = $this->accepted_job();
		$body = $this->loopback_body( $job );

		$body['_token'] = 'not-a-valid-token';

		$this->call_handle( $body );

		$this->assertSame(
			Storage::STATUS_QUEUED,
			$this->storage->get_status( $job['ids']['question_id'] )
		);
	}

	/**
	 * Test a token cannot be reused on another question id.
	 *
	 * @group chat
	 */
	public function test_handle_rejects_token_for_other_question() {
		$first  = $this->accepted_job();
		$second = $this->accepted_job();
		$body   = $this->loopback_body( $first );

		$body['question_id'] = $second['ids']['question_id'];

		$this->call_handle( $body );

		$this->assertSame(
			Storage::STATUS_QUEUED,
			$this->storage->get_status( $first['ids']['question_id'] )
		);
		$this->assertSame(
			Storage::STATUS_QUEUED,
			$this->storage->get_status( $second['ids']['question_id'] )
		);
	}

	/**
	 * Test a user without edit_posts cannot run the worker.
	 *
	 * @group chat
	 */
	public function test_handle_rejects_user_without_ask_capability() {
		$bot        = $this->bots->get( $this->bots->create( 'Async bot', Bots::MODE_WP_ASYNC ) );
		$subscriber = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $subscriber );

		$accepted = $this->storage->accept_question( 'What is ElasticPress?' );
		$this->storage->set_job_context(
			$accepted['question_id'],
			(int) $bot['id'],
			[]
		);

		$body = $this->loopback_body(
			[
				'bot'      => $bot,
				'ids'      => $accepted,
				'question' => 'What is ElasticPress?',
			]
		);

		$this->call_handle( $body );

		$this->assertSame(
			Storage::STATUS_QUEUED,
			$this->storage->get_status( $accepted['question_id'] )
		);
	}

	/**
	 * Test a logged-out user id is rejected even with a matching hash.
	 *
	 * @group chat
	 */
	public function test_handle_rejects_zero_user_id() {
		$job = $this->accepted_job();

		$this->call_handle(
			[
				'action'      => EPLabs_Async_Runner::ACTION,
				'_token'      => $this->forge_token( $job['ids']['question_id'], 0 ),
				'question_id' => $job['ids']['question_id'],
				'user_id'     => 0,
			]
		);

		$this->assertSame(
			Storage::STATUS_QUEUED,
			$this->storage->get_status( $job['ids']['question_id'] )
		);
	}
}
