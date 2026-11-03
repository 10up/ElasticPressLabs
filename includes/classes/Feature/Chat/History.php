<?php
/**
 * Chat History screen.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AI Chat → History: lists conversations, opens them on Ask, and moves
 * them to and from the trash.
 */
class History {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ep-ai-chat-history';

	/**
	 * Query arg (and nonce action) of the export download.
	 *
	 * @var string
	 */
	const EXPORT_ARG = 'ep_chat_export';

	/**
	 * Actions this screen handles, with the result query arg each one sets.
	 *
	 * @var array
	 */
	const ACTIONS = [
		'trash'   => 'trashed',
		'untrash' => 'untrashed',
		'delete'  => 'deleted',
	];

	/**
	 * List table for the current request.
	 *
	 * @var History_List_Table|null
	 */
	protected $table = null;

	/**
	 * Register the submenu under AI Chat.
	 *
	 * @return void
	 */
	public function register_menu() {
		$hook = add_submenu_page(
			Admin::PAGE_SLUG,
			esc_html__( 'History', 'elasticpress-labs' ),
			esc_html__( 'History', 'elasticpress-labs' ),
			Capabilities::get( Capabilities::ASK ),
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'load' ] );
		}
	}

	/**
	 * History screen URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * Nonced URL for a row action.
	 *
	 * @param string $action     `trash`, `untrash`, or `delete`.
	 * @param int    $ep_chat_id Conversation post ID.
	 * @return string
	 */
	public static function action_url( string $action, int $ep_chat_id ): string {
		$args = [
			'action' => $action,
			'post'   => $ep_chat_id,
		];

		if ( 'trash' !== $action ) {
			$args['post_status'] = 'trash';
		}

		return wp_nonce_url( self::url( $args ), self::nonce_action( $action, $ep_chat_id ) );
	}

	/**
	 * Nonce action for a row action.
	 *
	 * @param string $action     Action.
	 * @param int    $ep_chat_id Conversation post ID.
	 * @return string
	 */
	protected static function nonce_action( string $action, int $ep_chat_id ): string {
		return "ep_chat_history_{$action}_{$ep_chat_id}";
	}

	/**
	 * Handle row and bulk actions before output, then redirect.
	 *
	 * @return void
	 */
	public function load() {
		add_action( 'admin_enqueue_scripts', [ $this, 'styles' ] );

		$this->table = new History_List_Table();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in export().
		if ( isset( $_GET[ self::EXPORT_ARG ] ) ) {
			$this->export();
		}

		$action = $this->table->current_action();

		if ( ! $action || ! isset( self::ACTIONS[ $action ] ) ) {
			return;
		}

		$ids = $this->get_requested_ids( $action );

		if ( empty( $ids ) ) {
			return;
		}

		$done = $this->apply_action( $action, $ids );

		$redirect = remove_query_arg(
			[ 'action', 'action2', 'post', '_wpnonce', '_wp_http_referer', 'trashed', 'untrashed', 'deleted', 'filter_action' ],
			wp_get_referer() ? wp_get_referer() : self::url()
		);

		if ( 'trash' !== $action && $this->table->count_trash() < 1 ) {
			$redirect = remove_query_arg( 'post_status', $redirect );
		}

		wp_safe_redirect( add_query_arg( self::ACTIONS[ $action ], $done, $redirect ) );
		exit;
	}

	/**
	 * URL that downloads the export of the list as filtered now.
	 *
	 * @return string
	 */
	public function export_url(): string {
		$filters = $this->table->get_filters();
		$args    = [
			self::EXPORT_ARG => 1,
			'_wpnonce'       => wp_create_nonce( self::EXPORT_ARG ),
		];

		if ( 'trash' === $filters['view'] ) {
			$args['post_status'] = 'trash';
		}

		foreach ( [ 'author', 'm', 's' ] as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ $key ] = $filters[ $key ];
			}
		}

		return self::url( $args );
	}

	/**
	 * Send the JSON export of the filtered list, then stop. Only for users who see everyone's
	 * conversations.
	 *
	 * @return void
	 */
	protected function export() {
		if ( ! History_List_Table::can_see_all() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export conversations.', 'elasticpress-labs' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::EXPORT_ARG );

		// No output buffer or compression between the chunks and the browser.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Disposition: attachment; filename=' . Export::get_filename() );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Accel-Buffering: no' );

		( new Export() )->stream( $this->table->get_filters(), true, get_current_user_id() );
		exit;
	}

	/**
	 * Column widths, like core sets for its own list tables.
	 *
	 * @return void
	 */
	public function styles() {
		wp_add_inline_style(
			'list-tables',
			'.wp-list-table .column-author{width:14%}.wp-list-table .column-questions{width:7em}.wp-list-table .column-status{width:9em}.wp-list-table .column-date{width:14%}'
		);
	}

	/**
	 * Conversation IDs for the action, after the nonce check.
	 *
	 * @param string $action Action.
	 * @return array
	 */
	protected function get_requested_ids( string $action ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( isset( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) {
			check_admin_referer( 'bulk-conversations' );

			return array_filter( array_map( 'absint', wp_unslash( $_REQUEST['post'] ) ) );
		}

		$id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $id ) {
			return [];
		}

		check_admin_referer( self::nonce_action( $action, $id ) );

		return [ $id ];
	}

	/**
	 * Trash, restore, or delete conversations the user may delete.
	 *
	 * @param string $action Action.
	 * @param array  $ids    Conversation post IDs.
	 * @return int Number of conversations changed.
	 */
	public function apply_action( string $action, array $ids ): int {
		$done = 0;

		foreach ( $ids as $id ) {
			if ( Storage::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}

			if ( 'trash' === $action ) {
				$result = wp_trash_post( $id );
			} elseif ( 'untrash' === $action ) {
				$result = wp_untrash_post( $id );
			} else {
				$result = wp_delete_post( $id, true );
			}

			if ( $result ) {
				++$done;
			}
		}

		return $done;
	}

	/**
	 * Notice for the last action.
	 *
	 * @return void
	 */
	protected function render_notice() {
		$messages = [
			/* translators: %s: Number of conversations. */
			'trashed'   => _n_noop( '%s conversation moved to the Trash.', '%s conversations moved to the Trash.', 'elasticpress-labs' ),
			/* translators: %s: Number of conversations. */
			'untrashed' => _n_noop( '%s conversation restored from the Trash.', '%s conversations restored from the Trash.', 'elasticpress-labs' ),
			/* translators: %s: Number of conversations. */
			'deleted'   => _n_noop( '%s conversation permanently deleted.', '%s conversations permanently deleted.', 'elasticpress-labs' ),
		];

		foreach ( $messages as $key => $message ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			$count = isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : null;

			if ( null === $count ) {
				continue;
			}

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( translate_nooped_plural( $message, $count, 'elasticpress-labs' ), number_format_i18n( $count ) ) )
			);
		}
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->table ) {
			$this->table = new History_List_Table();
		}

		$this->table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'History', 'elasticpress-labs' ); ?></h1>
			<a href="<?php echo esc_url( Admin::ask_url() ); ?>" class="page-title-action"><?php esc_html_e( 'New chat', 'elasticpress-labs' ); ?></a>
			<?php if ( History_List_Table::can_see_all() ) : ?>
				<a href="<?php echo esc_url( $this->export_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Export', 'elasticpress-labs' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php $this->render_notice(); ?>
			<?php $this->table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php if ( $this->table->is_trash() ) : ?>
					<input type="hidden" name="post_status" value="trash" />
				<?php endif; ?>
				<?php $this->table->search_box( esc_html__( 'Search questions', 'elasticpress-labs' ), 'ep-chat-history' ); ?>
				<?php $this->table->display(); ?>
			</form>
		</div>
		<?php
	}
}
