<?php
/**
 * Unified Chat job handle.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Refusals;
use ElasticPressLabs\Feature\Chat\Sources;
use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Same shape for sync results and queued async work.
 */
class Job_Handle {
	/**
	 * In-request execution.
	 *
	 * @var string
	 */
	public const MODE_SYNC = 'sync';

	/**
	 * Queued execution.
	 *
	 * @var string
	 */
	public const MODE_ASYNC = 'async';

	/**
	 * In-request runner. Runner slugs are the bots' execution modes.
	 *
	 * @var string
	 */
	public const RUNNER_SYNC = Bots::MODE_SYNC;

	/**
	 * Labs wp-async loopback runner.
	 *
	 * @var string
	 */
	public const RUNNER_WP_ASYNC = Bots::MODE_WP_ASYNC;

	/**
	 * Action Scheduler runner.
	 *
	 * @var string
	 */
	public const RUNNER_ACTION_SCHEDULER = Bots::MODE_ACTION_SCHEDULER;

	/**
	 * WP-Cron runner.
	 *
	 * @var string
	 */
	public const RUNNER_WP_CRON = Bots::MODE_WP_CRON;

	/**
	 * Conversation post ID.
	 *
	 * @var int
	 */
	protected $ep_chat_id;

	/**
	 * Question comment ID (the job id).
	 *
	 * @var int
	 */
	protected $question_id;

	/**
	 * Answer comment ID, if written.
	 *
	 * @var int
	 */
	protected $answer_id = 0;

	/**
	 * Question status.
	 *
	 * @var string
	 */
	protected $status;

	/**
	 * Sync or async.
	 *
	 * @var string
	 */
	protected $mode;

	/**
	 * Runner slug.
	 *
	 * @var string
	 */
	protected $runner;

	/**
	 * REST/pipeline payload when the job has finished.
	 *
	 * @var array
	 */
	protected $result = [];

	/**
	 * Create a handle.
	 *
	 * @param int    $ep_chat_id  Conversation post ID.
	 * @param int    $question_id Question comment ID.
	 * @param string $status      Question status.
	 * @param string $mode        Sync or async.
	 * @param string $runner      Runner slug.
	 */
	public function __construct(
		int $ep_chat_id,
		int $question_id,
		string $status,
		string $mode,
		string $runner
	) {
		$this->ep_chat_id  = $ep_chat_id;
		$this->question_id = $question_id;
		$this->status      = $status;
		$this->mode        = $mode;
		$this->runner      = $runner;
	}

	/**
	 * Handle for a job that has been persisted and queued.
	 *
	 * @param int    $ep_chat_id  Conversation post ID.
	 * @param int    $question_id Question comment ID.
	 * @param string $mode        Sync or async.
	 * @param string $runner      Runner slug.
	 * @return self
	 */
	public static function queued(
		int $ep_chat_id,
		int $question_id,
		string $mode,
		string $runner
	): self {
		return new self(
			$ep_chat_id,
			$question_id,
			Storage::STATUS_QUEUED,
			$mode,
			$runner
		);
	}

	/**
	 * Attach a finished pipeline payload.
	 *
	 * @param array $result Pipeline/REST payload.
	 * @param int   $answer_id Answer comment ID.
	 * @return self
	 */
	public function with_result( array $result, int $answer_id = 0 ): self {
		$clone            = clone $this;
		$clone->result    = $result;
		$clone->answer_id = $answer_id;
		$clone->status    = isset( $result['status'] )
			? (string) $result['status']
			: $this->status;

		return $clone;
	}

	/**
	 * Whether the pipeline has already finished.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return ! in_array( $this->status, ( new Storage() )->get_active_statuses(), true );
	}

	/**
	 * Conversation post ID.
	 *
	 * @return int
	 */
	public function get_ep_chat_id(): int {
		return $this->ep_chat_id;
	}

	/**
	 * Question comment ID.
	 *
	 * @return int
	 */
	public function get_question_id(): int {
		return $this->question_id;
	}

	/**
	 * Question status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Runner slug.
	 *
	 * @return string
	 */
	public function get_runner(): string {
		return $this->runner;
	}

	/**
	 * REST body for Ask / POST chat.
	 *
	 * @return array
	 */
	public function to_rest(): array {
		if ( $this->is_complete() && [] !== $this->result ) {
			return Refusals::present_result( Sources::attach_to_payload( $this->result, $this->status ) );
		}

		return [
			'ep_chat_id'  => $this->ep_chat_id,
			'question_id' => $this->question_id,
			'status'      => $this->status,
			'mode'        => $this->mode,
			'runner'      => $this->runner,
		];
	}
}
