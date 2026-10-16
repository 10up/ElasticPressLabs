<?php
/**
 * WP-Cron Chat runner.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Fallback async runner for sites without Action Scheduler.
 */
class WP_Cron_Runner implements Runner {
	/**
	 * Shared worker hook.
	 *
	 * @var string
	 */
	public const HOOK = Action_Scheduler_Runner::HOOK;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return Job_Handle::RUNNER_WP_CRON;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Schedule the question on WP-Cron.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      ep_chat_id and question_id.
	 * @return Job_Handle|\WP_Error
	 */
	public function dispatch( string $question, array $history, array $bot, array $ids ) {
		$question_id = (int) $ids['question_id'];

		wp_schedule_single_event( time(), self::HOOK, [ $question_id, $this->get_slug() ] );

		return Job_Handle::queued(
			(int) $ids['ep_chat_id'],
			$question_id,
			Job_Handle::MODE_ASYNC,
			$this->get_slug()
		);
	}
}
