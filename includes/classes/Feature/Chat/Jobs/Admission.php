<?php
/**
 * Chat question admission: in-flight cap and duplicate asks.
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
 * Decides whether a user's question starts a new job, joins one already running, or waits.
 *
 * A question is identical to a running one when the user, the bot, the text (ignoring case and
 * whitespace), and the conversation match. For a new chat, the conversation is one whose only
 * question is that one. Identical questions join the running job. Otherwise, a user with too many
 * questions queued or running gets a 429. Stale jobs are timed out first, so a dead worker never
 * counts. Questions in the conversation being continued do not count either: the new question
 * supersedes them.
 */
class Admission {
	/**
	 * Error code for a user over the in-flight cap.
	 *
	 * @var string
	 */
	public const ERROR_RATE_LIMITED = 'ep_chat_rate_limited';

	/**
	 * Seconds a request waits for another request from the same user to be admitted.
	 *
	 * @var int
	 */
	protected const LOCK_WAIT = 10;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Create an admission check.
	 *
	 * @param Storage|null $storage Storage.
	 */
	public function __construct( ?Storage $storage = null ) {
		$this->storage = $storage ? $storage : new Storage();
	}

	/**
	 * Most questions one user can have queued or running at once.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_max_in_flight( int $user_id ): int {
		/**
		 * Filters how many Chat questions one user can have queued or running at once.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_max_in_flight
		 * @param {int} $max     Cap. Default: the Chat feature's setting (1).
		 * @param {int} $user_id User ID.
		 * @return {int} Filtered cap.
		 */
		return max( 1, (int) apply_filters( 'ep_chat_max_in_flight', (int) Settings::get( 'max_in_flight' ), $user_id ) );
	}

	/**
	 * Most questions one user can ask in an hour, or 0 for no limit.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_max_per_hour( int $user_id ): int {
		/**
		 * Filters how many Chat questions one user can ask in an hour. 0 means no limit.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_max_questions_per_hour
		 * @param {int} $max     Limit. Default: the Chat feature's setting (30).
		 * @param {int} $user_id User ID.
		 * @return {int} Filtered limit.
		 */
		return max( 0, (int) apply_filters( 'ep_chat_max_questions_per_hour', (int) Settings::get( 'max_per_hour' ), $user_id ) );
	}

	/**
	 * Seconds a rate-limited user should wait before trying again.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_retry_after( int $user_id ): int {
		/**
		 * Filters the Retry-After seconds sent with a rate-limited Chat question.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retry_after
		 * @param {int} $seconds Seconds. Default: the Chat feature's setting (10).
		 * @param {int} $user_id User ID.
		 * @return {int} Filtered seconds.
		 */
		return max( 1, (int) apply_filters( 'ep_chat_retry_after', (int) Settings::get( 'retry_after' ), $user_id ) );
	}

	/**
	 * Run a callback while holding the user's admission lock, so two requests from one user
	 * cannot both pass the checks before either stores its question.
	 *
	 * The lock is a MySQL named lock: taking it is atomic, a request that finds it taken waits
	 * for it, and the database releases it if the request dies. Without one (another database
	 * engine, or a wait that runs out), the callback runs unlocked. Named locks belong to a
	 * database connection, so behind a connection pooler (like ProxySQL) that sends queries of
	 * one request to different servers, the lock may not hold.
	 *
	 * @param int      $user_id  User ID.
	 * @param callable $callback Callback. Its return value is returned.
	 * @return mixed
	 */
	public function with_lock( int $user_id, callable $callback ) {
		global $wpdb;

		$name   = $this->get_lock_name( $user_id );
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		try {
			return $callback();
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Name of the user's admission lock.
	 *
	 * MySQL limits lock names to 64 characters and shares them across the databases of a server,
	 * so the name is a hash of the database, the table prefix, and the user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function get_lock_name( int $user_id ): string {
		global $wpdb;

		return 'ep_chat_admission_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $user_id );
	}

	/**
	 * Running question the new one should join, if any.
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $bot_id     Bot ID.
	 * @param string $question   Question text.
	 * @param int    $ep_chat_id Conversation being continued, or 0 for a new chat.
	 * @return int Question comment ID, or 0.
	 */
	public function find_duplicate( int $user_id, int $bot_id, string $question, int $ep_chat_id = 0 ): int {
		$text = $this->normalize( $question );

		foreach ( $this->get_in_flight( $user_id ) as $comment ) {
			if ( $this->normalize( $comment->comment_content ) !== $text ) {
				continue;
			}

			if ( (int) get_comment_meta( (int) $comment->comment_ID, Storage::META_BOT_ID, true ) !== $bot_id ) {
				continue;
			}

			$post_id = (int) $comment->comment_post_ID;

			if ( $ep_chat_id ? $post_id !== $ep_chat_id : 1 !== $this->count_questions( $post_id ) ) {
				continue;
			}

			return (int) $comment->comment_ID;
		}

		return 0;
	}

	/**
	 * Refuse a user over the in-flight cap.
	 *
	 * @param int $user_id    User ID.
	 * @param int $ep_chat_id Conversation being continued, or 0 for a new chat.
	 * @return true|\WP_Error
	 */
	public function check_cap( int $user_id, int $ep_chat_id = 0 ) {
		$count = 0;

		foreach ( $this->get_in_flight( $user_id ) as $comment ) {
			if ( ! $ep_chat_id || (int) $comment->comment_post_ID !== $ep_chat_id ) {
				++$count;
			}
		}

		$max = $this->get_max_in_flight( $user_id );

		if ( $count < $max ) {
			return $this->check_hourly_cap( $user_id );
		}

		$retry_after = $this->get_retry_after( $user_id );

		$message = 1 === $max
			/* translators: %d: Seconds to wait. */
			? _n( 'You already have a question running. Try again in %d second.', 'You already have a question running. Try again in %d seconds.', $retry_after, 'elasticpress-labs' )
			/* translators: %d: Seconds to wait. */
			: _n( 'You have too many questions running. Try again in %d second.', 'You have too many questions running. Try again in %d seconds.', $retry_after, 'elasticpress-labs' );

		return new \WP_Error(
			self::ERROR_RATE_LIMITED,
			esc_html( sprintf( $message, $retry_after ) ),
			[
				'status'      => 429,
				'retry_after' => $retry_after,
			]
		);
	}

	/**
	 * Refuse a user who asked the most questions allowed in the last hour.
	 *
	 * Every question counts, whatever its outcome: each one calls the AI provider. The wait
	 * lasts until the oldest of those questions is an hour old.
	 *
	 * @param int $user_id User ID.
	 * @return true|\WP_Error
	 */
	protected function check_hourly_cap( int $user_id ) {
		$max = $this->get_max_per_hour( $user_id );

		if ( ! $max ) {
			return true;
		}

		$asked = get_comments(
			[
				'user_id'       => $user_id,
				'type'          => Storage::COMMENT_TYPE_QUESTION,
				'status'        => 'all',
				'no_found_rows' => true,
				'number'        => $max,
				'orderby'       => 'comment_date_gmt',
				'order'         => 'DESC',
				'date_query'    => [
					[
						'column'    => 'comment_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
						'inclusive' => true,
					],
				],
			]
		);

		if ( count( $asked ) < $max ) {
			return true;
		}

		// The oldest of the newest $max questions decides when one more is allowed.
		$oldest  = (int) strtotime( end( $asked )->comment_date_gmt . ' UTC' );
		$wait    = max( MINUTE_IN_SECONDS, $oldest + HOUR_IN_SECONDS - time() );
		$minutes = (int) ceil( $wait / MINUTE_IN_SECONDS );

		return new \WP_Error(
			self::ERROR_RATE_LIMITED,
			esc_html(
				sprintf(
					/* translators: 1: Questions per hour, 2: Minutes to wait. */
					_n( 'You have asked %1$d questions in the last hour, the most allowed. Try again in %2$d minute.', 'You have asked %1$d questions in the last hour, the most allowed. Try again in %2$d minutes.', $minutes, 'elasticpress-labs' ),
					$max,
					$minutes
				)
			),
			[
				'status'      => 429,
				'retry_after' => $wait,
			]
		);
	}

	/**
	 * The user's questions that are still queued or running, after stale ones are timed out.
	 *
	 * @param int $user_id User ID.
	 * @return \WP_Comment[]
	 */
	protected function get_in_flight( int $user_id ): array {
		$args = [
			'user_id'       => $user_id,
			'type'          => Storage::COMMENT_TYPE_QUESTION,
			'status'        => 'approve',
			'no_found_rows' => true,
			'meta_query'    => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => Storage::META_STATUS,
					'value'   => $this->storage->get_active_statuses(),
					'compare' => 'IN',
				],
			],
		];

		$reaper   = new Reaper( $this->storage );
		$comments = [];

		foreach ( get_comments( $args ) as $comment ) {
			if ( ! $reaper->reap_question( (int) $comment->comment_ID )
				&& in_array( $this->storage->get_status( (int) $comment->comment_ID, true ), $this->storage->get_active_statuses(), true ) ) {
				$comments[] = $comment;
			}
		}

		return $comments;
	}

	/**
	 * Number of questions in a conversation.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return int
	 */
	protected function count_questions( int $ep_chat_id ): int {
		return (int) get_comments(
			[
				'post_id' => $ep_chat_id,
				'type'    => Storage::COMMENT_TYPE_QUESTION,
				'status'  => 'approve',
				'count'   => true,
			]
		);
	}

	/**
	 * Question text for comparison: lowercase, single spaces, trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function normalize( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) ) );
	}
}
