<?php
/**
 * Test Population_Match_Gate, Trend_Gate, and how Citations uses the same reading
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Citations;
use ElasticPressLabs\Feature\Chat\Figure_Context;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Population_Match_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Trend_Gate;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Refusals;

/**
 * Populations and years read next to figures, in answers and in passages.
 */
class TestChatPopulationTrend extends BaseTestCase {
	/**
	 * A passage.
	 *
	 * @param string $excerpt Text.
	 * @param string $title   Title.
	 * @param string $date    Date.
	 * @param int    $number  Number.
	 * @return array
	 */
	protected function passage( string $excerpt, string $title = 'A report', string $date = 'March 2, 2026', int $number = 1 ): array {
		return [
			'id'      => 0,
			'number'  => $number,
			'title'   => $title,
			'url'     => 'https://example.test/' . $number,
			'date'    => $date,
			'excerpt' => $excerpt,
		];
	}

	/**
	 * Run a gate.
	 *
	 * @param object $gate     Gate.
	 * @param string $answer   Answer.
	 * @param array  $passages Passages.
	 * @return \ElasticPressLabs\Feature\Chat\Gates\Gate_Result
	 */
	protected function check( $gate, string $answer, array $passages ) {
		return $gate->run(
			[
				'question' => 'A question?',
				'content'  => $answer,
				'passages' => $passages,
			]
		);
	}

	/**
	 * Both gates are optional, on by default, after Scope_Gate in the answer checks; their codes
	 * are declared, and the year code is a "withheld" decline.
	 *
	 * @group chat
	 */
	public function test_registration() {
		$slugs = array_map( static fn( $gate ) => $gate->get_slug(), Gates::registry()->get_for_stage( 'verify_answer' ) );

		$this->assertGreaterThan( array_search( 'scope', $slugs, true ), array_search( 'population_match', $slugs, true ) );
		$this->assertGreaterThan( array_search( 'population_match', $slugs, true ), array_search( 'trend_guard', $slugs, true ) );

		foreach ( [ new Population_Match_Gate(), new Trend_Gate() ] as $gate ) {
			$this->assertFalse( $gate->is_required() );
			$this->assertTrue( $gate->is_enabled_by_default() );
		}

		$this->assertSame( Refusals::KIND_WITHHELD, Refusals::describe( 'wave_not_in_corpus' )['kind'] );
		$this->assertSame( 'Figure not given for that group or place', Refusals::describe( 'unsupported_subgroup' )['label'] );
	}

	/**
	 * A figure the answer gives for a group needs a passage that gives it for that group: in the
	 * sentence, the sentence before, a table row with its header or caption, or the title.
	 *
	 * @group chat
	 */
	public function test_population_for_a_group() {
		$gate   = new Population_Match_Gate();
		$adults = $this->passage( "Share of U.S. adults who use TikTok\n| Year | Percent |\n| --- | --- |\n| 2021 | 21% |\n| 2025 | 37% |" );

		// The teens figure from the adults' table: declined.
		$this->assertTrue( $this->check( $gate, 'Among teens, 21% use TikTok almost constantly.', [ $adults ] )->is_refuse() );

		// Given for teens in the same sentence, the sentence before, a table header, or the title.
		$cases = [
			'The share of teens who say they are on TikTok almost constantly ticked up slightly to 21% this year.',
			'We asked teens how often they use TikTok. Some 21% say they are on it almost constantly.',
			"% of U.S. teens who use TikTok almost constantly\n| Group | Percent |\n| --- | --- |\n| Total | 21 |",
		];

		foreach ( $cases as $text ) {
			$this->assertFalse( $this->check( $gate, 'Among teens, 21% use TikTok almost constantly.', [ $adults, $this->passage( $text, 'A report', 'March 2, 2026', 2 ) ] )->is_refuse(), $text );
		}

		$this->assertFalse( $this->check( $gate, 'Among teens, 21% use TikTok almost constantly.', [ $this->passage( 'Some 21% say they are on it almost constantly.', 'Teens, Social Media and Technology 2025' ) ] )->is_refuse() );

		// Age table headers like "65+".
		$table = $this->passage( "| Platform | Ages 18-29 | 30-49 | 50-64 | 65+ |\n| --- | --- | --- | --- | --- |\n| National news | 51 | 52 | 58 | 62 |" );

		$this->assertFalse( $this->check( $gate, 'Some 62% of adults ages 65 and older trust national news.', [ $table ] )->is_refuse() );
	}

	/**
	 * A figure the answer gives for everyone is declined when every passage gives it only for a
	 * subgroup.
	 *
	 * @group chat
	 */
	public function test_population_for_everyone() {
		$gate   = new Population_Match_Gate();
		$online = $this->passage( 'Some 79% of online adults use Facebook, and 68% of online adults use it daily.' );

		$this->assertTrue( $this->check( $gate, 'About 68% of U.S. adults use Facebook daily.', [ $online ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, 'About 68% of online adults use Facebook daily.', [ $online ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, 'About 68% of U.S. adults use Facebook daily.', [ $online, $this->passage( 'That means that 68% of all U.S. adults are Facebook users.', 'B', 'November 11, 2016', 2 ) ] )->is_refuse() );

		// The figure's own "of …" phrase beats a nearer group before it.
		$both = $this->passage( '32% of internet users (28% of all U.S. adults) use Instagram', 'Social Media Update 2016', 'November 11, 2016', 3 );

		$this->assertFalse( $this->check( $gate, 'In 2016, 28% of all U.S. adults used Instagram.', [ $both ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, '2016: 28% of all U.S. adults (32% of internet users) used Instagram.', [ $both ] )->is_refuse() );
		$this->assertTrue( $this->check( $gate, 'About 28% of internet users use Instagram.', [ $both ] )->is_refuse() );

		// No population next to the figure: not this gate's to check.
		$this->assertFalse( $this->check( $gate, 'The share rose to 68% last year.', [ $online ] )->is_refuse() );
	}

	/**
	 * A figure the answer ties to a group and a year needs one place that gives it for both: the
	 * group's figure for another year plus another group's figure for that year is declined.
	 *
	 * @group chat
	 */
	public function test_population_and_year_together() {
		$gate  = new Population_Match_Gate();
		$teens = $this->passage( 'Some 63% of teens say they use TikTok.', 'Teens, Social Media and Technology 2023', 'December 11, 2023', 1 );
		$young = $this->passage( 'About six-in-ten adults under 30 (63%) say they use TikTok.', '8 facts about Americans and TikTok', 'March 2, 2026', 2 );

		$this->assertTrue( $this->check( $gate, 'Some 63% of teens used TikTok in 2025.', [ $teens, $young ] )->is_refuse() );
		$this->assertTrue( $this->check( $gate, "The share of teens who use TikTok:\n- 63% in 2025", [ $teens, $young ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, 'Some 63% of teens used TikTok in 2023.', [ $teens, $young ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, 'Some 63% of adults under 30 used TikTok in 2025.', [ $teens, $young ] )->is_refuse() );

		// A year no passage gives the figure for is Trend_Gate's to decline.
		$this->assertFalse( $this->check( $gate, 'Some 63% of teens used TikTok in 2019.', [ $teens, $young ] )->is_refuse() );
	}

	/**
	 * Comparisons ("Democrats and independents … 77% and 70%") and lists ("Among Hispanic
	 * adults:" items).
	 *
	 * @group chat
	 */
	public function test_population_comparisons_and_lists() {
		$gate    = new Population_Match_Gate();
		$parties = $this->passage( 'Today, 92% of Republicans say this, while 77% of Democrats and 70% of independents say the same.' );

		// Two parties, two figures: which is which is not read, so nothing is declined.
		$this->assertFalse( $this->check( $gate, 'The numbers among Democrats and independents have fallen to 77% and 70%.', [ $parties ] )->is_refuse() );

		$hispanic = $this->passage( 'Among Hispanic adults, 29% regularly get news on Instagram and 25% on TikTok.' );

		$this->assertFalse( $this->check( $gate, "Among Hispanic adults:\n- 25% regularly get news on TikTok.", [ $hispanic ] )->is_refuse() );
		$this->assertTrue( $this->check( $gate, "Among Black adults:\n- 25% regularly get news on TikTok.", [ $hispanic ] )->is_refuse() );
	}

	/**
	 * The year an answer ties to a figure follows the wording.
	 *
	 * @group chat
	 */
	public function test_reading_years() {
		$gate  = new Trend_Gate();
		$years = static fn( string $answer ) => array_combine( wp_list_pluck( $gate->get_claims( $answer ), 'figure' ), wp_list_pluck( $gate->get_claims( $answer ), 'year' ) );

		$this->assertSame(
			[
				'29%' => 2015,
				'34%' => 2018,
				'46%' => 2022,
			],
			$years( 'Ages 18-29: 29% in 2015, 34% in 2018, 46% in 2022.' )
		);
		$this->assertSame(
			[
				'21%' => 2021,
				'33%' => 2023,
			],
			$years( '2021: 21%, 2023: 33%.' )
		);
		$this->assertSame( [ '20%' => 1994 ], $years( 'In 1994, 20% of Americans trusted the government.' ) );
		$this->assertSame( [ '37%' => 2025 ], $years( 'About 37% of U.S. adults use TikTok as of 2025.' ) );
		$this->assertSame( [], $years( 'Since 2021, the share has grown, and 37% now use it.' ) );
	}

	/**
	 * A year the answer ties to a figure must be next to it in a passage, or be the passage's
	 * year or the year before.
	 *
	 * @group chat
	 */
	public function test_years() {
		$gate  = new Trend_Gate();
		$table = $this->passage( "| Year | Percent |\n| --- | --- |\n| 2021 | 21% |\n| 2025 | 37% |", 'TikTok use' );

		$this->assertFalse( $this->check( $gate, 'About 37% of adults used TikTok in 2025, up from 21% in 2021.', [ $table ] )->is_refuse() );
		$this->assertTrue( $this->check( $gate, 'About 37% of adults used TikTok in 2023.', [ $table ] )->is_refuse() );

		// From a 2016 report, given for 2024: declined; for 2015 (the report's year before): kept.
		$report = $this->passage( 'That means that 68% of all U.S. adults are Facebook users.', 'Social Media Update', 'November 11, 2016' );

		$this->assertTrue( $this->check( $gate, 'By 2024, 68% of U.S. adults used Facebook. In 2024, 68% did.', [ $report ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, 'In 2015, 68% of U.S. adults used Facebook.', [ $report ] )->is_refuse() );

		// "from 2007, when 71% said this", and a year in the title.
		$this->assertFalse( $this->check( $gate, 'That is down from 71% in 2007.', [ $this->passage( '63% are absolutely certain that God exists, down 8 percentage points from 2007, when 71% said this.', 'Faith', 'November 4, 2015' ) ] )->is_refuse() );
		$this->assertFalse( $this->check( $gate, '2022: 41% paid for nothing with cash.', [ $this->passage( 'Today, roughly four-in-ten Americans (41%) say none of their purchases are paid for using cash.', 'Striking findings from 2022', '' ) ] )->is_refuse() );
	}

	/**
	 * A trend across a change of method gets a caveat (no figures), added after the citations
	 * and stored; one year, or no note, gets none.
	 *
	 * @group chat
	 */
	public function test_caveat() {
		$gate        = new Trend_Gate();
		$post        = $this->factory()->post->create(
			[
				'post_title'   => 'TikTok use',
				'post_content' => 'Methodology: due to a mode shift in 2023, figures are not directly comparable.',
				'post_status'  => 'publish',
			]
		);
		$table       = $this->passage( "| Year | Percent |\n| --- | --- |\n| 2021 | 21% |\n| 2025 | 37% |", 'TikTok use' );
		$table['id'] = $post;
		$plain       = $table;
		$plain['id'] = 0;
		$trend       = 'About 37% of adults used TikTok in 2025, up from 21% in 2021.';
		$result      = $this->check( $gate, $trend, [ $table ] );
		$caveat      = $result->get_context()['caveats'][0] ?? '';

		$this->assertTrue( $result->is_modify() );
		$this->assertStringStartsWith( 'Note:', $caveat );
		$this->assertSame( [], ( new \ElasticPressLabs\Feature\Chat\Figures() )->locate( $caveat ) );
		$this->assertFalse( $this->check( $gate, 'About 37% of adults used TikTok in 2025.', [ $table ] )->is_modify() );
		$this->assertFalse( $this->check( $gate, $trend, [ $plain ] )->is_modify() );

		// Through the pipeline: after the answer, outside the citations, stored with it.
		add_filter( 'ep_chat_retrieve', static fn() => [ get_post( $post ) ] );
		add_filter( 'ep_chat_generate_text', static fn() => $trend );
		add_filter(
			'ep_chat_filter_passages',
			static function () use ( $table ) {
				return [ $table ];
			}
		);

		$result = ( new Pipeline() )->run(
			'How has TikTok use changed?',
			[],
			[
				'name'           => 'Test',
				'execution_mode' => 'sync',
				'optional_gates' => [ 'trend_guard' ],
			]
		);

		$this->assertSame( 'done', $result['status'] );
		$this->assertStringEndsWith( '_' . $caveat . '_', $result['content'] );
		$this->assertSame( [ $caveat ], $result['meta']['caveats'] );
		$this->assertDoesNotMatchRegularExpression( '/' . preg_quote( $caveat, '/' ) . '\S*\[\[/', $result['content'] );
	}

	/**
	 * Citations prefer the passage that gives the figure for the sentence's population and year.
	 *
	 * @group chat
	 */
	public function test_citations_prefer_the_fitting_passage() {
		$adults = $this->passage( "Share of U.S. adults who use TikTok\n| Year | Percent |\n| --- | --- |\n| 2021 | 21% |\n| 2025 | 37% |", 'TikTok facts', 'March 2, 2026', 1 );
		$teens  = $this->passage( 'The share of teens who say they are on TikTok almost constantly ticked up slightly to 21% this year.', 'Teens and social media', 'December 9, 2025', 2 );

		$result = ( new Citations() )->attribute( 'Among teens, 21% use TikTok almost constantly.', [ $adults, $teens ] );

		$this->assertSame( 2, (int) $result['provenance'][0]['n'] );
		$this->assertStringContainsString( 'teens', $result['provenance'][0]['quote'] );

		$result = ( new Citations() )->attribute( 'About 21% of U.S. adults used TikTok in 2021.', [ $teens, $adults ] );

		$this->assertSame( 1, (int) $result['provenance'][0]['n'] );
	}

	/**
	 * The vocabulary is filterable.
	 *
	 * @group chat
	 */
	public function test_groups_filter() {
		add_filter(
			'ep_chat_population_groups',
			static function ( $groups ) {
				$groups['other']['nurses'] = [ 'nurses' ];

				return $groups;
			}
		);

		$found = ( new Figure_Context() )->find_groups( 'Some 40% of nurses agree.' );

		$this->assertSame( 'nurses', $found[0]['group'] );
	}
}
