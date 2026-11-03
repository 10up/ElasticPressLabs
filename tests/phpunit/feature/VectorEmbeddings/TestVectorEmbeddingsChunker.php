<?php
/**
 * Test the paragraph and section chunker
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\VectorEmbeddings\Chunker;

/**
 * Chunker test class
 */
class TestVectorEmbeddingsChunker extends \WP_UnitTestCase {

	/**
	 * A sentence of `$count` words, e.g. "W1 w2 w3.".
	 *
	 * @param int    $count  Words.
	 * @param string $prefix Word prefix, to tell sentences apart.
	 * @return string
	 */
	protected function sentence( int $count, string $prefix = 'w' ): string {
		$words = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$words[] = $prefix . $i;
		}

		return ucfirst( implode( ' ', $words ) ) . '.';
	}

	/**
	 * Texts of the chunks.
	 *
	 * @param array $chunks Chunks.
	 * @return string[]
	 */
	protected function texts( array $chunks ): array {
		return array_column( $chunks, 'text' );
	}

	/**
	 * Whole paragraphs are packed until the next one does not fit.
	 *
	 * @group vector-embeddings
	 */
	public function test_packs_whole_paragraphs() {
		$chunker = new Chunker( 10, 0 );
		$text    = implode( "\n", [ $this->sentence( 4, 'a' ), $this->sentence( 4, 'b' ), $this->sentence( 4, 'c' ) ] );

		$this->assertSame(
			[
				$this->sentence( 4, 'a' ) . "\n" . $this->sentence( 4, 'b' ),
				$this->sentence( 4, 'c' ),
			],
			$this->texts( $chunker->chunk_sections( $text ) )
		);
	}

	/**
	 * A paragraph longer than a chunk splits at sentence ends, then at words.
	 *
	 * @group vector-embeddings
	 */
	public function test_long_paragraph_splits_at_sentences_then_words() {
		$chunker = new Chunker( 6, 0 );
		$text    = 'One two three. Four five six. Seven eight nine ten eleven twelve thirteen.';

		$this->assertSame(
			[
				'One two three. Four five six.',
				'Seven eight nine ten eleven twelve',
				'thirteen.',
			],
			$this->texts( $chunker->chunk_sections( $text ) )
		);
	}

	/**
	 * Initials and common abbreviations do not end a sentence.
	 *
	 * @group vector-embeddings
	 */
	public function test_abbreviations_do_not_end_sentences() {
		$chunker = new Chunker( 100, 0 );

		$this->assertSame(
			[
				'Most U.S. adults agree.',
				'Dr. Smith said so!',
				'Others, e.g. teens, do not (“really”.)',
				'Done?',
			],
			$chunker->split_sentences( 'Most U.S. adults agree. Dr. Smith said so! Others, e.g. teens, do not (“really”.) Done?' )
		);
	}

	/**
	 * Headings start new chunks and give each chunk its heading path; closing levels and
	 * skipped levels are handled.
	 *
	 * @group vector-embeddings
	 */
	public function test_headings_are_boundaries_and_paths() {
		$chunker = new Chunker( 20, 5 );
		$text    = implode(
			"\n",
			[
				'# Content',
				'#### Box',
				$this->sentence( 8, 'a' ),
				'## A',
				$this->sentence( 8, 'b' ),
				'### B',
				$this->sentence( 8, 'c' ),
				'## C',
				$this->sentence( 8, 'd' ),
			]
		);

		$chunks = $chunker->chunk_sections( $text );

		$this->assertSame( [ [ 'Box' ], [ 'A' ], [ 'A', 'B' ], [ 'C' ] ], array_column( $chunks, 'headings' ) );
		$this->assertSame( "# Content\n#### Box\n" . $this->sentence( 8, 'a' ), $chunks[0]['text'] );
		// No overlap across headings.
		$this->assertSame( "## A\n" . $this->sentence( 8, 'b' ), $chunks[1]['text'] );
		$this->assertSame( "### B\n" . $this->sentence( 8, 'c' ), $chunks[2]['text'] );
		$this->assertSame( "## C\n" . $this->sentence( 8, 'd' ), $chunks[3]['text'] );
	}

	/**
	 * A very small section stays with the next one.
	 *
	 * @group vector-embeddings
	 */
	public function test_small_section_merges_with_next() {
		$chunker = new Chunker( 20, 0 );
		$chunks  = $chunker->chunk_sections( "## A\nTiny.\n## B\n" . $this->sentence( 8 ) );

		$this->assertCount( 1, $chunks );
		$this->assertSame( [ 'A' ], $chunks[0]['headings'] );
		$this->assertSame( "## A\nTiny.\n## B\n" . $this->sentence( 8 ), $chunks[0]['text'] );
	}

	/**
	 * A chunk never ends with a heading: it moves to the next chunk.
	 *
	 * @group vector-embeddings
	 */
	public function test_trailing_heading_moves_to_next_chunk() {
		$chunker = new Chunker( 10, 0 );
		$chunks  = $chunker->chunk_sections( "One.\n## H\n" . $this->sentence( 9 ) );

		$this->assertSame( [ 'One.', "## H\n" . $this->sentence( 9 ) ], $this->texts( $chunks ) );
		$this->assertSame( [ [], [ 'H' ] ], array_column( $chunks, 'headings' ) );
	}

	/**
	 * The next chunk starts with the last whole sentences of the previous one, up to the
	 * overlap size.
	 *
	 * @group vector-embeddings
	 */
	public function test_overlap_carries_whole_sentences() {
		$chunker = new Chunker( 12, 5 );
		$chunks  = $chunker->chunk_sections( "Aa bb cc dd. Ee ff gg. Hh ii.\nJj kk ll mm nn oo." );

		$this->assertSame(
			[
				'Aa bb cc dd. Ee ff gg. Hh ii.',
				"Ee ff gg. Hh ii.\nJj kk ll mm nn oo.",
			],
			$this->texts( $chunks )
		);
	}

	/**
	 * No overlap when the last sentence is longer than the overlap, when the next piece
	 * would not fit with it, or when the overlap is 0.
	 *
	 * @group vector-embeddings
	 */
	public function test_overlap_limits() {
		$long_last = ( new Chunker( 10, 5 ) )->chunk_sections( 'Aa bb cc dd ee ff gg.' . "\n" . $this->sentence( 5 ) );
		$this->assertSame( $this->sentence( 5 ), $long_last[1]['text'] );

		$no_room = ( new Chunker( 10, 4 ) )->chunk_sections( 'Aa bb cc dd ee ff. Gg hh.' . "\n" . $this->sentence( 9 ) );
		$this->assertSame( $this->sentence( 9 ), $no_room[1]['text'] );

		$no_overlap = ( new Chunker( 12, 0 ) )->chunk_sections( "Aa bb cc dd. Ee ff gg. Hh ii.\nJj kk ll mm nn oo." );
		$this->assertSame( 'Jj kk ll mm nn oo.', $no_overlap[1]['text'] );
	}

	/**
	 * A long table splits at row boundaries and every piece repeats the header rows.
	 *
	 * @group vector-embeddings
	 */
	public function test_long_table_splits_at_rows_with_header() {
		$header = [ '| Year | All |', '| --- | --- |' ];
		$rows   = [];

		for ( $year = 2020; $year <= 2029; $year++ ) {
			$rows[] = "| {$year} | " . ( $year - 2000 ) . ' |';
		}

		$chunker = new Chunker( 25, 0 );
		$chunks  = $chunker->chunk_sections( "Cash use\n" . implode( "\n", array_merge( $header, $rows ) ) );
		$pieces  = array_values(
			array_filter(
				$this->texts( $chunks ),
				function ( $chunk ) {
					return false !== strpos( $chunk, '| 20' );
				}
			)
		);

		$this->assertGreaterThan( 1, count( $pieces ) );

		$seen = [];
		foreach ( $pieces as $piece ) {
			$lines = explode( "\n", $piece );
			$index = array_search( $header[0], $lines, true );

			$this->assertNotFalse( $index, $piece );
			$this->assertSame( $header[1], $lines[ $index + 1 ] );
			$this->assertLessThanOrEqual( 25, $chunker->count_words( $piece ) );

			$seen = array_merge( $seen, array_slice( $lines, $index + 2 ) );
		}

		$this->assertSame( $rows, $seen );
	}

	/**
	 * A small table stays whole, with the text around it.
	 *
	 * @group vector-embeddings
	 */
	public function test_small_table_stays_whole() {
		$table  = "| Year | All |\n| --- | --- |\n| 2026 | 12 |";
		$chunks = ( new Chunker( 50, 0 ) )->chunk_sections( "Before.\n{$table}\nAfter." );

		$this->assertSame( [ "Before.\n{$table}\nAfter." ], $this->texts( $chunks ) );
	}

	/**
	 * Labelled lines are packed; a long value splits with its label repeated, and every
	 * chunk starts with the header line.
	 *
	 * @group vector-embeddings
	 */
	public function test_labelled_long_value_repeats_label_and_header() {
		$chunker = new Chunker( 12, 5 );
		$value   = $this->sentence( 6, 'a' ) . ' ' . $this->sentence( 6, 'b' ) . ' ' . $this->sentence( 6, 'c' );
		$chunks  = $chunker->chunk_labelled( "# Metadata\n## sub_headline: {$value}\n## short: one two" );
		$texts   = $this->texts( $chunks );

		$this->assertSame(
			[
				"# Metadata\n## sub_headline: " . $this->sentence( 6, 'a' ),
				"# Metadata\n## sub_headline: " . $this->sentence( 6, 'b' ),
				"# Metadata\n## sub_headline: " . $this->sentence( 6, 'c' ),
				"# Metadata\n## short: one two",
			],
			$texts
		);

		foreach ( $texts as $text ) {
			$this->assertLessThanOrEqual( 12, $chunker->count_words( $text ) );
		}
	}

	/**
	 * A long term list splits at commas, never inside a term.
	 *
	 * @group vector-embeddings
	 */
	public function test_labelled_term_list_splits_at_commas() {
		$terms   = [ 'Economic Policy', 'Economy & Work', 'Personal Finances', 'Internet and Technology', 'United States' ];
		$chunker = new Chunker( 8, 0 );
		$chunks  = $chunker->chunk_labelled( "# Taxonomy Terms\n## category: " . implode( ', ', $terms ) );

		$found = [];
		foreach ( $this->texts( $chunks ) as $text ) {
			$lines = explode( "\n", $text );

			$this->assertSame( '# Taxonomy Terms', $lines[0] );
			$this->assertStringStartsWith( '## category: ', $lines[1] );

			$found = array_merge( $found, explode( ', ', substr( $lines[1], strlen( '## category: ' ) ) ) );
		}

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertSame( $terms, $found );
	}

	/**
	 * Lines without a label continue the previous value.
	 *
	 * @group vector-embeddings
	 */
	public function test_labelled_multi_line_value() {
		$chunks = ( new Chunker( 50, 0 ) )->chunk_labelled( "# Metadata\n## notes: line one\nline two" );

		$this->assertSame( [ "# Metadata\n## notes: line one line two" ], $this->texts( $chunks ) );
	}

	/**
	 * Editing one paragraph only changes the chunks of its own section.
	 *
	 * @group vector-embeddings
	 */
	public function test_edit_only_changes_its_section() {
		$chunker  = new Chunker( 20, 5 );
		$sections = function ( string $middle ) {
			return implode(
				"\n",
				[
					'## First',
					$this->sentence( 9, 'a' ),
					$this->sentence( 9, 'b' ),
					'## Second',
					$middle,
					$this->sentence( 9, 'c' ),
					'## Third',
					$this->sentence( 9, 'd' ),
					$this->sentence( 9, 'e' ),
				]
			);
		};

		$before = $chunker->chunk_sections( $sections( $this->sentence( 5, 'm' ) ) );
		$after  = $chunker->chunk_sections( $sections( $this->sentence( 12, 'n' ) ) );

		$by_section = function ( array $chunks, string $heading ) {
			return array_values(
				array_filter(
					$chunks,
					function ( $chunk ) use ( $heading ) {
						return [ $heading ] === $chunk['headings'];
					}
				)
			);
		};

		$this->assertSame( $by_section( $before, 'First' ), $by_section( $after, 'First' ) );
		$this->assertSame( $by_section( $before, 'Third' ), $by_section( $after, 'Third' ) );
		$this->assertNotSame( $by_section( $before, 'Second' ), $by_section( $after, 'Second' ) );
	}
}
