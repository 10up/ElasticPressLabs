<?php
/**
 * Chat job runner contract.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Dispatches Chat work through one backend.
 */
interface Runner {
	/**
	 * Stable slug used in handles and filters.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Whether this backend can enqueue work on this site.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Run or enqueue one accepted question.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      ep_chat_id and question_id.
	 * @return Job_Handle|\WP_Error
	 */
	public function dispatch( string $question, array $history, array $bot, array $ids );
}
