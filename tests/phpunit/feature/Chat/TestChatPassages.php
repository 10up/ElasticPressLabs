<?php
/**
 * Test Chat passages built from matched chunks
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Citations;
use ElasticPressLabs\Feature\Chat\Pipeline;

/**
 * A passage is the post's best matching chunks, in reading order, within a word budget.
 */
class TestChatPassages extends \WP_UnitTestCase {

	/**
	 * A post with matched chunks, as semantic search returns them (best first).
	 *
	 * @param string $title  Title.
	 * @param array  $chunks `[ text, type, offset ]` rows, best first.
	 * @return \WP_Post
	 */
	protected function post_with_chunks( string $title, array $chunks ): \WP_Post {
		$post = get_post( self::factory()->post->create( [ 'post_title' => $title ] ) );

		$post->matched_chunks = array_map(
			static function ( $chunk ) {
				return [
					'text'   => $chunk[0],
					'type'   => $chunk[1],
					'score'  => 1.0,
					'offset' => $chunk[2],
				];
			},
			$chunks
		);

		return $post;
	}

	/**
	 * Words of a given count.
	 *
	 * @param int    $count Words.
	 * @param string $word  Word.
	 * @return string
	 */
	protected function words( int $count, string $word = 'word' ): string {
		return trim( str_repeat( "$word ", $count ) );
	}

	/**
	 * Up to three matched chunks make the passage, joined in reading order; taxonomy chunks
	 * are left out and meta chunks are kept.
	 *
	 * @group chat
	 */
	public function test_passage_joins_matched_chunks_in_reading_order() {
		$post = $this->post_with_chunks(
			'Cash',
			[
				[ "Cash › Ages\n42% of adults say so.", 'content', 7 ],
				[ "Cash\n# Taxonomy Terms\n## category: Economy", 'taxonomy', 12 ],
				[ "Cash\n# Metadata\n## sub_headline: Most adults go cashless.", 'meta', 11 ],
				[ "Cash › Summary\nFewer Americans use cash.", 'content', 0 ],
				[ "Cash › Methods\nThe survey ran in May.", 'content', 9 ],
			]
		);

		$this->assertSame(
			"Cash › Summary\nFewer Americans use cash.\n\nCash › Ages\n42% of adults say so.\n\nCash\n# Metadata\n## sub_headline: Most adults go cashless.",
			( new Pipeline() )->to_passages( [ $post ] )[0]['excerpt']
		);
	}

	/**
	 * The chunk count and the chunk types are filterable.
	 *
	 * @group chat
	 */
	public function test_chunk_count_and_types_are_filterable() {
		$post = $this->post_with_chunks(
			'Cash',
			[
				[ "Cash › Ages\n42% of adults say so.", 'content', 7 ],
				[ "Cash\n# Taxonomy Terms\n## category: Economy", 'taxonomy', 12 ],
				[ "Cash\n# Metadata\n## sub_headline: Most adults go cashless.", 'meta', 11 ],
			]
		);

		add_filter(
			'ep_chat_passage_chunks',
			static function () {
				return 1;
			}
		);

		$this->assertSame( "Cash › Ages\n42% of adults say so.", ( new Pipeline() )->to_passages( [ $post ] )[0]['excerpt'] );

		remove_all_filters( 'ep_chat_passage_chunks' );
		add_filter(
			'ep_chat_passage_chunk_types',
			static function () {
				return [ 'content', 'taxonomy' ];
			}
		);

		$this->assertSame(
			"Cash › Ages\n42% of adults say so.\n\nCash\n# Taxonomy Terms\n## category: Economy",
			( new Pipeline() )->to_passages( [ $post ] )[0]['excerpt']
		);
	}

	/**
	 * Over the word budget, the lowest-ranked posts lose their extra chunks first, worst
	 * match first; every post keeps its best chunk.
	 *
	 * @group chat
	 */
	public function test_word_budget_trims_lowest_ranked_posts_first() {
		$first  = $this->post_with_chunks(
			'First',
			[
				[ $this->words( 40, 'a' ), 'content', 0 ],
				[ $this->words( 40, 'b' ), 'content', 1 ],
			]
		);
		$second = $this->post_with_chunks(
			'Second',
			[
				[ $this->words( 40, 'c' ), 'content', 0 ],
				[ $this->words( 40, 'd' ), 'content', 1 ],
				[ $this->words( 40, 'e' ), 'content', 2 ],
			]
		);

		add_filter(
			'ep_chat_passage_word_budget',
			static function () {
				return 130;
			}
		);

		$passages = ( new Pipeline() )->to_passages( [ $first, $second ] );

		// 200 words: the second post drops `e` then `d` (120 left); the first post keeps both.
		$this->assertSame( $this->words( 40, 'a' ) . "\n\n" . $this->words( 40, 'b' ), $passages[0]['excerpt'] );
		$this->assertSame( $this->words( 40, 'c' ), $passages[1]['excerpt'] );

		remove_all_filters( 'ep_chat_passage_word_budget' );
		add_filter(
			'ep_chat_passage_word_budget',
			static function () {
				return 10;
			}
		);

		$passages = ( new Pipeline() )->to_passages( [ $first, $second ] );

		// A tiny budget still leaves every post its best chunk.
		$this->assertSame( $this->words( 40, 'a' ), $passages[0]['excerpt'] );
		$this->assertSame( $this->words( 40, 'c' ), $passages[1]['excerpt'] );
	}

	/**
	 * Citations quote a figure from any chunk of a joined passage, without its context line,
	 * and quote a meta field as its value.
	 *
	 * @group chat
	 */
	public function test_citations_quote_any_chunk_and_meta_values() {
		$post     = $this->post_with_chunks(
			'Gun laws',
			[
				[ "Gun report › Gun laws › Republicans\n42% of moderate Republicans say laws should be stricter.", 'content', 3 ],
				[ "Gun report › Gun laws › Overall\nA majority of Americans (58%) say gun laws should be stricter.", 'content', 1 ],
				[ "Gun report › Gun laws\n# Metadata\n## sub_headline: About 61% of adults see gun violence as a very big problem.", 'meta', 9 ],
			]
		);
		$passages = ( new Pipeline() )->to_passages( [ $post ] );

		$passages[0]['number'] = 1;

		$citations = new Citations();
		$overall   = $citations->attribute( 'Overall, 58% say gun laws should be stricter.', $passages )['provenance'][0];
		$meta      = $citations->attribute( 'About 61% see gun violence as a very big problem.', $passages )['provenance'][0];

		$this->assertSame( 'A majority of Americans (58%) say gun laws should be stricter.', $overall['quote'] );
		$this->assertSame( 'About 61% of adults see gun violence as a very big problem.', $meta['quote'] );
	}
}
