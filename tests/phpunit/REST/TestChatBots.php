<?php
/**
 * Test Chat Bots REST controller
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest\REST;

use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\REST\ChatBots as ChatBotsController;

/**
 * Chat Bots REST test class
 */
class TestChatBots extends \ElasticPressLabsTest\BaseTestCase {
	/**
	 * Controller instance.
	 *
	 * @var ChatBotsController
	 */
	protected $controller;

	/**
	 * Bot storage.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->bots       = new Bots();
		$this->controller = new ChatBotsController( $this->bots );
		$this->bots->register_post_type();
	}

	/**
	 * Tear down each test: drop the REST server these tests built, so later tests build their own.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Register Chat Bots routes.
	 */
	protected function register_routes() {
		$chat = Features::factory()->get_registered_feature( 'chat' );

		if ( $chat && method_exists( $chat, 'set_i18n_strings' ) ) {
			$chat->set_i18n_strings();
		}

		add_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Test guests and editors cannot manage chat bots.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_requires_capability() {
		$this->register_routes();

		$request  = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat-bots' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );

		$bot_id = $this->bots->create( 'Locked', Bots::MODE_SYNC );
		$this->assertIsInt( $bot_id );

		$editor_id = $this->ep_factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		$this->assertFalse( current_user_can( Utils\get_capability( 'chat' ) ) );

		// Editors can ask, so they can list bots, but only what Ask shows.
		$list = rest_do_request( $request );

		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( [ 'id', 'name', 'execution_mode' ], array_keys( $list->get_data()[0] ) );

		$subscriber_id = $this->ep_factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( $editor_id );
		$this->assertSame( 403, rest_do_request( $this->request( 'GET', '/elasticpress-labs/v1/chat-bots/' . $bot_id ) )->get_status() );
		$this->assertSame(
			403,
			rest_do_request(
				$this->request(
					'POST',
					'/elasticpress-labs/v1/chat-bots',
					[
						'name'           => 'Nope',
						'execution_mode' => Bots::MODE_SYNC,
					]
				)
			)->get_status()
		);
		$this->assertSame(
			403,
			rest_do_request(
				$this->request(
					'PUT',
					'/elasticpress-labs/v1/chat-bots/' . $bot_id,
					[
						'id'             => $bot_id,
						'name'           => 'Hacked',
						'execution_mode' => Bots::MODE_WP_ASYNC,
					]
				)
			)->get_status()
		);
		$this->assertSame(
			403,
			rest_do_request(
				$this->request(
					'DELETE',
					'/elasticpress-labs/v1/chat-bots/' . $bot_id,
					[ 'id' => $bot_id ]
				)
			)->get_status()
		);
		$this->assertSame(
			403,
			rest_do_request(
				$this->request(
					'PUT',
					'/elasticpress-labs/v1/chat-bots/order',
					[ 'ids' => [ $bot_id ] ]
				)
			)->get_status()
		);
		$this->assertSame( Bots::POST_TYPE, get_post_type( $bot_id ) );
	}

	/**
	 * Test manage_options is not enough to manage chat bots.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_manage_options_cannot_manage_chat_bots() {
		$this->register_routes();

		$user_id = $this->ep_factory->user->create( [ 'role' => 'subscriber' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( Utils\get_capability( 'chat' ) ) );

		$request  = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat-bots' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test manage_elasticpress is enough even without an administrator role.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_manage_elasticpress_can_manage_chat_bots() {
		$this->register_routes();

		$user_id = $this->ep_factory->user->create( [ 'role' => 'editor' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Utils\get_capability( 'chat' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( Utils\get_capability( 'chat' ) ) );

		$response = rest_do_request(
			$this->request(
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Allowed',
					'execution_mode' => Bots::MODE_SYNC,
				]
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Allowed', $response->get_data()['name'] );
	}

	/**
	 * Test CRUD through REST.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_crud_chat_bots() {
		$admin_id = $this->ep_factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$created = $this->controller->create_item(
			$this->request(
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Researcher',
					'execution_mode' => Bots::MODE_WP_ASYNC,
				]
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$data = $created->get_data();
		$this->assertSame( 'Researcher', $data['name'] );
		$this->assertSame( Bots::MODE_WP_ASYNC, $data['execution_mode'] );
		$this->assertFalse( $data['rerank_enabled'] );
		$this->assertNull( $data['rerank_floor'] );
		// A new bot runs the optional gates that are on by default.
		$this->assertSame( [ 'injection_defense', 'injection_echo', 'figure_match', 'scope', 'population_match', 'trend_guard', 'superlative', 'claim_verifier', 'refusal', 'citation_attributor' ], $data['optional_gates'] );
		$this->assertNotEmpty( $data['gates'] );

		$list = $this->controller->get_items();
		$this->assertCount( 1, $list );

		$updated = $this->controller->update_item(
			$this->request(
				'PUT',
				'/elasticpress-labs/v1/chat-bots/' . $data['id'],
				[
					'id'             => $data['id'],
					'name'           => 'Editor',
					'execution_mode' => Bots::MODE_SYNC,
				]
			)
		);

		$this->assertSame( 'Editor', $updated['name'] );
		$this->assertSame( Bots::MODE_SYNC, $updated['execution_mode'] );

		$deleted = $this->controller->delete_item(
			$this->request(
				'DELETE',
				'/elasticpress-labs/v1/chat-bots/' . $data['id'],
				[ 'id' => $data['id'] ]
			)
		);

		$this->assertSame( 200, $deleted->get_status() );
		$this->assertTrue( $deleted->get_data()['deleted'] );
		$this->assertSame( [], $this->controller->get_items() );
	}

	/**
	 * Test a new bot without a mode answers in the background, and an update without a mode
	 * keeps the bot's own.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_mode_defaults() {
		wp_set_current_user( $this->ep_factory->user->create( [ 'role' => 'administrator' ] ) );

		$created = $this->controller->create_item(
			$this->request( 'POST', '/elasticpress-labs/v1/chat-bots', [ 'name' => 'Researcher' ] )
		)->get_data();

		$this->assertSame( Bots::MODE_WP_ASYNC, $created['execution_mode'] );

		$bot_id = $this->bots->create( 'Waits', Bots::MODE_SYNC );
		$params = [
			'id'   => $bot_id,
			'name' => 'Still waits',
		];

		$updated = $this->controller->update_item( $this->request( 'PUT', '/elasticpress-labs/v1/chat-bots/' . $bot_id, $params ) );

		$this->assertSame( 'Still waits', $updated['name'] );
		$this->assertSame( Bots::MODE_SYNC, $updated['execution_mode'] );
	}

	/**
	 * Test missing names are rejected.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_requires_name() {
		$admin_id = $this->ep_factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$error = $this->controller->create_item(
			$this->request(
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[ 'name' => '   ' ]
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_bot_empty_name', $error->get_error_code() );
	}

	/**
	 * Test REST create/update persist re-ranker settings.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_persists_rerank_settings() {
		$admin_id = $this->ep_factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$created = $this->controller->create_item(
			$this->request(
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Ranker',
					'execution_mode' => Bots::MODE_SYNC,
					'rerank_enabled' => true,
					'rerank_floor'   => 0.35,
				]
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$data = $created->get_data();
		$this->assertTrue( $data['rerank_enabled'] );
		$this->assertSame( 0.35, $data['rerank_floor'] );

		$updated = $this->controller->update_item(
			$this->request(
				'PUT',
				'/elasticpress-labs/v1/chat-bots/' . $data['id'],
				[
					'id'             => $data['id'],
					'name'           => 'Ranker',
					'execution_mode' => Bots::MODE_SYNC,
					'rerank_enabled' => false,
					'rerank_floor'   => '',
				]
			)
		);

		$this->assertFalse( $updated['rerank_enabled'] );
		$this->assertNull( $updated['rerank_floor'] );
	}

	/**
	 * Test REST persist chat bot display order.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_reorders_chat_bots() {
		$admin_id = $this->ep_factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$first  = $this->controller->create_item(
			$this->request(
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'First',
					'execution_mode' => Bots::MODE_WP_ASYNC,
				]
			)
		);
		$second = $this->controller->create_item(
			$this->request(
				'POST',
				'/elasticpress-labs/v1/chat-bots',
				[
					'name'           => 'Second',
					'execution_mode' => Bots::MODE_SYNC,
				]
			)
		);

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );

		$first_id  = $first->get_data()['id'];
		$second_id = $second->get_data()['id'];

		$reordered = $this->controller->update_order(
			$this->request(
				'PUT',
				'/elasticpress-labs/v1/chat-bots/order',
				[ 'ids' => [ $second_id, $first_id ] ]
			)
		);

		$this->assertSame( [ $second_id, $first_id ], wp_list_pluck( $reordered, 'id' ) );
		$this->assertSame( [ 'Second', 'First' ], wp_list_pluck( $reordered, 'name' ) );

		$error = $this->controller->update_order(
			$this->request(
				'PUT',
				'/elasticpress-labs/v1/chat-bots/order',
				[ 'ids' => [ $first_id ] ]
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_bot_invalid_order', $error->get_error_code() );
	}

	/**
	 * Build a REST request with params.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param array  $params Params.
	 * @return \WP_REST_Request
	 */
	protected function request( string $method, string $path, array $params = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}
}
