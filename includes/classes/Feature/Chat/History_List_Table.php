<?php
/**
 * Chat History list table.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists `ep_chat` conversations with a `WP_Query`, like the core posts list.
 *
 * Users see their own conversations. Users who can edit others' posts see
 * everyone's and can filter by author.
 */
class History_List_Table extends \WP_List_Table {
	/**
	 * Conversations per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Per-conversation question count and latest question ID, loaded once
	 * for the current page.
	 *
	 * @var array<int, array{count: int, latest: int}>
	 */
	protected $questions = [];

	/**
	 * View counts, cached for the request.
	 *
	 * @var array<string, int>
	 */
	protected $counts = [];

	/**
	 * Create the table.
	 *
	 * @param array $args List table args.
	 */
	public function __construct( $args = [] ) {
		parent::__construct(
			array_merge(
				[
					'plural'   => 'conversations',
					'singular' => 'conversation',
					'ajax'     => false,
				],
				$args
			)
		);

		$this->storage = new Storage();
	}

	/**
	 * Whether the current user sees everyone's conversations.
	 *
	 * @return bool
	 */
	public static function can_see_all(): bool {
		return Capabilities::current_user_can( Capabilities::OTHERS );
	}

	/**
	 * Whether the Trash view is shown.
	 *
	 * @return bool
	 */
	public function is_trash(): bool {
		return 'trash' === $this->get_request_string( 'post_status' );
	}

	/**
	 * A sanitized string from the request.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	protected function get_request_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '';
	}

	/**
	 * `WP_Query` args for the current request.
	 *
	 * @return array
	 */
	public function get_query_args(): array {
		return self::build_query_args(
			$this->get_filters(),
			self::can_see_all(),
			get_current_user_id(),
			[
				'posts_per_page' => self::PER_PAGE,
				'paged'          => max( 1, $this->get_pagenum() ),
			]
		);
	}

	/**
	 * The list filters of the current request: `view` (`all` or `trash`), `author`, `m`
	 * (YYYYMM), and `s`.
	 *
	 * @return array{view: string, author: int, m: string, s: string}
	 */
	public function get_filters(): array {
		return [
			'view'   => $this->is_trash() ? 'trash' : 'all',
			'author' => absint( $this->get_request_string( 'author' ) ),
			'm'      => $this->get_request_string( 'm' ),
			's'      => $this->get_request_string( 's' ),
		];
	}

	/**
	 * `WP_Query` args for list filters. The History screen and the export share them.
	 *
	 * @param array $filters `view`, `author`, `m`, `s` (see get_filters()).
	 * @param bool  $see_all Whether the user sees everyone's conversations (else only theirs).
	 * @param int   $user_id The user.
	 * @param array $extra   More args, such as paging.
	 * @return array
	 */
	public static function build_query_args( array $filters, bool $see_all, int $user_id, array $extra = [] ): array {
		$args = array_merge(
			[
				'post_type'           => Storage::POST_TYPE,
				'post_status'         => 'trash' === ( $filters['view'] ?? '' ) ? 'trash' : array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) ),
				// The ID orders conversations started in the same second, so paging neither repeats nor skips one.
				'orderby'             => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
				'ignore_sticky_posts' => true,
			],
			$extra
		);

		$author = absint( $filters['author'] ?? 0 );

		if ( ! $see_all ) {
			$args['author'] = $user_id;
		} elseif ( $author ) {
			$args['author'] = $author;
		}

		$month = (string) ( $filters['m'] ?? '' );

		if ( preg_match( '/^\d{6}$/', $month ) ) {
			$args['m'] = $month;
		}

		$search = trim( (string) ( $filters['s'] ?? '' ) );

		if ( '' !== $search ) {
			$ids              = self::search_conversation_ids( $search, $args['author'] ?? 0 );
			$args['post__in'] = $ids ? $ids : [ 0 ];
		}

		/**
		 * Filters the `WP_Query` args of the Chat History screen and the Chat export.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_history_query_args
		 * @param {array} $args Query args.
		 * @return {array} Filtered args.
		 */
		return apply_filters( 'ep_chat_history_query_args', $args );
	}

	/**
	 * Statuses listed in the "All" view: every status core lists, which
	 * leaves out the trash.
	 *
	 * @return array
	 */
	protected function get_listed_statuses(): array {
		return array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) );
	}

	/**
	 * Conversations with a question (including follow-ups) or a summary that
	 * matches the search.
	 *
	 * Each of the two lookups stops at `ep_chat_history_search_limit` rows,
	 * newest first, so a broad search can't load every question. Older
	 * matches beyond that are not listed.
	 *
	 * @param string $search    Search text.
	 * @param int    $author_id Restrict to this author's conversations. 0 for any.
	 * @return array Post IDs.
	 */
	public static function search_conversation_ids( string $search, int $author_id ): array {
		/**
		 * Filters the most rows the History search reads for each of its
		 * lookups (question text and conversation summaries).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_history_search_limit
		 * @param {int} $limit Maximum rows. Default 500.
		 * @return {int} Filtered maximum rows.
		 */
		$limit = max( 1, (int) apply_filters( 'ep_chat_history_search_limit', 500 ) );

		$args = [
			'type'          => Storage::COMMENT_TYPE_QUESTION,
			'post_type'     => Storage::POST_TYPE,
			'search'        => $search,
			'status'        => 'any',
			'orderby'       => 'comment_ID',
			'order'         => 'DESC',
			'number'        => $limit,
			'no_found_rows' => true,
		];

		if ( $author_id ) {
			$args['post_author'] = $author_id;
		}

		$ids = [];

		foreach ( get_comments( $args ) as $comment ) {
			// Comment search also matches author name, email, URL, and IP. Only the question counts.
			if ( false !== mb_stripos( $comment->comment_content, $search ) ) {
				$ids[] = (int) $comment->comment_post_ID;
			}
		}

		// The conversation summary (post_content), when summaries are on.
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_content LIKE %s",
			Storage::POST_TYPE,
			'%' . $wpdb->esc_like( $search ) . '%'
		);

		if ( $author_id ) {
			$sql .= $wpdb->prepare( ' AND post_author = %d', $author_id );
		}

		$sql .= $wpdb->prepare( ' ORDER BY ID DESC LIMIT %d', $limit );

		$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $sql ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Load the current page of conversations.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$query = new \WP_Query( $this->get_query_args() );

		$this->items = $query->posts;
		$this->load_questions( array_map( 'intval', wp_list_pluck( $this->items, 'ID' ) ) );

		$this->set_pagination_args(
			[
				'total_items' => (int) $query->found_posts,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) $query->max_num_pages,
			]
		);

		$this->_column_headers = [ $this->get_columns(), [], [], 'title' ];
	}

	/**
	 * Load the question count and latest question of each listed
	 * conversation in one query, and prime their comment meta.
	 *
	 * @param array $post_ids Conversation post IDs.
	 * @return void
	 */
	protected function load_questions( array $post_ids ) {
		global $wpdb;

		$this->questions = [];

		if ( empty( $post_ids ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One grouped query for the page; `$placeholders` is only `%d` tokens.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID AS post_id, COUNT(*) AS total, MAX(comment_ID) AS latest FROM {$wpdb->comments} WHERE comment_type = %s AND comment_post_ID IN ( {$placeholders} ) GROUP BY comment_post_ID",
				array_merge( [ Storage::COMMENT_TYPE_QUESTION ], $post_ids )
			)
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$this->questions[ (int) $row->post_id ] = [
				'count'  => (int) $row->total,
				'latest' => (int) $row->latest,
			];
		}

		update_meta_cache( 'comment', wp_list_pluck( $this->questions, 'latest' ) );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'cb'        => '<input type="checkbox" />',
			'title'     => esc_html__( 'Question', 'elasticpress-labs' ),
			'author'    => esc_html__( 'Author', 'elasticpress-labs' ),
			'questions' => esc_html__( 'Questions', 'elasticpress-labs' ),
			'status'    => esc_html__( 'Status', 'elasticpress-labs' ),
			'date'      => esc_html__( 'Date', 'elasticpress-labs' ),
		];
	}

	/**
	 * All / Trash views with counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$base  = History::url();
		$all   = $this->count_conversations( $this->get_listed_statuses() );
		$trash = $this->count_trash();

		$views = [
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $base ),
				$this->is_trash() ? '' : ' class="current" aria-current="page"',
				esc_html__( 'All', 'elasticpress-labs' ),
				esc_html( number_format_i18n( $all ) )
			),
		];

		if ( $trash ) {
			$views['trash'] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'post_status', 'trash', $base ) ),
				$this->is_trash() ? ' class="current" aria-current="page"' : '',
				esc_html__( 'Trash', 'elasticpress-labs' ),
				esc_html( number_format_i18n( $trash ) )
			);
		}

		return $views;
	}

	/**
	 * Number of trashed conversations the current user can see.
	 *
	 * @return int
	 */
	public function count_trash(): int {
		return $this->count_conversations( [ 'trash' ] );
	}

	/**
	 * Count the conversations the current user can see.
	 *
	 * @param array $statuses Post statuses.
	 * @return int
	 */
	protected function count_conversations( array $statuses ): int {
		$key = implode( ',', $statuses );

		if ( isset( $this->counts[ $key ] ) ) {
			return $this->counts[ $key ];
		}

		$args = [
			'post_type'      => Storage::POST_TYPE,
			'post_status'    => $statuses,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		];

		if ( ! self::can_see_all() ) {
			$args['author'] = get_current_user_id();
		}

		$this->counts[ $key ] = (int) ( new \WP_Query( $args ) )->found_posts;

		return $this->counts[ $key ];
	}

	/**
	 * Bulk actions for the current view.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		if ( $this->is_trash() ) {
			return [
				'untrash' => esc_html__( 'Restore', 'elasticpress-labs' ),
				'delete'  => esc_html__( 'Delete permanently', 'elasticpress-labs' ),
			];
		}

		return [
			'trash' => esc_html__( 'Move to Trash', 'elasticpress-labs' ),
		];
	}

	/**
	 * Month and author filters.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		add_filter( 'pre_months_dropdown_query', [ $this, 'get_months' ], 10, 2 );
		$this->months_dropdown( Storage::POST_TYPE );
		remove_filter( 'pre_months_dropdown_query', [ $this, 'get_months' ], 10 );

		if ( self::can_see_all() ) {
			$this->authors_dropdown();
		}

		submit_button( esc_html__( 'Filter', 'elasticpress-labs' ), '', 'filter_action', false, [ 'id' => 'ep-chat-history-filter' ] );

		echo '</div>';
	}

	/**
	 * Months for the date filter, limited to the conversations the current
	 * user can see in the current view.
	 *
	 * @param object[]|false $months    Months, or false to let core query them.
	 * @param string         $post_type Post type.
	 * @return object[]|false
	 */
	public function get_months( $months, $post_type ) {
		global $wpdb;

		if ( Storage::POST_TYPE !== $post_type ) {
			return $months;
		}

		$statuses     = $this->is_trash() ? [ 'trash' ] : $this->get_listed_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$values       = array_merge( [ Storage::POST_TYPE ], $statuses );
		$author_sql   = '';

		if ( ! self::can_see_all() ) {
			$author_sql = ' AND post_author = %d';
			$values[]   = get_current_user_id();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Same query core runs, scoped to the user; interpolated parts are only placeholders.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( {$placeholders} ){$author_sql} ORDER BY post_date DESC",
				$values
			)
		);
		// phpcs:enable
	}

	/**
	 * Author filter over the users who have conversations.
	 *
	 * @return void
	 */
	protected function authors_dropdown() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only filter options.
		$author_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s",
				Storage::POST_TYPE
			)
		);

		if ( empty( $author_ids ) ) {
			return;
		}

		echo '<label for="ep-chat-history-author" class="screen-reader-text">' . esc_html__( 'Filter by author', 'elasticpress-labs' ) . '</label>';

		wp_dropdown_users(
			[
				'id'              => 'ep-chat-history-author',
				'name'            => 'author',
				'include'         => array_map( 'intval', $author_ids ),
				'selected'        => absint( $this->get_request_string( 'author' ) ),
				'show_option_all' => esc_html__( 'All authors', 'elasticpress-labs' ),
			]
		);
	}

	/**
	 * Message when there are no conversations.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( $this->is_trash() ) {
			esc_html_e( 'No conversations in the Trash.', 'elasticpress-labs' );
			return;
		}

		if ( '' !== $this->get_request_string( 's' ) ) {
			esc_html_e( 'No questions match your search.', 'elasticpress-labs' );
			return;
		}

		esc_html_e( 'No questions yet. Ask one to start.', 'elasticpress-labs' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Post $item Conversation.
	 * @return string
	 */
	public function column_cb( $item ) {
		if ( ! current_user_can( 'delete_post', $item->ID ) ) {
			return '';
		}

		return sprintf(
			'<label class="screen-reader-text" for="ep-chat-%1$d">%2$s</label><input id="ep-chat-%1$d" type="checkbox" name="post[]" value="%1$d" />',
			(int) $item->ID,
			/* translators: %s: Conversation title. */
			esc_html( sprintf( __( 'Select %s', 'elasticpress-labs' ), $item->post_title ) )
		);
	}

	/**
	 * Question column with row actions.
	 *
	 * @param \WP_Post $item Conversation.
	 * @return string
	 */
	public function column_title( $item ) {
		$title = '' !== $item->post_title ? $item->post_title : __( '(no question)', 'elasticpress-labs' );

		if ( $this->is_trash() ) {
			return '<strong>' . esc_html( $title ) . '</strong>';
		}

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
			esc_url( Admin::ask_url( (int) $item->ID ) ),
			esc_html( $title )
		);
	}

	/**
	 * Row actions for the Question column.
	 *
	 * @param \WP_Post $item        Conversation.
	 * @param string   $column_name Column name.
	 * @param string   $primary     Primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$actions = [];
		$id      = (int) $item->ID;

		if ( $this->is_trash() ) {
			if ( current_user_can( 'delete_post', $id ) ) {
				$actions['untrash'] = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( History::action_url( 'untrash', $id ) ),
					esc_html__( 'Restore', 'elasticpress-labs' )
				);
				$actions['delete']  = sprintf(
					'<a class="submitdelete" href="%1$s">%2$s</a>',
					esc_url( History::action_url( 'delete', $id ) ),
					esc_html__( 'Delete permanently', 'elasticpress-labs' )
				);
			}

			return $this->row_actions( $actions );
		}

		$actions['open'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Admin::ask_url( $id ) ),
			esc_html__( 'Open', 'elasticpress-labs' )
		);

		if ( current_user_can( 'delete_post', $id ) ) {
			$actions['trash'] = sprintf(
				'<a class="submitdelete" href="%1$s">%2$s</a>',
				esc_url( History::action_url( 'trash', $id ) ),
				esc_html__( 'Trash', 'elasticpress-labs' )
			);
		}

		return $this->row_actions( $actions );
	}

	/**
	 * Author column. Links to that author's conversations for users who see
	 * everyone's.
	 *
	 * @param \WP_Post $item Conversation.
	 * @return string
	 */
	public function column_author( $item ) {
		$name = get_the_author_meta( 'display_name', (int) $item->post_author );

		if ( ! self::can_see_all() ) {
			return esc_html( $name );
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( add_query_arg( 'author', (int) $item->post_author, History::url() ) ),
			esc_html( $name )
		);
	}

	/**
	 * Number of questions in the conversation.
	 *
	 * @param \WP_Post $item Conversation.
	 * @return string
	 */
	public function column_questions( $item ) {
		return esc_html( number_format_i18n( $this->questions[ (int) $item->ID ]['count'] ?? 0 ) );
	}

	/**
	 * Status of the latest question.
	 *
	 * @param \WP_Post $item Conversation.
	 * @return string
	 */
	public function column_status( $item ) {
		$question_id = $this->questions[ (int) $item->ID ]['latest'] ?? 0;

		if ( ! $question_id ) {
			return '&mdash;';
		}

		$status = $this->storage->get_status( $question_id );

		if ( Storage::STATUS_ERROR === $status && $this->storage->is_timed_out( $question_id ) ) {
			return esc_html__( 'Timed out', 'elasticpress-labs' );
		}

		$labels = [
			Storage::STATUS_QUEUED  => __( 'Queued', 'elasticpress-labs' ),
			Storage::STATUS_RUNNING => __( 'Running', 'elasticpress-labs' ),
			Storage::STATUS_DONE    => __( 'Answered', 'elasticpress-labs' ),
			Storage::STATUS_REFUSED => __( 'Refused', 'elasticpress-labs' ),
			Storage::STATUS_CLARIFY => __( 'Clarify', 'elasticpress-labs' ),
			Storage::STATUS_ERROR   => __( 'Error', 'elasticpress-labs' ),
		];

		return esc_html( $labels[ $status ] ?? $status );
	}

	/**
	 * Date column.
	 *
	 * @param \WP_Post $item Conversation.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html(
			sprintf(
				/* translators: 1: Date, 2: Time. */
				__( '%1$s at %2$s', 'elasticpress-labs' ),
				get_the_date( '', $item ),
				get_the_time( '', $item )
			)
		);
	}
}
