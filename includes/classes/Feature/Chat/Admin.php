<?php
/**
 * Chat admin screens.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Jobs\Reaper;
use ElasticPressLabs\Utils as LabsUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the AI Chat admin menu and Ask screen.
 */
class Admin {
	/**
	 * Menu and page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ep-ai-chat';

	/**
	 * ElasticPress submenu slug for AI Chat Bots.
	 *
	 * @var string
	 */
	const BOTS_PAGE_SLUG = 'elasticpress-chat-bots';

	/**
	 * WordPress hooks.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
		add_filter( 'menu_order', [ $this, 'place_menu_in_first_section' ], 110 );
	}

	/**
	 * Register the top-level AI Chat menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			esc_html__( 'AI Chat', 'elasticpress-labs' ),
			esc_html__( 'AI Chat', 'elasticpress-labs' ),
			Capabilities::get( Capabilities::ASK ),
			self::PAGE_SLUG,
			[ $this, 'render_ask_page' ],
			'dashicons-format-chat',
			3
		);

		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__( 'Ask', 'elasticpress-labs' ),
			esc_html__( 'Ask', 'elasticpress-labs' ),
			Capabilities::get( Capabilities::ASK ),
			self::PAGE_SLUG,
			[ $this, 'render_ask_page' ]
		);

		( new History() )->register_menu();

		add_submenu_page(
			'elasticpress',
			esc_html__( 'AI Chat Bots', 'elasticpress-labs' ),
			esc_html__( 'AI Chat Bots', 'elasticpress-labs' ),
			Capabilities::get( Capabilities::MANAGE_BOTS ),
			self::BOTS_PAGE_SLUG,
			[ $this, 'render_bots_page' ]
		);
	}

	/**
	 * Place AI Chat at the end of the first admin menu section.
	 *
	 * Sites that rebuild `menu_order` (so the numeric `add_menu_page` position is
	 * ignored) still get the item before the first separator or Posts.
	 *
	 * @param array $menu_order Current menu order slugs.
	 * @return array
	 */
	public function place_menu_in_first_section( $menu_order ) {
		if ( ! is_array( $menu_order ) ) {
			return $menu_order;
		}

		$slug = self::PAGE_SLUG;

		$menu_order = array_values(
			array_filter(
				$menu_order,
				static function ( $item ) use ( $slug ) {
					return $item !== $slug;
				}
			)
		);

		$insert_at = count( $menu_order );

		foreach ( $menu_order as $index => $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			if ( str_starts_with( $item, 'separator' ) || 'edit.php' === $item ) {
				$insert_at = $index;
				break;
			}
		}

		array_splice( $menu_order, $insert_at, 0, [ $slug ] );

		return $menu_order;
	}

	/**
	 * Ask screen URL, optionally for one conversation.
	 *
	 * @param int $ep_chat_id Conversation post ID. 0 for a new chat.
	 * @return string
	 */
	public static function ask_url( int $ep_chat_id = 0 ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return $ep_chat_id ? add_query_arg( 'chat', $ep_chat_id, $url ) : $url;
	}

	/**
	 * Render the Ask screen mount point.
	 *
	 * @return void
	 */
	public function render_ask_page() {
		?>
		<div class="wrap">
			<div id="ep-ai-chat"></div>
		</div>
		<?php
	}

	/**
	 * Render the AI Chat Bots screen mount point.
	 *
	 * @return void
	 */
	public function render_bots_page() {
		include EP_PATH . '/includes/partials/header.php';

		?>
		<div class="wrap">
			<div id="ep-chat-bots"></div>
		</div>
		<?php
	}

	/**
	 * Whether the current request is the Ask screen.
	 *
	 * @return bool
	 */
	public function is_ask_page() {
		if ( empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		return self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Conversation ID from the Ask URL (`?chat=`).
	 *
	 * @return int
	 */
	protected function get_requested_chat_id(): int {
		if ( empty( $_GET['chat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}

		return absint( wp_unslash( $_GET['chat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Whether the current request is the AI Chat Bots screen.
	 *
	 * @return bool
	 */
	public function is_bots_page() {
		if ( ! function_exists( '\get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return 'elasticpress_page_' . self::BOTS_PAGE_SLUG === $screen->base;
	}

	/**
	 * Enqueue Chat admin scripts.
	 *
	 * @return void
	 */
	public function scripts() {
		if ( $this->is_ask_page() ) {
			$this->enqueue_ask_scripts();
			return;
		}

		if ( $this->is_bots_page() ) {
			$this->enqueue_bots_scripts();
		}
	}

	/**
	 * Enqueue the Ask screen script and styles.
	 *
	 * @return void
	 */
	protected function enqueue_ask_scripts() {
		$asset = LabsUtils\get_asset_info( 'chat-script' );

		if ( empty( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			'ep_chat_scripts',
			ELASTICPRESS_LABS_URL . 'dist/js/chat-script.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ep_chat_scripts', 'elasticpress-labs' );

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wp-edit-post' );

		wp_enqueue_style(
			'ep_chat_scripts',
			ELASTICPRESS_LABS_URL . 'dist/css/chat-script.css',
			array_values(
				array_filter(
					$asset['dependencies'],
					static function ( $style ) {
						return wp_style_is( $style, 'registered' );
					}
				)
			),
			$asset['version']
		);

		wp_localize_script(
			'ep_chat_scripts',
			'epChat',
			[
				'botsEndpoint'     => 'elasticpress-labs/v1/chat-bots',
				'botsAdminUrl'     => Capabilities::current_user_can( Capabilities::MANAGE_BOTS ) ? admin_url( 'admin.php?page=' . self::BOTS_PAGE_SLUG ) : '',
				'chatEndpoint'     => 'elasticpress-labs/v1/chat',
				'historyUrl'       => History::url(),
				'recentChats'      => $this->get_recent_conversations(),
				'recentChatsLimit' => $this->get_recent_conversations_count(),
				'askUrl'           => self::ask_url(),
				'todayLabel'       => wp_date( get_option( 'date_format' ) ),
				'initialChatId'    => $this->get_requested_chat_id(),
				'requestTimeoutMs' => 55000,
				'pollIntervalMs'   => 2000,
				'pollTimeoutMs'    => $this->get_poll_slow_ms(),
				'executionModes'   => ( new Bots() )->describe_execution_modes(),
				'searchAlgorithms' => Settings::get_choice_labels( 'search_fallback' ) + Settings::get_choice_labels( 'search_algorithm' ),
				'technicalDetails' => Capabilities::current_user_can( Capabilities::MANAGE_BOTS ),
				'aiMethods'        => Settings::get_ai_search_methods(),
			]
		);
	}

	/**
	 * How many recent conversations the Ask sidebar lists.
	 *
	 * @return int 0 turns the list off.
	 */
	protected function get_recent_conversations_count(): int {
		/**
		 * Filters how many recent conversations the Ask sidebar lists.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_recent_conversations_count
		 * @param {int} $count Number of conversations. Default 10. 0 turns the list off.
		 * @return {int} Filtered number.
		 */
		return max( 0, (int) apply_filters( 'ep_chat_recent_conversations_count', 10 ) );
	}

	/**
	 * The current user's latest conversations, for the Ask sidebar.
	 *
	 * A search keeps the conversations History finds for it: a question
	 * (follow-ups too) or the summary matches.
	 *
	 * @param string $search Search text. Empty for the latest conversations.
	 * @return array[] Each with `id`, `title`, `date`, and `url`.
	 */
	public function get_recent_conversations( string $search = '' ): array {
		$count = $this->get_recent_conversations_count();

		if ( $count <= 0 ) {
			return [];
		}

		$args = [
			'post_type'           => Storage::POST_TYPE,
			'post_status'         => array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) ),
			'author'              => get_current_user_id(),
			'posts_per_page'      => $count,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];

		$search = trim( $search );

		if ( '' !== $search ) {
			$ids = History_List_Table::search_conversation_ids( $search, get_current_user_id() );

			if ( ! $ids ) {
				return [];
			}

			$args['post__in'] = $ids;
		}

		$query = new \WP_Query( $args );

		return array_map(
			static function ( \WP_Post $post ) {
				// As get_the_title() in the admin: outside it (REST), core adds "Private: " to the title.
				$title = apply_filters( 'the_title', $post->post_title, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

				return [
					'id'    => (int) $post->ID,
					'title' => html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'date'  => get_the_date( '', $post ),
					'url'   => self::ask_url( (int) $post->ID ),
				];
			},
			$query->posts
		);
	}

	/**
	 * How long Ask polls before it says the answer is taking longer than
	 * expected. Ask keeps polling after that: only the reaper ends a job.
	 *
	 * @return int Milliseconds.
	 */
	protected function get_poll_slow_ms(): int {
		$reaper  = new Reaper();
		$seconds = max(
			$reaper->get_ceiling( Storage::STATUS_QUEUED ),
			$reaper->get_ceiling( Storage::STATUS_RUNNING )
		);

		return $seconds * 1000;
	}

	/**
	 * Enqueue the AI Chat Bots screen script and styles.
	 *
	 * @return void
	 */
	protected function enqueue_bots_scripts() {
		$asset = LabsUtils\get_asset_info( 'chat-bots-script' );

		if ( empty( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			'ep_chat_bots_scripts',
			ELASTICPRESS_LABS_URL . 'dist/js/chat-bots-script.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ep_chat_bots_scripts', 'elasticpress-labs' );

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wp-edit-post' );

		wp_enqueue_style(
			'ep_chat_bots_scripts',
			ELASTICPRESS_LABS_URL . 'dist/css/chat-bots-script.css',
			array_values(
				array_filter(
					$asset['dependencies'],
					static function ( $style ) {
						return wp_style_is( $style, 'registered' );
					}
				)
			),
			$asset['version']
		);

		wp_localize_script(
			'ep_chat_bots_scripts',
			'epChatBots',
			[
				'restApiEndpoint' => 'elasticpress-labs/v1/chat-bots',
				'availableGates'  => Gates::registry()->summarize(),
				'gateStages'      => Gates::get_stage_labels(),
				'executionModes'  => ( new Bots() )->describe_execution_modes(),
				'answering'       => $this->describe_answering_settings(),
			]
		);
	}

	/**
	 * What the bot screen needs for the shared answering settings: each field's label, help,
	 * range, and inherited value (shown as the default, and as `inherited`), the model list, and
	 * the Chat feature screen URL.
	 *
	 * @return array
	 */
	protected function describe_answering_settings(): array {
		$fields = [];

		foreach ( Settings::get_shared_keys() as $key ) {
			$field     = Settings::FIELDS[ $key ];
			$inherited = Settings::get_inherited( $key );

			if ( 'model' === $field['type'] ) {
				$default = '' !== $inherited ? Models::get_label( (string) $inherited ) : __( 'the site’s default model', 'elasticpress-labs' );

				if ( '' === $inherited && isset( $field['fallback'] ) ) {
					$default = 'intent_model' === $field['fallback']
						? __( 'same as the question model', 'elasticpress-labs' )
						: __( 'same as the answer model', 'elasticpress-labs' );
				}
			} elseif ( 'bool' === $field['type'] ) {
				$default = $inherited ? __( 'on', 'elasticpress-labs' ) : __( 'off', 'elasticpress-labs' );
			} elseif ( 'choice' === $field['type'] ) {
				$default = Settings::get_choice_labels( $key )[ (string) $inherited ] ?? (string) $inherited;
			} elseif ( 'text' === $field['type'] ) {
				// The inherited text itself, shown in the empty text area.
				$default = (string) $inherited;
			} else {
				$default = null === $inherited ? __( 'none', 'elasticpress-labs' ) : (string) $inherited;
			}

			$fields[ $key ] = [
				'label'        => Settings::get_label( $key ),
				'help'         => Settings::get_help( $key, 'bot' ),
				'details'      => Settings::get_details( $key ),
				'descriptions' => Settings::get_choice_descriptions( $key ),
				'type'         => $field['type'],
				'choices'      => 'choice' === $field['type'] ? array_map(
					static fn( $value, $label ) => [
						'value' => $value,
						'label' => $label,
					],
					array_keys( Settings::get_choice_labels( $key ) ),
					Settings::get_choice_labels( $key )
				) : null,
				'min'          => $field['min'] ?? null,
				'max'          => $field['max'] ?? $field['max_length'] ?? null,
				'default'      => $default,
				// The inherited value itself, for fields shown only for some values of another.
				'inherited'    => Settings::to_stored( $inherited ),
			];
		}

		$models  = Models::get_list();
		$feature = \ElasticPress\Features::factory()->get_registered_feature( 'chat' );

		return [
			'fields'      => $fields,
			'models'      => array_map(
				static function ( $model ) {
					return [
						'value' => $model['value'],
						'label' => $model['label'],
					];
				},
				$models['models']
			),
			'featureUrl'  => admin_url( 'admin.php?page=elasticpress#/chat' ),
			// The search methods that use the fallback and the query model.
			'aiMethods'   => Settings::get_ai_search_methods(),
			// Re-ranking needs the Chat feature's connection; without it the toggle is disabled.
			'rerankSetUp' => $feature instanceof Chat && $feature->is_rerank_set_up(),
		];
	}
}
