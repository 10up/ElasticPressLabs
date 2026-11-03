<?php
/**
 * Chat conversation retention.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Deletes conversations that had no new question or answer for a number of days.
 *
 * It runs on the Reaper's five-minute sweep, after it, and takes a few
 * conversations at a time. Deleting a conversation removes its questions and
 * answers, with their meta. A conversation with a question still queued or
 * running is kept.
 */
class Retention {
	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Create the retention sweep.
	 *
	 * @param Storage|null $storage Storage.
	 */
	public function __construct( ?Storage $storage = null ) {
		$this->storage = $storage ? $storage : new Storage();
	}

	/**
	 * Run after the Reaper's sweep, so the jobs it timed out no longer hold a conversation.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( Reaper::HOOK, [ $this, 'sweep' ], 20 );
	}

	/**
	 * Days a conversation is kept after its last activity. 0 keeps them for ever.
	 *
	 * @return int
	 */
	public function get_days(): int {
		/**
		 * Filters how many days a conversation is kept after its last question or answer.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retention_days
		 * @param {int} $days Days. Default: the Chat setting, 0 (keep for ever) if not set.
		 * @return {int} Filtered days. 0 or less keeps conversations for ever.
		 */
		return max( 0, (int) apply_filters( 'ep_chat_retention_days', (int) Settings::get( 'retention_days' ) ) );
	}

	/**
	 * Delete one batch of expired conversations.
	 *
	 * @return int Number of conversations deleted.
	 */
	public function sweep(): int {
		$days = $this->get_days();

		if ( ! $days ) {
			return 0;
		}

		/**
		 * Filters how many expired conversations one retention sweep deletes.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retention_batch_size
		 * @param {int} $size Batch size.
		 * @return {int} Filtered size.
		 */
		$size = max( 1, (int) apply_filters( 'ep_chat_retention_batch_size', 50 ) );

		$ids = get_posts(
			[
				'post_type'        => Storage::POST_TYPE,
				// Trashed conversations too.
				'post_status'      => array_values( get_post_stati() ),
				'fields'           => 'ids',
				'numberposts'      => $size,
				'orderby'          => 'post_modified_gmt',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'date_query'       => [
					[
						'column' => 'post_modified_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
					],
				],
			]
		);

		$count = 0;

		foreach ( $ids as $ep_chat_id ) {
			if ( $this->has_active_question( (int) $ep_chat_id ) ) {
				continue;
			}

			if ( wp_delete_post( (int) $ep_chat_id, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a conversation has a question that is queued or running.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return bool
	 */
	protected function has_active_question( int $ep_chat_id ): bool {
		$ids = get_comments(
			[
				'post_id'       => $ep_chat_id,
				'type'          => Storage::COMMENT_TYPE_QUESTION,
				'status'        => 'any',
				'fields'        => 'ids',
				'number'        => 1,
				'no_found_rows' => true,
				'meta_query'    => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Storage::META_STATUS,
						'value'   => $this->storage->get_active_statuses(),
						'compare' => 'IN',
					],
				],
			]
		);

		return ! empty( $ids );
	}
}
