<?php
/**
 * Test Chat dispatcher and runners
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Jobs\Action_Scheduler_Runner;
use ElasticPressLabs\Feature\Chat\Jobs\Dispatcher;
use ElasticPressLabs\Feature\Chat\Jobs\EPLabs_Async_Runner;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Handle;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Processor;
use ElasticPressLabs\Feature\Chat\Jobs\Sync_Runner;
use ElasticPressLabs\Feature\Chat\Jobs\WP_Cron_Runner;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Chat jobs test class
 */
class TestChatJobs extends \WP_UnitTestCase {
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
	 * @param string $mode Execution mode.
	 * @return array
	 */
	protected function accepted_job( string $mode = Bots::MODE_SYNC ): array {
		$bot      = $this->bots->get( $this->bots->create( 'Job bot', $mode ) );
		$accepted = $this->storage->accept_question( 'What is ElasticPress?' );

		return [
			'bot'      => $bot,
			'ids'      => $accepted,
			'question' => 'What is ElasticPress?',
		];
	}

	/**
	 * Stub retrieve and synthesis.
	 *
	 * @param string $reply Generated reply.
	 * @return void
	 */
	protected function stub_pipeline( string $reply = 'Queued reply.' ) {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'ElasticPress Guide',
				'post_content' => 'ElasticPress is a WordPress search plugin.',
				'post_status'  => 'publish',
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
			static function () use ( $reply ) {
				return $reply;
			}
		);
	}

	/**
	 * Test the dispatcher honors the bot mode and both filters.
	 *
	 * @group chat
	 */
	public function test_dispatcher_honors_mode_and_runner_filters() {
		$job  = $this->accepted_job( Bots::MODE_SYNC );
		$seen = [];
		$cron = new WP_Cron_Runner();

		add_filter(
			'ep_chat_execution_mode',
			static function ( $mode ) use ( &$seen ) {
				$seen['mode'] = $mode;

				return Job_Handle::MODE_ASYNC;
			}
		);

		add_filter(
			'ep_chat_runner',
			static function ( $runner, $mode ) use ( &$seen, $cron ) {
				$seen['runner_mode'] = $mode;

				return $cron;
			},
			10,
			2
		);

		$handle = ( new Dispatcher() )->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertSame( Job_Handle::MODE_SYNC, $seen['mode'] );
		$this->assertSame( Job_Handle::MODE_ASYNC, $seen['runner_mode'] );
		$this->assertInstanceOf( Job_Handle::class, $handle );
		$this->assertSame( Job_Handle::RUNNER_WP_CRON, $handle->get_runner() );
		$this->assertFalse( $handle->is_complete() );
		$this->assertNotFalse(
			wp_next_scheduled(
				WP_Cron_Runner::HOOK,
				[ $job['ids']['question_id'], Job_Handle::RUNNER_WP_CRON ]
			)
		);
	}

	/**
	 * Test the dispatcher uses the bot runner slug.
	 *
	 * @group chat
	 */
	public function test_dispatcher_uses_bot_runner_slug() {
		$job = $this->accepted_job( Bots::MODE_WP_CRON );

		$handle = ( new Dispatcher() )->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertInstanceOf( Job_Handle::class, $handle );
		$this->assertSame( Job_Handle::RUNNER_WP_CRON, $handle->get_runner() );
		$this->assertNotFalse(
			wp_next_scheduled(
				WP_Cron_Runner::HOOK,
				[ $job['ids']['question_id'], Job_Handle::RUNNER_WP_CRON ]
			)
		);
	}

	/**
	 * Test Sync_Runner returns a finished handle in-request.
	 *
	 * @group chat
	 */
	public function test_sync_runner_completes_in_request() {
		$this->stub_pipeline( 'Sync reply.' );

		$job    = $this->accepted_job();
		$handle = ( new Sync_Runner() )->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertInstanceOf( Job_Handle::class, $handle );
		$this->assertTrue( $handle->is_complete() );
		$this->assertSame( Storage::STATUS_DONE, $handle->get_status() );
		$this->assertSame( 'Sync reply.', $handle->to_rest()['content'] );
		$this->assertSame(
			Storage::STATUS_DONE,
			$this->storage->get_status( $job['ids']['question_id'] )
		);
	}

	/**
	 * Test the wp-async runner posts to admin-post.php on shutdown.
	 *
	 * @group chat
	 */
	public function test_eplabs_async_runner_enqueues_loopback() {
		$job      = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$requests = [];

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$requests ) {
				if ( false === strpos( $url, 'admin-post.php' ) ) {
					return $pre;
				}

				$requests[] = $args;

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
		$handle = $runner->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertFalse( $handle->is_complete() );
		$this->assertSame( Job_Handle::RUNNER_WP_ASYNC, $handle->get_runner() );

		$runner->launch_on_shutdown();

		$this->assertCount( 1, $requests );
		$this->assertSame(
			'local' !== wp_get_environment_type(),
			$requests[0]['sslverify']
		);
		$this->assertSame(
			$job['ids']['question_id'],
			(int) $requests[0]['body']['question_id']
		);
		$this->assertNotEmpty( $requests[0]['body']['_token'] );
	}

	/**
	 * Test the loopback worker accepts a token without a session cookie.
	 *
	 * @group chat
	 */
	public function test_eplabs_async_runner_handle_accepts_token_without_session() {
		$this->stub_pipeline( 'Loopback reply.' );

		$job = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$this->storage->set_job_context(
			$job['ids']['question_id'],
			(int) $job['bot']['id'],
			[]
		);

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

		wp_set_current_user( 0 );
		$_POST = $body;

		try {
			$runner->handle();
		} catch ( \WPDieException $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// wp_die() after process().
		}

		$this->assertSame(
			Storage::STATUS_DONE,
			$this->storage->get_status( $job['ids']['question_id'] )
		);
	}

	/**
	 * Test Action Scheduler enqueues when the library is loaded.
	 *
	 * @group chat
	 */
	public function test_action_scheduler_runner_enqueues_when_available() {
		$runner = new Action_Scheduler_Runner();

		if ( ! $runner->is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in this environment.' );
		}

		$job    = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$handle = $runner->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertInstanceOf( Job_Handle::class, $handle );
		$this->assertSame( Job_Handle::RUNNER_ACTION_SCHEDULER, $handle->get_runner() );
		$this->assertTrue(
			as_has_scheduled_action(
				Action_Scheduler_Runner::HOOK,
				[ $job['ids']['question_id'], Job_Handle::RUNNER_ACTION_SCHEDULER ],
				Action_Scheduler_Runner::GROUP
			)
		);
	}

	/**
	 * Test Action Scheduler is reported unavailable when the API is missing.
	 *
	 * @group chat
	 */
	public function test_action_scheduler_runner_requires_library() {
		$runner = new Action_Scheduler_Runner();

		if ( $runner->is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is loaded in this environment.' );
		}

		$job    = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$result = $runner->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertFalse( $runner->is_available() );
		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_runner_unavailable', $result->get_error_code() );
	}

	/**
	 * Test an unavailable runner falls back to WP-Cron.
	 *
	 * @group chat
	 */
	public function test_dispatcher_falls_back_when_runner_unavailable() {
		$job = $this->accepted_job( Bots::MODE_WP_ASYNC );

		add_filter(
			'ep_chat_runner',
			static function () {
				return new Action_Scheduler_Runner();
			}
		);

		if ( ( new Action_Scheduler_Runner() )->is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is loaded in this environment.' );
		}

		$handle = ( new Dispatcher() )->dispatch(
			$job['question'],
			[],
			$job['bot'],
			$job['ids']
		);

		$this->assertInstanceOf( Job_Handle::class, $handle );
		$this->assertSame( Job_Handle::RUNNER_WP_CRON, $handle->get_runner() );
		$this->assertNotFalse(
			wp_next_scheduled(
				WP_Cron_Runner::HOOK,
				[ $job['ids']['question_id'], Job_Handle::RUNNER_WP_CRON ]
			)
		);
	}

	/**
	 * Test the worker writes done/refused/error on the question.
	 *
	 * @group chat
	 */
	public function test_job_processor_writes_terminal_status() {
		$this->stub_pipeline( 'Worker reply.' );

		$job = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$this->storage->set_job_context(
			$job['ids']['question_id'],
			$job['bot']['id'],
			[]
		);

		$handle = ( new Job_Processor() )->process( $job['ids']['question_id'] );

		$this->assertInstanceOf( Job_Handle::class, $handle );
		$this->assertSame( Storage::STATUS_DONE, $handle->get_status() );
		$this->assertSame( 'Worker reply.', $handle->to_rest()['content'] );

		add_filter( 'ep_chat_retrieve', '__return_empty_array', 20 );

		$refused_job = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$this->storage->set_job_context(
			$refused_job['ids']['question_id'],
			$refused_job['bot']['id'],
			[]
		);

		$refused = ( new Job_Processor() )->process( $refused_job['ids']['question_id'] );

		$this->assertSame( Storage::STATUS_REFUSED, $refused->get_status() );
	}

	/**
	 * Test an exception in the pipeline is reported through an action, while the person only
	 * sees a generic error.
	 *
	 * @group chat
	 */
	public function test_job_processor_reports_pipeline_exceptions() {
		$this->stub_pipeline();

		add_filter(
			'ep_chat_retrieve',
			static function () {
				throw new \RuntimeException( 'Gate bug.' );
			},
			20
		);

		$seen = [];

		add_action(
			'ep_chat_pipeline_exception',
			static function ( $e, $question_id ) use ( &$seen ) {
				$seen[] = [ $e->getMessage(), $question_id ];
			},
			10,
			2
		);

		$job = $this->accepted_job( Bots::MODE_WP_ASYNC );
		$this->storage->set_job_context( $job['ids']['question_id'], $job['bot']['id'], [] );

		$result = ( new Job_Processor() )->process( $job['ids']['question_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_pipeline_exception', $result->get_error_code() );
		$this->assertSame( 'The assistant could not generate a reply.', $result->get_error_message() );
		$this->assertSame( Storage::STATUS_ERROR, $this->storage->get_status( $job['ids']['question_id'], true ) );
		$this->assertSame( [ [ 'Gate bug.', $job['ids']['question_id'] ] ], $seen );
	}
}
