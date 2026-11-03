<?php
/**
 * Test Chat citation markers and provenance
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Citations;

/**
 * Citations: marker placement, source choice, quotes, and provenance.
 */
class TestChatCitations extends \WP_UnitTestCase {

	/**
	 * Citations helper.
	 *
	 * @var Citations
	 */
	protected $citations;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->citations = new Citations();
	}

	/**
	 * A passage the model saw.
	 *
	 * @param int    $number  Passage number.
	 * @param string $excerpt Passage text.
	 * @param string $title   Title.
	 * @return array
	 */
	protected function passage( int $number, string $excerpt, string $title = '' ): array {
		return [
			'number'  => $number,
			'title'   => '' === $title ? "Report {$number}" : $title,
			'url'     => "https://example.test/report-{$number}/",
			'date'    => 'May 1, 2026',
			'excerpt' => $excerpt,
		];
	}

	/**
	 * Quote text inside each mark range.
	 *
	 * @param array $entry Provenance entry.
	 * @return string[]
	 */
	protected function marked( array $entry ): array {
		return array_map(
			static function ( $range ) use ( $entry ) {
				return mb_substr( $entry['quote'], $range[0], $range[1] - $range[0], 'UTF-8' );
			},
			$entry['marks']
		);
	}

	/**
	 * A marker goes right after the figure (after closing emphasis); figures of one sentence
	 * from the same source share one marker, after the last; k counts facts in order.
	 *
	 * @group chat
	 */
	public function test_markers_follow_figures_and_share_per_source() {
		$passages = [
			$this->passage( 1, 'Today, 42% of U.S. adults say they do not use cash in a typical week, up from 29% in 2018.' ),
			$this->passage( 2, 'About 3.4 million people answered the survey.' ),
		];

		$result = $this->citations->attribute(
			'About **42%** of adults do not use cash, up from 29% in 2018. About 3.4 million people answered.',
			$passages
		);

		$this->assertSame(
			'About **42%** of adults do not use cash, up from 29%[[1]] in 2018. About 3.4 million[[2]] people answered.',
			$result['content']
		);
		$this->assertSame( [ 1, 2 ], wp_list_pluck( $result['provenance'], 'k' ) );
		$this->assertSame( [ 1, 2 ], wp_list_pluck( $result['provenance'], 'n' ) );
		$this->assertSame( '42%, 29%', $result['provenance'][0]['figure'] );
		$this->assertSame( [ '42%', '29%' ], $this->marked( $result['provenance'][0] ) );

		$single = $this->citations->attribute( 'Some **42%**, a lot.', $passages );
		$this->assertSame( 'Some **42%**[[1]], a lot.', $single['content'] );
	}

	/**
	 * Markers the model wrote are removed; only the attributor cites.
	 *
	 * @group chat
	 */
	public function test_model_markers_are_removed() {
		$result = $this->citations->attribute(
			'The weather is nice [[3]]. Cash is fine [[ 1 ]].',
			[ $this->passage( 1, 'Unrelated text about surveys.' ) ]
		);

		$this->assertSame( 'The weather is nice. Cash is fine.', $result['content'] );
		$this->assertSame( [], $result['provenance'] );
	}

	/**
	 * Among passages with the figure, the one with the most figures of the sentence wins, then
	 * the one with the most shared words, then the first.
	 *
	 * @group chat
	 */
	public function test_source_choice() {
		$passages = [
			$this->passage( 1, 'Some 42% of voters like pizza on weekends.' ),
			$this->passage( 2, 'Today, 42% of U.S. adults say they do not use cash in a typical week.' ),
			$this->passage( 3, 'Today, 42% of U.S. adults say they do not use cash, up from 24% in 2015.' ),
		];

		$words = $this->citations->attribute( 'About 42% of adults do not use cash in a typical week.', array_slice( $passages, 0, 2 ) );
		$this->assertSame( 2, $words['provenance'][0]['n'] );

		$figures = $this->citations->attribute( 'About 42% of adults do not use cash in a typical week, up from 24%.', $passages );
		$this->assertSame( 3, $figures['provenance'][0]['n'] );

		$tie = $this->citations->attribute( 'It is 42%.', [ $passages[1], $passages[2] ] );
		$this->assertSame( 2, $tie['provenance'][0]['n'] );
	}

	/**
	 * The quote is the source sentence with the figure, clipped to 260 characters around it
	 * without cutting words; the context line and headings are never quoted.
	 *
	 * @group chat
	 */
	public function test_quote_is_the_clipped_source_sentence() {
		$long  = str_repeat( 'Lorem ipsum dolor sit amet ', 15 ) . 'and 42% of adults say so ' . str_repeat( 'consectetur adipiscing elit ', 15 ) . 'today.';
		$chunk = "Report 1 › Findings 42%\n## A heading with 42%\nIntro sentence here. {$long} Last sentence.";

		$entry = $this->citations->attribute( 'Some 42% of adults say so.', [ $this->passage( 1, $chunk ) ] )['provenance'][0];

		$this->assertLessThanOrEqual( Citations::QUOTE_LENGTH, mb_strlen( $entry['quote'], 'UTF-8' ) );
		$this->assertStringStartsWith( '…', $entry['quote'] );
		$this->assertStringEndsWith( '…', $entry['quote'] );
		$this->assertStringContainsString( 'and 42% of adults say so', $entry['quote'] );
		$this->assertStringNotContainsString( '›', $entry['quote'] );
		$this->assertSame( [ '42%' ], $this->marked( $entry ) );
		$this->assertMatchesRegularExpression( '/^…\S/u', $entry['quote'] );

		$short = $this->citations->attribute( 'Some 42% said so.', [ $this->passage( 1, "Report 1 › Key\nFirst. Then 42% said so. Last." ) ] )['provenance'][0];
		$this->assertSame( 'Then 42% said so.', $short['quote'] );
	}

	/**
	 * A table figure quotes the header and one row per figure; the column comes from the
	 * answer's header words, the row from the year next to the figure.
	 *
	 * @group chat
	 */
	public function test_table_quote_uses_the_column_and_year() {
		$table = "% who make no purchases with cash\n| | Ages 18-29 | 30-49 |\n| --- | --- | --- |\n| 2022 | 46 | 49 |\n| 2026 | 51 | 46 |";
		$src   = [ $this->passage( 1, $table ) ];

		$column = $this->citations->attribute( '* **Ages 30–49:** 49% in 2022; 46% in 2026.', $src )['provenance'][0];

		$this->assertSame( "| | Ages 18-29 | 30-49 |\n| 2022 | 46 | 49 |\n| 2026 | 51 | 46 |", $column['quote'] );
		$this->assertSame( [ '49', '46' ], $this->marked( $column ) );
		// The marks are in the 30-49 column, not the 46 in the 2022 row.
		$this->assertSame( [ [ 39, 41 ], [ 58, 60 ] ], $column['marks'] );
		// A table row cannot be a text fragment: the link goes to the line above the table.
		$this->assertSame(
			'https://example.test/report-1/#:~:text=%25%20who%20make%20no%20purchases%20with%20cash',
			$column['verify_url']
		);

		$year = $this->citations->attribute( 'In 2022, 46% of young adults did.', $src )['provenance'][0];
		$this->assertSame( "| | Ages 18-29 | 30-49 |\n| 2022 | 46 | 49 |", $year['quote'] );
	}

	/**
	 * Citations read percentage tables as Figure_Match_Gate does (after normalization): a caption
	 * with a full-width percent sign marks the table's bare numbers as percentages.
	 *
	 * @group chat
	 */
	public function test_percentage_table_rule_matches_figure_matcher() {
		$table = "Adults who pay with cash (％)\n| Year | Share |\n| --- | --- |\n| 2026 | 12 |";
		$entry = $this->citations->attribute( 'In 2026, 12% paid with cash.', [ $this->passage( 1, $table ) ] )['provenance'][0];

		$this->assertSame( "| Year | Share |\n| 2026 | 12 |", $entry['quote'] );
		$this->assertSame( [ '12' ], $this->marked( $entry ) );
	}

	/**
	 * Years and counts under 1,000 get no marker by default, and do through the filter.
	 *
	 * @group chat
	 */
	public function test_figure_kinds_filter() {
		$src    = [ $this->passage( 1, 'In 2024, 34 states and 1,502 adults took part.' ) ];
		$answer = 'In 2024, 34 states and 1,502 adults took part.';

		$this->assertSame( 'In 2024, 34 states and 1,502[[1]] adults took part.', $this->citations->attribute( $answer, $src )['content'] );

		add_filter(
			'ep_chat_citation_figure_kinds',
			static function ( $kinds ) {
				return array_merge( $kinds, [ 'year', 'count' ] );
			}
		);

		$this->assertSame( 'In 2024, 34 states and 1,502[[1]] adults took part.', $this->citations->attribute( $answer, $src )['content'] );
		$this->assertSame( '2024, 34, 1,502', $this->citations->attribute( $answer, $src )['provenance'][0]['figure'] );
	}

	/**
	 * A sentence without marked figures is cited at its end when it shares three content
	 * words with a passage; headings and list intros are not.
	 *
	 * @group chat
	 */
	public function test_qualitative_sentences() {
		$src    = [ $this->passage( 1, 'Younger adults are especially likely to go cashless. Other text.' ) ];
		$result = $this->citations->attribute(
			"## Younger adults cashless\nBreakdown for younger adults going cashless:\nYounger adults are likely to go cashless. The weather is nice.",
			$src
		);

		$this->assertSame(
			"## Younger adults cashless\nBreakdown for younger adults going cashless:\nYounger adults are likely to go cashless[[1]]. The weather is nice.",
			$result['content']
		);
		$this->assertSame( '', $result['provenance'][0]['figure'] );
		$this->assertSame( 'Younger adults are especially likely to go cashless.', $result['provenance'][0]['quote'] );
		$this->assertSame( [], $result['provenance'][0]['marks'] );
	}

	/**
	 * A sentence that says what the sources lack is never cited, even when it shares words
	 * with a passage; the figures after it are.
	 *
	 * @group chat
	 */
	public function test_sentences_about_missing_data_are_not_cited() {
		$src    = [ $this->passage( 1, 'Among U.S. adults, 42% say they do not use cash in a typical week.' ) ];
		$result = $this->citations->attribute(
			'The sources do not contain information about adults in Canada who use cash in a typical week. However, 42% of U.S. adults do not use cash.',
			$src
		);

		$this->assertSame(
			'The sources do not contain information about adults in Canada who use cash in a typical week. However, 42%[[1]] of U.S. adults do not use cash.',
			$result['content']
		);

		// "Do not address" as well as "does not address".
		$this->assertSame(
			'The provided sources do not address how adults in Canada use cash in a typical week.',
			$this->citations->attribute( 'The provided sources do not address how adults in Canada use cash in a typical week.', $src )['content']
		);
	}

	/**
	 * Provenance carries the source and a text-fragment link to the sentence.
	 *
	 * @group chat
	 */
	public function test_provenance_and_verify_url() {
		$src   = [ $this->passage( 1, 'Roughly three-in-ten U.S. adults (29%) say they make no purchases using cash, up from 24% in 2015.', 'Cash & cards' ) ];
		$entry = $this->citations->attribute( 'About 29% of adults.', $src )['provenance'][0];

		$this->assertSame( 'Cash & cards', $entry['title'] );
		$this->assertSame( 'https://example.test/report-1/', $entry['url'] );
		$this->assertSame( 'May 1, 2026', $entry['date'] );
		$this->assertSame(
			'https://example.test/report-1/#:~:text=Roughly%20three%2Din%2Dten%20U.S.%20adults,from%2024%25%20in%202015.',
			$entry['verify_url']
		);
		$this->assertSame(
			'https://example.test/a/#:~:text=Short%2C%20sweet%20%26%20clear.',
			$this->citations->get_verify_url( 'https://example.test/a/#old', 'Short, **sweet** & clear.' )
		);
	}

	/**
	 * A count or decimal whose sentence marker points to a passage without it gets its own
	 * marker, to a passage that has it; list numbers and counts the sentence's source already
	 * has do not.
	 *
	 * @group chat
	 */
	public function test_uncited_figures_get_their_own_passage() {
		$passages = [
			$this->passage( 1, 'In most of the countries surveyed, a median of 58% of adults view the United Nations favorably.', 'Global attitudes' ),
			$this->passage( 2, 'The survey was conducted in 24 countries among 30,861 adults, with a 2.5 point margin.', 'Methodology' ),
		];

		$mixed = $this->citations->attribute( 'Across the 24 countries surveyed, a median of 58% of adults view the United Nations favorably.', $passages );

		$this->assertSame( 'Across the 24[[1]] countries surveyed, a median of 58%[[2]] of adults view the United Nations favorably.', $mixed['content'] );
		$this->assertSame( [ 2, 1 ], wp_list_pluck( $mixed['provenance'], 'n' ) );
		$this->assertSame( '24', $mixed['provenance'][0]['figure'] );

		// A plain sentence: its marker's passage lacks the decimal, so the decimal gets its own.
		$sentence = $this->citations->attribute( 'Views of the United Nations in the countries surveyed have a 2.5 point margin.', $passages );

		$this->assertSame( 'Views of the United Nations in the countries surveyed have a 2.5[[1]] point margin[[2]].', $sentence['content'] );
		$this->assertSame( [ 2, 1 ], wp_list_pluck( $sentence['provenance'], 'n' ) );

		// Unchanged: the marker's passage has the count; a list number is not a figure to cite.
		$covered = $this->citations->attribute( 'The survey was conducted in 24 countries among adults, the methodology says.', $passages );

		$this->assertSame( 'The survey was conducted in 24 countries among adults, the methodology says[[1]].', $covered['content'] );
		$this->assertSame(
			'1. A median of 58%[[1]] of adults view the United Nations favorably.',
			$this->citations->attribute( '1. A median of 58% of adults view the United Nations favorably.', $passages )['content']
		);
	}

	/**
	 * A count that gets its own marker to a passage with a table is quoted from the table row
	 * that has it (it has no year of its own to pick a row by).
	 *
	 * @group chat
	 */
	public function test_uncited_figure_in_a_table_is_quoted_from_its_row() {
		$passages = [
			$this->passage( 1, 'In most of the countries surveyed, a median of 58% of adults view the United Nations favorably.', 'Global attitudes' ),
			$this->passage( 2, "Sample sizes\n| Survey | Countries |\n| --- | --- |\n| Spring survey | 24 |", 'Methodology' ),
		];

		$result = $this->citations->attribute( 'Across the 24 countries surveyed, a median of 58% of adults view the United Nations favorably.', $passages );

		$this->assertSame( 'Across the 24[[1]] countries surveyed, a median of 58%[[2]] of adults view the United Nations favorably.', $result['content'] );
		$this->assertSame( "| Survey | Countries |\n| Spring survey | 24 |", $result['provenance'][0]['quote'] );
		$this->assertSame( [ '24' ], $this->marked( $result['provenance'][0] ) );
	}
}
