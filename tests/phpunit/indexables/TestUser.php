<?php
/**
 * Test user indexable functionality
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress;

/**
 * Test user indexable class
 */
class TestUser extends BaseTestCase {
	/**
	 * Checking if HTTP request returns 404 status code.
	 *
	 * @var boolean
	 */
	public $is_404 = false;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		$wpdb->suppress_errors();

		\ElasticPress\register_indexable_posts();

		$instance = new \ElasticPressLabs\Feature\Users();
		ElasticPress\Features::factory()->register_feature( $instance );

		ElasticPress\Features::factory()->activate_feature( 'users' );
		ElasticPress\Features::factory()->setup_features();

		ElasticPress\Indexables::factory()->get( 'user' )->delete_index();
		ElasticPress\Indexables::factory()->get( 'user' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->sync_queue = [];

		$admin_id = $this->factory->user->create(
			[
				'role'          => 'administrator',
				'user_login'    => 'test_admin',
				'first_name'    => 'Mike',
				'last_name'     => 'Mickey',
				'display_name'  => 'mikey',
				'user_email'    => 'mikey@gmail.com',
				'user_nicename' => 'mike',
				'user_url'      => 'http://abc.com',
			]
		);

		grant_super_admin( $admin_id );

		wp_set_current_user( $admin_id );

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'users' )->search_setup();
	}

	/**
	 * Get User feature
	 *
	 * @return ElasticPress\Feature\Users
	 */
	protected function get_feature() {
		return ElasticPress\Features::factory()->get_registered_feature( 'users' );
	}

	/**
	 * Create and index users for testing
	 */
	public function createAndIndexUsers() {
		ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->add_to_queue( 1 );

		ElasticPress\Indexables::factory()->get( 'user' )->bulk_index(
			array_keys( ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->sync_queue[1] )
		);

		$user_1 = $this->ep_factory->user->create(
			[
				'user_login'   => 'user1-author',
				'role'         => 'author',
				'first_name'   => 'Dave',
				'last_name'    => 'Smith',
				'display_name' => 'dave',
				'user_email'   => 'dave@gmail.com',
				'user_url'     => 'http://bac.com',
				'meta_input'   => [
					'user_1_key' => 'value1',
					'user_num'   => 5,
					'long_key'   => 'here is a text field',
				],
			]
		);

		$user_2 = $this->ep_factory->user->create(
			[
				'user_login'   => 'user2-contributor',
				'role'         => 'contributor',
				'first_name'   => 'Zoey',
				'last_name'    => 'Johnson',
				'display_name' => 'Zoey',
				'user_email'   => 'zoey@gmail.com',
				'user_url'     => 'http://google.com',
				'meta_input'   => [
					'user_2_key' => 'value2',
				],
			]
		);

		$user_3 = $this->ep_factory->user->create(
			[
				'user_login'   => 'user3-editor',
				'role'         => 'editor',
				'first_name'   => 'Joe',
				'last_name'    => 'Doe',
				'display_name' => 'joe',
				'user_email'   => 'joe@gmail.com',
				'user_url'     => 'http://cab.com',
				'meta_input'   => [
					'user_3_key' => 'value3',
					'user_num'   => 5,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		return [ $user_1, $user_2, $user_3 ];
	}

	/**
	 * Clean up after each test. Reset our mocks
	 */
	public function tear_down() {
		parent::tear_down();

		$this->fired_actions = array();
	}

	/**
	 * Test a simple user sync
	 *
	 * @group user
	 */
	public function testUserSync() {
		add_action(
			'ep_sync_user_on_transition',
			function () {
				$this->fired_actions['ep_sync_user_on_transition'] = true;
			}
		);

		ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->sync_queue = [];

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$this->assertEquals( 1, count( ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->sync_queue ) );

		ElasticPress\Indexables::factory()->get( 'user' )->index( $user_id );

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$this->assertTrue( ! empty( $this->fired_actions['ep_sync_user_on_transition'] ) );

		$user = ElasticPress\Indexables::factory()->get( 'user' )->get( $user_id );
		$this->assertTrue( ! empty( $user ) );
	}

	/**
	 * Test a simple user sync with meta
	 *
	 * @group user
	 */
	public function testUserSyncMeta() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		update_user_meta( $user_id, 'new_meta', 'test' );

		ElasticPress\Indexables::factory()->get( 'user' )->index( $user_id );

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$user = ElasticPress\Indexables::factory()->get( 'user' )->get( $user_id );

		$this->assertEquals( 'test', $user['meta']['new_meta'][0]['value'] );
	}

	/**
	 * Test a simple user sync on meta update
	 *
	 * @group user
	 */
	public function testUserSyncOnMetaUpdate() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->sync_queue = [];

		update_user_meta( $user_id, 'test_key', true );

		$this->assertEquals( 1, count( ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->sync_queue ) );
		$this->assertTrue( ! empty( ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->add_to_queue( $user_id ) ) );
	}

	/**
	 * Test user sync kill. Note we can't actually check Elasticsearch here due to how the
	 * code is structured.
	 *
	 * @group user
	 */
	public function testUserSyncKill() {
		$created_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		add_action(
			'ep_sync_user_on_transition',
			function () {
				$this->fired_actions['ep_sync_user_on_transition'] = true;
			}
		);

		add_filter(
			'ep_user_sync_kill',
			function ( $kill, $user_id ) use ( $created_user_id ) {
				if ( $created_user_id === $user_id ) {
					return true;
				}

				return $kill;
			},
			10,
			2
		);

		ElasticPress\Indexables::factory()->get( 'user' )->sync_manager->action_sync_on_update( $created_user_id );

		$this->assertTrue( empty( $this->fired_actions['ep_sync_user_on_transition'] ) );
	}

	/**
	 * Test a basic user query with and without ElasticPress
	 *
	 * @group user
	 */
	public function testBasicUserQuery() {
		$this->createAndIndexUsers();

		// First try without ES and make sure everything is right.
		$user_query = new \WP_User_Query(
			[
				'number' => 10,
			]
		);

		$this->assertArrayNotHasKey( 'elasticsearch_success', $user_query->query_vars );
		$this->assertEquals( 5, count( $user_query->results ) );
		$this->assertEquals( 5, $user_query->total_users );

		// Now try with Elasticsearch.
		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 10,
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 5, count( $user_query->results ) );
		$this->assertEquals( 5, $user_query->total_users );
	}

	/**
	 * Test user query number parameter
	 *
	 * @group user
	 */
	public function testUserQueryNumber() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 1,
			]
		);

		$this->assertEquals( 1, count( $user_query->results ) );
		$this->assertEquals( 5, $user_query->total_users );

		$this->ep_factory->user->create_many( 15 );
		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 20, count( $user_query->results ) );
		$this->assertEquals( 20, $user_query->total_users );
	}

	/**
	 * Test user query number parameter
	 *
	 * @group user
	 */
	public function testUserQueryOffset() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 1,
			]
		);

		$first_user = $user_query->results[0];

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 1,
				'offset'       => 1,
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertNotEquals( $first_user->ID, $user_query->results[0]->ID );
	}

	/**
	 * Test user query paged parameter
	 *
	 * @group user
	 */
	public function testUserQueryPaged() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 1,
			]
		);

		$first_user = $user_query->results[0];

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 1,
				'paged'        => 2,
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertNotEquals( $first_user->ID, $user_query->results[0]->ID );
	}

	/**
	 * Test user query role paramter
	 *
	 * @group user
	 */
	public function testUserQueryRole() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'role'         => 'editor',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertTrue( in_array( 'editor', $user_query->results[0]->roles, true ) );
	}

	/**
	 * Test user query include parameter
	 *
	 * @group user
	 */
	public function testUserInclude() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'include'      => [ 1 ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 1, $user_query->results[0]->ID );
	}

	/**
	 * Test user query exclude parameter
	 *
	 * @group user
	 */
	public function testUserExclude() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'exclude'      => [ 1 ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 4, $user_query->total_users );
	}

	/**
	 * Test user query login parameter
	 *
	 * @group user
	 */
	public function testUserQueryLogin() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'login'        => 'test_admin',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'test_admin', $user_query->results[0]->user_login );
	}

	/**
	 * Test user query login__in paramter
	 *
	 * @group user
	 */
	public function testUserQueryLoginIn() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'login__in'    => [ 'test_admin' ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'test_admin', $user_query->results[0]->user_login );
	}

	/**
	 * Test user query login__not_in paramter
	 *
	 * @group user
	 */
	public function testUserQueryLoginNotIn() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate'  => true,
				'login__not_in' => [ 'test_admin' ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 4, $user_query->total_users );
	}

	/**
	 * Test user query nicename parameter
	 *
	 * @group user
	 */
	public function testUserQueryNicename() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'nicename'     => 'mike',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'mike', $user_query->results[0]->user_nicename );
	}

	/**
	 * Test user query nicename__in parameter
	 *
	 * @group user
	 */
	public function testUserQueryNicenameIn() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'nicename__in' => [ 'mike' ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'mike', $user_query->results[0]->user_nicename );
	}

	/**
	 * Test user query nicename__in parameter
	 *
	 * @group user
	 */
	public function testUserQueryNicenameNotIn() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate'     => true,
				'nicename__not_in' => [ 'mike' ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 4, $user_query->total_users );
	}

	/**
	 * Test user query role__not_in paramter
	 *
	 * @group user
	 */
	public function testUserQueryRoleNotIn() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'role__not_in' => [ 'editor' ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		foreach ( $user_query->results as $user ) {
			$this->assertFalse( in_array( 'editor', $user_query->results[0]->roles, true ) );
		}
	}

	/**
	 * Test user query role__in paramter
	 *
	 * @group user
	 */
	public function testUserQueryRoleIn() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'role__in'     => [
					'editor',
					'author',
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		foreach ( $user_query->results as $user ) {
			$this->assertTrue( ( in_array( 'editor', $user_query->results[0]->roles, true ) || in_array( 'author', $user_query->results[0]->roles, true ) ) );
		}
	}

	/**
	 * Test user query orderby paramter where we are ordering by display name
	 *
	 * @group user
	 */
	public function testUserQueryOrderbyDisplayName() {
		$users_id = $this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'display_name',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		$users_id_fetched = wp_list_pluck( $user_query->results, 'ID' );

		$this->assertCount( 5, $user_query->results );

		foreach ( $users_id as $user_id ) {
			$this->assertContains( $user_id, $users_id_fetched );
		}

		$users_display_name_fetched = wp_list_pluck( $user_query->results, 'display_name' );

		$this->assertEquals( 'admin', $users_display_name_fetched[0] );
		$this->assertEquals( 'Zoey', $users_display_name_fetched[4] );
	}

	/**
	 * Test order by display_name in format_args().
	 *
	 * We should not use a text/string field to sort
	 * in Elasticsearch.
	 *
	 * @group user
	 */
	public function testFormatArgsOrderByDisplayName() {
		$user = new \ElasticPressLabs\Indexable\User\User();

		$user_query = new \WP_User_Query();

		$args = $user->format_args(
			[
				'orderby' => 'display_name',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'display_name.sortable', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'display_name', $args['sort'][0] );

		$args = $user->format_args(
			[
				'orderby' => 'name',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'display_name.sortable', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'display_name', $args['sort'][0] );
	}

	/**
	 * Test user query orderby paramter where we are ordering by user_nicename
	 *
	 * @group user
	 */
	public function testUserQueryOrderbyUserNicename() {
		$users_id = $this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'user_nicename',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		$users_id_fetched = wp_list_pluck( $user_query->results, 'ID' );

		$this->assertCount( 5, $user_query->results );

		foreach ( $users_id as $user_id ) {
			$this->assertContains( $user_id, $users_id_fetched );
		}

		$users_display_name_fetched = wp_list_pluck( $user_query->results, 'display_name' );

		// Check if 'admin' is the first user
		$this->assertEquals( 'admin', $users_display_name_fetched[0] );
	}

	/**
	 * Test order by user_nicename in format_args().
	 *
	 * We should not use a text/string field to sort
	 * in Elasticsearch.
	 *
	 * @return void  * @group user
	 */
	public function testFormatArgsOrderByUserNicename() {
		$user = new \ElasticPressLabs\Indexable\User\User();

		$user_query = new \WP_User_Query();

		$args = $user->format_args(
			[
				'orderby' => 'user_nicename',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'user_nicename.raw', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'user_nicename', $args['sort'][0] );

		$args = $user->format_args(
			[
				'orderby' => 'nicename',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'user_nicename.raw', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'user_nicename', $args['sort'][0] );
	}

	/**
	 * Test user query orderby parameter where we are ordering by user_email
	 *
	 * @group user
	 */
	public function testUserQueryOrderbyUserEmail() {
		$users_id = $this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'user_email',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		$users_id_fetched = wp_list_pluck( $user_query->results, 'ID' );

		$this->assertCount( 5, $user_query->results );

		foreach ( $users_id as $user_id ) {
			$this->assertContains( $user_id, $users_id_fetched );
		}

		$users_display_name_fetched = wp_list_pluck( $user_query->results, 'display_name' );

		// Check if 'admin' is the first user
		$this->assertEquals( 'admin', $users_display_name_fetched[0] );
	}

	/**
	 * Test order by user_email in format_args().
	 *
	 * We should not use a text/string field to sort
	 * in Elasticsearch.
	 *
	 * @return void
	 * @group user
	 */
	public function testFormatArgsOrderByUserEmail() {
		$user = new \ElasticPressLabs\Indexable\User\User();

		$user_query = new \WP_User_Query();

		$args = $user->format_args(
			[
				'orderby' => 'user_email',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'user_email.raw', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'user_email', $args['sort'][0] );

		$args = $user->format_args(
			[
				'orderby' => 'user_email',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'user_email.raw', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'user_email', $args['sort'][0] );
	}

	/**
	 * Test user query orderby parameter where we are ordering by user_url
	 *
	 * @group user
	 */
	public function testUserQueryOrderbyUserUrl() {
		$users_id = $this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'user_url',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		$users_id_fetched = wp_list_pluck( $user_query->results, 'ID' );

		$this->assertCount( 5, $user_query->results );

		foreach ( $users_id as $user_id ) {
			$this->assertContains( $user_id, $users_id_fetched );
		}

		$users_display_name_fetched = wp_list_pluck( $user_query->results, 'display_name' );

		$this->assertEquals( 'mikey', $users_display_name_fetched[0] );
	}

	/**
	 * Test order by user_url in format_args().
	 *
	 * We should not use a text/string field to sort
	 * in Elasticsearch.
	 *
	 * @return void
	 * @group user
	 */
	public function testFormatArgsOrderByUserUrl() {
		$user = new \ElasticPressLabs\Indexable\User\User();

		$user_query = new \WP_User_Query();

		$args = $user->format_args(
			[
				'orderby' => 'user_url',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'user_url.raw', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'user_url', $args['sort'][0] );

		$args = $user->format_args(
			[
				'orderby' => 'user_url',
			],
			$user_query
		);

		$this->assertArrayHasKey( 'user_url.raw', $args['sort'][0] );
		$this->assertArrayNotHasKey( 'user_url', $args['sort'][0] );
	}

	/**
	 * Test user query orderby paramter where we are ordering by ID
	 *
	 * @group user
	 */
	public function testUserQueryOrderbyID() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'ID',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		foreach ( $user_query->results as $key => $user ) {
			if ( ! empty( $user_query->results[ $key - 1 ] ) ) {
				$this->assertTrue( $user_query->results[ $key - 1 ]->ID < $user->ID );
			}
		}
	}

	/**
	 * Test user query orderby paramter where we are ordering by email
	 *
	 * @group user
	 */
	public function testUserQueryOrderbyEmail() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'email',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		foreach ( $user_query->results as $key => $user ) {
			if ( ! empty( $user_query->results[ $key - 1 ] ) ) {
				$this->assertTrue( strcasecmp( $user_query->results[ $key - 1 ]->user_email, $user->user_email ) < 0 );
			}
		}
	}

	/**
	 * Test user query order parameter where we are ordering by ID descending
	 *
	 * @group user
	 */
	public function testUserQueryOrderDesc() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => 'ID',
				'order'        => 'desc',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		foreach ( $user_query->results as $key => $user ) {
			if ( ! empty( $user_query->results[ $key - 1 ] ) ) {
				$this->assertTrue( $user_query->results[ $key - 1 ]->ID > $user->ID );
			}
		}
	}

	/**
	 * Test meta query with simple args
	 */
	public function testUserMetaQuerySimple() {
		$this->createAndIndexUsers();

		// Value does not exist so should return nothing
		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_key'     => 'user_1_key',
				'meta_value'   => 'value5',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 0, $user_query->total_users );

		// This value exists
		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_key'     => 'user_1_key',
				'meta_value'   => 'value1',
			]
		);

		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'value1', get_user_meta( $user_query->results[0]->ID, 'user_1_key', true ) );
	}

	/**
	 * Test meta query with simple args and meta_compare does not equal
	 */
	public function testUserMetaQuerySimpleCompare() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_key'     => 'user_1_key',
				'meta_value'   => 'value1',
				'meta_compare' => '!=',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 4, $user_query->total_users );
	}

	/**
	 * Test meta query with no compare
	 */
	public function testUserMetaQueryNoCompare() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_query'   => [
					[
						'key'   => 'user_1_key',
						'value' => 'value1',
					],
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'value1', get_user_meta( $user_query->results[0]->ID, 'user_1_key', true ) );
	}

	/**
	 * Test meta query compare equals
	 */
	public function testUserMetaQueryCompareEquals() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_query'   => [
					[
						'key'     => 'user_2_key',
						'value'   => 'value2',
						'compare' => '=',
					],
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'value2', get_user_meta( $user_query->results[0]->ID, 'user_2_key', true ) );
	}

	/**
	 * Test meta query with multiple statements
	 */
	public function testUserMetaQueryMulti() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_query'   => [
					[
						'key'   => 'user_num',
						'value' => 5,
					],
					[
						'key'   => 'user_1_key',
						'value' => 'value1',
					],
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'value1', get_user_meta( $user_query->results[0]->ID, 'user_1_key', true ) );
	}

	/**
	 * Test meta query with multiple statements and relation OR
	 */
	public function testUserMetaQueryMultiRelationOr() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_query'   => [
					[
						'key'   => 'user_num',
						'value' => 5,
					],
					[
						'key'   => 'user_1_key',
						'value' => 'value1',
					],
					'relation' => 'or',
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 2, $user_query->total_users );
	}

	/**
	 * Test meta query with multiple statements and relation AND
	 */
	public function testUserMetaQueryMultiRelationAnd() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'meta_query'   => [
					[
						'key'   => 'user_num',
						'value' => 5,
					],
					[
						'key'   => 'user_1_key',
						'value' => 'value1',
					],
					'relation' => 'and',
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
	}

	/**
	 * Test basic user search
	 */
	public function testBasicUserSearch() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'search' => 'joe',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'user3-editor', $user_query->results[0]->user_login );
	}

	/**
	 * Test basic user search via user login
	 */
	public function testBasicUserSearchUserLogin() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'search' => 'joe',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'user3-editor', $user_query->results[0]->user_login );
	}

	/**
	 * Test basic user search via user url
	 */
	public function testBasicUserSearchUserUrl() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'search'        => 'http://google.com',
				'search_fields' => [
					'user_url.raw',
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'user2-contributor', $user_query->results[0]->user_login );
	}

	/**
	 * Test basic user search via meta
	 */
	public function testBasicUserSearchMeta() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'search'        => 'test field',
				'search_fields' => [
					'meta.long_key.value',
				],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'user1-author', $user_query->results[0]->user_login );
	}

	/**
	 * Tests a single field in the fields parameters for user queries.
	 */
	public function testSingleUserFieldQuery() {
		$this->createAndIndexUsers();

		// First, get the IDs of the users.
		$user_query = new \WP_User_Query(
			[
				'number' => 5,
				'fields' => 'ID',
			]
		);

		$this->assertEquals( 5, count( $user_query->results ) );

		// This returns an array of strings, while EP returns ints.
		$user_ids = array_map( 'absint', $user_query->results );

		// Run the same query against EP to verify we're only getting
		// user IDs.
		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => 5,
				'fields'       => 'ID',
			]
		);

		$ep_user_ids = array_map( 'absint', $user_query->results );

		$this->assertSame( $user_ids, $ep_user_ids );
	}

	/**
	 * Tests multiple fields in the fields parameters for user queries.
	 */
	public function testMultipleUserFieldsQuery() {
		$this->createAndIndexUsers();

		$count = 5;

		// First, get the IDs of the users.
		$user_query = new \WP_User_Query(
			[
				'number' => $count,
				'fields' => [ 'ID', 'display_name' ],
			]
		);

		$users = $user_query->results;

		$this->assertEquals( $count, count( $users ) );

		// Run the same query against EP to verify we're getting classes
		// with properties.
		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'number'       => $count,
				'fields'       => [ 'ID', 'display_name' ],
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );

		$ep_users = $user_query->results;

		$this->assertEquals( 5, count( $users ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( absint( $users[ $i ]->ID ), absint( $ep_users[ $i ]->ID ) );
			$this->assertSame( $users[ $i ]->display_name, $ep_users[ $i ]->display_name );
		}
	}

	/**
	 * Test integration with User Queries.
	 */
	public function testIntegrateSearchQueries() {
		$this->assertTrue( $this->get_feature()->integrate_search_queries( true, null ) );
		$this->assertFalse( $this->get_feature()->integrate_search_queries( false, null ) );

		$query = new \WP_User_Query(
			[
				'ep_integrate' => false,
			]
		);

		$this->assertFalse( $this->get_feature()->integrate_search_queries( true, $query ) );

		$query = new \WP_User_Query(
			[
				'ep_integrate' => 0,
			]
		);

		$this->assertFalse( $this->get_feature()->integrate_search_queries( true, $query ) );

		$query = new \WP_User_Query(
			[
				'ep_integrate' => 'false',
			]
		);

		$this->assertFalse( $this->get_feature()->integrate_search_queries( true, $query ) );

		$query = new \WP_User_Query(
			[
				'search' => 'user',
			]
		);

		$this->assertTrue( $this->get_feature()->integrate_search_queries( false, $query ) );
	}

	/**
	 * Test users that does not belong to any blog.
	 */
	public function testUserSearchLimitedToOneBlog() {
		// This user does not belong to any blog.
		$this->ep_factory->user->create(
			[
				'user_login' => 'users-and-blogs-1',
				'role'       => '',
				'first_name' => 'No Blog',
				'last_name'  => 'User',
				'user_email' => 'no-blog@test.com',
				'user_url'   => 'http://domain.test',
			]
		);
		$this->ep_factory->user->create(
			[
				'user_login' => 'users-and-blogs-2',
				'role'       => 'contributor',
				'first_name' => 'Blog',
				'last_name'  => 'User',
				'user_email' => 'blog@test.com',
				'user_url'   => 'http://domain.test',
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		// Here `blog_id` defaults to `get_current_blog_id()`.
		$query = new \WP_User_Query(
			[
				'search' => 'users-and-blogs',
			]
		);

		$this->assertTrue( $this->get_feature()->integrate_search_queries( false, $query ) );
		$this->assertEquals( 1, $query->total_users );
		$this->assertTrue( $query->query_vars['elasticsearch_success'] );

		// Search accross all blogs.
		$query = new \WP_User_Query(
			[
				'search'  => 'users-and-blogs',
				'blog_id' => 0,
			]
		);

		$this->assertTrue( $this->get_feature()->integrate_search_queries( false, $query ) );
		$this->assertEquals( 2, $query->total_users );
		$this->assertTrue( $query->query_vars['elasticsearch_success'] );
	}

	/**
	 * Test user query search by user login.
	 */
	public function testUserQueryUserLogin() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'search'         => 'contributor',
				'search_columns' => [ 'user_login' ],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'user2-contributor', $user_query->results[0]->user_login );
		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
	}

	/**
	 * Test user query search by user nicename.
	 */
	public function testUserQueryUserNiceName() {
		$this->createAndIndexUsers();

		$user_query = new \WP_User_Query(
			[
				'search'         => 'mike',
				'search_columns' => [ 'user_nicename' ],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$this->assertEquals( 1, $user_query->total_users );
		$this->assertEquals( 'test_admin', $user_query->results[0]->user_login );
		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
	}

	/**
	 * Test user query default orderby set to asc.
	 */
	public function testUserQueryDefaultOrderBy() {
		$this->createAndIndexUsers();

		$expected_user_order = [
			'admin',
			'test_admin',
			'user1-author',
			'user2-contributor',
			'user3-editor',
		];

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$user_query = new \WP_User_Query(
			[
				'ep_integrate' => true,
				'orderby'      => '',
			]
		);

		$user_order = array();
		foreach ( $user_query->results as $user ) {
			$user_order[] = $user->user_login;
		}

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
		$this->assertEquals( $expected_user_order, $user_order );
	}

	/**
	 * Test default order set to the score when orderby is set to empty
	 */
	public function testUserQueryDefaultOrder() {
		$this->createAndIndexUsers();

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_action(
			'pre_http_request',
			function ( $preempt, $parsed_args ) {
				$body = json_decode( $parsed_args['body'], true );

				$this->assertNotEmpty( $body['sort'][0]['_score'] );

				return $preempt;
			},
			10,
			2
		);

		$user_query = new \WP_User_Query(
			[
				'orderby' => '',
				'search'  => 'user',
			]
		);

		$this->assertTrue( $user_query->query_vars['elasticsearch_success'] );
	}

	/**
	 * Compare the filtered users and total with WordPress, requiring an ES response.
	 *
	 * @param array    $args Query arguments.
	 * @param int[]    $expected_ids Expected user IDs in ascending order.
	 * @param int|null $expected_total Expected total before pagination.
	 */
	protected function assertPublishedPostsQuery( $args, $expected_ids, $expected_total = null ) {
		$args          += [
			'fields'        => 'ID',
			'orderby'       => 'ID',
			'order'         => 'ASC',
			'cache_results' => false,
		];
		$wp_query       = new \WP_User_Query( array_merge( $args, [ 'ep_integrate' => false ] ) );
		$es_query       = new \WP_User_Query( array_merge( $args, [ 'ep_integrate' => true ] ) );
		$expected_total = null === $expected_total ? count( $expected_ids ) : $expected_total;
		$this->assertSame( $expected_ids, array_map( 'intval', $wp_query->get_results() ) );
		$this->assertSame( $expected_ids, array_map( 'intval', $es_query->get_results() ) );
		$this->assertSame( $expected_total, (int) $wp_query->get_total() );
		$this->assertSame( $expected_total, (int) $es_query->get_total() );
		$this->assertTrue( $es_query->get( 'elasticsearch_success' ) );
	}

	/**
	 * Match native argument semantics, including private types and empty restrictions.
	 */
	public function testHasPublishedPostsQuery() {
		register_post_type( 'ep_private_test', [ 'public' => false ] );
		try {
			$users = [];
			foreach ( [ 'both', 'post', 'page', 'hidden', 'draft', 'none' ] as $name ) {
				$users[ $name ] = $this->factory->user->create( [ 'role' => 'author' ] );
			}
			foreach ( [ 'post', 'post', 'page' ] as $type ) {
				$this->factory->post->create(
					[
						'post_author' => $users['both'],
						'post_status' => 'publish',
						'post_type'   => $type,
					]
				);
			}
			$this->factory->post->create(
				[
					'post_author'   => $users['post'],
					'post_status'   => 'publish',
					'post_password' => 'secret',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $users['page'],
					'post_status' => 'publish',
					'post_type'   => 'page',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $users['hidden'],
					'post_status' => 'publish',
					'post_type'   => 'ep_private_test',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $users['draft'],
					'post_status' => 'draft',
				]
			);
			$indexable = ElasticPress\Indexables::factory()->get( 'user' );
			foreach ( $users as $user_id ) {
				$this->assertNotFalse( $indexable->index( $user_id, true ) );
			}
			ElasticPress\Elasticsearch::factory()->refresh_indices();
			$cases = [
				[ true, [ $users['both'], $users['post'], $users['page'] ] ],
				[ [ 'post' ], [ $users['both'], $users['post'] ] ],
				[ [ 'page' ], [ $users['both'], $users['page'] ] ],
				[ [ 'post', 'page' ], [ $users['both'], $users['post'], $users['page'] ] ],
				[ [ 'named' => 'post' ], [ $users['both'], $users['post'] ] ],
				[ [ 'post', 'post' ], [ $users['both'], $users['post'] ] ],
				[ [ 'POST' ], [ $users['both'], $users['post'] ] ],
				[ 'PoSt', [ $users['both'], $users['post'] ] ],
				[ [ 'po.st' ], [] ],
				[ [ 'ep_private_test' ], [ $users['hidden'] ] ],
				[ [ 'ep_missing_type' ], [] ],
				[ [ 'post', 'ep_missing_type' ], [ $users['both'], $users['post'] ] ],
				[ 'post', [ $users['both'], $users['post'] ] ],
				[ 1, [] ],
			];
			foreach ( [ false, null, [], '', 0, '0' ] as $empty ) {
				$cases[] = [ $empty, array_values( $users ) ];
			}
			foreach ( $cases as [ $restriction, $expected ] ) {
				$this->assertPublishedPostsQuery(
					[
						'include'             => array_values( $users ),
						'has_published_posts' => $restriction,
					],
					$expected
				);
			}
			$this->assertPublishedPostsQuery(
				[
					'include'             => array_values( $users ),
					'has_published_posts' => [ 'post' ],
					'blog_id'             => 0,
				],
				array_values( $users )
			);
		} finally {
			unregister_post_type( 'ep_private_test' );
		}
	}

	/**
	 * Published-post filters compose with roles, search, inclusion and pagination.
	 */
	public function testHasPublishedPostsCombinedFilters() {
		$users = [];
		foreach ( [ 'author', 'subscriber', 'author' ] as $offset => $role ) {
			$user_id = $this->factory->user->create(
				[
					'user_login' => [ 'ep116alpha', 'ep116bravo', 'ep116charlie' ][ $offset ],
					'role'       => $role,
				]
			);
			$users[] = $user_id;
			$this->factory->post->create(
				[
					'post_author' => $user_id,
					'post_status' => 'publish',
				]
			);
			$this->assertNotFalse( ElasticPress\Indexables::factory()->get( 'user' )->index( $user_id, true ) );
		}
		ElasticPress\Elasticsearch::factory()->refresh_indices();
		$args = [ 'has_published_posts' => [ 'post' ] ];
		$this->assertPublishedPostsQuery( $args + [ 'role' => 'author' ], [ $users[0], $users[2] ] );
		$this->assertPublishedPostsQuery( $args + [ 'role__in' => [ 'subscriber' ] ], [ $users[1] ] );
		$this->assertPublishedPostsQuery( $args + [ 'role__not_in' => [ 'subscriber' ] ], [ $users[0], $users[2] ] );
		$this->assertPublishedPostsQuery(
			$args + [
				'search'         => 'ep116alpha',
				'search_columns' => [ 'user_login' ],
			],
			[ $users[0] ]
		);
		$this->assertPublishedPostsQuery( $args + [ 'include' => [ $users[1] ] ], [ $users[1] ] );
		$this->assertPublishedPostsQuery( $args + [ 'exclude' => [ $users[1] ] ], [ $users[0], $users[2] ] );
		$this->assertPublishedPostsQuery(
			$args + [
				'number' => 1,
				'paged'  => 2,
			],
			[ $users[1] ],
			3
		);
		$this->assertPublishedPostsQuery(
			$args + [
				'number' => 1,
				'offset' => 2,
			],
			[ $users[2] ],
			3
		);
		$this->assertPublishedPostsQuery(
			$args + [
				'number' => 1,
				'paged'  => 4,
			],
			[],
			3
		);
	}

	/**
	 * Site and post type must match together, independently of current site context.
	 *
	 * @group multisite
	 */
	public function testHasPublishedPostsAcrossSites() {
		if ( ! is_multisite() || ! defined( 'EP_IS_NETWORK' ) || ! EP_IS_NETWORK ) {
			$this->markTestSkipped( 'Cross-site capabilities require network activation.' );
		}
		$home_id = get_current_blog_id();
		$site_id = $this->factory->blog->create();
		$users   = $this->factory->user->create_many( 3, [ 'role' => 'author' ] );
		$this->factory->post->create(
			[
				'post_author' => $users[0],
				'post_status' => 'publish',
			]
		);
		$this->factory->post->create(
			[
				'post_author' => $users[1],
				'post_status' => 'publish',
				'post_type'   => 'page',
			]
		);
		switch_to_blog( $site_id );
		try {
			add_user_to_blog( $site_id, $users[0], 'author' );
			add_user_to_blog( $site_id, $users[1], 'author' );
			$this->factory->post->create(
				[
					'post_author' => $users[0],
					'post_status' => 'publish',
					'post_type'   => 'page',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $users[1],
					'post_status' => 'publish',
				]
			);
			// An author who is not a member must not match this site's user query.
			$this->factory->post->create(
				[
					'post_author' => $users[2],
					'post_status' => 'publish',
				]
			);
		} finally {
			restore_current_blog();
		}
		foreach ( $users as $user_id ) {
			$this->assertNotFalse( ElasticPress\Indexables::factory()->get( 'user' )->index( $user_id, true ) );
		}
		ElasticPress\Elasticsearch::factory()->refresh_indices();
		$args = [
			'include'             => $users,
			'has_published_posts' => [ 'post' ],
		];
		$this->assertPublishedPostsQuery( $args, [ $users[0] ] );
		$this->assertPublishedPostsQuery( $args + [ 'blog_id' => $site_id ], [ $users[1] ] );
		$this->assertPublishedPostsQuery( $args + [ 'blog_id' => 0 ], $users );
		switch_to_blog( $site_id );
		try {
			$this->assertPublishedPostsQuery( $args, [ $users[1] ] );
			$this->assertPublishedPostsQuery( $args + [ 'blog_id' => $home_id ], [ $users[0] ] );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Large filtered counts must not be truncated at Elasticsearch's 10,000-hit limit.
	 */
	public function testHasPublishedPostsLargeTotal() {
		$indexable = ElasticPress\Indexables::factory()->get( 'user' );
		$client    = ElasticPress\Elasticsearch::factory();
		if ( version_compare( $client->get_elasticsearch_version(), '7.0', '<' ) ) {
			$this->markTestSkipped( 'Elasticsearch 7 introduced the default hit-count limit.' );
		}
		// Synthetic user documents avoid creating 10,001 WordPress users for a count test.
		$body = '';
		for ( $offset = 1; $offset <= 10001; ++$offset ) {
			$id    = 1000000 + $offset;
			$body .= wp_json_encode( [ 'index' => [ '_id' => $id ] ] ) . "\n";
			$body .= wp_json_encode(
				[
					'ID'                   => $id,
					'capabilities'         => [ get_current_blog_id() => [ 'roles' => [ 'author' ] ] ],
					'published_post_types' => [ get_current_blog_id() . ':post' ],
				]
			) . "\n";
		}
		$result = $client->bulk_index( $indexable->get_index_name(), 'user', $body );
		$this->assertNotWPError( $result );
		$this->assertFalse( $result['errors'] );
		$client->refresh_indices();
		foreach ( [ get_current_blog_id(), 0 ] as $blog_id ) {
			$query = new \WP_User_Query(
				[
					'ep_integrate'        => true,
					'blog_id'             => $blog_id,
					'has_published_posts' => [ 'post' ],
					'fields'              => 'ID',
					'number'              => 1,
					'orderby'             => 'ID',
					'order'               => 'ASC',
				]
			);
			$this->assertTrue( $query->get( 'elasticsearch_success' ) );
			$this->assertSame( 10001, (int) $query->get_total() );
			$this->assertSame( [ 1000001 ], array_map( 'intval', $query->get_results() ) );
		}
	}

	/**
	 * Flush the real user sync queue and verify which authors were scheduled.
	 *
	 * @param int[] $expected Expected author IDs.
	 */
	protected function syncPublishedAuthors( $expected ) {
		$manager = ElasticPress\Indexables::factory()->get( 'user' )->sync_manager;
		$manager->queue_deferred_authors();
		$queued = [];

		foreach ( $manager->sync_queue as $queue ) {
			$queued = array_merge( $queued, array_keys( $queue ) );
		}

		$queued = array_values( array_unique( $queued ) );
		sort( $queued );
		sort( $expected );
		$this->assertSame( $expected, $queued );
		$manager->index_sync_queue();
		ElasticPress\Elasticsearch::factory()->refresh_indices();
	}

	/**
	 * Publish, unpublish, trash, restore and delete through WordPress's normal hooks.
	 */
	public function testPublishedAuthorsLifecycle() {
		$user_id             = $this->ep_factory->user->create( [ 'role' => 'author' ] );
		$manager             = ElasticPress\Indexables::factory()->get( 'user' )->sync_manager;
		$manager->sync_queue = [];
		$post_id             = $this->factory->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'draft',
			]
		);
		$args                = [
			'has_published_posts' => [ 'post' ],
			'include'             => [ $user_id ],
		];
		$this->syncPublishedAuthors( [] );
		$this->assertPublishedPostsQuery( $args, [] );

		foreach ( [ 'publish', 'draft', 'publish', 'private', 'publish' ] as $status ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => $status,
				]
			);
			$this->syncPublishedAuthors( [ $user_id ] );
			$this->assertPublishedPostsQuery( $args, 'publish' === $status ? [ $user_id ] : [] );
		}

		wp_trash_post( $post_id );
		$this->syncPublishedAuthors( [ $user_id ] );
		$this->assertPublishedPostsQuery( $args, [] );
		// WordPress restores to draft by default.
		wp_untrash_post( $post_id );
		$this->syncPublishedAuthors( [] );
		$this->assertPublishedPostsQuery( $args, [] );
		wp_publish_post( $post_id );
		$this->syncPublishedAuthors( [ $user_id ] );
		wp_trash_post( $post_id );
		$this->syncPublishedAuthors( [ $user_id ] );
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		try {
			wp_untrash_post( $post_id );
		} finally {
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
		}
		$this->syncPublishedAuthors( [ $user_id ] );
		$this->assertPublishedPostsQuery( $args, [ $user_id ] );
		wp_delete_post( $post_id, true );
		$this->syncPublishedAuthors( [ $user_id ] );
		$this->assertPublishedPostsQuery( $args, [] );
	}

	/**
	 * Preserve remaining posts and refresh both authors, including author zero and private types.
	 */
	public function testPublishedAuthorsReassignmentAndTypes() {
		$users               = [
			$this->ep_factory->user->create( [ 'role' => 'author' ] ),
			$this->ep_factory->user->create( [ 'role' => 'author' ] ),
		];
		$manager             = ElasticPress\Indexables::factory()->get( 'user' )->sync_manager;
		$manager->sync_queue = [];
		register_post_type( 'ep_private_test', [ 'public' => false ] );
		try {
			$first  = $this->factory->post->create(
				[
					'post_author' => $users[0],
					'post_status' => 'publish',
				]
			);
			$second = $this->factory->post->create(
				[
					'post_author'   => $users[0],
					'post_status'   => 'publish',
					'post_password' => 'secret',
				]
			);
			$args   = [
				'has_published_posts' => [ 'post' ],
				'include'             => $users,
			];
			$this->syncPublishedAuthors( [ $users[0] ] );
			$this->assertPublishedPostsQuery( $args, [ $users[0] ] );
			wp_delete_post( $first, true );
			$this->syncPublishedAuthors( [ $users[0] ] );
			$this->assertPublishedPostsQuery( $args, [ $users[0] ] );
			$first = $this->factory->post->create(
				[
					'post_author' => $users[0],
					'post_status' => 'publish',
				]
			);
			$this->syncPublishedAuthors( [ $users[0] ] );
			wp_update_post(
				[
					'ID'          => $first,
					'post_author' => $users[1],
				]
			);
			$this->syncPublishedAuthors( $users );
			$this->assertPublishedPostsQuery( $args, $users );
			wp_update_post(
				[
					'ID'          => $first,
					'post_author' => 0,
				]
			);
			$this->syncPublishedAuthors( [ $users[1] ] );
			$this->assertPublishedPostsQuery( $args, [ $users[0] ] );
			wp_update_post(
				[
					'ID'          => $first,
					'post_author' => $users[1],
				]
			);
			$this->syncPublishedAuthors( [ $users[1] ] );
			$this->assertPublishedPostsQuery( $args, $users );
			wp_update_post(
				[
					'ID'        => $second,
					'post_type' => 'page',
				]
			);
			$this->syncPublishedAuthors( [ $users[0] ] );
			$this->assertPublishedPostsQuery( $args, [ $users[1] ] );
			$this->assertPublishedPostsQuery( array_merge( $args, [ 'has_published_posts' => [ 'page' ] ] ), [ $users[0] ] );
			wp_update_post(
				[
					'ID'        => $first,
					'post_type' => 'ep_private_test',
				]
			);
			$this->syncPublishedAuthors( [ $users[1] ] );
			$this->assertPublishedPostsQuery( $args, [] );
			$this->assertPublishedPostsQuery( array_merge( $args, [ 'has_published_posts' => [ 'ep_private_test' ] ] ), [ $users[1] ] );
			$this->assertPublishedPostsQuery( array_merge( $args, [ 'has_published_posts' => true ] ), [ $users[0] ] );
		} finally {
			unregister_post_type( 'ep_private_test' );
		}
	}

	/**
	 * Ignore ordinary edits and revisions; recompute the final state once per queued author.
	 */
	public function testPublishedAuthorsQueueAndScheduledPublication() {
		$indexable           = ElasticPress\Indexables::factory()->get( 'user' );
		$manager             = $indexable->sync_manager;
		$users               = [
			$this->ep_factory->user->create( [ 'role' => 'author' ] ),
			$this->ep_factory->user->create( [ 'role' => 'author' ] ),
		];
		$manager->sync_queue = [];
		$post_id             = $this->factory->post->create(
			[
				'post_author' => $users[0],
				'post_status' => 'publish',
			]
		);
		$this->syncPublishedAuthors( [ $users[0] ] );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => 'A changed title',
				'post_content' => 'A changed body',
			]
		);
		$this->factory->post->create(
			[
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
				'post_author' => $users[1],
			]
		);
		$draft = $this->factory->post->create(
			[
				'post_author' => $users[0],
				'post_status' => 'draft',
			]
		);
		wp_update_post(
			[
				'ID'          => $draft,
				'post_author' => $users[1],
				'post_type'   => 'page',
			]
		);
		$this->syncPublishedAuthors( [] );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_author' => $users[1],
			]
		);
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_author' => $users[0],
			]
		);
		$this->assertCount( 2, $manager->get_sync_queue() );
		$this->syncPublishedAuthors( $users );
		$args = [
			'has_published_posts' => [ 'post' ],
			'include'             => $users,
		];
		$this->assertPublishedPostsQuery( $args, [ $users[0] ] );
		$future = $this->factory->post->create(
			[
				'post_author' => $users[1],
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$this->syncPublishedAuthors( [] );
		$current_user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			wp_publish_post( $future ); // The same publication path used by WordPress cron.
			$this->syncPublishedAuthors( [ $users[1] ] );
		} finally {
			wp_set_current_user( $current_user );
		}
		$this->assertPublishedPostsQuery( $args, $users );
	}

	/**
	 * Deletion must remain correct even when adding to the queue immediately flushes it.
	 */
	public function testPublishedAuthorsImmediateFlushAndUserDeletion() {
		global $coauthors_plus;

		$indexable = ElasticPress\Indexables::factory()->get( 'user' );
		$manager   = $indexable->sync_manager;
		$users     = [
			$this->ep_factory->user->create( [ 'role' => 'author' ] ),
			$this->ep_factory->user->create( [ 'role' => 'author' ] ),
		];
		// The bundled Co-Authors Plus deletion hook expects its author term to exist.
		$coauthors_plus->update_author_term( get_userdata( $users[0] ) );
		$manager->sync_queue = [];
		$post_id             = $this->factory->post->create(
			[
				'post_author' => $users[0],
				'post_status' => 'publish',
			]
		);
		$this->syncPublishedAuthors( [ $users[0] ] );
		add_action( 'ep_after_add_to_queue', [ $manager, 'index_sync_queue' ] );
		try {
			wp_delete_post( $post_id, true );
			ElasticPress\Elasticsearch::factory()->refresh_indices();
			$this->assertPublishedPostsQuery(
				[
					'has_published_posts' => true,
					'include'             => $users,
				],
				[]
			);
			$this->factory->post->create(
				[
					'post_author' => $users[0],
					'post_status' => 'publish',
				]
			);
			$this->assertTrue( wp_delete_user( $users[0], $users[1] ) );
			$manager->queue_deferred_authors();
			$manager->index_sync_queue();
		} finally {
			remove_action( 'ep_after_add_to_queue', [ $manager, 'index_sync_queue' ] );
		}
		ElasticPress\Elasticsearch::factory()->refresh_indices();
		$this->assertPublishedPostsQuery(
			[
				'has_published_posts' => true,
				'include'             => $users,
			],
			[ $users[1] ]
		);

		if ( ! is_multisite() ) {
			$this->assertEmpty( $indexable->get( $users[0] ) );
		}
	}

	/**
	 * Post-driven refreshes honor both sync kill filters.
	 */
	public function testPublishedAuthorsSyncKill() {
		$user_id             = $this->ep_factory->user->create( [ 'role' => 'author' ] );
		$manager             = ElasticPress\Indexables::factory()->get( 'user' )->sync_manager;
		$manager->sync_queue = [];

		foreach ( [ 'ep_sync_indexable_kill', 'ep_user_sync_kill' ] as $filter ) {
			add_filter( $filter, '__return_true' );
			try {
				$this->factory->post->create(
					[
						'post_author' => $user_id,
						'post_status' => 'publish',
					]
				);
			} finally {
				remove_filter( $filter, '__return_true' );
			}
			$this->assertEmpty( $manager->get_sync_queue() );
		}
	}

	/**
	 * Membership and bulk reassignment refresh both users after the SQL update.
	 *
	 * @group multisite
	 */
	public function testPublishedAuthorsMultisiteMembership() {
		if ( ! is_multisite() || ! defined( 'EP_IS_NETWORK' ) || ! EP_IS_NETWORK ) {
			$this->markTestSkipped( 'Requires network activation.' );
		}

		$indexable           = ElasticPress\Indexables::factory()->get( 'user' );
		$manager             = $indexable->sync_manager;
		$users               = $this->factory->user->create_many( 3, [ 'role' => 'author' ] );
		$home_id             = get_current_blog_id();
		$site_id             = $this->factory->blog->create();
		$manager->sync_queue = [];
		$this->factory->post->create(
			[
				'post_author' => $users[0],
				'post_status' => 'publish',
			]
		);
		switch_to_blog( $site_id );
		try {
			add_user_to_blog( $site_id, $users[0], 'author' );
			add_user_to_blog( $site_id, $users[1], 'author' );
			$this->factory->post->create(
				[
					'post_author' => $users[0],
					'post_status' => 'publish',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $users[2],
					'post_status' => 'publish',
				]
			);
		} finally {
			restore_current_blog();
		}
		$this->syncPublishedAuthors( $users );
		$args = [
			'blog_id'             => $site_id,
			'has_published_posts' => [ 'post' ],
			'include'             => $users,
		];
		$this->assertPublishedPostsQuery( $args, [ $users[0] ] );
		add_action( 'ep_after_add_to_queue', [ $manager, 'index_sync_queue' ] );
		try {
			$this->assertTrue( remove_user_from_blog( $users[0], $site_id, $users[1] ) );
		} finally {
			remove_action( 'ep_after_add_to_queue', [ $manager, 'index_sync_queue' ] );
		}
		$this->assertSame( '/ep116-test', apply_filters( 'wp_redirect', '/ep116-test', 302 ) );
		$this->syncPublishedAuthors( [] );
		$this->assertPublishedPostsQuery( $args, [ $users[1] ] );
		$this->assertPublishedPostsQuery( array_merge( $args, [ 'blog_id' => $home_id ] ), [ $users[0] ] );
		add_user_to_blog( $site_id, $users[2], 'author' );
		$this->syncPublishedAuthors( [ $users[2] ] );
		$this->assertPublishedPostsQuery( $args, [ $users[1], $users[2] ] );
		remove_user_from_blog( $users[2], $site_id );
		$this->syncPublishedAuthors( [ $users[2] ] );
		$this->assertPublishedPostsQuery( $args, [ $users[1] ] );
		$this->assertContains( $site_id . ':post', $indexable->get( $users[2] )['published_post_types'] );
	}

	/**
	 * Deleting a site clears its tokens even for authors who are no longer members.
	 *
	 * @group multisite
	 * @expectedDeprecated delete_blog
	 */
	public function testPublishedAuthorsSiteDeletion() {
		if ( ! is_multisite() || ! defined( 'EP_IS_NETWORK' ) || ! EP_IS_NETWORK ) {
			$this->markTestSkipped( 'Requires network activation.' );
		}

		$indexable           = ElasticPress\Indexables::factory()->get( 'user' );
		$manager             = $indexable->sync_manager;
		$users               = $this->factory->user->create_many( 3, [ 'role' => 'author' ] );
		$site_id             = $this->factory->blog->create();
		$manager->sync_queue = [];
		$this->factory->post->create(
			[
				'post_author' => $users[0],
				'post_status' => 'publish',
			]
		);
		switch_to_blog( $site_id );
		try {
			add_user_to_blog( $site_id, $users[0], 'author' );
			add_user_to_blog( $site_id, $users[1], 'author' );
			$this->factory->post->create(
				[
					'post_author' => $users[0],
					'post_status' => 'publish',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $users[2],
					'post_status' => 'publish',
				]
			);
		} finally {
			restore_current_blog();
		}
		$this->syncPublishedAuthors( $users );
		add_action( 'ep_after_add_to_queue', [ $manager, 'index_sync_queue' ] );
		try {
			wpmu_delete_blog( $site_id, true );
		} finally {
			remove_action( 'ep_after_add_to_queue', [ $manager, 'index_sync_queue' ] );
		}
		$this->assertNull( get_site( $site_id ) );
		$this->assertArrayNotHasKey( $site_id, $manager->sync_queue );
		$this->syncPublishedAuthors( [] );

		foreach ( $users as $user_id ) {
			$this->assertNotContains( $site_id . ':post', $indexable->get( $user_id )['published_post_types'] );
		}

		$this->assertPublishedPostsQuery(
			[
				'has_published_posts' => true,
				'include'             => $users,
			],
			[ $users[0] ]
		);
	}

	/**
	 * Published types include all published content, once per site and type.
	 */
	public function testPreparePublishedPostTypes() {
		$user_id   = $this->factory->user->create();
		$indexable = ElasticPress\Indexables::factory()->get( 'user' );
		$this->assertSame( [], $indexable->prepare_document( $user_id )['published_post_types'] );

		register_post_type( 'ep_private_test', [ 'public' => false ] );
		try {
			foreach ( [ 'publish', 'publish', 'draft', 'future', 'private', 'trash' ] as $status ) {
				$this->factory->post->create(
					[
						'post_author' => $user_id,
						'post_status' => $status,
						'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '2020-01-01 00:00:00',
					]
				);
			}
			$this->factory->post->create(
				[
					'post_author'   => $user_id,
					'post_status'   => 'publish',
					'post_type'     => 'page',
					'post_password' => 'secret',
				]
			);
			$this->factory->post->create(
				[
					'post_author' => $user_id,
					'post_status' => 'publish',
					'post_type'   => 'ep_private_test',
				]
			);
			$other_user = $this->factory->user->create();
			$this->factory->post->create(
				[
					'post_author' => $other_user,
					'post_status' => 'publish',
				]
			);
			$blog_id = get_current_blog_id();
			$this->assertSame(
				[ $blog_id . ':ep_private_test', $blog_id . ':page', $blog_id . ':post' ],
				$indexable->prepare_document( $user_id )['published_post_types']
			);
			$this->assertSame( [ $blog_id . ':post' ], $indexable->prepare_published_post_types( $other_user ) );
		} finally {
			unregister_post_type( 'ep_private_test' );
		}
	}

	/**
	 * Drafts and other unpublished statuses do not establish eligibility.
	 */
	public function testUnpublishedPostTypesAreExcluded() {
		$user_id = $this->factory->user->create();
		foreach ( [ 'draft', 'future', 'private', 'trash' ] as $status ) {
			$this->factory->post->create(
				[
					'post_author' => $user_id,
					'post_status' => $status,
					'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '2020-01-01 00:00:00',
				]
			);
		}
		$indexable = ElasticPress\Indexables::factory()->get( 'user' );
		$this->assertSame( [], $indexable->prepare_published_post_types( $user_id ) );
	}

	/**
	 * Keep site/type pairs distinct even when the author is not a site member.
	 *
	 * @group multisite
	 */
	public function testPublishedPostTypesAcrossSites() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		$user_id = $this->factory->user->create();
		$site_id = $this->factory->blog->create();
		$home_id = get_current_blog_id();
		$this->factory->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'publish',
			]
		);
		switch_to_blog( $site_id );
		try {
			$this->factory->post->create(
				[
					'post_author' => $user_id,
					'post_status' => 'publish',
					'post_type'   => 'page',
				]
			);
			$this->assertFalse( is_user_member_of_blog( $user_id, $site_id ) );
		} finally {
			restore_current_blog();
		}
		$indexable = ElasticPress\Indexables::factory()->get( 'user' );
		// Authorship must not depend on which sites are selected for post indexing.
		add_filter( 'ep_indexable_sites', '__return_empty_array' );
		try {
			$this->assertEqualsCanonicalizing(
				[ $home_id . ':post', $site_id . ':page' ],
				$indexable->prepare_published_post_types( $user_id )
			);
		} finally {
			remove_filter( 'ep_indexable_sites', '__return_empty_array' );
		}
		$this->assertNotFalse( $indexable->index( $user_id, true ) );
		ElasticPress\Elasticsearch::factory()->refresh_indices();
		foreach ( [
			$home_id . ':post' => 1,
			$site_id . ':page' => 1,
			$site_id . ':post' => 0,
			$home_id . ':page' => 0,
		] as $pair => $count ) {
			$result = $indexable->query_es( [ 'query' => [ 'term' => [ 'published_post_types' => $pair ] ] ], [] );
			$this->assertNotFalse( $result );
			$total = $result['found_documents'];
			$this->assertSame( $count, is_array( $total ) ? $total['value'] : $total );
			if ( $count ) {
				$this->assertSame( $user_id, $result['documents'][0]['ID'] );
			}
		}
	}

	/**
	 * A failed database read must not overwrite a user's indexed eligibility.
	 */
	public function testPublishedPostTypesReadFailure() {
		$user_id = $this->factory->user->create();
		$this->factory->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'publish',
			]
		);
		$indexable = ElasticPress\Indexables::factory()->get( 'user' );
		$this->assertNotFalse( $indexable->index( $user_id, true ) );
		$expected = $indexable->get( $user_id );

		$fail_read = static function ( $query ) {
			if ( 0 === strpos( $query, 'SELECT DISTINCT post_type FROM ' ) ) {
				return 'SELECT post_type FROM ep116_missing_posts_table';
			}
			return $query;
		};
		add_filter( 'query', $fail_read );
		try {
			$this->assertFalse( $indexable->index( $user_id, true ) );
		} finally {
			remove_filter( 'query', $fail_read );
		}
		$this->assertSame( $expected, $indexable->get( $user_id ) );
	}

	/**
	 * Both mapping versions store the site/type pair as an exact keyword.
	 */
	public function testPublishedPostTypesMapping() {
		$directory = dirname( __DIR__, 3 ) . '/includes/mappings/user/';
		$legacy    = require $directory . 'initial.php';
		$current   = require $directory . '7-0.php';
		$this->assertSame( 'keyword', $legacy['mappings']['user']['properties']['published_post_types']['type'] );
		$this->assertSame( 'keyword', $current['mappings']['properties']['published_post_types']['type'] );
	}

	/**
	 * Test protected meta does not index.
	 */
	public function testProtectedMetaNotIndex() {

		$user_id = $this->factory->user->create(
			[
				'meta_input' => array(
					'_phone_number' => '1234567890',
				),
			]
		);

		$user = new \ElasticPressLabs\Indexable\User\User();

		$user_args = $user->prepare_document( $user_id );

		$this->assertTrue( empty( $user_args['meta']['_phone_number'] ) );
	}

	/**
	 * Test whitelisted meta does index.
	 */
	public function testProtectedWhiteListMetaIndex() {

		add_filter(
			'ep_prepare_user_meta_allowed_protected_keys',
			function ( $meta_keys ) {
				$meta_keys[] = '_phone_number';

				return $meta_keys;
			}
		);

		$user_id = $this->factory->user->create(
			[
				'meta_input' => array(
					'_phone_number' => '1234567890',
				),
			]
		);

		$user      = new \ElasticPressLabs\Indexable\User\User();
		$user_args = $user->prepare_document( $user_id );

		$this->assertEquals( $user_args['meta']['_phone_number'][0]['value'], '1234567890' );
	}

	/**
	 * Test query_db() function.
	 */
	public function testQueryDb() {

		$this->createAndIndexUsers();
		$user_1 = $this->factory->user->create();
		$user_2 = $this->factory->user->create();

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$user = new \ElasticPressLabs\Indexable\User\User();

		// Test the first loop of the indexing.
		$results = $user->query_db(
			[
				'per_page' => 1,
			]
		);

		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( 7, $results['total_objects'] );
		$this->assertEquals( $user_2, $results['objects'][0]->ID );

		// Test the second loop of the indexing.
		$results = $user->query_db(
			[
				'per_page' => 1,
				'offset'   => 1,
			]
		);

		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( 7, $results['total_objects'] );
		$this->assertEquals( $user_1, $results['objects'][0]->ID );
	}

	/**
	 * Test if the mapping applies the ep_stop filter correctly
	 *
	 * @since 2.1.1
	 * @group user
	 */
	public function test_mapping_ep_stop_filter() {
		$indexable      = ElasticPress\Indexables::factory()->get( 'user' );
		$index_name     = $indexable->get_index_name();
		$settings       = ElasticPress\Elasticsearch::factory()->get_index_settings( $index_name );
		$index_settings = $settings[ $index_name ]['settings'];

		$this->assertContains( 'ep_stop', $index_settings['index.analysis.analyzer.default.filter'] );
		$this->assertSame( '_english_', $index_settings['index.analysis.filter.ep_stop.stopwords'] );

		$change_lang = function ( $lang, $context ) {
			return 'filter_ep_stop' === $context ? '_arabic_' : $lang;
		};
		add_filter( 'ep_analyzer_language', $change_lang, 11, 2 );

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		$indexable->put_mapping();

		$settings       = ElasticPress\Elasticsearch::factory()->get_index_settings( $index_name );
		$index_settings = $settings[ $index_name ]['settings'];
		$this->assertSame( '_arabic_', $index_settings['index.analysis.filter.ep_stop.stopwords'] );
	}

	/**
	 * Test query_db() function.
	 *
	 * @since 2.5.0
	 * @group user
	 */
	public function test_query_db() {
		$this->ep_factory->user->create_many( 10 );

		$indexable = ElasticPress\Indexables::factory()->get( 'user' );

		$results = $indexable->query_db( [] );

		// 12 because 2 are created by the setup
		$this->assertEquals( 12, $results['total_objects'] );
		$this->assertCount( 12, $results['objects'] );

		$results = $indexable->query_db( [ 'per_page' => 5 ] );
		$this->assertCount( 5, $results['objects'] );
		$this->assertEquals( 12, $results['total_objects'] );

		// // get test_admin user.
		$test_admin = get_user_by( 'login', 'test_admin' );

		$results = $indexable->query_db( [ 'include' => $test_admin->ID ] );
		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( 1, $results['total_objects'] );

		$results = $indexable->query_db( [ 'exclude' => $test_admin->ID ] );
		$this->assertCount( 11, $results['objects'] );
		$this->assertEquals( 11, $results['total_objects'] );
	}

	/**
	 * Test query_db() function lower and upper limit.
	 *
	 * @since 2.5.0
	 * @group user
	 */
	public function test_query_db_with_limit() {
		$user_1_id = $this->ep_factory->user->create();

		$this->ep_factory->user->create_many( 5 );

		$user_2_id = $this->ep_factory->user->create();
		$indexable = ElasticPress\Indexables::factory()->get( 'user' );

		$results = $indexable->query_db(
			[
				'ep_indexing_lower_limit_object_id' => $user_2_id,
			]
		);

		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( $user_2_id, $results['objects'][0]->ID );
		$this->assertEquals( 1, $results['total_objects'] );

		$results = $indexable->query_db(
			[
				'ep_indexing_upper_limit_object_id' => $user_1_id,
			]
		);

		$this->assertCount( 3, $results['objects'] );
		$this->assertEquals( 3, $results['total_objects'] );
		$this->assertEquals( $user_1_id, $results['objects'][0]->ID );
	}

	/**
	 * Test query_db() function pagination.
	 *
	 * @since 2.5.0
	 * @group user
	 */
	public function test_query_db_pagination() {

		$user_1_id = get_user_by( 'login', 'admin' )->ID;
		$user_2_id = get_user_by( 'login', 'test_admin' )->ID;
		$user_3_id = $this->ep_factory->user->create();

		$indexable = ElasticPress\Indexables::factory()->get( 'user' );

		$results = $indexable->query_db( [ 'per_page' => 1 ] );
		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( 3, $results['total_objects'] );
		$this->assertEquals( $user_3_id, $results['objects'][0]->ID );

		// second loop
		$results = $indexable->query_db(
			[
				'per_page'                             => 1,
				'ep_indexing_last_processed_object_id' => $user_3_id,
			]
		);

		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( 3, $results['total_objects'] );
		$this->assertEquals( $user_2_id, $results['objects'][0]->ID );

		// third loop
		$results = $indexable->query_db(
			[
				'per_page'                             => 1,
				'ep_indexing_last_processed_object_id' => $user_2_id,
			]
		);

		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( 3, $results['total_objects'] );
		$this->assertEquals( $user_1_id, $results['objects'][0]->ID );
	}

	/**
	 * Test ep_user_pre_query_db_results filter to short-circuit the DB query
	 *
	 * @since 2.5.0
	 * @group user
	 */
	public function test_ep_user_pre_query_db_results_filter() {
		$users = $this->createAndIndexUsers();

		$expected_results = [
			'objects'       => [
				(object) [ 'ID' => $users[0] ],
				(object) [ 'ID' => $users[1] ],
			],
			'total_objects' => 2,
		];

		// Add filter to short-circuit the query
		add_filter(
			'ep_user_pre_query_db_results',
			function () use ( $expected_results ) {
				return $expected_results;
			}
		);

		$user    = new \ElasticPressLabs\Indexable\User\User();
		$results = $user->query_db( [] );

		$this->assertEquals( $expected_results['objects'], $results['objects'] );
		$this->assertEquals( $expected_results['total_objects'], $results['total_objects'] );
		$this->assertEquals( $expected_results['objects'][0]->ID, $results['objects'][0]->ID );
		$this->assertEquals( $expected_results['objects'][1]->ID, $results['objects'][1]->ID );
	}

	/**
	 * Test ep_user_query_db_sql filter to modify the SQL query
	 *
	 * @since 2.5.0
	 * @group user
	 */
	public function test_ep_user_query_db_sql_filter() {
		global $wpdb;

		$user_id = $this->ep_factory->user->create();

		add_filter(
			'ep_user_query_db_sql',
			function () use ( $wpdb, $user_id ) {
				return $wpdb->prepare(
					"SELECT SQL_CALC_FOUND_ROWS ID FROM {$wpdb->users} WHERE ID = %d",
					$user_id
				);
			},
			10,
			2
		);

		add_filter(
			'ep_user_query_db_count_objects_sql',
			function () use ( $wpdb, $user_id ) {
				return $wpdb->prepare(
					"SELECT COUNT(ID) FROM {$wpdb->users} WHERE ID = %d",
					$user_id
				);
			},
			10,
			2
		);

		$user    = new \ElasticPressLabs\Indexable\User\User();
		$results = $user->query_db( [] );

		$this->assertCount( 1, $results['objects'] );
		$this->assertEquals( $user_id, $results['objects'][0]->ID );
		$this->assertEquals( 1, $results['total_objects'] );
	}
}
