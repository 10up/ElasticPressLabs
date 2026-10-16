<?php
/**
 * Test Chat capabilities
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPressLabs\Feature\Chat\Admin;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Capabilities;
use ElasticPressLabs\Feature\Chat\Storage;
use ElasticPressLabs\REST\Chat as ChatController;
use ElasticPressLabs\REST\ChatBots as ChatBotsController;

/**
 * Chat capabilities test class
 */
class TestChatCapabilities extends BaseTestCase {
	/**
	 * Roles checked in the matrices.
	 *
	 * @var string[]
	 */
	protected const ROLES = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];

	/**
	 * Custom capabilities added to roles by a test, removed in tear_down.
	 *
	 * @var bool
	 */
	protected $granted = false;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		( new Storage() )->register_post_type();
		( new Bots() )->register_post_type();

		// rest_api_init also registers ElasticPress's features route, which needs every title.
		foreach ( Features::factory()->registered_features as $feature ) {
			if ( method_exists( $feature, 'set_i18n_strings' ) ) {
				$feature->set_i18n_strings();
			}
		}

		add_action( 'rest_api_init', [ new ChatController(), 'register_routes' ] );
		add_action( 'rest_api_init', [ new ChatBotsController( new Bots() ), 'register_routes' ] );

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Undo custom capability types and granted capabilities.
	 */
	public function tear_down() {
		remove_all_filters( 'ep_chat_capability_type' );

		if ( $this->granted ) {
			foreach ( wp_roles()->role_objects as $role ) {
				foreach ( array_keys( $role->capabilities ) as $capability ) {
					if ( false !== strpos( $capability, 'ep_chats' ) ) {
						$role->remove_cap( $capability );
					}
				}
			}
		}

		( new Storage() )->register_post_type();

		parent::tear_down();
	}

	/**
	 * Give conversations their own capabilities.
	 */
	protected function use_custom_capability_type() {
		add_filter(
			'ep_chat_capability_type',
			static function () {
				return [ 'ep_chat', 'ep_chats' ];
			}
		);

		( new Storage() )->register_post_type();
	}

	/**
	 * A user with a role.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function user( string $role ): int {
		return $this->ep_factory->user->create( [ 'role' => $role ] );
	}

	/**
	 * A stored conversation owned by a user.
	 *
	 * @param int $user_id Owner.
	 * @return int Conversation ID.
	 */
	protected function conversation( int $user_id ): int {
		$previous = get_current_user_id();

		wp_set_current_user( $user_id );
		$accepted = ( new Storage() )->accept_question( 'What is ElasticPress?' );
		wp_set_current_user( $previous );

		return (int) $accepted['ep_chat_id'];
	}

	/**
	 * Status of a REST request as a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $method  Method.
	 * @param string $path    Path.
	 * @param array  $params  Params.
	 * @return int
	 */
	protected function status( int $user_id, string $method, string $path, array $params = [] ): int {
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( $method, $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request )->get_status();
	}

	/**
	 * Access per role: [ ask, others, manage_bots ].
	 *
	 * @return array<string, bool[]>
	 */
	protected function access_by_role(): array {
		$access = [];

		foreach ( self::ROLES as $role ) {
			wp_set_current_user( $this->user( $role ) );

			$access[ $role ] = [
				Capabilities::current_user_can( Capabilities::ASK ),
				Capabilities::current_user_can( Capabilities::OTHERS ),
				Capabilities::current_user_can( Capabilities::MANAGE_BOTS ),
			];
		}

		return $access;
	}

	/**
	 * With no filters, access is what it was: post capabilities and ElasticPress's capability.
	 *
	 * @group chat
	 */
	public function test_defaults_keep_post_access() {
		$this->assertSame(
			[
				Capabilities::ASK         => 'edit_posts',
				Capabilities::OTHERS      => 'edit_others_posts',
				Capabilities::MANAGE_BOTS => 'manage_elasticpress',
			],
			Capabilities::get_map()
		);
		$this->assertSame( 'post', get_post_type_object( Storage::POST_TYPE )->capability_type );
		$this->assertSame(
			[
				'administrator' => [ true, true, true ],
				'editor'        => [ true, true, false ],
				'author'        => [ true, false, false ],
				'contributor'   => [ true, false, false ],
				'subscriber'    => [ false, false, false ],
			],
			$this->access_by_role()
		);
		$this->assertSame( 'do_not_allow', Capabilities::get( 'unknown' ) );
	}

	/**
	 * REST follows the map: who can ask, read others' conversations, list and manage bots.
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_rest_routes_follow_the_map() {
		$author = $this->user( 'author' );
		$editor = $this->user( 'editor' );
		$admin  = $this->user( 'administrator' );
		$sub    = $this->user( 'subscriber' );
		$mine   = $this->conversation( $author );

		$this->assertSame( 401, $this->status( 0, 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );
		$this->assertSame( 403, $this->status( $sub, 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );
		$this->assertSame(
			403,
			$this->status(
				$sub,
				'POST',
				'/elasticpress-labs/v1/chat',
				[
					'question' => 'Hi?',
					'bot_id'   => 1,
				]
			)
		);
		$this->assertSame( 200, $this->status( $author, 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );
		$this->assertSame( 403, $this->status( $this->user( 'author' ), 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );
		$this->assertSame( 200, $this->status( $editor, 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );

		$this->assertSame( 403, $this->status( $sub, 'GET', '/elasticpress-labs/v1/chat-bots' ) );
		$this->assertSame( 200, $this->status( $author, 'GET', '/elasticpress-labs/v1/chat-bots' ) );
		$this->assertSame(
			403,
			$this->status(
				$editor,
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Nope',
					'execution_mode' => Bots::MODE_SYNC,
				]
			)
		);
		$this->assertSame(
			201,
			$this->status(
				$admin,
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Yes',
					'execution_mode' => Bots::MODE_SYNC,
				]
			)
		);
	}

	/**
	 * Admin menus register with the map's capabilities.
	 *
	 * @group chat
	 */
	public function test_menus_use_the_map() {
		global $submenu, $menu;

		add_filter(
			'ep_chat_capabilities',
			static function ( $map ) {
				$map[ Capabilities::ASK ] = 'moderate_comments';

				return $map;
			}
		);

		wp_set_current_user( $this->user( 'administrator' ) );
		( new Admin() )->register_menu();

		$caps = [];

		foreach ( array_merge( $submenu[ Admin::PAGE_SLUG ] ?? [], $submenu['elasticpress'] ?? [] ) as $item ) {
			$caps[ $item[2] ] = $item[1];
		}

		$this->assertSame( 'moderate_comments', $caps[ Admin::PAGE_SLUG ] );
		$this->assertSame( 'moderate_comments', $caps[ \ElasticPressLabs\Feature\Chat\History::PAGE_SLUG ] );
		$this->assertSame( 'manage_elasticpress', $caps[ Admin::BOTS_PAGE_SLUG ] );

		$menu    = [];
		$submenu = [];
	}

	/**
	 * `ep_chat_capabilities` changes one entry; the rest keep their defaults.
	 *
	 * @group chat
	 */
	public function test_single_entry_override() {
		add_filter(
			'ep_chat_capabilities',
			static function ( $map ) {
				$map[ Capabilities::ASK ]    = 'manage_options';
				$map[ Capabilities::OTHERS ] = '';

				return $map;
			}
		);

		$this->assertSame( 'manage_options', Capabilities::get( Capabilities::ASK ) );
		$this->assertSame( 'edit_others_posts', Capabilities::get( Capabilities::OTHERS ) );
		$this->assertSame(
			403,
			$this->status(
				$this->user( 'editor' ),
				'POST',
				'/elasticpress-labs/v1/chat',
				[
					'question' => 'Hi?',
					'bot_id'   => 1,
				]
			)
		);
	}

	/**
	 * `ep_chat_bots_capability` changes bots only, not ElasticPress's capability.
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_bots_capability_override() {
		add_filter(
			'ep_chat_bots_capability',
			static function () {
				return 'edit_others_posts';
			}
		);

		( new Bots() )->register_post_type();

		$editor = $this->user( 'editor' );

		$this->assertSame(
			201,
			$this->status(
				$editor,
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Editor bot',
					'execution_mode' => Bots::MODE_SYNC,
				]
			)
		);
		$this->assertFalse( current_user_can( \ElasticPress\Utils\get_capability() ) );
		$this->assertSame( 'edit_others_posts', get_post_type_object( Bots::POST_TYPE )->cap->edit_posts );

		remove_all_filters( 'ep_chat_bots_capability' );
		( new Bots() )->register_post_type();
	}

	/**
	 * A custom capability type moves every check to the conversation's own capabilities, and
	 * nobody has them until they are granted.
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_custom_capability_type_locks_out_until_granted() {
		$this->use_custom_capability_type();

		$this->assertSame( 'ep_chat', get_post_type_object( Storage::POST_TYPE )->capability_type );
		$this->assertSame(
			[
				Capabilities::ASK         => 'edit_ep_chats',
				Capabilities::OTHERS      => 'edit_others_ep_chats',
				Capabilities::MANAGE_BOTS => 'manage_elasticpress',
			],
			Capabilities::get_map()
		);
		$this->assertSame(
			[
				'administrator' => [ false, false, true ],
				'editor'        => [ false, false, false ],
				'author'        => [ false, false, false ],
				'contributor'   => [ false, false, false ],
				'subscriber'    => [ false, false, false ],
			],
			$this->access_by_role()
		);
		$this->assertSame(
			403,
			$this->status(
				$this->user( 'administrator' ),
				'POST',
				'/elasticpress-labs/v1/chat',
				[
					'question' => 'Hi?',
					'bot_id'   => 1,
				]
			)
		);
	}

	/**
	 * grant_defaults() mirrors post access for the custom capabilities, and does nothing while
	 * conversations use post capabilities.
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_grant_defaults_mirrors_post_access() {
		$this->assertSame( [], Capabilities::grant_defaults() );

		$this->use_custom_capability_type();
		$this->granted = true;

		$changed = Capabilities::grant_defaults();

		// Every role that can edit posts, core or not (the test site has WooCommerce's too).
		$this->assertSame( [], array_diff( [ 'administrator', 'author', 'contributor', 'editor' ], $changed ) );
		$this->assertNotContains( 'subscriber', $changed );
		$this->assertSame( [], Capabilities::grant_defaults() );
		$this->assertSame(
			[
				'administrator' => [ true, true, true ],
				'editor'        => [ true, true, false ],
				'author'        => [ true, false, false ],
				'contributor'   => [ true, false, false ],
				'subscriber'    => [ false, false, false ],
			],
			$this->access_by_role()
		);

		$author = $this->user( 'author' );
		$editor = $this->user( 'editor' );
		$mine   = $this->conversation( $author );

		$this->assertSame( 200, $this->status( $author, 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );
		$this->assertSame( 403, $this->status( $this->user( 'author' ), 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );
		$this->assertSame( 200, $this->status( $editor, 'GET', '/elasticpress-labs/v1/chat/' . $mine ) );

		wp_set_current_user( $author );
		$this->assertTrue( current_user_can( 'delete_post', $mine ) );
		wp_set_current_user( $this->user( 'author' ) );
		$this->assertFalse( current_user_can( 'delete_post', $mine ) );
		wp_set_current_user( $editor );
		$this->assertTrue( current_user_can( 'delete_post', $mine ) );
	}

	/**
	 * grant_defaults() can be limited to some roles.
	 *
	 * @group chat
	 */
	public function test_grant_defaults_to_some_roles() {
		$this->use_custom_capability_type();
		$this->granted = true;

		$this->assertSame( [ 'editor' ], Capabilities::grant_defaults( [ 'editor', 'subscriber' ] ) );
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_ep_chats' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_others_ep_chats' ) );
	}

	/**
	 * With custom capabilities granted, History lists your own conversations, or everyone's for
	 * users with `others`.
	 *
	 * @group chat
	 */
	public function test_history_list_follows_custom_capabilities() {
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$this->use_custom_capability_type();
		$this->granted = true;
		Capabilities::grant_defaults();

		$author = $this->user( 'author' );
		$other  = $this->user( 'author' );
		$mine   = $this->conversation( $author );
		$theirs = $this->conversation( $other );

		$listed = function ( int $user_id ): array {
			wp_set_current_user( $user_id );

			$_REQUEST = [];
			$_GET     = [];
			$table    = new \ElasticPressLabs\Feature\Chat\History_List_Table();
			$table->prepare_items();

			return array_map( 'intval', wp_list_pluck( $table->items, 'ID' ) );
		};

		$this->assertSame( [ $mine ], $listed( $author ) );
		$this->assertEqualsCanonicalizing( [ $mine, $theirs ], $listed( $this->user( 'editor' ) ) );
	}
}
