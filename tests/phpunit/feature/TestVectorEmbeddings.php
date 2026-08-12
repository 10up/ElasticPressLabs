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
}
