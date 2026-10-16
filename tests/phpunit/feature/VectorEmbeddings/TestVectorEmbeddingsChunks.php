<?php
/**
 * Test the chunks built for a post
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPressLabs\Feature\VectorEmbeddings\Settings;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Post chunks test class
 */
class TestVectorEmbeddingsChunks extends \WP_UnitTestCase {

	/**
	 * Post indexable of the feature.
	 *
	 * @var \ElasticPressLabs\Feature\VectorEmbeddings\Indexables\Post\Post
	 */
	protected $indexable;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		update_option(
			Settings::SETTINGS_KEY,
			[
				'postTypeConfig' => [
					[
						'key'                   => 'post',
						'label'                 => 'Posts',
						'embeddable'            => true,
						'embeddingMode'         => 'automatic',
						'enableFieldsIndexing'  => false,
						'fieldsIndexingInclude' => [],
						'fieldsIndexingExclude' => [],
						'fieldsEmbedding'       => [ 'post_title', 'post_content', 'post_excerpt', 'sub_headline' ],
						'taxonomies'            => [],
					],
				],
				'chunkSize'      => 30,
				'chunkOverlap'   => 10,
			]
		);

		add_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );

		$feature = new VectorEmbeddings();
		Features::factory()->register_feature( $feature );
		$feature->setup();

		$this->indexable = $feature->get_indexables()['post'];

		// Building chunks must not call the embedding service.
		remove_filter( 'ep_post_sync_args_post_prepare_meta', [ $this->indexable, 'add_vector_field_to_post_sync' ] );

		add_filter(
			'ep_embeddings_post_embeddable_taxonomies',
			function () {
				return [ 'category' ];
			}
		);
	}

	/**
	 * Local embeddings generator.
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
	 * A post with a title entity, an excerpt, a heading, a category, and a meta field.
	 *
	 * @return int
	 */
	protected function create_post(): int {
		$words = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$words[] = "word{$i}";
		}

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Cash &amp; cards',
				'post_excerpt' => 'Short summary.',
				'post_content' => '<p>Intro sentence here.</p><h2>Method</h2><p>' . implode( ' ', $words ) . '.</p>',
			]
		);

		wp_set_post_categories( $post_id, [ self::factory()->category->create( [ 'name' => 'Economy' ] ) ] );
		update_post_meta( $post_id, 'sub_headline', '<em>Sub</em> line' );

		return $post_id;
	}

	/**
	 * Chunks start with the context line (title, then the heading path) and know their type.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunk_items_have_context_types_and_paths() {
		$post_id = $this->create_post();
		$items   = $this->indexable->get_post_chunk_items( $post_id );

		$this->assertSame( [ 'content', 'content', 'taxonomy', 'meta' ], array_column( $items, 'type' ) );

		// The title is only in the context line.
		$this->assertStringStartsWith( "Cash & cards\n# Summary\nShort summary.\n# Content\nIntro sentence here.", $items[0]['text'] );
		$this->assertSame( 1, substr_count( $items[0]['text'], 'Cash & cards' ) );
		$this->assertStringStartsWith( "Cash & cards › Method\n## Method\nword1 word2", $items[1]['text'] );
		$this->assertSame( "Cash & cards\n# Taxonomy Terms\n## category: Economy", $items[2]['text'] );
		$this->assertSame( "Cash & cards\n# Metadata\n## sub_headline: Sub line", $items[3]['text'] );

		$this->assertSame( array_column( $items, 'text' ), $this->indexable->get_post_chunks( $post_id ) );
	}

	/**
	 * Without the title field, the title is neither in the text nor in the context line;
	 * a post with nothing but a title keeps its title section.
	 *
	 * @group vector-embeddings
	 */
	public function test_title_follows_the_embedding_fields() {
		$settings = get_option( Settings::SETTINGS_KEY );
		$settings['postTypeConfig'][0]['fieldsEmbedding'] = [ 'post_content' ];
		update_option( Settings::SETTINGS_KEY, $settings );
		$this->indexable->settings_page = new Settings();

		$items = $this->indexable->get_post_chunk_items( $this->create_post() );

		$this->assertStringStartsWith( "# Content\nIntro sentence here.", $items[0]['text'] );
		$this->assertStringStartsWith( "Method\n## Method", $items[1]['text'] );
		$this->assertStringNotContainsString( 'Cash', implode( "\n", array_column( $items, 'text' ) ) );

		$settings['postTypeConfig'][0]['fieldsEmbedding'] = [ 'post_title', 'post_content' ];
		update_option( Settings::SETTINGS_KEY, $settings );
		$this->indexable->settings_page = new Settings();

		$title_only = self::factory()->post->create(
			[
				'post_title'   => 'Only a title',
				'post_content' => '',
			]
		);
		$items      = $this->indexable->get_post_chunk_items( $title_only );

		$this->assertSame( "Only a title\n# Title\nOnly a title", $items[0]['text'] );
	}

	/**
	 * The context line parts can be changed or removed.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunk_context_filter() {
		$post_id = $this->create_post();
		$seen    = [];

		add_filter(
			'ep_embeddings_chunk_context',
			function ( $parts, $post, $chunk_info ) use ( &$seen, $post_id ) {
				$seen[] = [ $post->ID === $post_id, $chunk_info['type'], $chunk_info['headings'] ];

				return 'meta' === $chunk_info['type'] ? [] : array_merge( [ 'Report' ], $parts );
			},
			10,
			3
		);

		$items = $this->indexable->get_post_chunk_items( $post_id );

		$this->assertStringStartsWith( "Report › Cash & cards › Method\n", $items[1]['text'] );
		$this->assertSame( "# Metadata\n## sub_headline: Sub line", $items[3]['text'] );
		$this->assertSame(
			[
				[ true, 'content', [] ],
				[ true, 'content', [ 'Method' ] ],
				[ true, 'taxonomy', [] ],
				[ true, 'meta', [] ],
			],
			$seen
		);
	}

	/**
	 * The chunk filter gets the final text (with the context line) and the chunk info.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunk_filter_receives_chunk_info() {
		$seen = [];

		add_filter(
			'ep_embeddings_chunk',
			function ( $chunk, $chunk_info ) use ( &$seen ) {
				$seen[] = $chunk_info['type'];

				return 'taxonomy' === $chunk_info['type'] ? '' : strtoupper( $chunk );
			},
			10,
			2
		);

		$items = $this->indexable->get_post_chunk_items( $this->create_post() );

		$this->assertSame( [ 'content', 'content', 'taxonomy', 'meta' ], $seen );
		// An emptied chunk is dropped.
		$this->assertSame( [ 'content', 'content', 'meta' ], array_column( $items, 'type' ) );
		$this->assertStringStartsWith( "CASH & CARDS\n", $items[0]['text'] );
	}

	/**
	 * The `words` strategy keeps the chunking of Labs 2.5: fixed word windows, no context line.
	 *
	 * @group vector-embeddings
	 */
	public function test_words_strategy_keeps_previous_chunking() {
		add_filter(
			'ep_embeddings_chunking_strategy',
			function () {
				return 'words';
			}
		);

		$items = $this->indexable->get_post_chunk_items( $this->create_post() );

		$this->assertSame( 'content', $items[0]['type'] );
		$this->assertStringStartsWith( '# Title', trim( $items[0]['text'] ) );
		$this->assertContains( 'taxonomy', array_column( $items, 'type' ) );
		$this->assertContains( 'meta', array_column( $items, 'type' ) );
	}

	/**
	 * Array meta values become a list of their values, not the word "Array".
	 *
	 * @group vector-embeddings
	 */
	public function test_array_meta_values_are_listed() {
		$post_id = $this->create_post();
		update_post_meta( $post_id, 'sub_headline', [ 'First', 'Second', [ 'Third', true, null ] ] );

		$items = $this->indexable->get_post_chunk_items( $post_id );

		$this->assertSame( "Cash & cards\n# Metadata\n## sub_headline: First, Second, Third", end( $items )['text'] );
	}

	/**
	 * Rendering posts for their chunks does not pile up Interactivity API state (a sync would
	 * otherwise keep every chart's data until it runs out of memory), and the page's own
	 * state is kept.
	 *
	 * @group vector-embeddings
	 */
	public function test_rendering_does_not_keep_interactivity_state() {
		if ( ! function_exists( 'wp_interactivity_state' ) ) {
			$this->markTestSkipped( 'The Interactivity API needs WordPress 6.5.' );
		}

		register_block_type(
			'ep-labs-test/interactive',
			[
				'render_callback' => function ( $attributes ) {
					wp_interactivity_state( 'ep-labs-test', [ 'charts' => [ $attributes['id'] => str_repeat( 'x', 1000 ) ] ] );

					return '<p>Chart ' . esc_html( $attributes['id'] ) . '</p>';
				},
			]
		);

		wp_interactivity_state( 'ep-labs-test', [ 'page' => 'kept' ] );

		foreach ( [ 'a', 'b', 'c' ] as $id ) {
			$post_id = self::factory()->post->create( [ 'post_content' => '<!-- wp:ep-labs-test/interactive {"id":"' . $id . '"} /-->' ] );
			$items   = $this->indexable->get_post_chunk_items( $post_id );

			$this->assertStringContainsString( "Chart {$id}", $items[0]['text'] );
		}

		$this->assertSame( [ 'page' => 'kept' ], wp_interactivity_state( 'ep-labs-test' ) );

		unregister_block_type( 'ep-labs-test/interactive' );
	}

	/**
	 * The document stores each chunk's text and type next to its vector, only when every
	 * vector has its chunk.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunks_field_stores_text_and_type() {
		$items = [
			[
				'text' => 'First chunk',
				'type' => 'content',
			],
			[
				'text' => 'Second chunk',
				'type' => 'taxonomy',
			],
		];

		$args = $this->indexable->add_chunks_field_value( [], [ [ 1, 2 ], [ '3', 4 ] ], $items );

		$this->assertSame(
			[
				[
					'vector' => [ 1.0, 2.0 ],
					'text'   => 'First chunk',
					'type'   => 'content',
				],
				[
					'vector' => [ 3.0, 4.0 ],
					'text'   => 'Second chunk',
					'type'   => 'taxonomy',
				],
			],
			$args['chunks']
		);

		$args = $this->indexable->add_chunks_field_value( [], [ [ 1, 2 ] ], $items );
		$this->assertSame( [ [ 'vector' => [ 1.0, 2.0 ] ] ], $args['chunks'] );
	}
}
