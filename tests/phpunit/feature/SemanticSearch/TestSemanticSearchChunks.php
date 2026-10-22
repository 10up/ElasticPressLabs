<?php
/**
 * Test the matching chunks returned by semantic search
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Features;
use ElasticPress\Indexables;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\SemanticSearch\SearchAlgorithm\Hybrid;
use ElasticPressLabs\Feature\SemanticSearch\SearchAlgorithm\Knn;
use ElasticPressLabs\Feature\SemanticSearch\SearchAlgorithm\KnnCosine;
use ElasticPressLabs\Feature\SemanticSearch\SemanticSearch;
use ElasticPressLabs\Feature\VectorEmbeddings\Settings;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Semantic search matching chunks test class
 */
class TestSemanticSearchChunks extends \WP_UnitTestCase {

	const API_URL = 'https://embeddings.example.test/v1/embeddings';

	/**
	 * Vector Embeddings post indexable.
	 *
	 * @var \ElasticPressLabs\Feature\VectorEmbeddings\Indexables\Post\Post
	 */
	protected $indexable;

	/**
	 * Semantic search feature.
	 *
	 * @var SemanticSearch
	 */
	protected $semantic_search;

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
						'fieldsEmbedding'       => [ 'post_title', 'post_content' ],
						'taxonomies'            => [],
					],
				],
				'chunkSize'      => 30,
				'chunkOverlap'   => 0,
			]
		);

		add_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );
		add_filter( 'ep_embeddings_dimensions', [ $this, 'set_dimensions' ] );
		add_filter( 'ep_embeddings_post_embeddable_taxonomies', '__return_empty_array' );
		add_filter( 'pre_http_request', [ $this, 'fake_embeddings' ], 10, 3 );

		$feature = new VectorEmbeddings();
		Features::factory()->register_feature( $feature );
		$feature->setup();
		$this->indexable = $feature->get_indexables()['post'];

		$this->semantic_search = new SemanticSearch();
		Features::factory()->register_feature( $this->semantic_search );
		$this->semantic_search->setup();

		Elasticsearch::factory()->delete_index( $this->get_index() );
		Indexables::factory()->get( 'post' )->put_mapping();
	}

	/**
	 * Restore the regular post index.
	 */
	public function tear_down() {
		Elasticsearch::factory()->delete_index( $this->get_index() );
		Indexables::factory()->get( 'post' )->put_mapping();

		parent::tear_down();
	}

	/**
	 * Feature settings.
	 *
	 * @return array
	 */
	public function set_settings() {
		return [
			'vector_embeddings' => [
				'active'                        => true,
				'ep_embeddings_generator'       => 'openai',
				'ep_embeddings_api_url'         => self::API_URL,
				'ep_embeddings_embedding_model' => 'model-a',
			],
			'semantic_search'   => [
				'active' => true,
			],
		];
	}

	/**
	 * Three dimensions.
	 *
	 * @return int
	 */
	public function set_dimensions() {
		return 3;
	}

	/**
	 * Fake embedding service: every chunk gets its own direction.
	 *
	 * @param false|array|\WP_Error $response Short-circuit response.
	 * @param array                 $args     Request args.
	 * @param string                $url      URL.
	 * @return false|array|\WP_Error
	 */
	public function fake_embeddings( $response, $args, $url ) {
		if ( self::API_URL !== $url ) {
			return $response;
		}

		$inputs = json_decode( $args['body'], true )['input'];

		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => wp_json_encode(
				[
					'data' => array_map(
						function ( $input ) {
							$seed = crc32( $input );

							return [ 'embedding' => [ ( $seed % 97 ) + 1, ( intdiv( $seed, 97 ) % 89 ) + 1, ( intdiv( $seed, 8633 ) % 83 ) + 1 ] ];
						},
						$inputs
					),
				]
			),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	}

	/**
	 * Post index name.
	 *
	 * @return string
	 */
	protected function get_index(): string {
		return Indexables::factory()->get( 'post' )->get_index_name();
	}

	/**
	 * A search query object.
	 *
	 * @return \WP_Query
	 */
	protected function get_search_query(): \WP_Query {
		$query                  = new \WP_Query();
		$query->is_search       = true;
		$query->query_vars['s'] = 'cash';

		return $query;
	}

	/**
	 * Formatted args the algorithms start from.
	 *
	 * @return array
	 */
	protected function get_formatted_args(): array {
		return [
			'from'        => 0,
			'size'        => 10,
			'post_filter' => [ 'match_all' => (object) [] ],
			'query'       => [ 'match_all' => (object) [] ],
		];
	}

	/**
	 * The three algorithms ask for the matching chunks' text and type, never their vectors.
	 *
	 * @group vector-embeddings
	 */
	public function test_algorithms_request_matching_chunks() {
		$args     = [ 'ep_vectors' => [ 1.0, 2.0, 3.0 ] ];
		$expected = [
			'name'    => 'ep_matched_chunks',
			'size'    => 3,
			'_source' => [ 'chunks.text', 'chunks.type' ],
		];

		$knn    = ( new Knn() )->get_es_query( $this->get_formatted_args(), $args, $this->get_search_query() );
		$hybrid = ( new Hybrid() )->get_es_query( $this->get_formatted_args(), $args, $this->get_search_query() );
		$cosine = ( new KnnCosine() )->get_es_query( $this->get_formatted_args(), $args, $this->get_search_query() );

		$this->assertSame( $expected, $knn['knn']['inner_hits'] );
		$this->assertSame( $expected, $hybrid['knn']['inner_hits'] );
		$this->assertSame( $expected, $cosine['query']['bool']['must'][0]['nested']['inner_hits'] );

		add_filter(
			'ep_semantic_search_matched_chunks',
			function () {
				return 1;
			}
		);

		$knn = ( new Knn() )->get_es_query( $this->get_formatted_args(), $args, $this->get_search_query() );
		$this->assertSame( 1, $knn['knn']['inner_hits']['size'] );
	}

	/**
	 * Test only content chunks reach `$post->matched_chunks` by default, and the types filter
	 * changes that. Only the named inner hits are read.
	 *
	 * @since 2.6.0
	 * @group vector-embeddings
	 */
	public function test_matched_chunk_types_filter() {
		$feature = Features::factory()->get_registered_feature( 'semantic_search' );
		$hits    = [
			'hits' => [
				'hits' => [
					[
						'_score'  => 0.9,
						'_nested' => [ 'offset' => 3 ],
						'_source' => [
							'text' => 'Private meta value',
							'type' => 'meta',
						],
					],
					[
						'_score'  => 0.8,
						'_nested' => [ 'offset' => 0 ],
						'_source' => [
							'text' => 'Content text',
							'type' => 'content',
						],
					],
				],
			],
		];

		$document = $feature->add_matched_chunks( [], [ 'inner_hits' => [ 'ep_matched_chunks' => $hits ] ] );
		$this->assertSame( [ 'Content text' ], array_column( $document['matched_chunks'], 'text' ) );

		$this->assertSame( [], $feature->add_matched_chunks( [], [ 'inner_hits' => [ 'chunks' => $hits ] ] ) );

		add_filter(
			'ep_semantic_search_matched_chunk_types',
			function () {
				return [ 'content', 'meta' ];
			}
		);

		$document = $feature->add_matched_chunks( [], [ 'inner_hits' => [ 'ep_matched_chunks' => $hits ] ] );
		$this->assertSame( [ 'Private meta value', 'Content text' ], array_column( $document['matched_chunks'], 'text' ) );
	}

	/**
	 * Post searches leave the chunks out of `_source`, unless the query picks its fields.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunks_excluded_from_search_source() {
		$this->assertSame(
			[ 'excludes' => [ 'chunks', 'ep_embeddings_control' ] ],
			$this->indexable->exclude_chunks_from_source( [] )['_source']
		);

		$this->assertSame(
			[ 'excludes' => [ 'post_content', 'chunks', 'ep_embeddings_control' ] ],
			$this->indexable->exclude_chunks_from_source( [ '_source' => [ 'excludes' => [ 'post_content' ] ] ] )['_source']
		);

		$ids_only = [ '_source' => [ 'includes' => [ 'post_id' ] ] ];
		$this->assertSame( $ids_only, $this->indexable->exclude_chunks_from_source( $ids_only ) );
	}

	/**
	 * A kNN search returns the chunks that matched as `$post->matched_chunks`, best first,
	 * with their position in the post, and Chat joins them in reading order as the passage.
	 *
	 * @group vector-embeddings
	 */
	public function test_search_returns_matching_chunk_and_chat_passage() {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Cash',
				'post_content' => '<h2>First</h2><p>' . str_repeat( 'Cash use keeps falling among adults. ', 3 ) . '</p>'
					. '<h2>Second</h2><p>Card payments are the most common way to pay today.</p>',
			]
		);

		Indexables::factory()->get( 'post' )->index( $post_id, true );
		Elasticsearch::factory()->refresh_indices();

		$response = Elasticsearch::factory()->remote_request( $this->get_index() . "/_doc/{$post_id}?_source_includes=chunks.vector,chunks.text" );
		$chunks   = json_decode( wp_remote_retrieve_body( $response ), true )['_source']['chunks'];
		$target   = end( $chunks );

		$this->assertGreaterThan( 1, count( $chunks ) );

		add_filter(
			'ep_search_algorithm_version',
			function () {
				return 'knn';
			}
		);

		$documents = [];
		add_filter(
			'ep_retrieve_the_post',
			function ( $document ) use ( &$documents ) {
				$documents[] = $document;
				return $document;
			},
			20
		);

		$query = new \WP_Query(
			[
				's'            => 'card payments',
				'ep_integrate' => true,
				'ep_vectors'   => array_map( 'floatval', $target['vector'] ),
				'post_type'    => 'post',
			]
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertSame( $post_id, $query->posts[0]->ID );

		$matched = $query->posts[0]->matched_chunks;

		$this->assertSame( $target['text'], $matched[0]['text'] );
		$this->assertSame( 'content', $matched[0]['type'] );
		$this->assertCount( min( 3, count( $chunks ) ), $matched );
		$this->assertArrayNotHasKey( 'chunks', $documents[0] );

		$passages = ( new Pipeline() )->to_passages( $query->posts );
		$reading  = array_filter(
			$matched,
			static function ( $chunk ) {
				return in_array( $chunk['type'], [ 'content', 'meta' ], true );
			}
		);

		usort(
			$reading,
			static function ( $a, $b ) {
				return $a['offset'] <=> $b['offset'];
			}
		);

		$this->assertSame( array_search( $target['text'], array_column( $chunks, 'text' ), true ), $matched[0]['offset'] );
		$this->assertSame( implode( "\n\n", array_column( $reading, 'text' ) ), $passages[0]['excerpt'] );
		// The best match is the last chunk of the post, so it closes the passage.
		$this->assertStringEndsWith( $target['text'], $passages[0]['excerpt'] );
	}

	/**
	 * Without matching chunks (keyword search, older index), Chat keeps the post excerpt;
	 * taxonomy chunks are not passage text by default (see TestChatPassages).
	 *
	 * @group vector-embeddings
	 */
	public function test_chat_passage_falls_back_to_excerpt() {
		$post = get_post(
			self::factory()->post->create(
				[
					'post_title'   => 'Cash',
					'post_excerpt' => 'Short summary.',
				]
			)
		);

		$this->assertSame( 'Short summary.', ( new Pipeline() )->to_passages( [ $post ] )[0]['excerpt'] );

		$post->matched_chunks = [
			[
				'text' => "Cash\n# Taxonomy Terms\n## category: Economy",
				'type' => 'taxonomy',
			],
		];

		$this->assertSame( 'Short summary.', ( new Pipeline() )->to_passages( [ $post ] )[0]['excerpt'] );

		$post->matched_chunks[] = [
			'text' => "Cash › Method\n## Method\n| Year | All |",
			'type' => 'content',
		];

		$this->assertSame( "Cash › Method\n## Method\n| Year | All |", ( new Pipeline() )->to_passages( [ $post ] )[0]['excerpt'] );
	}

	/**
	 * Chat's retrieval embeds the question once and reuses the vector for a repeat (a Retry)
	 * within the cache time; with no cache, it embeds every time.
	 *
	 * @group vector-embeddings
	 * @group chat
	 */
	public function test_chat_retrieval_reuses_the_question_vector() {
		$calls = [];
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) use ( &$calls ) {
				if ( self::API_URL === $url ) {
					$calls[] = json_decode( $args['body'], true )['input'];
				}

				return $response;
			},
			5,
			3
		);

		$retriever = new \ElasticPressLabs\Feature\Chat\Retriever();

		$retriever->search( 'How many adults use cash?' );
		$retriever->search( 'How many adults use cash?' );

		$this->assertCount( 1, $calls );

		add_filter( 'ep_chat_query_vector_ttl', '__return_zero' );
		$retriever->search( 'How many adults use cash?' );

		$this->assertCount( 2, $calls );
	}
}
