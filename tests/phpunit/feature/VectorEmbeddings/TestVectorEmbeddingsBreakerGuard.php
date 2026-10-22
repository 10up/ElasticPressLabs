<?php
/**
 * Test that documents keep their vectors while Vector Embeddings is temporarily disabled
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
 * Breaker guard test class
 */
class TestVectorEmbeddingsBreakerGuard extends \WP_UnitTestCase {

	/**
	 * Whether the feature is turned on in the settings.
	 *
	 * @var bool
	 */
	protected $active = true;

	/**
	 * Vector Embeddings feature.
	 *
	 * @var VectorEmbeddings
	 */
	protected $feature;

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
				'chunkSize'      => 100,
				'chunkOverlap'   => 0,
			]
		);

		add_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );
		add_filter(
			'ep_embeddings_dimensions',
			function () {
				return 3;
			}
		);

		$this->feature = new VectorEmbeddings();
		Features::factory()->register_feature( $this->feature );

		remove_all_filters( 'ep_post_sync_args_post_prepare_meta' );
		remove_all_filters( 'ep_post_mapping' );

		delete_transient( 'ep_vector_embeddings_failures' );
	}

	/**
	 * Restore the regular post index.
	 */
	public function tear_down() {
		delete_transient( 'ep_vector_embeddings_failures' );

		Elasticsearch::factory()->delete_index( Indexables::factory()->get( 'post' )->get_index_name() );
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
				'active'                  => $this->active,
				'ep_embeddings_generator' => 'openai',
			],
		];
	}

	/**
	 * Trip the breaker: more failures in the last hour than allowed.
	 */
	protected function trip_breaker() {
		set_transient( 'ep_vector_embeddings_failures', [ time(), time(), time(), time() ], HOUR_IN_SECONDS );
	}

	/**
	 * Trip the breaker, add the guard, and recreate the post index.
	 *
	 * @return string Index name.
	 */
	protected function guarded_index(): string {
		$this->trip_breaker();
		$this->assertFalse( $this->feature->is_active() );
		$this->assertTrue( $this->feature->is_temporarily_disabled_after_failures() );

		$this->feature->maybe_add_breaker_guard();

		$indexable = Indexables::factory()->get( 'post' );
		$index     = $indexable->get_index_name();

		Elasticsearch::factory()->delete_index( $index );
		$indexable->put_mapping();

		return $index;
	}

	/**
	 * Store a document as a healthy sync did, with one chunk.
	 *
	 * @param string $index   Index name.
	 * @param int    $post_id Post ID.
	 */
	protected function store_document( string $index, int $post_id ) {
		Elasticsearch::factory()->index_document(
			$index,
			'post',
			[
				'ID'         => $post_id,
				'post_id'    => $post_id,
				'post_type'  => 'post',
				'post_title' => 'Old title',
				'chunks'     => [
					[
						'vector' => [ 1.0, 2.0, 3.0 ],
						'text'   => 'Stored chunk',
						'hash'   => 'abc',
						'type'   => 'content',
					],
				],
			]
		);
	}

	/**
	 * With the breaker tripped, an embeddable post is indexed with its current fields and the
	 * chunks already stored for it, and its doc status says the vectors are older; a post with
	 * nothing stored is indexed without chunks; other posts index normally; a new index keeps
	 * the `chunks` mapping.
	 *
	 * @group vector-embeddings
	 */
	public function test_tripped_breaker_keeps_stored_vectors() {
		$index   = $this->guarded_index();
		$mapping = Elasticsearch::factory()->get_mapping( $index );
		$this->assertArrayHasKey( 'chunks', $mapping[ $index ]['mappings']['properties'] );

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'New title',
				'post_content' => 'Cash use keeps falling.',
			]
		);
		$new_id  = self::factory()->post->create( [ 'post_content' => 'Nothing stored yet.' ] );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => 'About us.',
			]
		);

		$this->store_document( $index, $post_id );

		$result = Indexables::factory()->get( 'post' )->bulk_index( [ $post_id, $new_id, $page_id ] );
		Elasticsearch::factory()->refresh_indices();

		$this->assertFalse( $result['errors'] );

		$documents = Elasticsearch::factory()->get_documents( $index, 'post', [ $post_id, $new_id, $page_id ], [ 'post_title', 'post_type', 'chunks.text', 'chunks.vector', 'ep_embeddings_control' ] );

		$this->assertSame( 'New title', $documents[ $post_id ]['post_title'] );
		$this->assertSame( 'Stored chunk', $documents[ $post_id ]['chunks'][0]['text'] );
		$this->assertEquals( [ 1.0, 2.0, 3.0 ], $documents[ $post_id ]['chunks'][0]['vector'] );
		$this->assertArrayNotHasKey( 'chunks', $documents[ $new_id ] );
		$this->assertSame( 'page', $documents[ $page_id ]['post_type'] );

		$status = apply_filters( 'ep_doc_status', [], $post_id, $documents[ $post_id ] );
		$this->assertSame( 'error', $status['status'] );
		$this->assertStringContainsString( 'temporarily disabled', $status['explanation'] );
	}

	/**
	 * When the stored chunks cannot be read, the post is not indexed, so it keeps them.
	 *
	 * @group vector-embeddings
	 */
	public function test_tripped_breaker_refuses_when_stored_chunks_cannot_be_read() {
		$index   = $this->guarded_index();
		$post_id = self::factory()->post->create( [ 'post_title' => 'New title' ] );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->store_document( $index, $post_id );

		add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) {
				return false !== strpos( $url, '/_mget' ) ? new \WP_Error( 'http_request_failed', 'Connection refused' ) : $response;
			},
			10,
			3
		);

		// The page keeps the bulk request from being empty.
		$result = Indexables::factory()->get( 'post' )->bulk_index( [ $post_id, $page_id ] );
		Elasticsearch::factory()->refresh_indices();

		$errors = wp_list_filter( wp_list_pluck( $result['items'], 'index' ), [ '_id' => $post_id ] );
		$error  = reset( $errors )['error'];

		$this->assertTrue( $result['errors'] );
		$this->assertSame( 'prepare_document_error', $error['type'] );
		$this->assertStringContainsString( 'could not be read', $error['reason'] );

		remove_all_filters( 'pre_http_request' );

		$response = Elasticsearch::factory()->remote_request( "{$index}/_doc/{$post_id}?_source_includes=post_title,chunks.text" );
		$document = json_decode( wp_remote_retrieve_body( $response ), true )['_source'];

		$this->assertSame( 'Old title', $document['post_title'] );
		$this->assertSame( 'Stored chunk', $document['chunks'][0]['text'] );
	}

	/**
	 * No guard when something else than the failures disables the feature.
	 *
	 * @group vector-embeddings
	 */
	public function test_no_guard_when_force_disabled() {
		$this->trip_breaker();
		add_filter(
			'ep_elasticsearch_version',
			function () {
				return '6.8.0';
			}
		);

		$this->assertFalse( $this->feature->is_temporarily_disabled_after_failures() );

		$this->feature->maybe_add_breaker_guard();
		$this->assertFalse( has_filter( 'ep_post_sync_args_post_prepare_meta' ) );
	}

	/**
	 * No guard while the feature works, or when it is turned off in the settings.
	 *
	 * @group vector-embeddings
	 */
	public function test_no_guard_when_healthy_or_off() {
		$this->feature->maybe_add_breaker_guard();
		$this->assertFalse( has_filter( 'ep_post_sync_args_post_prepare_meta' ) );

		$this->active = false;
		$this->trip_breaker();

		$this->assertFalse( $this->feature->is_temporarily_disabled_after_failures() );

		$this->feature->maybe_add_breaker_guard();
		$this->assertFalse( has_filter( 'ep_post_sync_args_post_prepare_meta' ) );
	}
}
