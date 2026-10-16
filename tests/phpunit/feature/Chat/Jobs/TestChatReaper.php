<?php
/**
 * Test the Chat running status, steps, and stale-job reaper
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Chat;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Handle;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Processor;
use ElasticPressLabs\Feature\Chat\Jobs\Reaper;
use ElasticPressLabs\Feature\Chat\Steps;
use ElasticPressLabs\Feature\Chat\Storage;
use ElasticPressLabs\REST\Chat as Chat_REST;

/**
 * Chat reaper test class
 */
class TestChatReaper extends \WP_UnitTestCase {
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
	 * Clean up the scheduled sweep.
	 */
	public function tear_down() {
		Reaper::unschedule();

		parent::tear_down();
	}

	/**
	 * Accept a question with worker context.
	 *
	 * @return array ep_chat_id, question_id, and bot.
	 */
	protected function accepted_job(): array {
		$bot      = $this->bots->get( $this->bots->create( 'Reaper bot', Bots::MODE_WP_ASYNC ) );
		$accepted = $this->storage->accept_question( 'What is ElasticPress?' );

		$this->storage->set_job_context( $accepted['question_id'], $bot['id'], [] );

		return array_merge( $accepted, [ 'bot' => $bot ] );
	}

	/**
	 * Move a question's last activity into the past.
	 *
	 * @param int $question_id Question comment ID.
	 * @param int $seconds     Seconds ago.
	 * @return void
	 */
	protected function age( int $question_id, int $seconds ) {
		update_comment_meta( $question_id, Storage::META_HEARTBEAT, time() - $seconds );
	}

	/**
	 * Stub retrieve and synthesis.
	 *
	 * @param callable|null $on_generate Called when the LLM stub runs.
	 * @return void
	 */
	protected function stub_pipeline( ?callable $on_generate = null ) {
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
			static function () use ( $on_generate ) {
				if ( $on_generate ) {
					$on_generate();
				}

				return 'Reaper reply.';
			}
		);
	}

	/**
	 * Answer comments of a question.
	 *
	 * @param int $question_id Question comment ID.
	 * @return array
	 */
	protected function answers( int $question_id ): array {
		return get_comments(
			[
				'parent' => $question_id,
				'type'   => Storage::COMMENT_TYPE_ANSWER,
				'status' => 'approve',
			]
		);
	}

	/**
	 * Test a new question records a heartbeat and only one worker claims it.
	 *
	 * @group chat
	 */
	public function test_claim_moves_queued_to_running_once() {
		$job = $this->accepted_job();

		$this->assertGreaterThan( 0, (int) get_comment_meta( $job['question_id'], Storage::META_HEARTBEAT, true ) );
		$this->assertTrue( $this->storage->claim_question( $job['question_id'] ) );
		$this->assertSame( Storage::STATUS_RUNNING, $this->storage->get_status( $job['question_id'] ) );
		$this->assertFalse( $this->storage->claim_question( $job['question_id'] ) );
		$this->assertFalse( ( new Job_Handle( 1, 2, Storage::STATUS_RUNNING, 'async', 'wp_async' ) )->is_complete() );
	}

	/**
	 * Test the worker stores each step and refreshes the heartbeat.
	 *
	 * @group chat
	 */
	public function test_worker_reports_steps_while_running() {
		$job      = $this->accepted_job();
		$seen     = [];
		$storage  = $this->storage;
		$question = $job['question_id'];

		$this->age( $question, 100 );

		$this->stub_pipeline(
			static function () use ( $storage, $question, &$seen ) {
				$seen = [
					'status'    => $storage->get_status( $question, true ),
					'step'      => $storage->get_step( $question ),
					'heartbeat' => $storage->get_heartbeat( $question ),
					'turns'     => $storage->get_turns( (int) get_comment( $question )->comment_post_ID ),
				];
			}
		);

		$handle = ( new Job_Processor() )->process( $question );

		$this->assertSame( Storage::STATUS_RUNNING, $seen['status'] );
		$this->assertSame( Steps::SYNTHESIZE, $seen['step'] );
		$this->assertGreaterThanOrEqual( time() - 5, $seen['heartbeat'] );
		$this->assertSame( Steps::SYNTHESIZE, $seen['turns'][0]['step'] );
		$this->assertSame( 'Writing the answer…', $seen['turns'][0]['step_label'] );

		$this->assertSame( Storage::STATUS_DONE, $handle->get_status() );
		$this->assertSame( '', $this->storage->get_step( $question ) );
		$this->assertFalse( has_action( 'ep_chat_step' ) );
	}

	/**
	 * Test step labels for queued, unknown, and filtered steps.
	 *
	 * @group chat
	 */
	public function test_step_labels() {
		$this->assertSame( 'Waiting to start…', Steps::label( Steps::RETRIEVE, Storage::STATUS_QUEUED ) );
		$this->assertSame( 'Searching sources…', Steps::label( Steps::RETRIEVE, Storage::STATUS_RUNNING ) );
		$this->assertSame( 'Thinking…', Steps::label( 'unknown_step', Storage::STATUS_RUNNING ) );
		$this->assertSame( 'Thinking…', Steps::label( '', Storage::STATUS_RUNNING ) );

		add_filter(
			'ep_chat_step_labels',
			static function ( $labels ) {
				$labels['figure_check'] = 'Checking every figure…';

				return $labels;
			}
		);

		$this->assertSame( 'Checking every figure…', Steps::label( 'figure_check', Storage::STATUS_RUNNING ) );
	}

	/**
	 * Test reading a conversation times out a stale job and returns the
	 * timeout answer.
	 *
	 * @group chat
	 */
	public function test_read_times_out_stale_job() {
		$job = $this->accepted_job();
		$this->age( $job['question_id'], 10 * MINUTE_IN_SECONDS );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'ep_chat_id', $job['ep_chat_id'] );

		$response = ( new Chat_REST() )->get_item( $request );

		$this->assertSame( Storage::STATUS_ERROR, $response['turns'][0]['status'] );
		$this->assertSame( 'assistant', $response['turns'][1]['role'] );
		$this->assertSame( Storage::ERROR_TIMEOUT, $response['turns'][1]['payload']['error_code'] );
		$this->assertTrue( $this->storage->is_timed_out( $job['question_id'] ) );
	}

	/**
	 * Test jobs with recent activity and finished jobs are left alone.
	 *
	 * @group chat
	 */
	public function test_fresh_and_finished_jobs_are_not_reaped() {
		$reaper = new Reaper();

		$fresh_queued = $this->accepted_job();
		$this->age( $fresh_queued['question_id'], 60 );

		$fresh_running = $this->accepted_job();
		$this->storage->claim_question( $fresh_running['question_id'] );
		$this->age( $fresh_running['question_id'], 60 );

		$finished = $this->accepted_job();
		$this->storage->complete_question( $finished['ep_chat_id'], $finished['question_id'], 'Done.' );
		$this->age( $finished['question_id'], HOUR_IN_SECONDS );

		$this->assertFalse( $reaper->reap_question( $fresh_queued['question_id'] ) );
		$this->assertFalse( $reaper->reap_question( $fresh_running['question_id'] ) );
		$this->assertFalse( $reaper->reap_question( $finished['question_id'] ) );
		$this->assertSame( 0, $reaper->sweep() );
		$this->assertSame( Storage::STATUS_DONE, $this->storage->get_status( $finished['question_id'] ) );
		$this->assertCount( 1, $this->answers( $finished['question_id'] ) );
	}

	/**
	 * Test the sweep uses each status ceiling and catches old questions
	 * with no heartbeat.
	 *
	 * @group chat
	 */
	public function test_sweep_times_out_stale_jobs() {
		$stale_queued = $this->accepted_job();
		$this->age( $stale_queued['question_id'], 6 * MINUTE_IN_SECONDS );

		$stale_running = $this->accepted_job();
		$this->storage->claim_question( $stale_running['question_id'] );
		$this->age( $stale_running['question_id'], 4 * MINUTE_IN_SECONDS );

		$slow_but_alive = $this->accepted_job();
		$this->storage->claim_question( $slow_but_alive['question_id'] );

		$legacy = $this->accepted_job();
		delete_comment_meta( $legacy['question_id'], Storage::META_HEARTBEAT );
		wp_update_comment(
			[
				'comment_ID'       => $legacy['question_id'],
				'comment_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			]
		);

		$this->assertSame( 3, ( new Reaper() )->sweep() );
		$this->assertTrue( $this->storage->is_timed_out( $stale_queued['question_id'] ) );
		$this->assertTrue( $this->storage->is_timed_out( $stale_running['question_id'] ) );
		$this->assertTrue( $this->storage->is_timed_out( $legacy['question_id'] ) );
		$this->assertSame( Storage::STATUS_RUNNING, $this->storage->get_status( $slow_but_alive['question_id'] ) );

		add_filter(
			'ep_chat_job_ceiling',
			static function () {
				return 1;
			}
		);

		$this->age( $slow_but_alive['question_id'], 2 );

		$this->assertSame( 1, ( new Reaper() )->sweep() );
	}

	/**
	 * Test a worker does not run a job that is already stale.
	 *
	 * @group chat
	 */
	public function test_processor_times_out_stale_job_instead_of_running() {
		$job       = $this->accepted_job();
		$generated = false;

		$this->stub_pipeline(
			static function () use ( &$generated ) {
				$generated = true;
			}
		);
		$this->age( $job['question_id'], 10 * MINUTE_IN_SECONDS );

		$handle = ( new Job_Processor() )->process( $job['question_id'] );

		$this->assertFalse( $generated );
		$this->assertSame( Storage::STATUS_ERROR, $handle->get_status() );
		$this->assertTrue( $this->storage->is_timed_out( $job['question_id'] ) );
	}

	/**
	 * Test an answer that arrives after the timeout replaces it.
	 *
	 * @group chat
	 */
	public function test_late_answer_replaces_timeout() {
		$job = $this->accepted_job();
		$this->storage->claim_question( $job['question_id'] );

		$this->assertTrue( $this->storage->time_out_question( $job['question_id'] ) );

		$answer_id = $this->storage->complete_question( $job['ep_chat_id'], $job['question_id'], 'Late reply.' );
		$answers   = $this->answers( $job['question_id'] );

		$this->assertIsInt( $answer_id );
		$this->assertSame( Storage::STATUS_DONE, $this->storage->get_status( $job['question_id'] ) );
		$this->assertCount( 1, $answers );
		$this->assertSame( 'Late reply.', $answers[0]->comment_content );
		$this->assertFalse( $this->storage->is_timed_out( $job['question_id'] ) );
	}

	/**
	 * Test a late answer is kept but flagged when a newer question exists.
	 *
	 * @group chat
	 */
	public function test_late_answer_after_newer_question_is_superseded() {
		$job = $this->accepted_job();
		$this->storage->claim_question( $job['question_id'] );
		$this->storage->time_out_question( $job['question_id'] );
		$this->storage->add_question( $job['ep_chat_id'], 'A newer question?' );

		$answer_id = $this->storage->complete_question( $job['ep_chat_id'], $job['question_id'], 'Late reply.' );

		$this->assertSame( Storage::STATUS_ERROR, $this->storage->get_status( $job['question_id'] ) );
		$this->assertTrue( get_comment_meta( $answer_id, Storage::META_PAYLOAD, true )['superseded'] );
		$this->assertCount( 2, $this->answers( $job['question_id'] ) );
	}

	/**
	 * Test the worker handle for a superseded late answer reports the
	 * stored `error` status, not the pipeline status.
	 *
	 * @group chat
	 */
	public function test_superseded_late_answer_handle_reports_error() {
		$job = $this->accepted_job();
		$this->storage->claim_question( $job['question_id'] );
		$this->storage->time_out_question( $job['question_id'] );
		$this->storage->add_question( $job['ep_chat_id'], 'A newer question?' );

		$handle = ( new Job_Processor() )->persist(
			$job['ep_chat_id'],
			$job['question_id'],
			[
				'status'  => Storage::STATUS_DONE,
				'content' => 'Late reply.',
			],
			Job_Handle::MODE_ASYNC,
			Job_Handle::RUNNER_WP_ASYNC
		);

		$this->assertSame( Storage::STATUS_ERROR, $handle->get_status() );
		$this->assertSame( Storage::STATUS_ERROR, $handle->to_rest()['status'] );
	}

	/**
	 * Test a late error does not replace the timeout.
	 *
	 * @group chat
	 */
	public function test_late_error_keeps_timeout() {
		$job = $this->accepted_job();
		$this->storage->claim_question( $job['question_id'] );
		$this->storage->time_out_question( $job['question_id'] );

		$late    = $this->storage->complete_question(
			$job['ep_chat_id'],
			$job['question_id'],
			'Provider failed.',
			[],
			Storage::STATUS_ERROR
		);
		$answers = $this->answers( $job['question_id'] );

		$this->assertWPError( $late );
		$this->assertSame( 'ep_chat_question_complete', $late->get_error_code() );
		$this->assertTrue( $this->storage->is_timed_out( $job['question_id'] ) );
		$this->assertCount( 1, $answers );
		$this->assertSame( Storage::ERROR_TIMEOUT, get_comment_meta( $answers[0]->comment_ID, Storage::META_ERROR_CODE, true ) );
	}

	/**
	 * Test an exception in the pipeline ends the job with an error answer
	 * instead of leaving it running.
	 *
	 * @group chat
	 */
	public function test_pipeline_exception_ends_job() {
		$job = $this->accepted_job();

		$this->stub_pipeline(
			static function () {
				throw new \RuntimeException( 'Provider exploded.' );
			}
		);

		$result  = ( new Job_Processor() )->process( $job['question_id'] );
		$answers = $this->answers( $job['question_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_pipeline_exception', $result->get_error_code() );
		$this->assertSame( Storage::STATUS_ERROR, $this->storage->get_status( $job['question_id'] ) );
		$this->assertCount( 1, $answers );
		$this->assertStringNotContainsString( 'exploded', $answers[0]->comment_content );
		$this->assertFalse( has_action( 'ep_chat_step' ) );
	}

	/**
	 * Test the reaper leaves no timeout answer when the job finished first,
	 * and a finished question does not take a second answer.
	 *
	 * @group chat
	 */
	public function test_finished_question_wins_races() {
		$job = $this->accepted_job();
		$this->storage->claim_question( $job['question_id'] );
		$this->storage->complete_question( $job['ep_chat_id'], $job['question_id'], 'On time.' );

		$this->assertFalse( $this->storage->time_out_question( $job['question_id'] ) );

		$again   = $this->storage->complete_question( $job['ep_chat_id'], $job['question_id'], 'Twice.' );
		$answers = $this->answers( $job['question_id'] );

		$this->assertWPError( $again );
		$this->assertSame( 'ep_chat_question_complete', $again->get_error_code() );
		$this->assertSame( Storage::STATUS_DONE, $this->storage->get_status( $job['question_id'] ) );
		$this->assertCount( 1, $answers );
		$this->assertSame( 'On time.', $answers[0]->comment_content );
	}

	/**
	 * Test the sweep is scheduled, and removed when Chat or the plugin is
	 * turned off.
	 *
	 * @group chat
	 */
	public function test_sweep_schedule() {
		$reaper = new Reaper();
		$reaper->setup();
		$reaper->schedule();

		$this->assertNotFalse( wp_next_scheduled( Reaper::HOOK ) );
		$this->assertArrayHasKey( Reaper::SCHEDULE, wp_get_schedules() );

		( new Chat() )->post_deactivation();

		$this->assertFalse( wp_next_scheduled( Reaper::HOOK ) );

		$reaper->schedule();
		\ElasticPressLabs\Core\deactivate();

		$this->assertFalse( wp_next_scheduled( Reaper::HOOK ) );
	}
}
