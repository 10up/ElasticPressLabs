<?php
/**
 * Action Scheduler Chat runner.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Enqueue Chat work on Action Scheduler when that library is loaded.
 */
class Action_Scheduler_Runner implements Runner {
	/**
	 * Shared worker hook.
	 *
	 * @var string
	 */
	public const HOOK = 'ep_chat_run_job';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	public const GROUP = 'ep-chat';

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return Job_Handle::RUNNER_ACTION_SCHEDULER;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Enqueue the question on Action Scheduler.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      ep_chat_id and question_id.
	 * @return Job_Handle|\WP_Error
	 */
	public function dispatch( string $question, array $history, array $bot, array $ids ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'ep_chat_runner_unavailable',
				esc_html__(
					'Action Scheduler is not available.',
					'elasticpress-labs'
				),
				[ 'status' => 500 ]
			);
		}

		$question_id = (int) $ids['question_id'];

		as_enqueue_async_action(
			self::HOOK,
			[ $question_id, $this->get_slug() ],
			self::GROUP
		);

		return Job_Handle::queued(
			(int) $ids['ep_chat_id'],
			$question_id,
			Job_Handle::MODE_ASYNC,
			$this->get_slug()
		);
	}
}
