<?php
/**
 * Native Chat conversation storage.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers `ep_chat` and stores turns as comments. Bot options live on
 * `ep_chat_bot`, not on the conversation post.
 */
class Storage {
	/**
	 * Conversation post type.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'ep_chat';

	/**
	 * Question comment type. This comment is the async job record.
	 *
	 * @var string
	 */
	public const COMMENT_TYPE_QUESTION = 'ep_chat_question';

	/**
	 * Answer comment type.
	 *
	 * @var string
	 */
	public const COMMENT_TYPE_ANSWER = 'ep_chat_answer';

	/**
	 * Status commentmeta key on the question comment.
	 *
	 * @var string
	 */
	public const META_STATUS = 'ep_chat_status';

	/**
	 * Structured payload commentmeta key on the answer comment.
	 *
	 * @var string
	 */
	public const META_PAYLOAD = 'ep_chat_payload';

	/**
	 * Bot ID stored on the question for the worker.
	 *
	 * @var string
	 */
	public const META_BOT_ID = 'ep_chat_bot_id';

	/**
	 * Prior turns stored on the question for the worker.
	 *
	 * @var string
	 */
	public const META_MESSAGES = 'ep_chat_messages';

	/**
	 * Last activity timestamp on the question. Written on queue, claim, and
	 * every pipeline step. The reaper reads it to find stale jobs.
	 *
	 * @var string
	 */
	public const META_HEARTBEAT = 'ep_chat_heartbeat';

	/**
	 * Current pipeline step on a running question.
	 *
	 * @var string
	 */
	public const META_STEP = 'ep_chat_step';

	/**
	 * Error code commentmeta key on an error answer comment.
	 *
	 * @var string
	 */
	public const META_ERROR_CODE = 'ep_chat_error_code';

	/**
	 * Error code of the answer the reaper writes on a stale job.
	 *
	 * @var string
	 */
	public const ERROR_TIMEOUT = 'job_timeout';

	/**
	 * Conversation postmeta: the key the Ask screen sent when it started the conversation.
	 *
	 * @var string
	 */
	public const META_CLIENT_KEY = 'ep_chat_client_key';

	/**
	 * Question is queued.
	 *
	 * @var string
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * Question was picked up by a worker.
	 *
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Question finished successfully.
	 *
	 * @var string
	 */
	public const STATUS_DONE = 'done';

	/**
	 * Question was refused.
	 *
	 * @var string
	 */
	public const STATUS_REFUSED = 'refused';

	/**
	 * Clarify: about to be declined, the question got options to choose from instead.
	 *
	 * @var string
	 */
	public const STATUS_CLARIFY = 'clarify';

	/**
	 * Question failed.
	 *
	 * @var string
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'pre_get_comments', [ $this, 'exclude_chat_comments' ] );
		add_filter( 'ep_indexable_post_types', [ $this, 'exclude_from_index' ] );
		add_filter( 'map_meta_cap', [ $this, 'map_read_to_edit' ], 10, 4 );
		add_filter( 'wp_untrash_post_status', [ $this, 'restore_previous_status' ], 10, 3 );
	}

	/**
	 * Reading a conversation needs the same capability as editing it.
	 *
	 * Users see their own conversations. Users who can edit others'
	 * conversations (editors and up by default) see everyone's.
	 *
	 * @param array  $caps    Primitive capabilities.
	 * @param string $cap     Meta capability.
	 * @param int    $user_id User ID.
	 * @param array  $args    Capability args. The first is the post ID.
	 * @return array
	 */
	public function map_read_to_edit( $caps, $cap, $user_id, $args ) {
		if ( 'read_post' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $caps;
		}

		return map_meta_cap( 'edit_post', $user_id, $post->ID );
	}

	/**
	 * Restore a trashed conversation to its status before the trash.
	 *
	 * Core restores posts as drafts. Conversations are private.
	 *
	 * @param string $new_status      Status core would restore.
	 * @param int    $post_id         Post ID.
	 * @param string $previous_status Status before the trash.
	 * @return string
	 */
	public function restore_previous_status( $new_status, $post_id, $previous_status ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) || ! $previous_status ) {
			return $new_status;
		}

		return $previous_status;
	}

	/**
	 * Whether a post is a conversation that is not in the trash.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return bool
	 */
	public function is_open_conversation( int $ep_chat_id ): bool {
		$post = get_post( $ep_chat_id );

		return $post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status;
	}

	/**
	 * The user's open conversation started with a client key.
	 *
	 * @param string $client_key Client key.
	 * @param int    $user_id    Conversation author.
	 * @return int Conversation post ID, or 0.
	 */
	public function find_by_client_key( string $client_key, int $user_id ): int {
		if ( '' === $client_key || ! $user_id ) {
			return 0;
		}

		$ids = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'author'           => $user_id,
				'meta_key'         => self::META_CLIENT_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $client_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Register the conversation post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$args = [
			'labels'              => [
				'name'          => esc_html__( 'Chats', 'elasticpress-labs' ),
				'singular_name' => esc_html__( 'Chat', 'elasticpress-labs' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => [ 'title', 'comments', 'author' ],
			'capability_type'     => Capabilities::get_capability_type(),
			'map_meta_cap'        => true,
		];

		/**
		 * Filters `ep_chat` post type args.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_register_post_type_args
		 * @param {array} $args Post type args.
		 * @return {array} Filtered args.
		 */
		$args = apply_filters( 'ep_chat_register_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Keep Chat comments out of default comment queries.
	 *
	 * @param \WP_Comment_Query $query Comment query.
	 * @return void
	 */
	public function exclude_chat_comments( \WP_Comment_Query $query ) {
		$type    = $query->query_vars['type'] ?? '';
		$type_in = $query->query_vars['type__in'] ?? [];

		if ( '' !== $type && 'all' !== $type ) {
			return;
		}

		if ( ! empty( $type_in ) ) {
			return;
		}

		$not_in   = (array) ( $query->query_vars['type__not_in'] ?? [] );
		$not_in[] = self::COMMENT_TYPE_QUESTION;
		$not_in[] = self::COMMENT_TYPE_ANSWER;

		$query->query_vars['type__not_in'] = array_values( array_unique( $not_in ) );
	}

	/**
	 * Do not index conversations as public search documents.
	 *
	 * @param array $post_types Indexable post types.
	 * @return array
	 */
	public function exclude_from_index( $post_types ) {
		unset( $post_types[ self::POST_TYPE ] );

		return $post_types;
	}

	/**
	 * Create a conversation post. Settings are not stored on the post.
	 *
	 * @param string $title Optional title.
	 * @return int|\WP_Error
	 */
	public function create_conversation( string $title = '' ) {
		$title = trim( $title );

		if ( '' === $title ) {
			$title = __( 'Chat', 'elasticpress-labs' );
		}

		$args = [
			'post_type'   => self::POST_TYPE,
			'post_status' => 'private',
			'post_title'  => $title,
			'post_author' => get_current_user_id(),
		];

		/**
		 * Filters args used to create an `ep_chat` conversation.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_create_conversation_args
		 * @param {array}  $args  Post args.
		 * @param {string} $title Requested title.
		 * @return {array} Filtered args.
		 */
		$args = apply_filters( 'ep_chat_create_conversation_args', $args, $title );

		return wp_insert_post( $args, true );
	}

	/**
	 * Accept a question: add a queued question comment to a conversation.
	 *
	 * Without a conversation ID, a new conversation is created and the
	 * question becomes its title. With one, the question is a follow-up in
	 * that conversation, and any unfinished question there is timed out
	 * first: the newer question supersedes it, so a late answer to it is
	 * stored flagged `superseded` and stays hidden.
	 *
	 * A new conversation stores the client key the Ask screen sent, so a retry that never got
	 * the conversation ID back can find it (see find_by_client_key()).
	 *
	 * @param string $question   User question.
	 * @param int    $ep_chat_id Existing conversation post ID, or 0 for a new one.
	 * @param string $client_key Client key for a new conversation.
	 * @return array|\WP_Error {
	 *     @type int $ep_chat_id  Conversation post ID.
	 *     @type int $question_id Question comment ID.
	 * }
	 */
	public function accept_question( string $question, int $ep_chat_id = 0, string $client_key = '' ) {
		if ( $ep_chat_id && ! $this->is_open_conversation( $ep_chat_id ) ) {
			return $this->conversation_not_found();
		}

		if ( $ep_chat_id ) {
			$this->time_out_active_questions( $ep_chat_id );
		} else {
			$ep_chat_id = $this->create_conversation( $question );

			if ( ! is_wp_error( $ep_chat_id ) && '' !== $client_key ) {
				update_post_meta( (int) $ep_chat_id, self::META_CLIENT_KEY, $client_key );
			}
		}

		if ( is_wp_error( $ep_chat_id ) ) {
			return $ep_chat_id;
		}

		$question_id = $this->add_question( (int) $ep_chat_id, $question );

		if ( is_wp_error( $question_id ) ) {
			return $question_id;
		}

		return [
			'ep_chat_id'  => (int) $ep_chat_id,
			'question_id' => (int) $question_id,
		];
	}

	/**
	 * Store the answer and set the question status.
	 *
	 * A question the reaper already timed out still takes a late `done` or
	 * `refused` answer: the answer replaces the timeout. If a newer question
	 * in the same conversation superseded it, the answer is kept but flagged
	 * `superseded` and the status stays `error`. A late `error` is dropped,
	 * because the timeout already reports the failure.
	 *
	 * @param int    $ep_chat_id  Conversation post ID.
	 * @param int    $question_id Question comment ID.
	 * @param string $content     Answer text.
	 * @param array  $payload     Structured payload (no secrets).
	 * @param string $status      Question status after the answer.
	 * @return int|\WP_Error Answer comment ID.
	 */
	public function complete_question(
		int $ep_chat_id,
		int $question_id,
		string $content,
		array $payload = [],
		string $status = self::STATUS_DONE
	) {
		if ( ! $this->is_allowed_status( $status ) ) {
			return new \WP_Error(
				'ep_chat_invalid_status',
				esc_html__( 'Invalid Chat status.', 'elasticpress-labs' )
			);
		}

		$late_allowed = self::STATUS_ERROR !== $status;

		if ( $late_allowed && $this->is_timed_out( $question_id ) && $this->is_superseded( $question_id ) ) {
			$payload['superseded'] = true;

			return $this->add_answer( $ep_chat_id, $content, $payload, $question_id );
		}

		$answer_id = $this->add_answer( $ep_chat_id, $content, $payload, $question_id );

		if ( is_wp_error( $answer_id ) ) {
			$this->compare_and_set_status( $question_id, $this->get_active_statuses(), self::STATUS_ERROR );

			return $answer_id;
		}

		if ( $this->compare_and_set_status( $question_id, $this->get_active_statuses(), $status ) ) {
			delete_comment_meta( $question_id, self::META_STEP );

			return $answer_id;
		}

		if ( $late_allowed
			&& $this->is_timed_out( $question_id )
			&& $this->compare_and_set_status( $question_id, [ self::STATUS_ERROR ], $status ) ) {
			$this->delete_timeout_answers( $question_id, $answer_id );

			return $answer_id;
		}

		wp_delete_comment( $answer_id, true );

		return new \WP_Error(
			'ep_chat_question_complete',
			esc_html__( 'This Chat question already has an answer.', 'elasticpress-labs' )
		);
	}

	/**
	 * Claim a queued question for a worker: `queued` → `running`.
	 *
	 * Only one worker wins the claim, so a question never runs twice.
	 *
	 * @param int $question_id Question comment ID.
	 * @return bool Whether this caller claimed the question.
	 */
	public function claim_question( int $question_id ): bool {
		if ( ! $this->compare_and_set_status( $question_id, [ self::STATUS_QUEUED ], self::STATUS_RUNNING ) ) {
			return false;
		}

		delete_comment_meta( $question_id, self::META_STEP );
		$this->touch_question( $question_id );

		return true;
	}

	/**
	 * Record worker activity and, optionally, the current pipeline step.
	 *
	 * @param int    $question_id Question comment ID.
	 * @param string $step        Step slug. Empty keeps the current step.
	 * @return void
	 */
	public function touch_question( int $question_id, string $step = '' ) {
		update_comment_meta( $question_id, self::META_HEARTBEAT, time() );

		if ( '' !== $step ) {
			update_comment_meta( $question_id, self::META_STEP, $step );
		}
	}

	/**
	 * Last activity timestamp of a question.
	 *
	 * Falls back to the comment date for questions stored before the
	 * heartbeat existed.
	 *
	 * @param int $question_id Question comment ID.
	 * @return int Unix timestamp, or 0 if the question does not exist.
	 */
	public function get_heartbeat( int $question_id ): int {
		$heartbeat = (int) get_comment_meta( $question_id, self::META_HEARTBEAT, true );

		if ( $heartbeat > 0 ) {
			return $heartbeat;
		}

		$comment = get_comment( $question_id );

		return $comment ? (int) strtotime( $comment->comment_date_gmt . ' UTC' ) : 0;
	}

	/**
	 * Current pipeline step of a question.
	 *
	 * @param int $question_id Question comment ID.
	 * @return string
	 */
	public function get_step( int $question_id ): string {
		return (string) get_comment_meta( $question_id, self::META_STEP, true );
	}

	/**
	 * Flip a stale queued/running question to `error` with a timeout answer.
	 *
	 * The timeout answer is written before the status flips. If the worker
	 * finishes first, the flip fails and the timeout answer is removed.
	 *
	 * @param int    $question_id Question comment ID.
	 * @param string $message     Answer text. Defaults to the timeout message.
	 * @return bool Whether the question was timed out.
	 */
	public function time_out_question( int $question_id, string $message = '' ): bool {
		$comment = get_comment( $question_id );

		if ( ! $comment || self::COMMENT_TYPE_QUESTION !== $comment->comment_type ) {
			return false;
		}

		$answer_id = $this->add_answer(
			(int) $comment->comment_post_ID,
			'' !== $message ? $message : $this->timeout_message(),
			[ 'error_code' => self::ERROR_TIMEOUT ],
			$question_id
		);

		if ( ! is_wp_error( $answer_id ) ) {
			update_comment_meta( $answer_id, self::META_ERROR_CODE, self::ERROR_TIMEOUT );
		}

		if ( ! $this->compare_and_set_status( $question_id, $this->get_active_statuses(), self::STATUS_ERROR ) ) {
			if ( ! is_wp_error( $answer_id ) ) {
				wp_delete_comment( $answer_id, true );
			}

			return false;
		}

		delete_comment_meta( $question_id, self::META_STEP );

		return true;
	}

	/**
	 * Whether the reaper timed out this question.
	 *
	 * @param int $question_id Question comment ID.
	 * @return bool
	 */
	public function is_timed_out( int $question_id ): bool {
		if ( self::STATUS_ERROR !== $this->get_status( $question_id, true ) ) {
			return false;
		}

		return [] !== $this->get_timeout_answer_ids( $question_id );
	}

	/**
	 * Whether a newer question exists in the same conversation.
	 *
	 * @param int $question_id Question comment ID.
	 * @return bool
	 */
	public function is_superseded( int $question_id ): bool {
		$comment = get_comment( $question_id );

		if ( ! $comment ) {
			return false;
		}

		$questions = get_comments(
			[
				'post_id' => (int) $comment->comment_post_ID,
				'type'    => self::COMMENT_TYPE_QUESTION,
				'status'  => 'approve',
				'fields'  => 'ids',
				'orderby' => 'comment_ID',
				'order'   => 'DESC',
				'number'  => 1,
			]
		);

		return ! empty( $questions ) && (int) $questions[0] > $question_id;
	}

	/**
	 * Statuses of a question that has not finished.
	 *
	 * @return array
	 */
	public function get_active_statuses(): array {
		return [ self::STATUS_QUEUED, self::STATUS_RUNNING ];
	}

	/**
	 * Reader-safe copy for a timed-out question.
	 *
	 * @return string
	 */
	protected function timeout_message(): string {
		$message = __( 'The request timed out. Try again.', 'elasticpress-labs' );

		/**
		 * Filters the answer the reaper writes on a stale Chat job.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_timeout_message
		 * @param {string} $message Timeout copy.
		 * @return {string} Filtered copy.
		 */
		return (string) apply_filters( 'ep_chat_timeout_message', $message );
	}

	/**
	 * Timeout answer comment IDs of a question.
	 *
	 * @param int $question_id Question comment ID.
	 * @return array
	 */
	protected function get_timeout_answer_ids( int $question_id ): array {
		return array_map(
			'intval',
			get_comments(
				[
					'parent'     => $question_id,
					'type'       => self::COMMENT_TYPE_ANSWER,
					'status'     => 'approve',
					'fields'     => 'ids',
					'meta_key'   => self::META_ERROR_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => self::ERROR_TIMEOUT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			)
		);
	}

	/**
	 * Delete the timeout answers once a late answer replaced them.
	 *
	 * @param int $question_id Question comment ID.
	 * @param int $keep_id     Late answer comment ID.
	 * @return void
	 */
	protected function delete_timeout_answers( int $question_id, int $keep_id ) {
		foreach ( $this->get_timeout_answer_ids( $question_id ) as $answer_id ) {
			if ( $answer_id !== $keep_id ) {
				wp_delete_comment( $answer_id, true );
			}
		}
	}

	/**
	 * Atomically move a question from one of `$from` to `$to`.
	 *
	 * The conditional UPDATE is the lock: when a worker and the reaper
	 * race, only one of them changes the status.
	 *
	 * @param int    $question_id Question comment ID.
	 * @param array  $from        Statuses the question may have now.
	 * @param string $to          New status.
	 * @return bool Whether this call changed the status.
	 */
	protected function compare_and_set_status( int $question_id, array $from, string $to ): bool {
		global $wpdb;

		if ( empty( $from ) || ! $this->is_allowed_status( $to ) ) {
			return false;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $from ), '%s' ) );

		// The meta API cannot compare-and-set, so this needs a direct query. `$placeholders` is
		// only `%s` tokens, and every value goes through prepare() as one array, which the
		// placeholder sniffs cannot count.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->commentmeta} SET meta_value = %s WHERE comment_id = %d AND meta_key = %s AND meta_value IN ( {$placeholders} )",
				array_merge( [ $to, $question_id, self::META_STATUS ], array_values( $from ) )
			)
		);
		// phpcs:enable

		wp_cache_delete( $question_id, 'comment_meta' );

		if ( $updated > 0 ) {
			// The meta API would bump this. Cached comment queries on the status depend on it.
			wp_cache_set_comments_last_changed();
		}

		return $updated > 0;
	}

	/**
	 * Get a conversation and its turns.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return array|\WP_Error
	 */
	public function get( int $ep_chat_id ) {
		if ( ! $this->is_open_conversation( $ep_chat_id ) ) {
			return $this->conversation_not_found();
		}

		$post = get_post( $ep_chat_id );

		return [
			'ep_chat_id' => (int) $post->ID,
			'title'      => $post->post_title,
			'turns'      => $this->get_turns( (int) $post->ID ),
		];
	}

	/**
	 * Time out every queued or running question in a conversation.
	 *
	 * @param int $ep_chat_id Conversation post ID.
	 * @return int Number of questions timed out.
	 */
	protected function time_out_active_questions( int $ep_chat_id ): int {
		$ids = get_comments(
			[
				'post_id'    => $ep_chat_id,
				'type'       => self::COMMENT_TYPE_QUESTION,
				'status'     => 'approve',
				'fields'     => 'ids',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => self::META_STATUS,
						'value'   => $this->get_active_statuses(),
						'compare' => 'IN',
					],
				],
			]
		);

		$count   = 0;
		$message = __( 'Stopped waiting for this answer.', 'elasticpress-labs' );

		foreach ( $ids as $id ) {
			if ( $this->time_out_question( (int) $id, $message ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Error for a missing or trashed conversation.
	 *
	 * @return \WP_Error
	 */
	protected function conversation_not_found(): \WP_Error {
		return new \WP_Error(
			'ep_chat_invalid_conversation',
			esc_html__( 'Invalid Chat conversation.', 'elasticpress-labs' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Prior turns to send to the model with a question.
	 *
	 * Built from storage, not from the client. Only finished exchanges count:
	 * a question whose answer is `done` or `refused`. Error, timeout, and
	 * superseded answers, and their questions, are left out. Citation
	 * markers such as `[[2]]` are stripped from answers. Comments are read
	 * newest first, a page at a time, and reading stops once there are enough
	 * exchanges.
	 *
	 * @param int   $ep_chat_id  Conversation post ID.
	 * @param int   $question_id The question being asked. Only earlier turns are used.
	 * @param array $bot         Selected chat bot (for its Conversation memory).
	 * @return array Turns with `role` and `content`, oldest first.
	 */
	public function get_history( int $ep_chat_id, int $question_id = 0, array $bot = [] ): array {
		/**
		 * Filters how many prior messages (questions and answers) are sent
		 * to the model with a follow-up question. With an odd number, the
		 * oldest answer is dropped so the history starts with a question.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_history_messages
		 * @param {int}   $count       Number of messages. Default: the bot's Conversation memory
		 *                             (else the Chat feature's, else 4).
		 * @param {int}   $ep_chat_id  Conversation post ID.
		 * @param {int}   $question_id Question comment ID.
		 * @param {array} $bot         Selected chat bot.
		 * @return {int} Filtered number of messages.
		 */
		$limit = (int) apply_filters( 'ep_chat_history_messages', (int) Settings::get( 'history_messages', $bot ), $ep_chat_id, $question_id, $bot );

		if ( $limit <= 0 ) {
			return [];
		}

		// Newest first, a page at a time, until there are enough finished exchanges.
		// Skipped exchanges (errors, timeouts) mean the last N comments are not the last N messages.
		$page      = max( 20, 2 * $limit + 10 );
		$offset    = 0;
		$answers   = [];
		$exchanges = [];
		$fetched   = 0;

		do {
			$comments = get_comments(
				[
					'post_id'       => $ep_chat_id,
					'type__in'      => [ self::COMMENT_TYPE_QUESTION, self::COMMENT_TYPE_ANSWER ],
					'status'        => 'approve',
					'orderby'       => 'comment_ID',
					'order'         => 'DESC',
					'number'        => $page,
					'offset'        => $offset,
					'no_found_rows' => true,
				]
			);

			foreach ( $comments as $comment ) {
				$id = (int) $comment->comment_ID;

				if ( self::COMMENT_TYPE_ANSWER === $comment->comment_type ) {
					$parent = (int) $comment->comment_parent;

					// The newest valid answer of a question wins. An answer comes after its question, so it is seen first.
					if ( ! $parent || isset( $answers[ $parent ] ) ) {
						continue;
					}

					$payload = get_comment_meta( $id, self::META_PAYLOAD, true );

					if ( ! empty( $payload['superseded'] ) || get_comment_meta( $id, self::META_ERROR_CODE, true ) ) {
						continue;
					}

					$answers[ $parent ] = $comment;

					continue;
				}

				if ( $question_id && $id >= $question_id ) {
					continue;
				}

				if ( empty( $answers[ $id ] ) || ! in_array( $this->get_status( $id ), [ self::STATUS_DONE, self::STATUS_REFUSED, self::STATUS_CLARIFY ], true ) ) {
					continue;
				}

				$answer = trim( Text::strip_markers( $answers[ $id ]->comment_content, true ) );

				if ( '' === $answer ) {
					continue;
				}

				$exchanges[] = [
					[
						'role'    => 'user',
						'content' => (string) $comment->comment_content,
					],
					[
						'role'    => 'assistant',
						'content' => $answer,
					],
				];

				// Two messages per exchange.
				if ( 2 * count( $exchanges ) >= $limit ) {
					break 2;
				}
			}

			$fetched = count( $comments );
			$offset += $page;
		} while ( $fetched === $page );

		$messages = [];

		foreach ( array_reverse( $exchanges ) as $exchange ) {
			$messages = array_merge( $messages, $exchange );
		}

		$messages = array_slice( $messages, -$limit );

		// An odd limit would start the history with an answer. Models expect a question first.
		if ( $messages && 'assistant' === $messages[0]['role'] ) {
			array_shift( $messages );
		}

		return $messages;
	}

	/**
	 * Add a question comment. This comment is the async job/status record.
	 *
	 * @param int    $conversation_id Conversation post ID.
	 * @param string $content         Question text.
	 * @param string $status          Initial status.
	 * @return int|\WP_Error
	 */
	public function add_question( int $conversation_id, string $content, string $status = self::STATUS_QUEUED ) {
		$comment_id = $this->insert_turn(
			$conversation_id,
			$content,
			self::COMMENT_TYPE_QUESTION
		);

		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}

		$updated = $this->set_status( $comment_id, $status );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->touch_question( $comment_id );

		return $comment_id;
	}

	/**
	 * Add an answer comment with an optional structured payload.
	 *
	 * @param int    $conversation_id Conversation post ID.
	 * @param string $content         Answer text.
	 * @param array  $payload         Structured payload (no secrets).
	 * @param int    $question_id     Optional parent question comment ID.
	 * @return int|\WP_Error
	 */
	public function add_answer( int $conversation_id, string $content, array $payload = [], int $question_id = 0 ) {
		$comment_id = $this->insert_turn(
			$conversation_id,
			$content,
			self::COMMENT_TYPE_ANSWER,
			$question_id
		);

		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}

		if ( ! empty( $payload ) ) {
			update_comment_meta( $comment_id, self::META_PAYLOAD, $payload );
		}

		return $comment_id;
	}

	/**
	 * Set the job status on a question comment.
	 *
	 * @param int    $question_id Question comment ID.
	 * @param string $status      Status slug.
	 * @return true|\WP_Error
	 */
	public function set_status( int $question_id, string $status ) {
		if ( ! $this->is_allowed_status( $status ) ) {
			return new \WP_Error(
				'ep_chat_invalid_status',
				esc_html__( 'Invalid Chat status.', 'elasticpress-labs' )
			);
		}

		$comment = get_comment( $question_id );

		if ( ! $comment || self::COMMENT_TYPE_QUESTION !== $comment->comment_type ) {
			return new \WP_Error(
				'ep_chat_invalid_question',
				esc_html__( 'Invalid Chat question.', 'elasticpress-labs' )
			);
		}

		update_comment_meta( $question_id, self::META_STATUS, $status );

		return true;
	}

	/**
	 * Get the job status for a question comment.
	 *
	 * @param int  $question_id Question comment ID.
	 * @param bool $fresh       Skip the meta cache. Use when another process may have changed it.
	 * @return string
	 */
	public function get_status( int $question_id, bool $fresh = false ): string {
		if ( $fresh ) {
			wp_cache_delete( $question_id, 'comment_meta' );
		}

		return (string) get_comment_meta( $question_id, self::META_STATUS, true );
	}

	/**
	 * Store worker context on the question comment. No secrets.
	 *
	 * @param int   $question_id Question comment ID.
	 * @param int   $bot_id      Selected bot ID.
	 * @param array $messages    Prior conversation turns.
	 * @return true|\WP_Error
	 */
	public function set_job_context( int $question_id, int $bot_id, array $messages ) {
		$comment = get_comment( $question_id );

		if ( ! $comment || self::COMMENT_TYPE_QUESTION !== $comment->comment_type ) {
			return new \WP_Error(
				'ep_chat_invalid_question',
				esc_html__( 'Invalid Chat question.', 'elasticpress-labs' )
			);
		}

		update_comment_meta( $question_id, self::META_BOT_ID, $bot_id );
		update_comment_meta( $question_id, self::META_MESSAGES, $messages );

		return true;
	}

	/**
	 * Load worker context from the question comment.
	 *
	 * @param int $question_id Question comment ID.
	 * @return array|\WP_Error
	 */
	public function get_job_context( int $question_id ) {
		$comment = get_comment( $question_id );

		if ( ! $comment || self::COMMENT_TYPE_QUESTION !== $comment->comment_type ) {
			return new \WP_Error(
				'ep_chat_invalid_question',
				esc_html__( 'Invalid Chat question.', 'elasticpress-labs' )
			);
		}

		$messages = get_comment_meta( $question_id, self::META_MESSAGES, true );
		$answers  = get_comments(
			[
				'parent'  => $question_id,
				'type'    => self::COMMENT_TYPE_ANSWER,
				'status'  => 'approve',
				'number'  => 1,
				'orderby' => 'comment_ID',
				'order'   => 'DESC',
			]
		);

		return [
			'ep_chat_id'  => (int) $comment->comment_post_ID,
			'question_id' => $question_id,
			'question'    => (string) $comment->comment_content,
			'bot_id'      => (int) get_comment_meta( $question_id, self::META_BOT_ID, true ),
			'messages'    => is_array( $messages ) ? $messages : [],
			'answer_id'   => ! empty( $answers ) ? (int) $answers[0]->comment_ID : 0,
		];
	}

	/**
	 * Get ordered turns for a conversation.
	 *
	 * @param int $conversation_id Conversation post ID.
	 * @return array
	 */
	public function get_turns( int $conversation_id ): array {
		$comments = get_comments(
			[
				'post_id'  => $conversation_id,
				'type__in' => [ self::COMMENT_TYPE_QUESTION, self::COMMENT_TYPE_ANSWER ],
				'status'   => 'approve',
				// IDs keep insert order when turns share a timestamp.
				'orderby'  => 'comment_ID',
				'order'    => 'ASC',
			]
		);

		$turns = [];
		$bots  = [];

		foreach ( $comments as $comment ) {
			$payload = get_comment_meta( $comment->comment_ID, self::META_PAYLOAD, true );
			$content = (string) $comment->comment_content;
			$status  = '';

			if ( self::COMMENT_TYPE_QUESTION === $comment->comment_type ) {
				$status = $this->get_status( (int) $comment->comment_ID );
			} elseif ( $comment->comment_parent ) {
				$status = $this->get_status( (int) $comment->comment_parent );
			}

			$payload = is_array( $payload ) ? $payload : [];

			if ( self::COMMENT_TYPE_ANSWER === $comment->comment_type ) {
				$payload = Sources::attach_to_payload( $payload, $status );
				$bot_id  = (int) get_comment_meta( (int) $comment->comment_parent, self::META_BOT_ID, true );

				if ( ! isset( $bots[ $bot_id ] ) ) {
					$bot             = $bot_id ? ( new Bots() )->get( $bot_id ) : [];
					$bots[ $bot_id ] = is_array( $bot ) ? $bot : [];
				}

				[ $payload, $content ] = Refusals::present( $payload, $content, $status, $bots[ $bot_id ] );
			}

			$turn = [
				'id'      => (int) $comment->comment_ID,
				'role'    => self::COMMENT_TYPE_QUESTION === $comment->comment_type ? 'user' : 'assistant',
				'content' => $content,
				'status'  => $status,
				'payload' => $payload,
			];

			if ( 'user' === $turn['role'] && in_array( $status, $this->get_active_statuses(), true ) ) {
				$step               = $this->get_step( (int) $comment->comment_ID );
				$turn['step']       = $step;
				$turn['step_label'] = Steps::label( $step, $status );
			}

			$turns[] = $turn;
		}

		return $turns;
	}

	/**
	 * Insert a turn comment.
	 *
	 * @param int    $conversation_id Conversation post ID.
	 * @param string $content         Comment content.
	 * @param string $type            Comment type.
	 * @param int    $parent_id       Parent comment ID.
	 * @return int|\WP_Error
	 */
	protected function insert_turn( int $conversation_id, string $content, string $type, int $parent_id = 0 ) {
		$content = trim( $content );

		if ( '' === $content ) {
			return new \WP_Error(
				'ep_chat_empty_turn',
				esc_html__( 'Chat turns cannot be empty.', 'elasticpress-labs' )
			);
		}

		$post = get_post( $conversation_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'ep_chat_invalid_conversation',
				esc_html__( 'Invalid Chat conversation.', 'elasticpress-labs' )
			);
		}

		$user = wp_get_current_user();

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $conversation_id,
				'comment_content'      => $content,
				'comment_type'         => $type,
				'comment_parent'       => $parent_id,
				'comment_approved'     => 1,
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name ? $user->display_name : __( 'Chat', 'elasticpress-labs' ),
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_agent'        => '',
			]
		);

		if ( ! $comment_id ) {
			return new \WP_Error(
				'ep_chat_turn_failed',
				esc_html__( 'Could not store the Chat turn.', 'elasticpress-labs' )
			);
		}

		return (int) $comment_id;
	}

	/**
	 * Whether a status slug is allowed.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	protected function is_allowed_status( string $status ): bool {
		$allowed = [
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_DONE,
			self::STATUS_REFUSED,
			self::STATUS_CLARIFY,
			self::STATUS_ERROR,
		];

		/**
		 * Filters allowed Chat question statuses.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_allowed_statuses
		 * @param {array} $allowed Status slugs.
		 * @return {array} Filtered slugs.
		 */
		$allowed = apply_filters( 'ep_chat_allowed_statuses', $allowed );

		return in_array( $status, $allowed, true );
	}
}
