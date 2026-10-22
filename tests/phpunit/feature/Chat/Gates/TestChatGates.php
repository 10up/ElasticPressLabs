<?php
/**
 * Test Chat gates
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Client;
use ElasticPressLabs\Feature\Chat\Gates\Base_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Empty_Retrieve_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Registry;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Result;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Refusal_Gate;
use ElasticPressLabs\Feature\Chat\Pipeline;

/**
 * Chat gate registry and seam tests.
 */
class TestChatGates extends \WP_UnitTestCase {
	/**
	 * Reset the singleton.
	 */
	public function set_up() {
		parent::set_up();

		Gates::reset();
	}

	/**
	 * Reset the singleton.
	 */
	public function tear_down() {
		Gates::reset();

		parent::tear_down();
	}

	/**
	 * Labs ships the required empty-retrieve gate.
	 *
	 * @group chat
	 */
	public function test_registry_includes_empty_retrieve() {
		$gate = Gates::registry()->get( Empty_Retrieve_Gate::SLUG );

		$this->assertInstanceOf( Empty_Retrieve_Gate::class, $gate );
		$this->assertTrue( $gate->is_required() );
		$this->assertSame( Gate::STAGE_FILTER_PASSAGES, $gate->get_stage() );
	}

	/**
	 * Plugins can add, replace, and remove gates.
	 *
	 * @group chat
	 */
	public function test_register_replace_and_unregister() {
		$optional = new FixtureOptionalGate();

		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) use ( $optional ) {
				$registry->register( $optional );
			}
		);

		$registry = Gates::registry();
		$this->assertSame( $optional, $registry->get( 'fixture_optional' ) );

		$replacement = new FixtureRefuseGate(
			'fixture_optional',
			Gate::STAGE_BEFORE_ASK,
			'blocked',
			'No'
		);
		$registry->replace( 'fixture_optional', $replacement );
		$this->assertSame( $replacement, $registry->get( 'fixture_optional' ) );

		$order = wp_list_pluck( $registry->summarize(), 'slug' );
		$first = new FixtureRefuseGate( Empty_Retrieve_Gate::SLUG, Gate::STAGE_FILTER_PASSAGES, 'blocked', 'No' );
		$registry->replace( Empty_Retrieve_Gate::SLUG, $first );
		$this->assertSame( $first, $registry->get( Empty_Retrieve_Gate::SLUG ) );
		$this->assertSame( $order, wp_list_pluck( $registry->summarize(), 'slug' ), 'A replaced gate keeps its place.' );

		$registry->unregister( 'fixture_optional' );
		$this->assertNull( $registry->get( 'fixture_optional' ) );
	}

	/**
	 * A gate that reuses another gate's test uses the registered one, so a replacement is honored.
	 *
	 * @group chat
	 */
	public function test_get_gate_uses_the_registered_gate() {
		$replacement = new class() extends Refusal_Gate {};

		Gates::registry()->replace( Refusal_Gate::SLUG, $replacement );
		$this->assertSame( $replacement, Gates::get_gate( Refusal_Gate::class ) );

		Gates::registry()->unregister( Refusal_Gate::SLUG );
		$this->assertInstanceOf( Refusal_Gate::class, Gates::get_gate( Refusal_Gate::class ), 'A removed gate still lends its test.' );
	}

	/**
	 * Optional gates stay off until the bot enables them.
	 *
	 * @group chat
	 */
	public function test_optional_gate_is_dark_by_default() {
		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) {
				$registry->register( new FixtureOptionalGate() );
			}
		);

		Gates::reset();
		$gate = Gates::registry()->get( 'fixture_optional' );

		$this->assertFalse( Gates::should_run( $gate, [ 'optional_gates' => [] ] ) );
		$this->assertTrue(
			Gates::should_run( $gate, [ 'optional_gates' => [ 'fixture_optional' ] ] )
		);
	}

	/**
	 * Empty retrieve refuses with no_hits and skips the LLM.
	 *
	 * @group chat
	 */
	public function test_empty_retrieve_gate_refuses_with_reason() {
		add_filter( 'ep_chat_retrieve', '__return_empty_array' );

		$called = false;
		add_filter(
			'ep_chat_generate_text',
			static function () use ( &$called ) {
				$called = true;

				return 'should not run';
			}
		);

		$result = ( new Pipeline() )->run(
			'What is ElasticPress?',
			[],
			[
				'id'             => 1,
				'execution_mode' => 'sync',
			]
		);

		$this->assertFalse( $called );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( Empty_Retrieve_Gate::REASON, $result['refusal_reason'] );
		$this->assertSame( [], $result['hits'] );
	}

	/**
	 * The named Chat filters are applied.
	 *
	 * @group chat
	 */
	public function test_named_filters_are_applied() {
		$seen = [];

		add_filter(
			'ep_chat_before_ask',
			static function ( $context ) use ( &$seen ) {
				$seen[]            = 'before_ask';
				$context['marked'] = true;

				return $context;
			}
		);
		add_filter(
			'ep_chat_filter_passages',
			static function ( $passages ) use ( &$seen ) {
				$seen[] = 'filter_passages';

				return $passages;
			}
		);
		add_filter(
			'ep_chat_verify_answer',
			static function ( $context ) use ( &$seen ) {
				$seen[] = 'verify_answer';

				return $context;
			}
		);
		add_filter(
			'ep_chat_message_meta',
			static function ( $payload ) use ( &$seen ) {
				$seen[]                 = 'message_meta';
				$payload['from_filter'] = true;

				return $payload;
			}
		);
		add_filter(
			'ep_chat_refusal_reasons',
			static function ( $reasons ) use ( &$seen ) {
				$seen[]           = 'refusal_reasons';
				$reasons['extra'] = [ 'label' => 'Extra' ];

				return $reasons;
			}
		);

		$post_id = $this->factory()->post->create(
			[
				'post_title'  => 'Guide',
				'post_status' => 'publish',
			]
		);
		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'Grounded';
			}
		);

		$result  = ( new Pipeline() )->run( 'What is ElasticPress?', [], [ 'execution_mode' => 'sync' ] );
		$reasons = Gates::refusal_reasons();

		$this->assertSame( 'done', $result['status'] );
		$this->assertContains( 'before_ask', $seen );
		$this->assertContains( 'filter_passages', $seen );
		$this->assertContains( 'verify_answer', $seen );
		$this->assertContains( 'message_meta', $seen );
		$this->assertContains( 'refusal_reasons', $seen );
		$this->assertArrayHasKey( 'extra', $reasons );
		$this->assertTrue( $result['hits'] && isset( $result['hits'][0] ) );
	}

	/**
	 * Synthesis prompt filter is applied when building the instruction.
	 *
	 * @group chat
	 */
	public function test_synthesis_prompt_filter() {
		add_filter(
			'ep_chat_synthesis_prompt',
			static function ( $instruction ) {
				return $instruction . ' NEVER-INVENT';
			}
		);

		$method = new \ReflectionMethod( Client::class, 'get_system_instruction' );
		$method->setAccessible( true );

		$instruction = $method->invoke( new Client(), 'What is ElasticPress?', [], [] );

		$this->assertStringContainsString( 'NEVER-INVENT', $instruction );
	}

	/**
	 * A before-ask refusal stops retrieve and synthesis.
	 *
	 * @group chat
	 */
	public function test_before_ask_refusal_stops_pipeline() {
		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) {
				$registry->register(
					new FixtureRefuseGate(
						'block_ask',
						Gate::STAGE_BEFORE_ASK,
						'blocked',
						'Not allowed.'
					)
				);
			}
		);

		$retrieved = false;
		add_filter(
			'ep_chat_retrieve',
			static function () use ( &$retrieved ) {
				$retrieved = true;

				return [];
			}
		);

		$result = ( new Pipeline() )->run( 'What is ElasticPress?', [], [ 'execution_mode' => 'sync' ] );

		$this->assertFalse( $retrieved );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'blocked', $result['refusal_reason'] );
		$this->assertSame( 'Not allowed.', $result['content'] );
	}

	/**
	 * A verify-answer refusal replaces a generated reply.
	 *
	 * @group chat
	 */
	public function test_verify_answer_refusal() {
		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) {
				$registry->register(
					new FixtureRefuseGate(
						'block_verify',
						Gate::STAGE_VERIFY_ANSWER,
						'ungrounded',
						'Could not verify.'
					)
				);
			}
		);

		$post_id = $this->factory()->post->create(
			[
				'post_title'  => 'Guide',
				'post_status' => 'publish',
			]
		);
		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'Draft answer';
			}
		);

		$result = ( new Pipeline() )->run( 'What is ElasticPress?', [], [ 'execution_mode' => 'sync' ] );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'ungrounded', $result['refusal_reason'] );
		$this->assertSame( 'Could not verify.', $result['content'] );
	}

	/**
	 * A synthesis-prompt refusal skips the LLM.
	 *
	 * @group chat
	 */
	public function test_synthesis_prompt_refusal() {
		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) {
				$registry->register(
					new FixtureRefuseGate(
						'block_prompt',
						Gate::STAGE_SYNTHESIS_PROMPT,
						'blocked',
						'Prompt blocked.'
					)
				);
			}
		);

		$post_id = $this->factory()->post->create(
			[
				'post_title'  => 'Guide',
				'post_status' => 'publish',
			]
		);
		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);

		$result = ( new Pipeline() )->run( 'What is ElasticPress?', [], [ 'execution_mode' => 'sync' ] );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'blocked', $result['refusal_reason'] );
		$this->assertSame( 'Prompt blocked.', $result['content'] );
	}

	/**
	 * A gate declares its refusal reasons: they join the vocabulary without the filter, and
	 * refuse() uses the (filtered) copy.
	 *
	 * @group chat
	 */
	public function test_gate_declares_its_refusal_reasons() {
		$gate = new class() extends Base_Gate {
			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function get_slug(): string {
				return 'declares_reason';
			}

			/**
			 * Label.
			 *
			 * @return string
			 */
			public function get_label(): string {
				return 'Declares reason';
			}

			/**
			 * Stage.
			 *
			 * @return string
			 */
			public function get_stage(): string {
				return self::STAGE_BEFORE_ASK;
			}

			/**
			 * Required.
			 *
			 * @return bool
			 */
			public function is_required(): bool {
				return true;
			}

			/**
			 * Always refuse.
			 *
			 * @param array $context Context.
			 * @return Gate_Result
			 */
			public function run( array $context ): Gate_Result {
				return $this->refuse( 'own_reason' );
			}

			/**
			 * Reasons.
			 *
			 * @return array
			 */
			public function get_refusal_reasons(): array {
				return [
					'own_reason' => [
						'label'   => 'Own reason',
						'message' => 'Own copy.',
					],
				];
			}
		};

		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) use ( $gate ) {
				$registry->register( $gate );
			}
		);

		$this->assertSame( 'Own copy.', Gates::refusal_reasons()['own_reason']['message'] );
		$this->assertSame( 'Own copy.', $gate->run( [] )->get_message() );

		add_filter(
			'ep_chat_refusal_reasons',
			static function ( $reasons ) {
				$reasons['own_reason']['message'] = 'Reworded.';
				return $reasons;
			}
		);

		$this->assertSame( 'Reworded.', $gate->run( [] )->get_message() );
		$this->assertSame( 'own_reason', $gate->run( [] )->get_reason() );
	}

	/**
	 * Only the gates that run for a bot add their instruction lines, once each, in ladder order.
	 *
	 * @group chat
	 */
	public function test_instructions_follow_the_gates_that_run() {
		$make = function ( string $slug, bool $required, array $lines ) {
			return new class( $slug, $required, $lines ) extends Base_Gate {
				/**
				 * Slug.
				 *
				 * @var string
				 */
				protected $slug;

				/**
				 * Required.
				 *
				 * @var bool
				 */
				protected $required;

				/**
				 * Lines.
				 *
				 * @var array
				 */
				protected $lines;

				/**
				 * Constructor.
				 *
				 * @param string $slug     Slug.
				 * @param bool   $required Required.
				 * @param array  $lines    Lines.
				 */
				public function __construct( string $slug, bool $required, array $lines ) {
					$this->slug     = $slug;
					$this->required = $required;
					$this->lines    = $lines;
				}

				/**
				 * Slug.
				 *
				 * @return string
				 */
				public function get_slug(): string {
					return $this->slug;
				}

				/**
				 * Label.
				 *
				 * @return string
				 */
				public function get_label(): string {
					return $this->slug;
				}

				/**
				 * Stage.
				 *
				 * @return string
				 */
				public function get_stage(): string {
					return self::STAGE_VERIFY_ANSWER;
				}

				/**
				 * Required.
				 *
				 * @return bool
				 */
				public function is_required(): bool {
					return $this->required;
				}

				/**
				 * Pass.
				 *
				 * @param array $context Context.
				 * @return Gate_Result
				 */
				public function run( array $context ): Gate_Result {
					return Gate_Result::pass();
				}

				/**
				 * Lines.
				 *
				 * @param array $bot Bot.
				 * @return string[]
				 */
				public function get_instructions( array $bot ): array {
					return $this->lines;
				}
			};
		};

		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) use ( $make ) {
				$registry->register( $make( 'always', true, [ 'Always line.', ' ' ] ) );
				$registry->register( $make( 'optional_lines', false, [ 'Optional line.', 'Always line.' ] ) );
			}
		);

		$lines = Gates::instructions( [ 'optional_gates' => [] ] );
		$this->assertContains( 'Always line.', $lines );
		$this->assertNotContains( 'Optional line.', $lines );

		$lines = Gates::instructions( [ 'optional_gates' => [ 'optional_lines' ] ] );
		$this->assertSame( 1, count( array_keys( $lines, 'Always line.', true ) ) );
		$this->assertGreaterThan( array_search( 'Always line.', $lines, true ), array_search( 'Optional line.', $lines, true ) );

		// The system instruction carries them, grounded or not.
		$client = new Client();
		$method = new \ReflectionMethod( $client, 'get_system_instruction' );
		$method->setAccessible( true );

		$bot = [ 'optional_gates' => [ 'optional_lines' ] ];
		$this->assertStringContainsString( 'Optional line.', $method->invoke( $client, 'Q?', [ [ 'title' => 'T' ] ], $bot ) );
		$this->assertStringContainsString( 'Always line.', $method->invoke( $client, 'Q?', [], $bot ) );
		$this->assertStringNotContainsString( 'Optional line.', $method->invoke( $client, 'Q?', [], [ 'optional_gates' => [] ] ) );
	}

	/**
	 * The bot screen's gate rows: every Labs gate has a plain description, and each stage has a
	 * heading (filterable) in pipeline order.
	 *
	 * @group chat
	 */
	public function test_gate_rows_have_descriptions_and_stage_headings() {
		$rows = Gates::registry()->summarize();

		foreach ( $rows as $row ) {
			$this->assertNotSame( '', $row['description'], $row['slug'] );
			$this->assertNotSame( $row['stage'], $row['stage_label'], $row['slug'] );
		}

		$this->assertSame(
			[ Gate::STAGE_BEFORE_ASK, Gate::STAGE_FILTER_PASSAGES, Gate::STAGE_SYNTHESIS_PROMPT, Gate::STAGE_VERIFY_ANSWER, Gate::STAGE_ATTRIBUTE_ANSWER ],
			array_keys( Gates::get_stage_labels() )
		);
		$this->assertSame( 'Checking the answer', Gates::get_stage_label( Gate::STAGE_VERIFY_ANSWER ) );
		$this->assertSame( 'custom_stage', Gates::get_stage_label( 'custom_stage' ) );

		add_filter(
			'ep_chat_gate_stage_labels',
			static function ( $labels ) {
				$labels[ Gate::STAGE_VERIFY_ANSWER ] = 'After the answer';

				return $labels;
			}
		);

		$this->assertSame( 'After the answer', Gates::get_stage_label( Gate::STAGE_VERIFY_ANSWER ) );

		// A gate without a description (Base_Gate's default) shows none.
		$seen = [];

		$this->assertSame( '', ( new FixtureSpyGate( 'spy', Gate::STAGE_BEFORE_ASK, $seen ) )->get_description() );
	}
}
