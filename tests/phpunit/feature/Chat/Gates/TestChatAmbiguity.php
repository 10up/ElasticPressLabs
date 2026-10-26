<?php
/**
 * Test the Ambiguity_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Clarifier;
use ElasticPressLabs\Feature\Chat\Gates\Ambiguity_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Injection_Defense_Gate;

/**
 * Ambiguity_Gate tests.
 */
class TestChatAmbiguity extends \WP_UnitTestCase {

	/**
	 * Gate under test.
	 *
	 * @var Ambiguity_Gate
	 */
	protected $gate;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();
		$this->gate = new Ambiguity_Gate();
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the gate on a question and titled, dated passages.
	 *
	 * @param string  $question Question.
	 * @param array[] $passages `[ title, date ]` pairs.
	 * @return \ElasticPressLabs\Feature\Chat\Gates\Gate_Result
	 */
	protected function run_gate( string $question, array $passages ) {
		return $this->gate->run(
			[
				'question' => $question,
				'passages' => array_map(
					static function ( $pair ) {
						return [
							'title'   => $pair[0],
							'date'    => $pair[1],
							'excerpt' => 'Some text.',
						];
					},
					$passages
				),
			]
		);
	}

	/**
	 * Waves of one report series, each titled with its survey year.
	 *
	 * @return array[]
	 */
	protected function commuter_waves(): array {
		return [
			[ 'Commuters, Transit and Technology 2024', 'December 12, 2024' ],
			[ 'Commuters, Transit and Technology 2022', 'August 10, 2022' ],
			[ 'Commuters, Transit and Technology 2018', 'May 31, 2018' ],
			[ 'Remote Work Shifts Transit Use and Other Daily Trips', 'April 9, 2015' ],
		];
	}

	/**
	 * The gate is optional, off by default (it trades newest-wave answers for ask-backs), and
	 * runs on the passages before the answer.
	 *
	 * @group chat
	 */
	public function test_is_optional_default_off_before_the_answer() {
		$this->assertFalse( $this->gate->is_required() );
		$this->assertFalse( $this->gate->is_enabled_by_default() );
		$this->assertSame( Gate::STAGE_FILTER_PASSAGES, $this->gate->get_stage() );
		$this->assertNotContains( Ambiguity_Gate::SLUG, Gates::get_default_optional() );
	}

	/**
	 * It is registered right after injection defense, so a planted instruction is refused before
	 * any wave is offered, and before the answer gates.
	 *
	 * @group chat
	 */
	public function test_registered_after_injection_defense() {
		$slugs = array_map(
			static function ( $gate ) {
				return $gate->get_slug();
			},
			Gates::registry()->get_for_stage( Gate::STAGE_FILTER_PASSAGES )
		);

		$this->assertSame( [ 'empty_retrieve', Injection_Defense_Gate::SLUG, Ambiguity_Gate::SLUG ], $slugs );
	}

	/**
	 * A yearless question over several waves of one report is stopped with the waves offered.
	 *
	 * @group chat
	 */
	public function test_yearless_question_over_several_waves_asks_back() {
		$result = $this->run_gate( 'What share of teens say they use TikTok?', $this->commuter_waves() );

		$this->assertTrue( $result->is_refuse() );
		$this->assertSame( Ambiguity_Gate::REASON, $result->get_reason() );

		$clarify = $result->get_context()['clarify'];

		$this->assertStringContainsString( '2024 (Commuters, Transit and Technology 2024)', $clarify['prompt'] );
		$this->assertStringContainsString( '2018 (Commuters, Transit and Technology 2018)', $clarify['prompt'] );
		$this->assertSame(
			[
				'What share of teens say they use TikTok, in the 2024 survey?',
				'What share of teens say they use TikTok, in the 2022 survey?',
				'What share of teens say they use TikTok, in the 2018 survey?',
			],
			$clarify['options']
		);
	}

	/**
	 * A long question is shortened in the options so each still ends with its own year and the
	 * Clarifier keeps them apart (a 332-character question).
	 *
	 * @group chat
	 */
	public function test_long_question_keeps_one_option_per_wave() {
		$question = 'Gender chart for the commuting piece. ' . str_repeat( 'Which of the travel problems are men more likely than women to run into, and the other way round? ', 3 );
		$result   = $this->run_gate( $question, $this->commuter_waves() );
		$offer    = Clarifier::from_gate( $result->get_context() );

		$this->assertGreaterThan( Ambiguity_Gate::MAX_OPTION_LENGTH, mb_strlen( $question ) );
		$this->assertIsArray( $offer );
		$this->assertCount( 3, $offer['options'] );

		foreach ( [ 2024, 2022, 2018 ] as $i => $year ) {
			$this->assertStringEndsWith( "…, in the {$year} survey?", $offer['options'][ $i ] );
			$this->assertLessThanOrEqual( Ambiguity_Gate::MAX_OPTION_LENGTH, mb_strlen( $offer['options'][ $i ] ) );
		}
	}

	/**
	 * A long question in a non-Latin script is shortened without cutting a character in half,
	 * so the options are still offered, and so is a family title in that script.
	 *
	 * @group chat
	 */
	public function test_long_non_latin_question_keeps_its_options() {
		$question = str_repeat( 'мир ', 90 ) . '?';
		$result   = $this->run_gate( $question, [ [ 'Опрос про мир 2024', 'December 12, 2024' ], [ 'Опрос про мир 2022', 'August 10, 2022' ] ] );
		$offer    = Clarifier::from_gate( $result->get_context() );

		$this->assertTrue( $result->is_refuse() );
		$this->assertSame( 'Опрос про мир', $result->get_context()['ambiguity']['title'] );
		$this->assertIsArray( $offer );
		$this->assertCount( 2, $offer['options'] );
		$this->assertStringEndsWith( 'мир…, in the 2024 survey?', $offer['options'][0] );
	}

	/**
	 * The publication year is read from dotted site date formats too.
	 *
	 * @group chat
	 */
	public function test_wave_year_from_dotted_dates() {
		$this->assertSame( 2016, $this->gate->read_wave_year( 'Commuter trends', '12.05.2016' ) );
		$this->assertSame( 2016, $this->gate->read_wave_year( 'Commuter trends', '2016.05.12' ) );
		$this->assertSame( 2014, $this->gate->read_wave_year( 'Commuter trends 2014', '22.10.2014' ) );
	}

	/**
	 * A year or a wave word in the question picks the wave: the gate passes.
	 *
	 * @group chat
	 */
	public function test_year_or_wave_word_in_the_question_passes() {
		$this->assertFalse( $this->run_gate( 'In 2024, what share of teens say they use TikTok?', $this->commuter_waves() )->is_refuse() );
		$this->assertFalse( $this->run_gate( 'In Commuters, Transit and Technology 2022, what share of teens said they use TikTok?', $this->commuter_waves() )->is_refuse() );
		$this->assertFalse( $this->run_gate( 'What is the latest share of teens who use TikTok?', $this->commuter_waves() )->is_refuse() );
		$this->assertFalse( $this->run_gate( 'What do recent surveys say about teen TikTok use?', $this->commuter_waves() )->is_refuse() );
		$this->assertFalse( $this->run_gate( 'What share of teens use TikTok now?', $this->commuter_waves() )->is_refuse() );
		$this->assertFalse( $this->run_gate( 'How has teen TikTok use changed since 2018?', $this->commuter_waves() )->is_refuse() );

		// A year followed by a comma is still a year; "2,000" and "2016.5" are not years.
		$this->assertTrue( $this->gate->is_disambiguated( 'In Transit Survey Update 2016, what share used bike lanes?' ) );
		$this->assertFalse( $this->gate->is_disambiguated( 'Do 2,000 teens use TikTok?' ) );
		$this->assertFalse( $this->gate->is_disambiguated( 'Is the index at 2016.5?' ) );
	}

	/**
	 * A follow-up turn passes: the earlier turns already fixed the wave.
	 *
	 * @group chat
	 */
	public function test_follow_up_turn_passes() {
		$passages = array_map(
			static function ( $pair ) {
				return [
					'title'   => $pair[0],
					'date'    => $pair[1],
					'excerpt' => 'Some text.',
				];
			},
			$this->commuter_waves()
		);

		$result = $this->gate->run(
			[
				'question' => 'And how does that split by gender?',
				'passages' => $passages,
				'history'  => [
					[
						'role'    => 'user',
						'content' => 'In 2024, what share of teens use TikTok?',
					],
				],
			]
		);

		$this->assertFalse( $result->is_refuse() );
	}

	/**
	 * One wave of each report, or no passages, is not ambiguous.
	 *
	 * @group chat
	 */
	public function test_single_wave_passes() {
		$single = [
			[ 'Commuters, Transit and Technology 2024', 'December 12, 2024' ],
			[ 'Commuters and Bike Lanes Today', 'May 9, 2024' ],
			[ 'How Riders and Drivers Share the Road', 'March 11, 2024' ],
		];

		$this->assertFalse( $this->run_gate( 'What share of teens say they use TikTok?', $single )->is_refuse() );
		$this->assertFalse( $this->run_gate( 'What share of teens say they use TikTok?', [] )->is_refuse() );
	}

	/**
	 * Generic page titles (Methodology, Appendix) from different years are not waves of one
	 * report, and a projection year in a title is not a wave.
	 *
	 * @group chat
	 */
	public function test_generic_titles_and_projection_years_do_not_count() {
		$generic = [
			[ 'Methodology', 'December 12, 2024' ],
			[ 'Methodology', 'April 22, 2025' ],
			[ 'Appendix: Detailed tables', 'June 19, 2018' ],
			[ 'Appendix: Detailed tables', 'June 19, 2019' ],
		];

		$this->assertFalse( $this->run_gate( 'How many commuters did we survey for the commuter report?', $generic )->is_refuse() );

		$projection = [
			[ 'Experts Predict the Best and Worst Changes in City Life by 2035', 'June 21, 2023' ],
			[ '2. Projecting city population shares by 2070', 'September 13, 2022' ],
		];

		$this->assertFalse( $this->run_gate( 'Are people more concerned than excited about AI?', $projection )->is_refuse() );
		$this->assertSame( 2023, $this->gate->read_wave_year( 'Experts Predict the Best and Worst Changes in City Life by 2035', 'June 21, 2023' ) );
	}

	/**
	 * Publication years stand in for waves when the titles carry none.
	 *
	 * @group chat
	 */
	public function test_publication_years_make_waves_when_titles_have_none() {
		$result = $this->run_gate(
			'What share of Americans regularly visit a public library?',
			[
				[ 'Library Use Across Age Groups', 'November 14, 2013' ],
				[ 'Library Use Across Age Groups 2016', 'May 26, 2016' ],
				[ 'Library Use Across Age Groups 2018', 'September 10, 2018' ],
			]
		);

		$this->assertTrue( $result->is_refuse() );
		$this->assertSame( [ 2018, 2016, 2013 ], array_keys( $result->get_context()['ambiguity']['waves'] ) );
	}

	/**
	 * The offer a gate carries becomes a clarify result with no model, and a broken one is
	 * ignored so the plain refusal stands.
	 *
	 * @group chat
	 */
	public function test_clarifier_reads_a_gate_offer() {
		$result = $this->run_gate( 'What share of teens say they use TikTok?', $this->commuter_waves() );
		$offer  = Clarifier::from_gate( $result->get_context() );

		$this->assertIsArray( $offer );
		$this->assertSame( '', $offer['model'] );
		$this->assertCount( 3, $offer['options'] );

		$this->assertNull( Clarifier::from_gate( [] ) );
		$this->assertNull(
			Clarifier::from_gate(
				[
					'clarify' => [
						'prompt'  => 'Which?',
						'options' => [ 'Only one' ],
					],
				]
			)
		);
	}

	/**
	 * The refusal code is in the vocabulary with the no-sources kind, so the card lists the
	 * reports found.
	 *
	 * @group chat
	 */
	public function test_refusal_reason_is_declared() {
		$reasons = Gates::refusal_reasons();

		$this->assertArrayHasKey( Ambiguity_Gate::REASON, $reasons );
		$this->assertSame( 'no_sources', $reasons[ Ambiguity_Gate::REASON ]['kind'] );
	}
}
