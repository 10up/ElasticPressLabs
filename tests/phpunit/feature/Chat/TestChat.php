<?php
/**
 * Test Chat feature
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\FeatureRequirementsStatus;
use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Admin;
use ElasticPressLabs\Feature\Chat\Chat;
use ElasticPressLabs\Feature\Chat\Client;
use ElasticPressLabs\Feature\Chat\Retriever;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Chat feature test class
 */
class TestChat extends \WP_UnitTestCase {
	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		Features::factory()->register_feature( new VectorEmbeddings() );
		Features::factory()->register_feature( new Chat() );
	}

	/**
	 * Get Chat feature
	 *
	 * @return Chat
	 */
	protected function get_feature() {
		return Features::factory()->get_registered_feature( 'chat' );
	}

	/**
	 * Treat Vector Embeddings as active for requirement checks.
	 */
	protected function activate_vector_embeddings() {
		add_filter(
			'ep_feature_active',
			static function ( $active, $settings, $feature ) {
				if ( $feature && 'vector_embeddings' === $feature->slug ) {
					return true;
				}

				return $active;
			},
			10,
			3
		);
	}

	/**
	 * Test construct
	 *
	 * @group chat
	 */
	public function test_construct() {
		$instance = $this->get_feature();
		$instance->set_i18n_strings();

		$this->assertEquals( 'chat', $instance->slug );
		$this->assertEquals( 'Chat', $instance->title );
		$this->assertEquals( 'ai', $instance->group );
		$this->assertContains( 'vector_embeddings', $instance->get_required_feature() );
		$this->assertFalse( $instance->is_active() );
	}

	/**
	 * Test that Chat is available when Vector Embeddings is active.
	 *
	 * @group chat
	 */
	public function test_requirements_status_when_embeddings_active() {
		$this->activate_vector_embeddings();

		$status = $this->get_feature()->requirements_status();

		$this->assertSame( FeatureRequirementsStatus::MANUALLY_ENABLED, $status->code );
	}

	/**
	 * Test that Chat still requires Vector Embeddings.
	 *
	 * @group chat
	 */
	public function test_requirements_status_requires_vector_embeddings() {
		add_filter( 'ep_feature_active', '__return_false' );

		$status = $this->get_feature()->requirements_status();

		$this->assertSame( FeatureRequirementsStatus::FORCE_DISABLED, $status->code );
		$this->assertStringContainsString( 'Vector Embeddings', $status->message );
	}

	/**
	 * Test that the AI Chat menu is registered when the feature is set up.
	 *
	 * @group chat
	 */
	public function test_registers_ai_chat_menu() {
		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Utils\get_capability( 'chat' ) );
		wp_set_current_user( $user_id );

		$this->get_feature()->setup();

		set_current_screen( 'dashboard' );
		do_action( 'admin_menu' );

		global $menu, $submenu;

		$capability = Utils\get_capability( 'chat' );
		$slugs      = wp_list_pluck( (array) $menu, 2 );
		$this->assertContains( Admin::PAGE_SLUG, $slugs );
		$this->assertArrayHasKey( Admin::PAGE_SLUG, (array) $submenu );
		$this->assertSame( 'Ask', $submenu[ Admin::PAGE_SLUG ][0][0] );
		$this->assertSame( 'edit_posts', $submenu[ Admin::PAGE_SLUG ][0][1] );

		$bot_items = (array) ( $submenu['elasticpress'] ?? [] );
		$bot_slugs = wp_list_pluck( $bot_items, 2 );
		$this->assertContains( Admin::BOTS_PAGE_SLUG, $bot_slugs );

		foreach ( $bot_items as $item ) {
			if ( Admin::BOTS_PAGE_SLUG === $item[2] ) {
				$this->assertSame( $capability, $item[1] );
				break;
			}
		}

		$position = null;

		foreach ( (array) $menu as $menu_position => $item ) {
			if ( isset( $item[2] ) && Admin::PAGE_SLUG === $item[2] ) {
				$this->assertSame( 'edit_posts', $item[1] );
				$position = $menu_position;
				break;
			}
		}

		$this->assertSame( 3, $position );
	}

	/**
	 * Test authors can open Ask but cannot manage chat bots.
	 *
	 * @group chat
	 */
	public function test_author_sees_ask_menu_not_chat_bots() {
		$user_id = $this->factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $user_id );

		$this->get_feature()->setup();

		set_current_screen( 'dashboard' );
		do_action( 'admin_menu' );

		global $menu;

		$slugs = wp_list_pluck( (array) $menu, 2 );
		$this->assertContains( Admin::PAGE_SLUG, $slugs );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( Utils\get_capability( 'chat' ) ) );
	}

	/**
	 * Test that AI Chat is placed at the end of the first menu section.
	 *
	 * @group chat
	 */
	public function test_places_ai_chat_menu_in_first_section() {
		$admin = new Admin();

		$this->assertSame(
			[ 'index.php', 'custom-dashboard', Admin::PAGE_SLUG, 'separator1', 'edit.php' ],
			$admin->place_menu_in_first_section(
				[ 'index.php', 'custom-dashboard', 'separator1', 'edit.php', Admin::PAGE_SLUG ]
			)
		);

		$this->assertSame(
			[ 'index.php', Admin::PAGE_SLUG, 'edit.php' ],
			$admin->place_menu_in_first_section( [ 'index.php', 'edit.php', Admin::PAGE_SLUG ] )
		);
	}

	/**
	 * Test that the generate-text filter can short-circuit the client.
	 *
	 * @group chat
	 */
	public function test_client_generate_text_filter() {
		$client = new Client();

		add_filter(
			'ep_chat_generate_text',
			static function ( $pre, $question, $history ) {
				return $question . ':' . count( $history );
			},
			10,
			3
		);

		$this->assertSame(
			'What is ElasticPress?:1',
			$client->generate_text(
				'What is ElasticPress?',
				[
					[
						'role'    => 'user',
						'content' => 'Earlier question',
					],
				]
			)
		);
	}

	/**
	 * Test that a missing AI client returns an error when not stubbed.
	 *
	 * @group chat
	 */
	public function test_client_requires_wordpress_ai_when_not_stubbed() {
		$client = new Client();

		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WordPress AI is available in this environment.' );
		}

		$result = $client->generate_text( 'What is ElasticPress?' );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_ai_unavailable', $result->get_error_code() );
	}

	/**
	 * Test generate_text passes retrieved passages to the short-circuit filter.
	 *
	 * @group chat
	 */
	public function test_generate_text_filter_receives_passages() {
		$client = new Client();

		add_filter(
			'ep_chat_generate_text',
			static function ( $pre, $question, $history, $passages ) {
				return $passages[0]['excerpt'];
			},
			10,
			4
		);

		$this->assertSame(
			'WordPress search plugin',
			$client->generate_text(
				'What is ElasticPress?',
				[],
				[
					[
						'id'      => 1,
						'title'   => 'Guide',
						'url'     => 'https://example.com/guide',
						'excerpt' => 'WordPress search plugin',
					],
				]
			)
		);
	}

	/**
	 * Test Chat retrieve query args include the Chat flag.
	 *
	 * @group chat
	 */
	public function test_retriever_query_args_set_chat_flag() {
		$retriever = new Retriever();
		$args      = $retriever->get_query_args( 'What is ElasticPress?' );

		$this->assertTrue( $args['ep_integrate'] );
		$this->assertTrue( $args[ Retriever::QUERY_FLAG ] );
		$this->assertSame( 'What is ElasticPress?', $args['s'] );
		$this->assertSame( $retriever->get_post_types(), $args['post_type'] );
		$this->assertContains( 'post', $args['post_type'] );
	}

	/**
	 * Test Chat retrieves from the embeddable post types, with a filter and a
	 * fallback to posts.
	 *
	 * @group chat
	 */
	public function test_retriever_post_types_follow_embeddable_types() {
		$configs = [];

		add_filter(
			'pre_option_ep_vector_embeddings_settings',
			static function () use ( &$configs ) {
				return [ 'postTypeConfig' => $configs ];
			}
		);

		$configs = [
			[
				'key'        => 'post',
				'embeddable' => true,
			],
			[
				'key'        => 'page',
				'embeddable' => true,
			],
			[
				'key'        => 'attachment',
				'embeddable' => false,
			],
			[
				'key'        => 'not_a_post_type',
				'embeddable' => true,
			],
		];
		$this->assertSame( [ 'post', 'page' ], ( new Retriever() )->get_post_types() );

		$configs = [];
		$this->assertSame( [ 'post' ], ( new Retriever() )->get_post_types() );

		add_filter(
			'ep_chat_retrieve_post_types',
			static function ( $post_types ) {
				$post_types[] = 'page';

				return $post_types;
			}
		);
		$this->assertSame( [ 'post', 'page' ], ( new Retriever() )->get_post_types() );
	}

	/**
	 * Test the Chat feature card exposes re-ranker connection fields.
	 *
	 * @group chat
	 */
	public function test_settings_schema_includes_rerank_connection() {
		$feature = $this->get_feature();
		$feature->reset_settings_schema();

		$schema = $feature->get_settings_schema();
		$keys   = wp_list_pluck( $schema, 'key' );

		$this->assertContains( 'rerank_api_key', $keys );
		$this->assertContains( 'rerank_api_url', $keys );
		$this->assertContains( 'rerank_model', $keys );
		$this->assertNotContains( 'additional_links', $keys );

		$by_key = [];
		foreach ( $schema as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$by_key[ $field['key'] ] = $field;
			}
		}

		$this->assertStringContainsString(
			'foundation-model',
			$by_key['rerank_model']['help']
		);
		$this->assertSame( '', $feature->get_setting( 'rerank_api_url' ) );
		$this->assertSame( '', $feature->get_setting( 'rerank_api_key' ) );
		$this->assertSame( '', $feature->get_setting( 'rerank_model' ) );
	}

	/**
	 * The Chat feature card starts with a button to the chat bots screen when Chat is on.
	 *
	 * @group chat
	 */
	public function test_settings_schema_links_to_chat_bots() {
		$this->activate_vector_embeddings();

		Features::factory()->activate_feature( 'chat' );
		$this->get_feature()->reset_settings_schema();

		$schema = $this->get_feature()->get_settings_schema();
		$keys   = wp_list_pluck( $schema, 'key' );

		$this->assertContains( 'rerank_api_key', $keys );
		$this->assertContains( 'rerank_api_url', $keys );
		$this->assertContains( 'rerank_model', $keys );
		$this->assertSame( 'manage_bots', $keys[1] ); // After "Enable".
		$this->assertStringContainsString( 'page=' . \ElasticPressLabs\Feature\Chat\Admin::BOTS_PAGE_SLUG, $schema[1]['label'] );
	}
}
