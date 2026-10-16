<?php
/**
 * Test the Figure_Match_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Client;
use ElasticPressLabs\Feature\Chat\Gates\Figure_Match_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Registry;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Pipeline;

/**
 * Figure_Match_Gate and pipeline tests.
 */
class TestChatFigureMatch extends \WP_UnitTestCase {

	/**
	 * Answer the fake model returns.
	 *
	 * @var string
	 */
	protected $answer = '';

	/**
	 * Setup each test: one retrieved post, a fake model.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Fewer Americans use cash',
				'post_excerpt' => 'Today, 42% of U.S. adults say they do not use cash in a typical week, up from 24% in 2015.',
				'post_status'  => 'publish',
				// A fixed date, so the source's own year (which grounds years) is known.
				'post_date'    => '2022-05-01 10:00:00',
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
			function () {
				return $this->answer;
			}
		);
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the pipeline for a question.
	 *
	 * @param string $answer  Answer from the fake model.
	 * @param array  $history Prior turns.
	 * @param string $question Question.
	 * @return array
	 */
	protected function ask( string $answer, array $history = [], string $question = 'How many adults use cash?' ): array {
		$this->answer = $answer;

		// The answer checks, without the optional model-based ones.
		return ( new Pipeline() )->run(
			$question,
			$history,
			[
				'execution_mode' => 'sync',
				'optional_gates' => [ 'figure_match', 'scope', 'superlative', 'refusal', 'citation_attributor' ],
			]
		);
	}

	/**
	 * Labs registers the gate as optional and on by default, first of the answer checks (after
	 * the question-injection check).
	 *
	 * @group chat
	 */
	public function test_gate_is_optional_and_first_answer_check() {
		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) {
				$registry->register( new FixtureRefuseGate( 'later_verify', Gate::STAGE_VERIFY_ANSWER, 'x', 'x' ) );
			}
		);

		$verify = array_values(
			array_filter(
				Gates::registry()->get_all(),
				static function ( Gate $gate ) {
					// The question-injection check runs before it, so its reason wins.
					return Gate::STAGE_VERIFY_ANSWER === $gate->get_stage() && 'injection_echo' !== $gate->get_slug();
				}
			)
		);

		$this->assertInstanceOf( Figure_Match_Gate::class, $verify[0] );
		$this->assertFalse( $verify[0]->is_required() );
		$this->assertTrue( $verify[0]->is_enabled_by_default() );

		$rows = wp_list_pluck( Gates::describe_for_bot( 0 ), 'required', 'slug' );
		$this->assertFalse( $rows['figure_match'] );
		$this->assertContains( 'figure_match', Gates::get_default_optional() );
		$this->assertArrayHasKey( Figure_Match_Gate::REASON, Gates::refusal_reasons() );
	}

	/**
	 * An answer whose figures are all in the passages is returned.
	 *
	 * @group chat
	 */
	public function test_grounded_answer_passes() {
		$result = $this->ask( 'About 42% of adults do not use cash, up from 24% in 2015 [[1]].' );

		$this->assertSame( 'done', $result['status'] );
		$this->assertStringContainsString( '42%', $result['content'] );
	}

	/**
	 * A figure that no passage contains refuses the whole answer; the draft is not returned,
	 * and the details go to the action.
	 *
	 * @group chat
	 */
	public function test_ungrounded_figure_refuses_without_the_draft() {
		$details = [];
		add_action(
			'ep_chat_ungrounded_figures',
			static function ( $unmatched ) use ( &$details ) {
				$details = $unmatched;
			}
		);

		$draft  = 'About 45% of adults do not use cash, up from 24% in 2015.';
		$result = $this->ask( $draft );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( Figure_Match_Gate::REASON, $result['refusal_reason'] );
		$this->assertSame( Figure_Match_Gate::get_default_message(), $result['content'] );
		$this->assertStringNotContainsString( '45%', wp_json_encode( $result ) );
		$this->assertSame( [ '45%' ], $details );
	}

	/**
	 * A plugin can reword the refusal through the vocabulary filter.
	 *
	 * @group chat
	 */
	public function test_refusal_copy_is_filterable() {
		add_filter(
			'ep_chat_refusal_reasons',
			static function ( $reasons ) {
				$reasons[ Figure_Match_Gate::REASON ]['message'] = 'Custom copy.';
				return $reasons;
			}
		);

		$this->assertSame( 'Custom copy.', $this->ask( 'About 45% agree.' )['content'] );
	}

	/**
	 * Figures from earlier answers or from the question do not ground a new answer.
	 *
	 * @group chat
	 */
	public function test_history_and_question_figures_do_not_ground() {
		$history = [
			[
				'role'    => 'user',
				'content' => 'And in 2018?',
			],
			[
				'role'    => 'assistant',
				'content' => 'In 2018, 31% did not use cash.',
			],
		];

		$this->assertSame( 'refused', $this->ask( 'As noted, 31% in 2018.', $history )['status'] );
		$this->assertSame( 'refused', $this->ask( 'No, it is not 45%: it is 42%.', [], 'Is it 45%?' )['status'] );
	}

	/**
	 * A year written in the question may appear in the answer ("no estimate for 2026");
	 * the question's statistics still do not ground, and other years still must be in a
	 * passage.
	 *
	 * @group chat
	 */
	public function test_question_years_ground_but_question_statistics_do_not() {
		$question = 'In 2026, did 45% of adults stop using cash?';

		$this->assertSame( 'done', $this->ask( 'About 42% do not use cash; the sources have no estimate for 2026.', [], $question )['status'] );
		$this->assertSame( 'refused', $this->ask( 'About 45% do not use cash.', [], $question )['status'] );
		$this->assertSame( 'refused', $this->ask( 'About 42% do not use cash; no estimate for 2027.', [], $question )['status'] );
		// A year needs a year's shape: "2,026" in the question is a count, not a year.
		$this->assertSame( 'refused', $this->ask( 'About 42%, and 2026 said so.', [], 'Did 2,026 adults answer?' )['status'] );
	}

	/**
	 * A source's own year (from its URL or publication date) grounds a year, so "the 2023
	 * report" passes; other years, and other numbers in the URL, do not.
	 *
	 * @group chat
	 */
	public function test_source_years_ground() {
		$this->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Online dating',
				'post_excerpt' => 'Three-in-ten adults have used a dating site or app.',
				'post_status'  => 'publish',
				'post_date'    => '2023-02-02 10:00:00',
			]
		);
		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			},
			20
		);

		$this->assertSame( 'done', $this->ask( 'The 2023 report says three-in-ten adults have used one.' )['status'] );
		$this->assertSame( 'refused', $this->ask( 'The 2019 report says three-in-ten adults have used one.' )['status'] );

		// With plain permalinks the year comes from the date; a post ID in the URL grounds nothing.
		$this->set_permalink_structure( '' );

		$this->assertSame( 'done', $this->ask( 'The 2023 report says three-in-ten adults have used one.' )['status'] );
		$this->assertSame( 'refused', $this->ask( 'Report ' . $post_id . '.5 says three-in-ten.' )['status'] );
	}

	/**
	 * An answer without figures passes.
	 *
	 * @group chat
	 */
	public function test_answer_without_figures_passes() {
		$this->assertSame( 'done', $this->ask( 'Fewer Americans use cash than a decade ago [[1]].' )['status'] );
	}

	/**
	 * The passage title counts as source text; a removed gate does not run.
	 *
	 * @group chat
	 */
	public function test_title_counts_and_gate_can_be_removed() {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Views of 2024 candidates',
				'post_excerpt' => 'Most adults have views.',
				'post_status'  => 'publish',
			]
		);
		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			},
			20
		);

		$this->assertSame( 'done', $this->ask( 'This is about the 2024 candidates.' )['status'] );
		$this->assertSame( 'refused', $this->ask( 'This is about the 2020 candidates.' )['status'] );

		Gates::reset();
		add_action(
			'ep_chat_register_gate',
			static function ( Gate_Registry $registry ) {
				$registry->unregister( Figure_Match_Gate::SLUG );
			}
		);

		$this->assertSame( 'done', $this->ask( 'This is about the 2020 candidates.' )['status'] );
	}

	/**
	 * The default instruction asks the model to copy numbers exactly.
	 *
	 * @group chat
	 */
	public function test_instruction_asks_to_copy_numbers_exactly() {
		$client = new Client();
		$method = new \ReflectionMethod( $client, 'get_system_instruction' );
		$method->setAccessible( true );

		$instruction = $method->invoke(
			$client,
			'Q?',
			[
				[
					'title'   => 'T',
					'excerpt' => 'E',
				],
			],
			[ 'optional_gates' => [ Figure_Match_Gate::SLUG ] ]
		);

		$this->assertStringContainsString( 'Copy every number exactly as it is written in the sources.', $instruction );
	}
}
