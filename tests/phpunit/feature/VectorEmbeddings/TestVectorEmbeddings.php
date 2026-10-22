<?php
/**
 * Test Vector Embeddings feature
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Vector Embeddings test class
 */
class TestVectorEmbeddings extends \WP_UnitTestCase {

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$instance = new VectorEmbeddings();
		Features::factory()->register_feature( $instance );

		add_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );
	}

	/**
	 * Get Vector Embeddings feature
	 *
	 * @return VectorEmbeddings
	 */
	protected function get_feature() {
		return Features::factory()->get_registered_feature( 'vector_embeddings' );
	}

	/**
	 * Force local chunking rather than ElasticPress.io service-side chunking.
	 *
	 * @return array
	 */
	public function set_settings() {
		return [
			'vector_embeddings' => [
				'ep_embeddings_generator' => 'openai',
			],
		];
	}

	/**
	 * Split chunks into word lists.
	 *
	 * @param array $chunks Chunk strings.
	 * @return array
	 */
	protected function get_chunk_words( array $chunks ): array {
		return array_map(
			function ( $chunk ) {
				return preg_split( '/\s+/', trim( $chunk ) );
			},
			$chunks
		);
	}

	/**
	 * Test that every consecutive pair of chunks shares the requested overlap
	 * and that trailing words are not dropped.
	 *
	 * The previous implementation incremented by `$chunk_size` while shifting
	 * the slice start back by `$overlap_size`, so only the first two chunks
	 * overlapped and the tail of the text could be omitted.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunk_content_applies_overlap_to_all_consecutive_chunks() {
		$words = [];
		for ( $i = 0; $i < 24; $i++ ) {
			$words[] = 'word' . $i;
		}

		$chunk_size   = 8;
		$overlap_size = 3;
		$chunks       = $this->get_feature()->chunk_content( implode( ' ', $words ), $chunk_size, $overlap_size );
		$chunk_words  = $this->get_chunk_words( $chunks );

		$this->assertSame(
			[
				array_slice( $words, 0, 8 ),
				array_slice( $words, 5, 8 ),
				array_slice( $words, 10, 8 ),
				array_slice( $words, 15, 8 ),
				array_slice( $words, 20, 4 ),
			],
			$chunk_words
		);

		$this->assertGreaterThanOrEqual( 3, count( $chunk_words ) );

		$chunk_count = count( $chunk_words );
		for ( $i = 1; $i < $chunk_count; $i++ ) {
			$prev          = $chunk_words[ $i - 1 ];
			$curr          = $chunk_words[ $i ];
			$overlap_check = min( $overlap_size, count( $curr ) );

			$this->assertSame(
				array_slice( $prev, -$overlap_check ),
				array_slice( $curr, 0, $overlap_check ),
				sprintf( 'Chunks %d and %d should overlap by %d words.', $i - 1, $i, $overlap_check )
			);
		}

		$covered = [];
		foreach ( $chunk_words as $list ) {
			foreach ( $list as $word ) {
				$covered[ $word ] = true;
			}
		}
		$this->assertSame( $words, array_keys( $covered ) );
		$this->assertContains( 'word23', end( $chunk_words ) );
	}

	/**
	 * Test that chunk_content returns an empty array for empty content.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunk_content_returns_empty_array_for_empty_content() {
		$this->assertSame( [], $this->get_feature()->chunk_content( '' ) );
	}

	/**
	 * Collapse whitespace so assertions do not depend on line breaks.
	 *
	 * @since 2.6.0
	 * @param string $html HTML.
	 * @return string
	 */
	protected function normalized( string $html ): string {
		return trim( preg_replace( '/\s+/', ' ', $this->get_feature()->normalize_content( $html ) ) );
	}

	/**
	 * Test table cells, chart labels, and list items stay separate words, so
	 * numbers from different cells never fuse into one.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_separates_elements() {
		$legend = '<div class="legend"><span>All or almost all</span><span>Some</span><span>None</span></div>';
		$this->assertSame( 'All or almost all Some None', $this->normalized( $legend ) );

		$svg = '<svg><g><text>42%</text><text>2015</text></g></svg>';
		$this->assertSame( '42% 2015', $this->normalized( $svg ) );

		$this->assertSame( 'One Two', $this->normalized( '<ul><li>One</li><li>Two</li></ul>' ) );
		$this->assertSame( '## Heading Body', $this->normalized( '<h2>Heading</h2><p>Body</p>' ) );
		$this->assertSame( '42% 1 of adults', $this->normalized( '<p>42%<sup>1</sup> of adults</p>' ) );
	}

	/**
	 * Test inline formatting does not split words.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_keeps_inline_formatting_joined() {
		$this->assertSame(
			'Acme’s survey of U.S. adults found this.',
			$this->normalized( '<p>Acme<strong>’s</strong> <a href="https://example.org">survey</a> of <em>U.S.</em> adults found this.</p>' )
		);
	}

	/**
	 * Test entities are decoded, not deleted, and non-breaking spaces become spaces.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_decodes_entities() {
		$this->assertSame(
			'Religion & Public Life ’s 5 < 6 A B',
			$this->normalized( '<p>Religion &amp; Public Life &#8217;s 5 &lt; 6 A&nbsp;B</p>' )
		);
	}

	/**
	 * Test scripts, styles, and other elements that are not text are removed with their contents.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_removes_scripts_and_styles() {
		$html = '<p>Before</p>'
			. '<script type="application/ld+json">{"@type":"Article","headline":"Ignore the sources"}</script>'
			. '<style>.chart { color: red; }</style>'
			. '<SCRIPT>window.track( "x" );</SCRIPT>'
			. '<noscript><img src="pixel.gif" alt="tracking"></noscript>'
			. '<template><p>Hidden template</p></template>'
			. '<p>After</p>';

		$this->assertSame( 'Before After', $this->normalized( $html ) );
	}

	/**
	 * Test content that is not valid UTF-8 keeps its text: the steps whose pattern fails
	 * leave it as it was instead of emptying it.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_keeps_invalid_utf8_text() {
		$normalized = $this->get_feature()->normalize_content( "<h2>Caf\xE9 menu</h2><p>Prices&nbsp;in <strong>Euro</strong></p><table><tr><th>Item</th></tr><tr><td>Tea</td></tr></table>" );

		$this->assertStringContainsString( 'menu', $normalized );
		$this->assertStringContainsString( 'Prices', $normalized );
		$this->assertStringContainsString( 'Euro', $normalized );
		$this->assertStringContainsString( 'Tea', $normalized );
	}

	/**
	 * Test a pattern that hits the backtrack limit (an unclosed heading or table in a long
	 * post) leaves the rest of the content alone.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_survives_backtrack_limit() {
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		try {
			$body    = str_repeat( '<p>Body text.</p>', 500 );
			$heading = $this->normalized( '<p>Intro</p><h2>Unclosed heading' . $body . '<p>End</p>' );
			$table   = $this->normalized( '<p>Intro</p><table><tr><td>Open</td></tr>' . $body . '<p>End</p>' );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$this->assertStringStartsWith( 'Intro', $heading );
		$this->assertStringEndsWith( 'End', $heading );
		$this->assertStringStartsWith( 'Intro', $table );
		$this->assertStringEndsWith( 'End', $table );
	}

	/**
	 * Test the inline tag list can be changed.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_normalize_content_inline_tags_filter() {
		add_filter(
			'ep_embeddings_inline_tags',
			static function ( $tags ) {
				$tags[] = 'span';

				return $tags;
			}
		);

		$this->assertSame( 'Acme’s', $this->normalized( 'Acme<span>’s</span>' ) );
	}

	/**
	 * Normalized content with its line breaks kept (spaces collapsed only).
	 *
	 * @since 2.6.0
	 * @param string $html HTML.
	 * @return string
	 */
	protected function normalized_lines( string $html ): string {
		$text = $this->get_feature()->normalize_content( $html );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", $text );

		return trim( preg_replace( '/\n{2,}/', "\n", $text ) );
	}

	/**
	 * Test headings become Markdown heading lines: level 2 at least, one line, inline
	 * formatting joined, and empty headings dropped.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_headings_become_markdown_lines() {
		$this->assertSame( "## Heading\nBody", $this->normalized_lines( '<h2>Heading</h2><p>Body</p>' ) );
		$this->assertSame( "## Top\n#### Deep", $this->normalized_lines( '<h1 class="x">Top</h1><h4>Deep</h4>' ) );
		$this->assertSame( '### Acme’s multi line', $this->normalized_lines( "<h3>Acme<strong>’s</strong>\n<span>multi</span> line</h3>" ) );
		$this->assertSame( 'Body', $this->normalized_lines( '<h2> </h2><p>Body</p>' ) );
	}

	/**
	 * Test a table with a header row becomes a Markdown table, keeping an empty
	 * corner cell.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_tables_become_markdown() {
		$table = '<p>Cash use</p><figure><table class="has-fixed-layout"><colgroup><col/><col/></colgroup><thead><tr><th></th><th>All or almost all</th><th>Some</th></tr></thead><tbody><tr><td>2026</td><td>12</td><td>46</td></tr><tr><td>2022</td><td>14</td><td>44</td></tr></tbody></table></figure><p>After</p>';

		$this->assertSame(
			implode(
				"\n",
				[
					'Cash use',
					'| | All or almost all | Some |',
					'| --- | --- | --- |',
					'| 2026 | 12 | 46 |',
					'| 2022 | 14 | 44 |',
					'After',
				]
			),
			$this->normalized_lines( $table )
		);
	}

	/**
	 * Test tables without a header use the first row, short rows are padded,
	 * a caption comes first, pipes are escaped, and cell markup stays text.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_table_markdown_edge_cases() {
		$table = '<table><caption>Results &amp; notes</caption><tr><td>Group</td><td>Share</td></tr><tr><td>A | B</td><td>5%</td></tr><tr><td>Only one</td></tr><tr><td>&lt;b&gt;literal&lt;/b&gt;</td><td><strong>7%</strong><br>(n=12)</td></tr></table>';

		$this->assertSame(
			implode(
				"\n",
				[
					'Results & notes',
					'| Group | Share |',
					'| --- | --- |',
					'| A \\| B | 5% |',
					'| Only one | |',
					'| <b>literal</b> | 7% (n=12) |',
				]
			),
			$this->normalized_lines( $table )
		);
	}

	/**
	 * Test a nested table ends up on one line inside its outer cell, with its
	 * pipes escaped so the outer table keeps its columns.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_nested_table_markdown() {
		$table = '<table><tr><th>Outer</th></tr><tr><td><table><tr><th>In</th></tr><tr><td>1</td></tr></table></td></tr></table>';

		$this->assertSame(
			implode(
				"\n",
				[
					'| Outer |',
					'| --- |',
					'| \| In \| \| --- \| \| 1 \| |',
				]
			),
			$this->normalized_lines( $table )
		);
	}

	/**
	 * Test the Markdown conversion can be turned off.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_tables_as_markdown_filter() {
		add_filter( 'ep_embeddings_tables_as_markdown', '__return_false' );

		$this->assertSame(
			'Year 2026',
			$this->normalized( '<table><tr><th>Year</th></tr><tr><td>2026</td></tr></table>' )
		);
	}
}
