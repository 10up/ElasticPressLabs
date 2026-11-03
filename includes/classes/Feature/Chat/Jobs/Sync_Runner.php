<?php
/**
 * In-request Chat runner.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs retrieve → synthesize on the same request.
 */
class Sync_Runner implements Runner {
	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return Job_Handle::RUNNER_SYNC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Run retrieve → synthesize on this request.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      ep_chat_id and question_id.
	 * @return Job_Handle|\WP_Error
	 */
	public function dispatch( string $question, array $history, array $bot, array $ids ) {
		return ( new Job_Processor() )->execute(
			(int) $ids['ep_chat_id'],
			(int) $ids['question_id'],
			$question,
			$history,
			$bot,
			Job_Handle::MODE_SYNC,
			$this->get_slug()
		);
	}
}
