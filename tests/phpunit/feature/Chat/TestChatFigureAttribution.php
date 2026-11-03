<?php
/**
 * Test that every figure the reader sees is cited to a passage that has it
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Gates\Figure_Match_Gate;
use ElasticPressLabs\Feature\Chat\Pipeline;

/**
 * Figure attribution test class: answers go through the whole pipeline with the default gates.
 */
class TestChatFigureAttribution extends \WP_UnitTestCase {
	/**
	 * The model's reply for the next run.
	 *
	 * @var string
	 */
	protected $reply = '';

	/**
	 * Two sources: a finding and its methodology.
	 */
	public function set_up() {
		parent::set_up();

		$ids = [
			$this->factory()->post->create(
				[
					'post_title'   => 'Global attitudes',
					'post_content' => 'In most of the countries surveyed, a median of 58% of adults view the United Nations favorably.',
					'post_excerpt' => '',
					'post_status'  => 'publish',
				]
			),
			$this->factory()->post->create(
				[
					'post_title'   => 'Methodology',
					'post_content' => 'The survey was conducted in 24 countries among 30,861 adults, with a 2.5 point margin of error.',
					'post_excerpt' => '',
					'post_status'  => 'publish',
				]
			),
		];

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $ids ) {
				return array_map( 'get_post', $ids );
			}
		);

		add_filter(
			'ep_chat_generate_text',
			function () {
				return $this->reply;
			}
		);
	}

	/**
	 * Run the pipeline with a reply.
	 *
	 * @param string $reply Model reply.
	 * @return array
	 */
	protected function answer( string $reply ): array {
		$this->reply = $reply;

		return ( new Pipeline() )->run(
			'How is the United Nations viewed?',
			[],
			[
				'name'           => 'Test',
				'execution_mode' => 'sync',
				// The answer checks, without the optional model-based ones.
				'optional_gates' => [ 'figure_match', 'scope', 'superlative', 'refusal', 'citation_attributor' ],
			]
		);
	}

	/**
	 * Assert that every figure the reader sees (not years, not list numbers) sits in a sentence
	 * with a marker whose passage has that figure.
	 *
	 * @param array $result Pipeline result.
	 */
	protected function assert_every_figure_cited_to_its_passage( array $result ) {
		$figures  = new Figures();
		$passages = [];

		foreach ( $result['hits'] as $hit ) {
			$passages[ (int) $hit['number'] ] = $hit['title'] . ' ' . $hit['excerpt'];
		}

		$sources = [];

		foreach ( $result['meta']['provenance'] as $entry ) {
			$sources[ (int) $entry['k'] ] = (int) $entry['n'];
		}

		foreach ( preg_split( '/(?<=[.!?])\s+/u', (string) $result['content'] ) as $sentence ) {
			preg_match_all( '/\[\[(\d+)\]\]/', $sentence, $markers );

			$plain = (string) preg_replace( '/\[\[\d+\]\]/', '', $sentence );
			$cited = array_map(
				static function ( $k ) use ( $sources, $passages ) {
					return $passages[ $sources[ (int) $k ] ] ?? '';
				},
				$markers[1]
			);

			foreach ( $figures->locate( $plain ) as $figure ) {
				if ( Figures::KIND_YEAR === $figure['kind'] || preg_match( '/^\s*$/u', substr( $plain, 0, $figure['start'] ) ) ) {
					continue;
				}

				$text  = substr( $plain, $figure['start'], $figure['end'] - $figure['start'] );
				$found = false;

				foreach ( $cited as $passage ) {
					$found = $found || [] === $figures->unmatched( $text, [ $passage ] );
				}

				$this->assertTrue( $found, "\"{$text}\" in \"{$plain}\" is not in a passage its markers cite." );
			}
		}
	}

	/**
	 * A figure in no passage never reaches the reader.
	 *
	 * @group chat
	 */
	public function test_figure_in_no_passage_is_declined() {
		$result = $this->answer( 'A median of 71% of adults view the United Nations favorably.' );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( Figure_Match_Gate::REASON, $result['refusal_reason'] );
		$this->assertStringNotContainsString( '71%', $result['content'] ?? '' );
	}

	/**
	 * A figure only another passage has is shown with a marker to that passage.
	 *
	 * @group chat
	 */
	public function test_figure_from_another_passage_is_cited_to_it() {
		$result = $this->answer( 'Across the 24 countries surveyed, a median of 58% of adults view the United Nations favorably.' );

		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 'Across the 24[[1]] countries surveyed, a median of 58%[[2]] of adults view the United Nations favorably.', $result['content'] );

		$by_k = array_column( $result['meta']['provenance'], null, 'k' );
		$hits = array_column( $result['hits'], null, 'number' );

		$this->assertSame( 'Methodology', $hits[ $by_k[1]['n'] ]['title'] );
		$this->assertSame( 'Global attitudes', $hits[ $by_k[2]['n'] ]['title'] );
		$this->assert_every_figure_cited_to_its_passage( $result );
	}

	/**
	 * For a set of answers, no figure's marker points to a passage without it.
	 *
	 * @group chat
	 */
	public function test_no_figure_is_cited_to_a_passage_without_it() {
		foreach ( [
			'A median of 58% of adults view the United Nations favorably, according to a survey of 24 countries.',
			'The survey covered 30,861 adults in 24 countries, and a median of 58% view the United Nations favorably.',
			'Views of the United Nations have a 2.5 point margin of error across the 24 countries surveyed.',
			"The findings:\n\n- A median of 58% view the United Nations favorably.\n- The survey reached 30,861 adults.",
			'The United Nations is viewed favorably by a median of 58% of adults.',
		] as $reply ) {
			$result = $this->answer( $reply );

			$this->assertSame( 'done', $result['status'], $reply );
			$this->assertNotEmpty( $result['meta']['provenance'], $reply );
			$this->assert_every_figure_cited_to_its_passage( $result );
		}
	}
}
