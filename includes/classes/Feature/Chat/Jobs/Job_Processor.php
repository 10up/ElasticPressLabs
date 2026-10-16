<?php
/**
 * Shared Chat job worker.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs the pipeline for a persisted question and writes the answer.
 */
class Job_Processor {
	/**
	 * Process a queued question comment.
	 *
	 * A job that is already stale is timed out instead of run. A job that
	 * another worker claimed, or that already finished, is not run again.
	 *
	 * @param int    $question_id Question comment ID.
	 * @param string $mode        Sync or async.
	 * @param string $runner      Runner slug.
	 * @return Job_Handle|\WP_Error
	 */
	public function process(
		int $question_id,
		string $mode = Job_Handle::MODE_ASYNC,
		string $runner = Job_Handle::RUNNER_WP_ASYNC
	) {
		$storage = new Storage();
		$context = $storage->get_job_context( $question_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		( new Reaper( $storage ) )->reap_question( $question_id );

		if ( ! $storage->claim_question( $question_id ) ) {
			return $this->current_handle( $storage, $question_id, $mode, $runner );
		}

		$bot = ( new Bots() )->get( $context['bot_id'] );

		if ( is_wp_error( $bot ) ) {
			return $this->persist(
				$context['ep_chat_id'],
				$question_id,
				$bot,
				$mode,
				$runner
			);
		}

		return $this->run_claimed(
			$context['ep_chat_id'],
			$question_id,
			$context['question'],
			$context['messages'],
			$bot,
			$mode,
			$runner
		);
	}

	/**
	 * Claim and run a question on this request. Used by the sync runner,
	 * which already holds the question, history, and bot.
	 *
	 * @param int    $ep_chat_id  Conversation post ID.
	 * @param int    $question_id Question comment ID.
	 * @param string $question    User question.
	 * @param array  $history     Prior turns.
	 * @param array  $bot         Selected chat bot.
	 * @param string $mode        Sync or async.
	 * @param string $runner      Runner slug.
	 * @return Job_Handle|\WP_Error
	 */
	public function execute(
		int $ep_chat_id,
		int $question_id,
		string $question,
		array $history,
		array $bot,
		string $mode,
		string $runner
	) {
		$storage = new Storage();

		if ( ! $storage->claim_question( $question_id ) ) {
			return $this->current_handle( $storage, $question_id, $mode, $runner );
		}

		return $this->run_claimed( $ep_chat_id, $question_id, $question, $history, $bot, $mode, $runner );
	}

	/**
	 * Run the pipeline for a claimed question and persist the result.
	 *
	 * Every `ep_chat_step` the pipeline reports is stored on the question
	 * and refreshes its heartbeat. An exception ends the job with an error
	 * answer, so the question does not stay `running` until the reaper.
	 *
	 * @param int    $ep_chat_id  Conversation post ID.
	 * @param int    $question_id Question comment ID.
	 * @param string $question    User question.
	 * @param array  $history     Prior turns.
	 * @param array  $bot         Selected chat bot.
	 * @param string $mode        Sync or async.
	 * @param string $runner      Runner slug.
	 * @return Job_Handle|\WP_Error
	 */
	protected function run_claimed(
		int $ep_chat_id,
		int $question_id,
		string $question,
		array $history,
		array $bot,
		string $mode,
		string $runner
	) {
		$storage = new Storage();
		$track   = static function ( $step ) use ( $storage, $question_id ) {
			$storage->touch_question( $question_id, (string) $step );
		};

		add_action( 'ep_chat_step', $track );

		try {
			$result = ( new Pipeline() )->run( $question, $history, $bot );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error(
				'ep_chat_pipeline_exception',
				esc_html__( 'The assistant could not generate a reply.', 'elasticpress-labs' )
			);
		} finally {
			remove_action( 'ep_chat_step', $track );
		}

		return $this->persist( $ep_chat_id, $question_id, $result, $mode, $runner );
	}

	/**
	 * Handle for a question this worker did not claim.
	 *
	 * @param Storage $storage     Storage.
	 * @param int     $question_id Question comment ID.
	 * @param string  $mode        Sync or async.
	 * @param string  $runner      Runner slug.
	 * @return Job_Handle|\WP_Error
	 */
	protected function current_handle( Storage $storage, int $question_id, string $mode, string $runner ) {
		$context = $storage->get_job_context( $question_id );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return ( new Job_Handle(
			$context['ep_chat_id'],
			$question_id,
			$storage->get_status( $question_id, true ),
			$mode,
			$runner
		) )->with_result(
			[],
			$context['answer_id']
		);
	}

	/**
	 * Persist a pipeline result onto the question comment.
	 *
	 * @param int    $ep_chat_id  Conversation post ID.
	 * @param int    $question_id Question comment ID.
	 * @param mixed  $result      Pipeline result.
	 * @param string $mode        Sync or async.
	 * @param string $runner      Runner slug.
	 * @return Job_Handle|\WP_Error
	 */
	public function persist(
		int $ep_chat_id,
		int $question_id,
		$result,
		string $mode,
		string $runner
	) {
		$storage = new Storage();
		$ids     = [
			'ep_chat_id'  => $ep_chat_id,
			'question_id' => $question_id,
		];

		if ( is_wp_error( $result ) ) {
			return $this->persist_error( $storage, $ids, $result );
		}

		$hits    = isset( $result['hits'] ) && is_array( $result['hits'] ) ? $result['hits'] : [];
		$allowed = [
			Storage::STATUS_DONE,
			Storage::STATUS_REFUSED,
			Storage::STATUS_CLARIFY,
			Storage::STATUS_ERROR,
		];
		$status  = isset( $result['status'] ) && in_array( $result['status'], $allowed, true )
			? $result['status']
			: ( [] === $hits ? Storage::STATUS_REFUSED : Storage::STATUS_DONE );
		$content = isset( $result['content'] ) ? (string) $result['content'] : '';
		$payload = isset( $result['meta'] ) && is_array( $result['meta'] )
			? $result['meta']
			: [ 'hits' => $hits ];

		if ( ! empty( $result['refusal_reason'] ) && empty( $payload['refusal_reason'] ) ) {
			$payload['refusal_reason'] = $result['refusal_reason'];
		}

		$answer_id = $storage->complete_question(
			$ep_chat_id,
			$question_id,
			$content,
			$payload,
			$status
		);

		if ( is_wp_error( $answer_id ) ) {
			return $this->error_with_ids( $answer_id, $ids );
		}

		// A late answer to a superseded question is stored, but the status stays `error`.
		$status = $storage->get_status( $question_id );

		/**
		 * Fires after an answer is stored for a question (answered, declined, or, for a late
		 * answer, still `error`).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_question_answered
		 * @param {int}    $ep_chat_id  Conversation post ID.
		 * @param {int}    $question_id Question comment ID.
		 * @param {int}    $answer_id   Answer comment ID.
		 * @param {string} $status      Question status.
		 */
		do_action( 'ep_chat_question_answered', $ep_chat_id, $question_id, (int) $answer_id, $status );

		$result['ep_chat_id']  = $ep_chat_id;
		$result['question_id'] = $question_id;
		$result['answer_id']   = (int) $answer_id;
		$result['status']      = $status;

		return ( new Job_Handle(
			$ep_chat_id,
			$question_id,
			$status,
			$mode,
			$runner
		) )->with_result( $result, (int) $answer_id );
	}

	/**
	 * Write an error answer and return the WP_Error with IDs.
	 *
	 * @param Storage   $storage Storage.
	 * @param array     $ids     ep_chat_id and question_id.
	 * @param \WP_Error $error   Pipeline error.
	 * @return \WP_Error
	 */
	protected function persist_error( Storage $storage, array $ids, \WP_Error $error ): \WP_Error {
		$message = trim( $error->get_error_message() );

		if ( '' === $message ) {
			$message = __( 'The assistant could not generate a reply.', 'elasticpress-labs' );
		}

		$answer_id = $storage->complete_question(
			(int) $ids['ep_chat_id'],
			(int) $ids['question_id'],
			$message,
			[],
			Storage::STATUS_ERROR
		);

		if ( ! is_wp_error( $answer_id ) ) {
			$ids['answer_id'] = (int) $answer_id;
		}

		return $this->error_with_ids( $error, $ids );
	}

	/**
	 * Attach persisted IDs to a WP_Error payload.
	 *
	 * @param \WP_Error $error Error.
	 * @param array     $ids   Persisted IDs.
	 * @return \WP_Error
	 */
	protected function error_with_ids( \WP_Error $error, array $ids ): \WP_Error {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		return new \WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array_merge( $data, $ids )
		);
	}
}
