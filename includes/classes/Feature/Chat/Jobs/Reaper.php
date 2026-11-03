<?php
/**
 * Stale Chat job reaper.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Flips stale queued/running questions to `error`.
 *
 * A job is stale when it shows no activity for longer than the ceiling of
 * its status: `queued` with no pickup, or `running` with no heartbeat. A
 * job that keeps reporting steps is never stale, however long it runs.
 *
 * Reaping happens lazily when a conversation or job is read, and in a
 * scheduled sweep for conversations nobody opens.
 */
class Reaper {
	/**
	 * Scheduled sweep hook.
	 *
	 * @var string
	 */
	public const HOOK = 'ep_chat_reap_stale_jobs';

	/**
	 * Cron schedule slug for the sweep.
	 *
	 * @var string
	 */
	public const SCHEDULE = 'ep_chat_reaper';

	/**
	 * Seconds between sweeps.
	 *
	 * @var int
	 */
	public const INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Create a reaper.
	 *
	 * @param Storage|null $storage Storage.
	 */
	public function __construct( ?Storage $storage = null ) {
		$this->storage = $storage ? $storage : new Storage();
	}

	/**
	 * Register the sweep.
	 *
	 * @return void
	 */
	public function setup() {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, [ $this, 'sweep' ] );
		add_action( 'init', [ $this, 'schedule' ] );
	}

	/**
	 * Add the sweep interval.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = [
			'interval' => self::INTERVAL,
			'display'  => esc_html__( 'Every five minutes (Chat stale-job reaper)', 'elasticpress-labs' ),
		];

		return $schedules;
	}

	/**
	 * Schedule the sweep if it is not scheduled yet.
	 *
	 * @return void
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Remove the scheduled sweep.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Seconds of inactivity after which a job with this status is stale.
	 *
	 * @param string $status `queued` or `running`.
	 * @return int
	 */
	public function get_ceiling( string $status ): int {
		$ceiling = Storage::STATUS_QUEUED === $status
			? 5 * MINUTE_IN_SECONDS
			: 3 * MINUTE_IN_SECONDS;

		/**
		 * Filters how long a Chat job may show no activity before the reaper
		 * flips it to `error`. For `queued`, this is the wait for a worker
		 * to pick the job up. For `running`, it is the time since the last
		 * reported step.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_job_ceiling
		 * @param {int}    $ceiling Seconds.
		 * @param {string} $status  `queued` or `running`.
		 * @return {int} Filtered seconds.
		 */
		$ceiling = (int) apply_filters( 'ep_chat_job_ceiling', $ceiling, $status );

		return max( 1, $ceiling );
	}

	/**
	 * Whether a question is queued/running with no activity past its ceiling.
	 *
	 * @param int $question_id Question comment ID.
	 * @return bool
	 */
	public function is_stale( int $question_id ): bool {
		$status = $this->storage->get_status( $question_id, true );

		if ( ! in_array( $status, $this->storage->get_active_statuses(), true ) ) {
			return false;
		}

		$heartbeat = $this->storage->get_heartbeat( $question_id );

		return $heartbeat > 0 && time() - $heartbeat > $this->get_ceiling( $status );
	}

	/**
	 * Time out one question if it is stale.
	 *
	 * @param int $question_id Question comment ID.
	 * @return bool Whether the question was timed out.
	 */
	public function reap_question( int $question_id ): bool {
		if ( ! $this->is_stale( $question_id ) ) {
			return false;
		}

		return $this->storage->time_out_question( $question_id );
	}

	/**
	 * Time out stale questions of one conversation. Used on read.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return int Number of questions timed out.
	 */
	public function reap_conversation( int $ep_chat_id ): int {
		$question_ids = get_comments(
			[
				'post_id'    => $ep_chat_id,
				'type'       => Storage::COMMENT_TYPE_QUESTION,
				'status'     => 'approve',
				'fields'     => 'ids',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Storage::META_STATUS,
						'value'   => $this->storage->get_active_statuses(),
						'compare' => 'IN',
					],
				],
			]
		);

		return $this->reap_ids( $question_ids );
	}

	/**
	 * Scheduled sweep over all conversations.
	 *
	 * @return int Number of questions timed out.
	 */
	public function sweep(): int {
		/**
		 * Filters how many stale Chat jobs one sweep looks at per status.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_reaper_batch_size
		 * @param {int} $size Batch size.
		 * @return {int} Filtered size.
		 */
		$size  = max( 1, (int) apply_filters( 'ep_chat_reaper_batch_size', 50 ) );
		$count = 0;

		foreach ( $this->storage->get_active_statuses() as $status ) {
			$question_ids = get_comments(
				[
					'type'          => Storage::COMMENT_TYPE_QUESTION,
					'status'        => 'approve',
					'fields'        => 'ids',
					'number'        => $size,
					'orderby'       => 'comment_ID',
					'order'         => 'ASC',
					'no_found_rows' => true,
					'meta_query'    => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						[
							'key'   => Storage::META_STATUS,
							'value' => $status,
						],
						[
							'relation' => 'OR',
							[
								'key'     => Storage::META_HEARTBEAT,
								'value'   => time() - $this->get_ceiling( $status ),
								'compare' => '<',
								'type'    => 'NUMERIC',
							],
							[
								'key'     => Storage::META_HEARTBEAT,
								'compare' => 'NOT EXISTS',
							],
						],
					],
				]
			);

			$count += $this->reap_ids( $question_ids );
		}

		return $count;
	}

	/**
	 * Time out each stale question in a list.
	 *
	 * @param array $question_ids Question comment IDs.
	 * @return int Number of questions timed out.
	 */
	protected function reap_ids( array $question_ids ): int {
		$count = 0;

		foreach ( $question_ids as $question_id ) {
			if ( $this->reap_question( (int) $question_id ) ) {
				++$count;
			}
		}

		return $count;
	}
}
