<?php
/**
 * Chat bot configuration storage.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\Chat\Gates\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers `ep_chat_bot` and stores per-bot options as postmeta.
 */
class Bots {
	/**
	 * Chat bot post type.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'ep_chat_bot';

	/**
	 * Execution mode postmeta key.
	 *
	 * @var string
	 */
	public const META_EXECUTION_MODE = 'ep_chat_bot_execution_mode';

	/**
	 * Whether this bot re-ranks retrieved passages.
	 *
	 * @var string
	 */
	public const META_RERANK_ENABLED = 'ep_chat_bot_rerank_enabled';

	/**
	 * Postmeta prefix of every bot setting, and the key prefix of the shared answering settings
	 * a bot overrides (see Settings::FIELDS), such as `ep_chat_bot_answer_model`. Duplicate
	 * copies every meta key with this prefix.
	 *
	 * @var string
	 */
	public const META_SETTING_PREFIX = 'ep_chat_bot_';

	/**
	 * Run the pipeline in the same request.
	 *
	 * @var string
	 */
	public const MODE_SYNC = 'sync';

	/**
	 * Queue via the Labs wp-async loopback runner.
	 *
	 * @var string
	 */
	public const MODE_WP_ASYNC = 'wp_async';

	/**
	 * Queue via Action Scheduler.
	 *
	 * @var string
	 */
	public const MODE_ACTION_SCHEDULER = 'action_scheduler';

	/**
	 * Queue via WP-Cron.
	 *
	 * @var string
	 */
	public const MODE_WP_CRON = 'wp_cron';

	/**
	 * Default bot title created on feature activation.
	 *
	 * @var string
	 */
	public const DEFAULT_NAME = 'Default';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_filter( 'ep_indexable_post_types', [ $this, 'exclude_from_index' ] );
	}

	/**
	 * Register the chat bot post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$capability   = Capabilities::get( Capabilities::MANAGE_BOTS );
		$capabilities = array_fill_keys(
			[ 'edit_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_post', 'read_private_posts', 'delete_post', 'create_posts', 'delete_posts', 'delete_others_posts' ],
			$capability
		);

		$args = [
			'labels'              => [
				'name'          => esc_html__( 'Chat Bots', 'elasticpress-labs' ),
				'singular_name' => esc_html__( 'Chat Bot', 'elasticpress-labs' ),
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
			'supports'            => [ 'title' ],
			'capability_type'     => 'post',
			'capabilities'        => $capabilities,
			'map_meta_cap'        => false,
		];

		/**
		 * Filters `ep_chat_bot` post type args.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_bot_register_post_type_args
		 * @param {array} $args Post type args.
		 * @return {array} Filtered args.
		 */
		$args = apply_filters( 'ep_chat_bot_register_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Do not index chat bots as public search documents.
	 *
	 * @param array $post_types Indexable post types.
	 * @return array
	 */
	public function exclude_from_index( $post_types ) {
		unset( $post_types[ self::POST_TYPE ] );

		return $post_types;
	}

	/**
	 * Runner slugs that may be stored on a bot.
	 *
	 * Action Scheduler is omitted unless that library is loaded.
	 *
	 * @return array
	 */
	public function allowed_execution_modes(): array {
		$modes = [
			self::MODE_SYNC,
			self::MODE_WP_ASYNC,
			self::MODE_WP_CRON,
		];

		if ( ( new Jobs\Action_Scheduler_Runner() )->is_available() ) {
			$modes[] = self::MODE_ACTION_SCHEDULER;
		}

		return $modes;
	}

	/**
	 * Labels for the bot radio and Ask select.
	 *
	 * @return array
	 */
	public function describe_execution_modes(): array {
		$labels       = [
			self::MODE_SYNC             => __( 'Sync', 'elasticpress-labs' ),
			self::MODE_WP_ASYNC         => __( 'WP Async', 'elasticpress-labs' ),
			self::MODE_ACTION_SCHEDULER => __( 'Action Scheduler', 'elasticpress-labs' ),
			self::MODE_WP_CRON          => __( 'WP Cron', 'elasticpress-labs' ),
		];
		$descriptions = [
			self::MODE_SYNC             => __( 'The answer is written while the person waits. Fastest for most models.', 'elasticpress-labs' ),
			self::MODE_WP_ASYNC         => __( 'Starts right after the page request ends. The quickest background runner.', 'elasticpress-labs' ),
			self::MODE_ACTION_SCHEDULER => __( 'Runs in the Action Scheduler queue.', 'elasticpress-labs' ),
			self::MODE_WP_CRON          => __( 'Runs on the next WP-Cron run, which can wait on sites with little traffic.', 'elasticpress-labs' ),
		];
		$modes        = [];

		foreach ( $this->allowed_execution_modes() as $slug ) {
			$modes[] = [
				'value'       => $slug,
				'label'       => $labels[ $slug ] ?? $slug,
				'description' => $descriptions[ $slug ] ?? '',
			];
		}

		return $modes;
	}

	/**
	 * Whether a slug queues instead of running in-request.
	 *
	 * @param string $mode Stored or filtered mode.
	 * @return bool
	 */
	public function is_async_mode( string $mode ): bool {
		return self::MODE_SYNC !== $this->sanitize_execution_mode( $mode );
	}

	/**
	 * Normalize an execution mode slug.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	public function sanitize_execution_mode( string $mode ): string {
		return in_array( $mode, $this->allowed_execution_modes(), true )
			? $mode
			: self::MODE_SYNC;
	}

	/**
	 * Create a chat bot.
	 *
	 * @param string $name            Bot name.
	 * @param string $execution_mode  Sync or async.
	 * @param array  $settings        Optional re-ranker settings.
	 * @return int|\WP_Error
	 */
	public function create( string $name, string $execution_mode = self::MODE_SYNC, array $settings = [] ) {
		$valid = $this->validate_settings( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$name = trim( $name );

		if ( '' === $name ) {
			return $this->empty_name_error();
		}

		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_author' => get_current_user_id(),
				'menu_order'  => $this->next_menu_order(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->force_publish( (int) $post_id );

		update_post_meta(
			$post_id,
			self::META_EXECUTION_MODE,
			$this->sanitize_execution_mode( $execution_mode )
		);
		$this->update_settings( (int) $post_id, $settings );

		return (int) $post_id;
	}

	/**
	 * Update a chat bot.
	 *
	 * @param int    $bot_id          Bot post ID.
	 * @param string $name            Bot name.
	 * @param string $execution_mode  Sync or async.
	 * @param array  $settings        Optional re-ranker settings.
	 * @return int|\WP_Error
	 */
	public function update( int $bot_id, string $name, string $execution_mode, array $settings = [] ) {
		$valid = $this->validate_settings( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post = $this->get_post( $bot_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$name = trim( $name );

		if ( '' === $name ) {
			return $this->empty_name_error();
		}

		$updated = wp_update_post(
			[
				'ID'         => $bot_id,
				'post_title' => $name,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_post_meta(
			$bot_id,
			self::META_EXECUTION_MODE,
			$this->sanitize_execution_mode( $execution_mode )
		);
		$this->update_settings( $bot_id, $settings );

		return (int) $updated;
	}

	/**
	 * Duplicate a chat bot: same settings (every `ep_chat_bot_*` field, so fields added later are
	 * copied too), named "<name> (copy)", placed last.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return int|\WP_Error New bot ID.
	 */
	public function duplicate( int $bot_id ) {
		$post = $this->get_post( $bot_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$copy_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: %s: chat bot name */
				'post_title'  => sprintf( __( '%s (copy)', 'elasticpress-labs' ), $post->post_title ),
				'post_author' => get_current_user_id(),
				'menu_order'  => $this->next_menu_order(),
			],
			true
		);

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		$this->force_publish( (int) $copy_id );

		foreach ( get_post_meta( $bot_id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, self::META_SETTING_PREFIX ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				// Slashed, because add_post_meta() unslashes.
				add_post_meta( (int) $copy_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		return (int) $copy_id;
	}

	/**
	 * Delete a chat bot.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return true|\WP_Error
	 */
	public function delete( int $bot_id ) {
		$post = $this->get_post( $bot_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$deleted = wp_delete_post( $bot_id, true );

		if ( ! $deleted ) {
			return new \WP_Error(
				'ep_chat_bot_delete_failed',
				esc_html__( 'Could not delete the chat bot.', 'elasticpress-labs' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}

	/**
	 * Get one chat bot as an array.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return array|\WP_Error
	 */
	public function get( int $bot_id ) {
		$post = $this->get_post( $bot_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->to_array( $post );
	}

	/**
	 * List all chat bots. There is no maximum count.
	 *
	 * @return array
	 */
	public function get_all(): array {
		$posts = get_posts(
			[
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPageNoUnlimited.posts_per_page_posts_per_page
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'suppress_filters'       => true,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			]
		);

		return array_map( [ $this, 'to_array' ], $posts );
	}

	/**
	 * Create a default bot when none exist.
	 *
	 * @return int|\WP_Error 0 when a bot already exists.
	 */
	public function ensure_default() {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			$this->register_post_type();
		}

		if ( ! empty( $this->get_all() ) ) {
			return 0;
		}

		return $this->create( __( 'Default', 'elasticpress-labs' ), self::MODE_SYNC );
	}

	/**
	 * Persist admin list order. The first ID is the Ask default.
	 *
	 * @param array $ids Bot IDs in display order.
	 * @return array|\WP_Error
	 */
	public function reorder( array $ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids )
				)
			)
		);

		$current = wp_list_pluck( $this->get_all(), 'id' );
		sort( $current );
		$sorted = $ids;
		sort( $sorted );

		if ( $current !== $sorted ) {
			return new \WP_Error(
				'ep_chat_bot_invalid_order',
				esc_html__( 'Chat bot order must include every existing bot.', 'elasticpress-labs' ),
				[ 'status' => 400 ]
			);
		}

		foreach ( $ids as $index => $bot_id ) {
			wp_update_post(
				[
					'ID'         => $bot_id,
					'menu_order' => $index,
				]
			);
		}

		return $this->get_all();
	}

	/**
	 * Shape a bot post for REST and Ask.
	 *
	 * @param \WP_Post $post Bot post.
	 * @return array
	 */
	public function to_array( \WP_Post $post ): array {
		$mode = (string) get_post_meta( $post->ID, self::META_EXECUTION_MODE, true );

		return [
			'id'             => (int) $post->ID,
			'name'           => $post->post_title,
			'execution_mode' => $this->sanitize_execution_mode( $mode ),
			'rerank_enabled' => $this->get_rerank_enabled( (int) $post->ID ),
			'optional_gates' => Gates::get_optional_enabled( (int) $post->ID ),
			'gates'          => Gates::describe_for_bot( (int) $post->ID ),
		] + $this->get_setting_overrides( (int) $post->ID );
	}

	/**
	 * Get a bot post or an error.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return \WP_Post|\WP_Error
	 */
	protected function get_post( int $bot_id ) {
		$post = get_post( $bot_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'ep_chat_invalid_bot',
				esc_html__( 'Invalid chat bot.', 'elasticpress-labs' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * Ensure a bot post is published. wp_insert_post can demote this when the
	 * current user cannot publish.
	 *
	 * @param int $post_id Bot post ID.
	 * @return void
	 */
	protected function force_publish( int $post_id ) {
		if ( 'publish' === get_post_status( $post_id ) ) {
			return;
		}

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[ 'post_status' => 'publish' ],
			[ 'ID' => $post_id ],
			[ '%s' ],
			[ '%d' ]
		);

		clean_post_cache( $post_id );
	}

	/**
	 * Whether a bot re-ranks passages.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return bool
	 */
	public function get_rerank_enabled( int $bot_id ): bool {
		return $this->sanitize_rerank_enabled(
			get_post_meta( $bot_id, self::META_RERANK_ENABLED, true )
		);
	}

	/**
	 * Normalize the re-ranker on/off flag.
	 *
	 * @param mixed $enabled Raw value.
	 * @return bool
	 */
	public function sanitize_rerank_enabled( $enabled ): bool {
		return filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Store a bot's settings: re-ranker on/off, optional gates, and its overrides of the shared
	 * answering settings (empty clears an override, so the bot inherits). Only keys present in
	 * $settings change.
	 *
	 * @param int   $bot_id   Bot post ID.
	 * @param array $settings Settings.
	 * @return void
	 */
	protected function update_settings( int $bot_id, array $settings ) {
		if ( array_key_exists( 'rerank_enabled', $settings ) ) {
			update_post_meta(
				$bot_id,
				self::META_RERANK_ENABLED,
				$this->sanitize_rerank_enabled( $settings['rerank_enabled'] ) ? '1' : '0'
			);
		}

		if ( array_key_exists( 'optional_gates', $settings ) ) {
			Gates::update_optional_enabled( $bot_id, $settings['optional_gates'] );
		}

		foreach ( Settings::get_shared_keys() as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$value = Settings::sanitize( $key, $settings[ $key ] );

			if ( null === $value || is_wp_error( $value ) ) {
				delete_post_meta( $bot_id, self::META_SETTING_PREFIX . $key );
			} else {
				// Slashed, because update_post_meta() unslashes and the instructions can hold backslashes.
				update_post_meta( $bot_id, self::META_SETTING_PREFIX . $key, wp_slash( Settings::to_stored( $value ) ) );
			}
		}
	}

	/**
	 * A bot's own values for the shared answering settings; null means it inherits.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return array<string, mixed>
	 */
	public function get_setting_overrides( int $bot_id ): array {
		$overrides = [];

		foreach ( Settings::get_shared_keys() as $key ) {
			$value             = Settings::sanitize( $key, get_post_meta( $bot_id, self::META_SETTING_PREFIX . $key, true ), false );
			$overrides[ $key ] = is_wp_error( $value ) ? null : $value;
		}

		return $overrides;
	}

	/**
	 * Refuse out-of-range values and unknown models before a bot is saved.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	protected function validate_settings( array $settings ) {
		foreach ( Settings::get_shared_keys() as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$value = Settings::sanitize( $key, $settings[ $key ] );

			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		return true;
	}

	/**
	 * Next menu_order so a new bot is appended (does not become default).
	 *
	 * @return int
	 */
	protected function next_menu_order(): int {
		$latest = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'orderby'          => 'menu_order',
				'order'            => 'DESC',
				'suppress_filters' => true,
			]
		);

		if ( ! $latest ) {
			return 0;
		}

		return (int) $latest[0]->menu_order + 1;
	}

	/**
	 * Error for a missing bot name.
	 *
	 * @return \WP_Error
	 */
	protected function empty_name_error() {
		return new \WP_Error(
			'ep_chat_bot_empty_name',
			esc_html__( 'Enter a chat bot name.', 'elasticpress-labs' ),
			[ 'status' => 400 ]
		);
	}
}
