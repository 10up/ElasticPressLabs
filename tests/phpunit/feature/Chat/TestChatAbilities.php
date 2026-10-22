<?php
/**
 * Test the Chat abilities (ask and search, for MCP)
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Abilities;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Clarifier;
use ElasticPressLabs\Feature\Chat\Intent;
use ElasticPressLabs\Feature\Chat\Refusals;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Registration, ask (answers, each way a decline is shown, clarify, follow-ups), search, access,
 * and that nothing the reader would not see leaves.
 */
class TestChatAbilities extends BaseTestCase {
	/**
	 * A passage sentence that must never leave through an ability unless the Sources rail
	 * shows it.
	 *
	 * @var string
	 */
	protected const PASSAGE = 'Most adults say they trust each other. Today, 42% of adults do not use cash.';

	/**
	 * Bots.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Retrieved post IDs.
	 *
	 * @var int[]
	 */
	protected $post_ids = [];

	/**
	 * The answer model's reply.
	 *
	 * @var string
	 */
	protected $reply = 'About 42% of adults do not use cash.';

	/**
	 * Set up Chat pieces that run only when Chat is on, the abilities, and stubs.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9 or later.' );
		}

		$this->bots = new Bots();
		$this->bots->register_post_type();

		$storage = new Storage();
		$storage->register_post_type();
		$storage->setup();

		( new Intent() )->setup();
		( new Clarifier() )->setup();

		$this->register_abilities();
		$this->register_routes();

		foreach ( [ 'Cash Report', 'Trust in government' ] as $title ) {
			$this->post_ids[] = $this->factory()->post->create(
				[
					'post_title'   => $title,
					'post_content' => self::PASSAGE,
					'post_excerpt' => '',
					'post_status'  => 'publish',
				]
			);
		}

		add_filter(
			'ep_chat_retrieve',
			function () {
				return array_map( 'get_post', $this->post_ids );
			}
		);

		add_filter(
			'ep_chat_generate_text',
			function () {
				return $this->reply;
			}
		);

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Remove the abilities and the category.
	 */
	public function tear_down() {
		$this->tear_down_abilities();

		parent::tear_down();
	}

	/**
	 * Unregister the abilities and the category.
	 */
	protected function tear_down_abilities() {
		foreach ( [ Abilities::ASK, Abilities::SEARCH, Abilities::LIST_BOTS ] as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		if ( wp_has_ability_category( Abilities::CATEGORY ) ) {
			wp_unregister_ability_category( Abilities::CATEGORY );
		}
	}

	/**
	 * Register only the Chat category and abilities, inside their actions (the registry refuses
	 * registration anywhere else, and running every callback again would register others twice).
	 */
	protected function register_abilities() {
		wp_get_abilities(); // Initializes the registries first.

		$abilities = new Abilities();

		foreach ( [
			'wp_abilities_api_categories_init' => [ $abilities, 'register_category' ],
			'wp_abilities_api_init'            => [ $abilities, 'register_abilities' ],
		] as $hook => $callback ) {
			global $wp_filter;

			$saved = $wp_filter[ $hook ] ?? null;

			unset( $wp_filter[ $hook ] );
			add_action( $hook, $callback );
			do_action( $hook );

			if ( null === $saved ) {
				unset( $wp_filter[ $hook ] );
			} else {
				$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	/**
	 * A site can keep the abilities from being registered.
	 *
	 * @group chat
	 */
	public function test_register_abilities_filter() {
		$this->tear_down_abilities();

		add_filter( 'ep_chat_register_abilities', '__return_false' );
		$this->register_abilities();

		foreach ( [ Abilities::ASK, Abilities::SEARCH, Abilities::LIST_BOTS ] as $name ) {
			$this->assertFalse( wp_has_ability( $name ), $name );
		}

		$this->assertFalse( wp_has_ability_category( Abilities::CATEGORY ) );
	}

	/**
	 * Chat's REST routes (Chat is not turned on in tests).
	 */
	protected function register_routes() {
		global $wp_rest_server;

		// ElasticPress's own routes read each feature's title, which older EP versions set late.
		foreach ( \ElasticPress\Features::factory()->registered_features as $feature ) {
			if ( method_exists( $feature, 'set_i18n_strings' ) ) {
				$feature->set_i18n_strings();
			}
		}

		add_action( 'rest_api_init', [ new \ElasticPressLabs\REST\Chat(), 'register_routes' ] );

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Chat feature settings.
	 *
	 * @param array $values Values.
	 */
	protected function set_feature( array $values ) {
		$settings         = Utils\get_option( 'ep_feature_settings', [] );
		$settings['chat'] = array_merge( [ 'active' => false ], is_array( $settings['chat'] ?? null ) ? $settings['chat'] : [], $values );

		Utils\update_option( 'ep_feature_settings', $settings );
	}

	/**
	 * Run an ability as the current user.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input.
	 * @return mixed
	 */
	protected function execute_ability( string $name, array $input ) {
		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * Both abilities are registered in the Chat category, marked for MCP, with schemas.
	 *
	 * @group chat
	 */
	public function test_registration() {
		foreach ( [ Abilities::ASK, Abilities::SEARCH, Abilities::LIST_BOTS ] as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category() );
			$this->assertTrue( $ability->get_meta()['mcp']['public'] );
			$this->assertSame( 'tool', $ability->get_meta()['mcp']['type'] );
		}

		$this->assertNotEmpty( wp_get_ability( Abilities::ASK )->get_input_schema()['properties'] );

		$this->assertSame( [ 'question' ], wp_get_ability( Abilities::ASK )->get_input_schema()['required'] );
		$this->assertTrue( wp_get_ability( Abilities::SEARCH )->get_meta()['annotations']['readonly'] );
	}

	/**
	 * Search returns titles, links, and dates only, up to the limit.
	 *
	 * @group chat
	 */
	public function test_search() {
		$this->bots->create( 'Default', Bots::MODE_SYNC );

		$result = $this->execute_ability(
			Abilities::SEARCH,
			[
				'query' => 'cash',
				'limit' => 1,
			]
		);

		$this->assertSame(
			[
				[
					'title' => 'Cash Report',
					'url'   => get_permalink( $this->post_ids[0] ),
					'date'  => get_the_date( 'Y-m-d', $this->post_ids[0] ),
				],
			],
			$result['results']
		);
		$this->assertStringNotContainsString( 'trust each other', wp_json_encode( $result ) );
	}

	/**
	 * An answer: stored in History, with citations and the cited sources only (what the Sources
	 * rail shows), even for an async bot (the ask runs in the request).
	 *
	 * @group chat
	 */
	public function test_ask_answers() {
		$bot_id = $this->bots->create( 'Async', Bots::MODE_WP_ASYNC );
		$result = $this->execute_ability(
			Abilities::ASK,
			[
				'question' => 'How many adults do not use cash?',
				'bot_id'   => $bot_id,
			]
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertStringContainsString( '42%[[1]]', $result['answer'] );
		$this->assertSame( '42%', $result['citations'][0]['figure'] );
		$this->assertSame( 'Cash Report', $result['citations'][0]['title'] );
		$this->assertCount( 1, $result['sources'] );
		$this->assertSame( Storage::STATUS_DONE, ( new Storage() )->get_status( $result['question_id'] ) );
		$this->assertSame( (int) $result['conversation_id'], (int) get_comment( $result['question_id'] )->comment_post_ID );

		// Only the quoted sentence of the passage leaves, as the Sources rail shows it; the rest of
		// the passage and the uncited source never do.
		$json = wp_json_encode( $result );

		$this->assertSame( 'Today, 42% of adults do not use cash.', $result['citations'][0]['quote'] );
		$this->assertStringNotContainsString( 'trust each other', $json );
		$this->assertStringNotContainsString( 'excerpt', $json );
		$this->assertStringNotContainsString( 'Trust in government', $json );
	}

	/**
	 * A decline is shown as Ask shows it for the bot: with the reason, without it, or the same
	 * card for every decline; no passage text in any of them.
	 *
	 * @group chat
	 */
	public function test_declines_follow_the_bot() {
		$this->reply = 'About 73% of adults do not use cash.';

		$show = $this->execute_ability(
			Abilities::ASK,
			[
				'question' => 'How many adults do not use cash?',
				'bot_id'   => $this->bots->create( 'Show', Bots::MODE_SYNC ),
			]
		);

		$this->assertSame( 'refused', $show['status'] );
		$this->assertSame( 'numeric_mismatch', $show['refusal']['reason'] );
		$this->assertSame( 'Unsupported figure', $show['refusal']['label'] );
		$this->assertSame( Refusals::KIND_WITHHELD, $show['refusal']['kind'] );
		$this->assertCount( 2, $show['related'] );
		$this->assertSame( [ 'title', 'url', 'date' ], array_keys( $show['related'][0] ) );

		$hide = $this->execute_ability(
			Abilities::ASK,
			[
				'question' => 'How many adults do not use cash today?',
				'bot_id'   => $this->bots->create( 'Hide', Bots::MODE_SYNC, [ 'decline_display' => 'hide' ] ),
			]
		);

		$this->assertSame( Refusals::get_generic_message(), $hide['answer'] );
		$this->assertArrayNotHasKey( 'reason', $hide['refusal'] );
		$this->assertArrayNotHasKey( 'label', $hide['refusal'] );
		$this->assertCount( 2, $hide['related'] );

		$uniform = $this->execute_ability(
			Abilities::ASK,
			[
				'question' => 'How many adults avoid cash?',
				'bot_id'   => $this->bots->create( 'Uniform', Bots::MODE_SYNC, [ 'decline_display' => 'uniform' ] ),
			]
		);

		$this->assertSame(
			[ 'next_step' => 'Try rephrasing the question, or ask about another topic.' ],
			$uniform['refusal']
		);
		$this->assertSame( [], $uniform['related'] );

		foreach ( [ $show, $hide, $uniform ] as $result ) {
			$json = wp_json_encode( $result );

			$this->assertStringNotContainsString( '73%', $json, 'The withheld draft never leaves.' );
			$this->assertStringNotContainsString( 'trust each other', $json, 'Passage text never leaves.' );
			$this->assertArrayNotHasKey( 'citations', $result );
		}
	}

	/**
	 * A clarify answer returns the question and the options, and no passage text.
	 *
	 * @group chat
	 */
	public function test_clarify() {
		$this->reply = 'About 73% of adults trust each other.';

		add_filter(
			'ep_chat_clarify_pre',
			static function () {
				return '{"clarify":true,"prompt":"Trust in what?","options":["How much do adults trust the government?","How much do adults trust each other?"]}';
			}
		);

		$result = $this->execute_ability(
			Abilities::ASK,
			[
				'question' => 'trust',
				'bot_id'   => $this->bots->create( 'Clarify', Bots::MODE_SYNC, [ 'clarify_enabled' => '1' ] ),
			]
		);

		$this->assertSame( 'clarify', $result['status'] );
		$this->assertSame( 'Trust in what?', $result['answer'] );
		$this->assertSame( [ 'How much do adults trust the government?', 'How much do adults trust each other?' ], $result['options'] );
		$this->assertStringNotContainsString( 'Most adults say', wp_json_encode( $result ) );
	}

	/**
	 * The bots are listed in order with the default marked: the first sync bot, else the first
	 * bot. Ask and search use it when no bot is given.
	 *
	 * @group chat
	 */
	public function test_list_bots_and_default() {
		$async  = $this->bots->create( 'Slower', Bots::MODE_WP_ASYNC );
		$sync   = $this->bots->create( 'Quick', Bots::MODE_SYNC );
		$second = $this->bots->create( 'Quick too', Bots::MODE_SYNC );

		$this->assertSame(
			[
				[
					'id'      => $async,
					'name'    => 'Slower',
					'default' => false,
				],
				[
					'id'      => $sync,
					'name'    => 'Quick',
					'default' => true,
				],
				[
					'id'      => $second,
					'name'    => 'Quick too',
					'default' => false,
				],
			],
			$this->execute_ability( Abilities::LIST_BOTS, [] )['bots']
		);

		$asked = $this->execute_ability( Abilities::ASK, [ 'question' => 'How many adults do not use cash?' ] );

		$this->assertSame( $sync, (int) get_comment_meta( $asked['question_id'], Storage::META_BOT_ID, true ) );

		// No sync bot: the first bot, still answered in the request.
		$this->bots->delete( $sync );
		$this->bots->delete( $second );

		$this->assertTrue( $this->execute_ability( Abilities::LIST_BOTS, [] )['bots'][0]['default'] );

		$asked = $this->execute_ability( Abilities::ASK, [ 'question' => 'How many adults avoid cash?' ] );

		$this->assertSame( 'done', $asked['status'] );
		$this->assertSame( $async, (int) get_comment_meta( $asked['question_id'], Storage::META_BOT_ID, true ) );

		// Listing needs Chat access too.
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertWPError( $this->execute_ability( Abilities::LIST_BOTS, [] ) );
	}

	/**
	 * A follow-up continues the conversation.
	 *
	 * @group chat
	 */
	public function test_follow_up() {
		$this->bots->create( 'First', Bots::MODE_SYNC );

		$first  = $this->execute_ability( Abilities::ASK, [ 'question' => 'How many adults do not use cash?' ] );
		$second = $this->execute_ability(
			Abilities::ASK,
			[
				'question'        => 'And last year?',
				'conversation_id' => $first['conversation_id'],
			]
		);

		$this->assertSame( $first['conversation_id'], $second['conversation_id'] );
		$this->assertNotSame( $first['question_id'], $second['question_id'] );
	}

	/**
	 * Access is Chat's: without the ask capability, both abilities are denied; a follow-up to
	 * someone else's conversation is denied.
	 *
	 * @group chat
	 */
	public function test_access() {
		$bot_id = $this->bots->create( 'Bot', Bots::MODE_SYNC );
		$other  = $this->execute_ability(
			Abilities::ASK,
			[
				'question' => 'How many adults do not use cash?',
				'bot_id'   => $bot_id,
			]
		);

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertWPError( $this->execute_ability( Abilities::ASK, [ 'question' => 'Cash?' ] ) );
		$this->assertWPError( $this->execute_ability( Abilities::SEARCH, [ 'query' => 'cash' ] ) );

		// An author can ask, but not add to someone else's conversation (editors see everyone's,
		// as on Ask).
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertSame(
			'done',
			$this->execute_ability(
				Abilities::ASK,
				[
					'question' => 'How many adults do not use cash?',
					'bot_id'   => $bot_id,
				]
			)['status']
		);
		$this->assertWPError(
			$this->execute_ability(
				Abilities::ASK,
				[
					'question'        => 'And last year?',
					'conversation_id' => $other['conversation_id'],
				]
			)
		);
	}
}
