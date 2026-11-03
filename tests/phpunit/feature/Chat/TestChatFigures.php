<?php
/**
 * Test the Chat figures helper
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Figures;

/**
 * Figures extraction, normalization, and matching.
 */
class TestChatFigures extends \WP_UnitTestCase {

	/**
	 * Figures helper.
	 *
	 * @var Figures
	 */
	protected $figures;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->figures = new Figures();
	}

	/**
	 * Answer figures that the passage does not ground.
	 *
	 * @param string $answer  Answer.
	 * @param string $passage Passage.
	 * @return string[]
	 */
	protected function unmatched( string $answer, string $passage ): array {
		return $this->figures->unmatched( $answer, [ $passage ] );
	}

	/**
	 * Percentages, points, magnitudes, decimals, and counts of two or more digits are
	 * figures; single-digit counts are not.
	 *
	 * @group chat
	 */
	public function test_extracts_figures() {
		$this->assertSame(
			[ '7%', '3.5%', '5 ppt', '3.4 million', '2 billion', '1502', '34', '2024', '2.5' ],
			$this->figures->extract( '7%, 3.5 percent, 5 percentage points, 3.4 million, 2 billion, 1,502 people, 34 states in 2024, 2.5 times, and 3 groups.' )
		);
	}

	/**
	 * Every spelling of a percentage is the same figure.
	 *
	 * @group chat
	 */
	public function test_percentage_spellings_match() {
		foreach ( [ '34%', '34 %', '34 percent', '34 per cent', '34 Percent', '34PERCENT', '**34%**', '34.0%', '３４%', "34\u{00A0}%" ] as $spelling ) {
			$this->assertSame( [], $this->unmatched( "About {$spelling} of adults agree.", 'Some 34% of U.S. adults say so.' ), $spelling );
		}
	}

	/**
	 * Numbers with and without thousands separators, and decimals written differently.
	 *
	 * @group chat
	 */
	public function test_separators_and_decimals_match() {
		$this->assertSame( [], $this->unmatched( 'We surveyed 9,750 adults.', 'A total of 9750 adults took part.' ) );
		$this->assertSame( [], $this->unmatched( 'It rose to 3.50%.', 'It was 3.5% last year.' ) );
		$this->assertSame( [], $this->unmatched( 'About 1,234,567 people.', 'Count: 1234567.' ) );
	}

	/**
	 * A figure is never grounded by a longer or different figure that contains it.
	 *
	 * @group chat
	 */
	public function test_whole_figures_only() {
		$this->assertSame( [ '7%' ], $this->unmatched( 'Only 7% agree.', 'About 37% agree.' ) );
		$this->assertSame( [ '342' ], $this->unmatched( 'We asked 342 people.', 'We asked 1,342 people.' ) );
		$this->assertSame( [ '50 million' ], $this->unmatched( 'About 50 million adults.', 'Since 1950, the number grew.' ) );
		$this->assertSame( [ '3.4 million' ], $this->unmatched( 'About 3.4 million people.', 'About 3.4 billion people.' ) );
		$this->assertSame( [ '5%' ], $this->unmatched( 'It rose 5%.', 'It rose 5 percentage points.' ) );
		$this->assertSame( [ '5 ppt' ], $this->unmatched( 'It rose 5 percentage points.', 'It is 5% now.' ) );
		$this->assertSame( [ '45' ], $this->unmatched( 'In 45 states.', 'In 45.5% of cases.' ) );
	}

	/**
	 * Citation markers, Markdown list numbers, and URLs are not figures.
	 *
	 * @group chat
	 */
	public function test_markers_list_numbers_and_urls_are_ignored() {
		$answer = "10. First point [[12]].\n11) Second point [[ 3 ]].\nSee https://example.org/2026/07/23/report-2024/ and www.example.org/99.";

		$this->assertSame( [], $this->figures->extract( $answer ) );
	}

	/**
	 * Several figures with one missing: only the missing one is reported; no figures pass.
	 *
	 * @group chat
	 */
	public function test_reports_only_missing_figures() {
		$passage = 'In 2026, 42% of U.S. adults said they do not use cash, up from 24% in 2015.';

		// Years are figures too: 2018 is not in the passage.
		$this->assertSame( [ '31%', '2018' ], $this->unmatched( 'In 2026, 42% did not use cash (up from 24% in 2015, and 31% in 2018).', $passage ) );
		$this->assertSame( [], $this->unmatched( 'Fewer Americans use cash than before.', $passage ) );
		$this->assertSame( [ '31%' ], $this->unmatched( '31% said so, and 31% again.', $passage ) );
	}

	/**
	 * A number cell of a percentage table grounds that percentage; whole cells only.
	 *
	 * @group chat
	 */
	public function test_percentage_table_cells_ground_percentages() {
		$passage = "Cash use\n% of U.S. adults who say they use cash for …\n| | All or almost all | Some | None |\n| --- | --- | --- | --- |\n| 2026 | 12 | 46 | 42 |\n| 2015 | 24 | 52 | 24 |";

		$this->assertSame( [], $this->unmatched( 'In 2026, 12% used cash for almost all purchases and 46% for some.', $passage ) );
		$this->assertSame( [ '37%' ], $this->unmatched( 'About 37% used cash.', "| Year | Share |\n| --- | --- |\n| 2026 | 7% |" ) );
		$this->assertSame( [ '6%' ], $this->unmatched( 'About 6% used cash.', $passage ) );

		// Without a percentage marker, a bare cell does not ground a percentage.
		$counts = "Respondents\n| Group | Sample |\n| --- | --- |\n| Adults | 12 |";
		$this->assertSame( [ '12%' ], $this->unmatched( 'About 12% of adults.', $counts ) );
		$this->assertSame( [], $this->unmatched( 'A sample of 12 adults.', $counts ) );

		// The `%` can be in the header row itself.
		$this->assertSame( [], $this->unmatched( 'About 12% of adults.', "| Group | % |\n| --- | --- |\n| Adults | 12 |" ) );
	}

	/**
	 * Figures in any of several passages count.
	 *
	 * @group chat
	 */
	public function test_any_passage_grounds() {
		$this->assertSame( [], $this->figures->unmatched( '42% and 3,554 adults.', [ 'About 42% agree.', 'Sample size: 3554' ] ) );
	}

	/**
	 * locate() gives each figure as written, its kind, and its position; markers, URLs, and
	 * list numbers are skipped, and the figures match extract().
	 *
	 * @group chat
	 */
	public function test_locate_figures_and_kinds() {
		$text  = '10. About **42%** of adults (9,750) in 2026, up 5 percentage points; 3.4 million people, 34 states, 2,024 votes, 2.5 times [[3]] https://x.test/2026/12';
		$found = $this->figures->locate( $text );

		$this->assertSame(
			[
				[ '42%', 'percent', '42%' ],
				[ '9750', 'large_count', '9,750' ],
				[ '2026', 'year', '2026' ],
				[ '5 ppt', 'ppt', '5 percentage points' ],
				[ '3.4 million', 'magnitude', '3.4 million' ],
				[ '34', 'count', '34' ],
				[ '2024', 'large_count', '2,024' ],
				[ '2.5', 'decimal', '2.5' ],
			],
			array_map(
				static function ( $figure ) {
					return [ $figure['figure'], $figure['kind'], $figure['text'] ];
				},
				$found
			)
		);

		foreach ( $found as $figure ) {
			$this->assertSame( $figure['text'], substr( $text, $figure['start'], $figure['end'] - $figure['start'] ) );
		}

		$this->assertSame( $this->figures->extract( $text ), wp_list_pluck( $found, 'figure' ) );
	}
}
