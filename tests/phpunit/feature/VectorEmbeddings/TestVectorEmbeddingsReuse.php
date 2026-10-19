<?php
/**
 * Test reusing the vectors of unchanged chunks
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Features;
use ElasticPress\Indexables;
use ElasticPressLabs\Feature\VectorEmbeddings\Settings;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Vector reuse test class
 */
class TestVectorEmbeddingsReuse extends \WP_UnitTestCase {

	const API_URL = 'https://embeddings.example.test/v1/embeddings';

	/**
	 * Inputs of every call to the (fake) embedding service.
	 *
	 * @var array[]
	 */
	protected $embedding_calls = [];

	/**
	 * URLs of the `_mget` requests.
	 *
	 * @var string[]
	 */
	protected $mget_requests = [];

	/**
	 * Post IDs asked by each `_mget` request.
	 *
	 * @var int[][]
	 */
	protected $mget_ids = [];

	/**
	 * Embedding model in the feature settings.
	 *
	 * @var string
	 */
	protected $model = 'model-a';

	/**
	 * Whether `_mget` requests fail.
	 *
	 * @var bool
	 */
	protected $fail_mget = false;

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
		add_filter( 'pre_http_request', [ $this, 'fake_requests' ], 10, 3 );

		$feature = new VectorEmbeddings();
		Features::factory()->register_feature( $feature );
		$feature->setup();

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
				'ep_embeddings_embedding_model' => $this->model,
			],
		];
	}

	/**
	 * Three dimensions keep the fake vectors small.
	 *
	 * @return int
	 */
	public function set_dimensions() {
		return 3;
	}

	/**
	 * Answer embedding requests with fake vectors, record `_mget` requests.
	 *
	 * @param false|array|\WP_Error $response Short-circuit response.
	 * @param array                 $args     Request args.
	 * @param string                $url      URL.
	 * @return false|array|\WP_Error
	 */
	public function fake_requests( $response, $args, $url ) {
		if ( self::API_URL === $url ) {
			$inputs                  = json_decode( $args['body'], true )['input'];
			$this->embedding_calls[] = $inputs;

			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => wp_json_encode(
					[
						'data' => array_map(
							function ( $input ) {
								return [ 'embedding' => [ 1, ( strlen( $input ) % 7 ) + 1, ( crc32( $input ) % 5 ) + 1 ] ];
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

		if ( false !== strpos( $url, '/_mget' ) ) {
			$this->mget_requests[] = $url;
			$this->mget_ids[]      = array_map( 'intval', (array) ( json_decode( (string) ( $args['body'] ?? '' ), true )['ids'] ?? [] ) );

			if ( $this->fail_mget ) {
				return new \WP_Error( 'http_request_failed', 'Connection refused' );
			}
		}

		return $response;
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
	 * Post content with two sections, so the post has several chunks.
	 *
	 * @param string $second Text of the second section.
	 * @return string
	 */
	protected function content( string $second = 'The second section keeps its own words here.' ): string {
		return '<h2>First</h2><p>' . str_repeat( 'The first section talks about cash and cards. ', 3 ) . '</p>'
			. '<h2>Second</h2><p>' . $second . '</p>';
	}

	/**
	 * Index a post and return its stored chunks.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function index( int $post_id ): array {
		Indexables::factory()->get( 'post' )->index( $post_id, true );
		Elasticsearch::factory()->refresh_indices();

		$response = Elasticsearch::factory()->remote_request( $this->get_index() . "/_doc/{$post_id}?_source_includes=chunks.hash,chunks.vector,chunks.text,chunks.type" );
		$body     = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['_source']['chunks'] ?? [];
	}

	/**
	 * Number of chunks sent to the embedding service since the last reset.
	 *
	 * @return int
	 */
	protected function embedded_count(): int {
		return array_sum( array_map( 'count', $this->embedding_calls ) );
	}

	/**
	 * A second sync of an unchanged post reuses every vector: no embedding call.
	 *
	 * @group vector-embeddings
	 */
	public function test_unchanged_post_reuses_every_vector() {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Cash',
				'post_content' => $this->content(),
			]
		);

		$first = $this->index( $post_id );

		$this->assertGreaterThan( 1, count( $first ) );
		$this->assertSame( count( $first ), $this->embedded_count() );
		$this->assertSame( 40, strlen( $first[0]['hash'] ) );
		$this->assertSame( 'content', $first[0]['type'] );

		$this->embedding_calls = [];
		$second                = $this->index( $post_id );

		$this->assertSame( [], $this->embedding_calls );
		$this->assertSame( array_column( $first, 'hash' ), array_column( $second, 'hash' ) );
		$this->assertEqualsWithDelta( array_column( $first, 'vector' ), array_column( $second, 'vector' ), 0.0001 );
	}

	/**
	 * Editing one section re-embeds only its chunk.
	 *
	 * @group vector-embeddings
	 */
	public function test_edited_section_reembeds_only_its_chunk() {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Cash',
				'post_content' => $this->content(),
			]
		);

		$first = $this->index( $post_id );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->content( 'The second section now says something else entirely.' ),
			]
		);

		$this->embedding_calls = [];
		$second                = $this->index( $post_id );

		$this->assertCount( 1, $this->embedding_calls );
		$this->assertCount( 1, $this->embedding_calls[0] );
		$this->assertStringContainsString( 'something else entirely', $this->embedding_calls[0][0] );
		$this->assertSame( $first[0]['hash'], $second[0]['hash'] );
		$this->assertNotSame( end( $first )['hash'], end( $second )['hash'] );
	}

	/**
	 * Another model gives other hashes: everything is embedded again.
	 *
	 * @group vector-embeddings
	 */
	public function test_model_change_reembeds_everything() {
		$post_id = self::factory()->post->create( [ 'post_content' => $this->content() ] );

		$first = $this->index( $post_id );

		$this->model           = 'model-b';
		$this->embedding_calls = [];
		$this->index( $post_id );

		$this->assertSame( count( $first ), $this->embedded_count() );
	}

	/**
	 * The stored vectors of a batch are loaded with one request, before the documents are
	 * prepared.
	 *
	 * @group vector-embeddings
	 */
	public function test_batch_loads_stored_vectors_once() {
		$post_ids = self::factory()->post->create_many( 3, [ 'post_content' => $this->content() ] );

		foreach ( $post_ids as $post_id ) {
			$this->index( $post_id );
		}

		$this->embedding_calls = [];
		$this->mget_requests   = [];

		// ElasticPress 5.4+ fires this in bulk_index(); fired here so the test also runs on older versions.
		do_action( 'ep_pre_prepare_documents', $post_ids, 'post' );
		Indexables::factory()->get( 'post' )->bulk_index( $post_ids );

		$this->assertCount( 1, $this->mget_requests );
		$this->assertStringContainsString( '_source_includes=chunks.hash,chunks.vector', $this->mget_requests[0] );
		$this->assertSame( [], $this->embedding_calls );
	}

	/**
	 * A batch loads its stored vectors one group at a time, never all at once.
	 *
	 * @group vector-embeddings
	 */
	public function test_batch_loads_stored_vectors_in_groups() {
		$post_ids = self::factory()->post->create_many( 5, [ 'post_content' => $this->content() ] );

		foreach ( $post_ids as $post_id ) {
			$this->index( $post_id );
		}

		add_filter(
			'ep_embeddings_reuse_group_size',
			static function () {
				return 2;
			}
		);

		$this->embedding_calls = [];
		$this->mget_requests   = [];
		$this->mget_ids        = [];

		do_action( 'ep_pre_prepare_documents', $post_ids, 'post' );
		Indexables::factory()->get( 'post' )->bulk_index( $post_ids );

		$this->assertSame( array_chunk( $post_ids, 2 ), $this->mget_ids );
		$this->assertSame( [], $this->embedding_calls, 'Every vector was reused.' );
	}

	/**
	 * During a full reindex the index was just recreated: no lookup, every chunk is embedded.
	 *
	 * @group vector-embeddings
	 */
	public function test_full_reindex_skips_the_lookup() {
		$post_id = self::factory()->post->create( [ 'post_content' => $this->content() ] );
		$first   = $this->index( $post_id );

		add_filter( 'ep_is_full_reindexing_post', '__return_true' );

		$this->embedding_calls = [];
		$this->mget_requests   = [];
		$this->index( $post_id );

		$this->assertSame( [], $this->mget_requests );
		$this->assertSame( count( $first ), $this->embedded_count() );
	}

	/**
	 * A failed lookup never blocks indexing: every chunk is embedded again.
	 *
	 * @group vector-embeddings
	 */
	public function test_failed_lookup_embeds_everything() {
		$post_id = self::factory()->post->create( [ 'post_content' => $this->content() ] );
		$first   = $this->index( $post_id );

		$this->fail_mget       = true;
		$this->embedding_calls = [];
		$second                = $this->index( $post_id );

		$this->assertSame( count( $first ), $this->embedded_count() );
		$this->assertSame( array_column( $first, 'hash' ), array_column( $second, 'hash' ) );
	}

	/**
	 * The hash covers the text, the API URL, the model, and the dimensions.
	 *
	 * @group vector-embeddings
	 */
	public function test_chunk_hash_covers_text_url_model_and_dimensions() {
		$feature = Features::factory()->get_registered_feature( 'vector_embeddings' );
		$hash    = $feature->get_chunk_hash( 'Some text' );

		$this->assertSame( $hash, $feature->get_chunk_hash( 'Some text' ) );
		$this->assertNotSame( $hash, $feature->get_chunk_hash( 'Some text.' ) );

		$this->model = 'model-b';
		$this->assertNotSame( $hash, $feature->get_chunk_hash( 'Some text' ) );

		$this->model = 'model-a';
		$this->assertSame( $hash, $feature->get_chunk_hash( 'Some text' ) );

		$url_filter = function () {
			return 'https://other.example.org/v1/embeddings';
		};
		add_filter( 'ep_embeddings_api_url', $url_filter );
		$this->assertNotSame( $hash, $feature->get_chunk_hash( 'Some text' ) );
		remove_filter( 'ep_embeddings_api_url', $url_filter );

		add_filter(
			'ep_embeddings_dimensions',
			function () {
				return 4;
			},
			20
		);
		$this->assertNotSame( $hash, $feature->get_chunk_hash( 'Some text' ) );
	}
}
