<?php
/**
 * Test the Scope_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Scope_Gate;

/**
 * Scope_Gate tests.
 */
class TestChatScope extends \WP_UnitTestCase {

	/**
	 * Gate under test.
	 *
	 * @var Scope_Gate
	 */
	protected $gate;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();
		$this->gate = new Scope_Gate();
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the gate.
	 *
	 * @param string   $question Question.
	 * @param string   $answer   Answer.
	 * @param string[] $passages Passage texts.
	 * @return bool Whether the answer passes.
	 */
	protected function passes( string $question, string $answer, array $passages ): bool {
		return ! $this->gate->run(
			[
				'question' => $question,
				'content'  => $answer,
				'passages' => array_map(
					static function ( $text ) {
						return [
							'title'   => 'Report',
							'excerpt' => $text,
						];
					},
					$passages
				),
			]
		)->is_refuse();
	}

	/**
	 * A national figure pinned to the state the question names is refused; the same figure
	 * given for that state in a passage passes.
	 *
	 * @group chat
	 */
	public function test_national_figure_pinned_to_a_state_is_refused() {
		$question = 'How many Ohioans trust local news?';

		$this->assertFalse( $this->passes( $question, 'About **71%** of Ohioans trust local news.', [ 'Nationally, 71% of adults trust local news.' ] ) );
		$this->assertTrue( $this->passes( $question, 'About 71% of Ohioans trust local news [[1]].', [ 'In Ohio, 71% of adults trust local news.' ] ) );

		// Too far apart in the passage sentence: not bound.
		$far = 'In Ohio, the survey that ran in spring asked adults many questions about news, and in the end 71% said so.';
		$this->assertFalse( $this->passes( $question, 'About 71% of Ohioans trust local news.', [ $far ] ) );
	}

	/**
	 * A real comparison of two states the question names passes.
	 *
	 * @group chat
	 */
	public function test_multi_state_comparison_passes() {
		$this->assertTrue(
			$this->passes(
				'Compare Texas and California on trust in local news.',
				'In Texas 64% trust local news, compared with 58% in California.',
				[ 'Texas: 64% trust local news.', 'In California, 58% trust local news.' ]
			)
		);
	}

	/**
	 * The longest place name wins: West Virginia is never also Virginia, New York is one place,
	 * and Washington alone is not the state.
	 *
	 * @group chat
	 */
	public function test_place_names_and_collisions() {
		$this->assertSame( [ 'west virginia', 'west virginia' ], wp_list_pluck( $this->gate->find_places( 'West Virginians and West Virginia' ), 'place' ) );
		$this->assertSame( [ 'virginia', 'west virginia' ], wp_list_pluck( $this->gate->find_places( 'Virginia, not West Virginia' ), 'place' ) );
		$this->assertSame( [ 'new york' ], wp_list_pluck( $this->gate->find_places( 'Most New Yorkers agree' ), 'place' ) );
		$this->assertSame( [], $this->gate->find_places( 'The Washington Post in Washington, D.C.' ) );
		$this->assertSame( [ 'washington' ], wp_list_pluck( $this->gate->find_places( 'Washingtonians say' ), 'place' ) );
		$this->assertSame( [ 'arkansas' ], wp_list_pluck( $this->gate->find_places( 'In Arkansas' ), 'place' ) );

		// A West Virginia figure does not bind to a question about Virginia.
		$this->assertFalse( $this->passes( 'What share of Virginians vote?', 'About 60% of Virginians vote.', [ 'In West Virginia, 60% vote.' ] ) );
	}

	/**
	 * A table row binds its place and its figures at any distance; bare numbers of a
	 * percentage table are percentages.
	 *
	 * @group chat
	 */
	public function test_table_row_binds() {
		$table = "% who trust local news\n| State | Share | Notes on the sample and the weighting used in this very long column |\n| --- | --- | --- |\n| Ohio | 71 | weighted to match the adult population of the state in the latest census |";

		$this->assertTrue( $this->passes( 'Trust in local news in Ohio?', 'In Ohio, 71% trust local news.', [ $table ] ) );
		$this->assertFalse( $this->passes( 'Trust in local news in Iowa?', 'In Iowa, 71% trust local news.', [ $table ] ) );
	}

	/**
	 * Without statistics, a passage must have data for the place; a national-marked sentence
	 * is not checked; a question without a place passes.
	 *
	 * @group chat
	 */
	public function test_no_statistics_national_sentences_and_no_place() {
		$national = [ 'Nationally, 71% of adults trust local news.' ];

		$this->assertFalse( $this->passes( 'Do Ohioans trust local news?', 'Most Ohioans trust local news.', $national ) );
		$this->assertTrue( $this->passes( 'Do Ohioans trust local news?', 'Most Ohioans trust local news.', [ 'In Ohio, 71% trust it.' ] ) );
		$this->assertTrue( $this->passes( 'Do Ohioans trust local news?', 'Nationally, 71% of adults trust local news; the sources have no Ohio figure.', $national ) );
		// Only the statistic given for the place is checked.
		$this->assertTrue( $this->passes( 'Do Ohioans trust local news?', 'Nationally 71% trust local news, and in Ohio 64% do.', [ 'Nationally, 71% trust local news.', 'In Ohio, 64% trust it.' ] ) );
		$this->assertFalse( $this->passes( 'Do Ohioans trust local news?', 'Nationally 71% trust local news, and in Ohio 64% do.', $national ) );
		$this->assertTrue( $this->passes( 'Do adults trust local news?', 'About 71% of Ohioans trust it.', $national ) );
	}

	/**
	 * List items take the scope of their lead-in line: national figures listed under "U.S.
	 * adults overall:" are not checked; figures listed under "In Ohio:" are. An answer without
	 * statistics that says the sources lack the place is left to Refusal_Gate.
	 *
	 * @group chat
	 */
	public function test_lists_and_honest_declines() {
		$national = [ 'Among U.S. adults, 18% had used ChatGPT in 2023 and 34% in 2025.' ];
		$question = 'What share of Texans have used ChatGPT?';
		$answer   = "The sources do not contain data for Texas; they only provide national figures.\n\nThe sources do cover the share of U.S. adults overall who have used ChatGPT:\n* **2023:** 18%\n* **2025:** 34%";

		$this->assertTrue( $this->passes( $question, $answer, $national ) );
		$this->assertFalse( $this->passes( $question, "In Texas:\n* 2023: 18%\n* 2025: 34%", $national ) );
		$this->assertTrue( $this->passes( $question, 'The sources do not contain data on Texans; they only provide national figures.', $national ) );
		$this->assertTrue( $this->passes( $question, 'About 34% of Americans have used ChatGPT; the sources have no Texas figure.', $national ) );
	}

	/**
	 * Labs registers the gate after Figure_Match_Gate, with its reason and prompt line.
	 *
	 * @group chat
	 */
	public function test_gate_registration() {
		$gate = Gates::registry()->get( Scope_Gate::SLUG );

		$this->assertFalse( $gate->is_required() );
		$this->assertTrue( $gate->is_enabled_by_default() );
		$this->assertArrayHasKey( 'unsupported_subgroup', Gates::refusal_reasons() );
		$this->assertContains(
			'Only attribute a figure to a state when a source gives it for that state; if the sources only have national figures, say so.',
			Gates::instructions( [ 'optional_gates' => [ Scope_Gate::SLUG ] ] )
		);

		add_filter(
			'ep_chat_scope_places',
			static function ( $places ) {
				$places['puerto rico'] = [ 'puerto rico', 'puerto ricans' ];

				return $places;
			}
		);

		$this->assertSame( [ 'puerto rico' ], wp_list_pluck( ( new Scope_Gate() )->find_places( 'Puerto Ricans said' ), 'place' ) );
	}
}
