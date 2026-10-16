<?php
/**
 * Test Chat bot storage
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPressLabs\Feature\Chat\Admin;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Chat;
use ElasticPressLabs\Feature\Chat\Gates\Empty_Retrieve_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Chat bots test class
 */
class TestChatBots extends \WP_UnitTestCase {
	/**
	 * Bots instance.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		Features::factory()->register_feature( new VectorEmbeddings() );
		Features::factory()->register_feature( new Chat() );

		$this->bots = new Bots();
		$this->bots->setup();
		$this->bots->register_post_type();

		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
	}

	/**
	 * Test the chat bot post type is private and has no UI.
	 *
	 * @group chat
	 */
	public function test_registers_private_bot_post_type() {
		$post_type  = get_post_type_object( Bots::POST_TYPE );
		$capability = \ElasticPress\Utils\get_capability( 'chat' );

		$this->assertNotNull( $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->publicly_queryable );
		$this->assertFalse( $post_type->show_ui );
		$this->assertFalse( $post_type->show_in_rest );
		$this->assertTrue( $post_type->exclude_from_search );
		$this->assertSame( 'manage_elasticpress', $capability );
		$this->assertSame( $capability, $post_type->cap->create_posts );
		$this->assertSame( $capability, $post_type->cap->edit_post );
		$this->assertSame( $capability, $post_type->cap->edit_posts );
		$this->assertSame( $capability, $post_type->cap->publish_posts );
		$this->assertSame( $capability, $post_type->cap->delete_post );
		$this->assertSame( $capability, $post_type->cap->delete_posts );
		$this->assertSame( $capability, $post_type->cap->delete_others_posts );
	}

	/**
	 * Test creating a bot stores name and execution mode.
	 *
	 * @group chat
	 */
	public function test_create_bot_stores_name_and_mode() {
		$bot_id = $this->bots->create( 'Researcher', Bots::MODE_WP_ASYNC );

		$this->assertIsInt( $bot_id );
		$this->assertSame( Bots::POST_TYPE, get_post_type( $bot_id ) );
		$this->assertSame( 'Researcher', get_the_title( $bot_id ) );
		$this->assertSame(
			Bots::MODE_WP_ASYNC,
			get_post_meta( $bot_id, Bots::META_EXECUTION_MODE, true )
		);
	}

	/**
	 * Test invalid execution modes fall back to sync.
	 *
	 * @group chat
	 */
	public function test_invalid_execution_mode_falls_back_to_sync() {
		$bot_id = $this->bots->create( 'Fallback', 'nope' );

		$bot = $this->bots->get( $bot_id );

		$this->assertSame( Bots::MODE_SYNC, $bot['execution_mode'] );
	}

	/**
	 * Test the legacy async slug is not mapped.
	 *
	 * @group chat
	 */
	public function test_legacy_async_slug_is_not_mapped() {
		$bot_id = $this->bots->create( 'Legacy', 'async' );

		$bot = $this->bots->get( $bot_id );

		$this->assertSame( Bots::MODE_SYNC, $bot['execution_mode'] );
	}

	/**
	 * Test empty names are rejected.
	 *
	 * @group chat
	 */
	public function test_rejects_empty_name() {
		$error = $this->bots->create( '   ' );

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_bot_empty_name', $error->get_error_code() );
	}

	/**
	 * Test bots can be listed, updated, and deleted with no count cap.
	 *
	 * @group chat
	 */
	public function test_lists_updates_and_deletes_bots() {
		$first  = $this->bots->create( 'Alpha', Bots::MODE_SYNC );
		$second = $this->bots->create( 'Beta', Bots::MODE_WP_ASYNC );

		$list = $this->bots->get_all();

		$this->assertCount( 2, $list );
		$this->assertSame( 'Alpha', $list[0]['name'] );
		$this->assertSame( 'Beta', $list[1]['name'] );

		$this->bots->update( $first, 'Alpha renamed', Bots::MODE_WP_ASYNC );

		$updated = $this->bots->get( $first );
		$this->assertSame( 'Alpha renamed', $updated['name'] );
		$this->assertSame( Bots::MODE_WP_ASYNC, $updated['execution_mode'] );

		$this->assertTrue( $this->bots->delete( $second ) );
		$this->assertWPError( $this->bots->get( $second ) );
		$this->assertCount( 1, $this->bots->get_all() );
	}

	/**
	 * Test chat bots are not an indexable post type.
	 *
	 * @group chat
	 */
	public function test_bots_are_not_indexable() {
		$types = apply_filters(
			'ep_indexable_post_types',
			[ Bots::POST_TYPE => Bots::POST_TYPE ]
		);

		$this->assertArrayNotHasKey( Bots::POST_TYPE, $types );
	}

	/**
	 * Test a default bot is created when Chat is activated with none present.
	 *
	 * @group chat
	 */
	public function test_post_activation_creates_default_bot_when_none_exist() {
		$this->assertSame( [], $this->bots->get_all() );

		Features::factory()->get_registered_feature( 'chat' )->post_activation();

		$all = $this->bots->get_all();

		$this->assertCount( 1, $all );
		$this->assertSame( Bots::DEFAULT_NAME, $all[0]['name'] );
		$this->assertSame( Bots::MODE_SYNC, $all[0]['execution_mode'] );
	}

	/**
	 * Test activation does not add a default bot when one already exists.
	 *
	 * @group chat
	 */
	public function test_post_activation_does_not_duplicate_existing_bots() {
		$this->bots->create( 'Sync', Bots::MODE_SYNC );

		Features::factory()->get_registered_feature( 'chat' )->post_activation();

		$all = $this->bots->get_all();

		$this->assertCount( 1, $all );
		$this->assertSame( 'Sync', $all[0]['name'] );
	}

	/**
	 * Test bots store re-ranker on/off and an optional floor.
	 *
	 * @group chat
	 */
	public function test_stores_rerank_settings() {
		$bot_id = $this->bots->create(
			'Ranker',
			Bots::MODE_SYNC,
			[
				'rerank_enabled' => true,
				'rerank_floor'   => '0.35',
			]
		);

		$bot = $this->bots->get( $bot_id );

		$this->assertTrue( $bot['rerank_enabled'] );
		$this->assertSame( 0.35, $bot['rerank_floor'] );

		$this->bots->update(
			$bot_id,
			'Ranker',
			Bots::MODE_SYNC,
			[
				'rerank_enabled' => false,
				'rerank_floor'   => '',
			]
		);

		$updated = $this->bots->get( $bot_id );

		$this->assertFalse( $updated['rerank_enabled'] );
		$this->assertNull( $updated['rerank_floor'] );
	}

	/**
	 * Test a floor outside 0–1, or not a number, is refused and nothing is saved.
	 *
	 * @group chat
	 */
	public function test_refuses_invalid_rerank_floor() {
		$this->assertWPError( $this->bots->create( 'Low', Bots::MODE_SYNC, [ 'rerank_floor' => -1 ] ) );
		$this->assertWPError( $this->bots->create( 'High', Bots::MODE_SYNC, [ 'rerank_floor' => 1.5 ] ) );
		$this->assertWPError( $this->bots->create( 'Word', Bots::MODE_SYNC, [ 'rerank_floor' => 'nope' ] ) );
		$this->assertSame( [], $this->bots->get_all() );
	}

	/**
	 * Test new bots default to re-rank off with no floor.
	 *
	 * @group chat
	 */
	public function test_create_defaults_rerank_off() {
		$bot = $this->bots->get( $this->bots->create( 'Plain', Bots::MODE_SYNC ) );

		$this->assertFalse( $bot['rerank_enabled'] );
		$this->assertNull( $bot['rerank_floor'] );
	}

	/**
	 * Test list order is menu_order, not title, and new bots append.
	 *
	 * @group chat
	 */
	public function test_lists_bots_in_menu_order_and_appends_new() {
		$zebra = $this->bots->create( 'Zebra', Bots::MODE_WP_ASYNC );
		$alpha = $this->bots->create( 'Alpha', Bots::MODE_SYNC );

		$list = $this->bots->get_all();

		$this->assertSame( [ $zebra, $alpha ], wp_list_pluck( $list, 'id' ) );
		$this->assertSame( [ 'Zebra', 'Alpha' ], wp_list_pluck( $list, 'name' ) );
		$this->assertSame( 0, (int) get_post( $zebra )->menu_order );
		$this->assertSame( 1, (int) get_post( $alpha )->menu_order );
	}

	/**
	 * Test reorder persists and rejects incomplete ID sets.
	 *
	 * @group chat
	 */
	public function test_reorders_bots() {
		$first  = $this->bots->create( 'First', Bots::MODE_WP_ASYNC );
		$second = $this->bots->create( 'Second', Bots::MODE_SYNC );

		$reordered = $this->bots->reorder( [ $second, $first ] );

		$this->assertSame( [ $second, $first ], wp_list_pluck( $reordered, 'id' ) );
		$this->assertSame( 'Second', $reordered[0]['name'] );
		$this->assertSame( 0, (int) get_post( $second )->menu_order );
		$this->assertSame( 1, (int) get_post( $first )->menu_order );

		$error = $this->bots->reorder( [ $first ] );

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_bot_invalid_order', $error->get_error_code() );
	}

	/**
	 * Test bots expose the registered gate list.
	 *
	 * @group chat
	 */
	public function test_lists_registered_gates() {
		$bot = $this->bots->get( $this->bots->create( 'Gated', Bots::MODE_SYNC ) );

		// A new bot runs the optional gates that are on by default.
		$this->assertSame( [ 'injection_defense', 'injection_echo', 'figure_match', 'scope', 'population_match', 'trend_guard', 'superlative', 'claim_verifier', 'refusal', 'citation_attributor' ], $bot['optional_gates'] );
		$this->assertNotEmpty( $bot['gates'] );

		$by_slug = [];
		foreach ( $bot['gates'] as $row ) {
			$by_slug[ $row['slug'] ] = $row;
		}

		$this->assertArrayHasKey( Empty_Retrieve_Gate::SLUG, $by_slug );
		$this->assertTrue( $by_slug[ Empty_Retrieve_Gate::SLUG ]['required'] );
		$this->assertTrue( $by_slug[ Empty_Retrieve_Gate::SLUG ]['enabled'] );
		$this->assertSame( 'Without sources there is nothing to answer from.', $by_slug[ Empty_Retrieve_Gate::SLUG ]['reason'] );

		// Only Empty retrieve is required among Labs' gates; an optional gate has no reason.
		$this->assertSame( [ Empty_Retrieve_Gate::SLUG ], array_keys( array_filter( wp_list_pluck( $bot['gates'], 'required', 'slug' ) ) ) );
		$this->assertSame( '', $by_slug['figure_match']['reason'] );
	}

	/**
	 * Optional gates on by default are opt-out: a bot that sends no list runs them (and the
	 * required ones); an explicit list is followed as given.
	 *
	 * @group chat
	 */
	public function test_bot_without_a_gate_list_runs_the_defaults() {
		$figure_match = Gates::registry()->get( 'figure_match' );

		$this->assertTrue( Gates::should_run( $figure_match, [ 'execution_mode' => 'sync' ] ) );
		$this->assertTrue( Gates::should_run( Gates::registry()->get( 'empty_retrieve' ), [ 'execution_mode' => 'sync' ] ) );
		$this->assertFalse( Gates::should_run( $figure_match, [ 'optional_gates' => [] ] ) );
		$this->assertTrue( Gates::should_run( $figure_match, [ 'optional_gates' => [ 'figure_match' ] ] ) );
	}

	/**
	 * The bot screen gets a line on each runner, and for each setting its help, details, choice
	 * lines, and whether the re-ranker is set up.
	 *
	 * @group chat
	 */
	public function test_screen_config() {
		$modes = wp_list_pluck( $this->bots->describe_execution_modes(), 'description', 'value' );

		$this->assertNotEmpty( $modes[ Bots::MODE_SYNC ] );
		$this->assertNotEmpty( $modes[ Bots::MODE_WP_ASYNC ] );

		$describe = new \ReflectionMethod( Admin::class, 'describe_answering_settings' );
		$describe->setAccessible( true );
		$config = $describe->invoke( new Admin() );

		$this->assertSame( Settings::get_details( 'intent_enabled' ), $config['fields']['intent_enabled']['details'] );
		$this->assertSame( [ 'show', 'hide', 'uniform' ], array_keys( $config['fields']['decline_display']['descriptions'] ) );
		$this->assertFalse( $config['rerankSetUp'] );
		$this->assertStringEndsWith( '#/chat', $config['featureUrl'] );
	}
}
