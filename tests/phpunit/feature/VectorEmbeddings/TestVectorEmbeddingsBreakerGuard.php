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
	 * With the breaker tripped, an embeddable post fails to prepare with a clear error and
	 * its stored document keeps its chunks; other posts index normally; a new index keeps
	 * the `chunks` mapping.
	 *
	 * @group vector-embeddings
	 */
	public function test_tripped_breaker_keeps_stored_vectors() {
		$this->trip_breaker();
		$this->assertFalse( $this->feature->is_active() );
		$this->assertTrue( $this->feature->is_temporarily_disabled_after_failures() );

		$this->feature->maybe_add_breaker_guard();

		$indexable = Indexables::factory()->get( 'post' );
		$index     = $indexable->get_index_name();

		Elasticsearch::factory()->delete_index( $index );
		$indexable->put_mapping();

		$mapping = Elasticsearch::factory()->get_mapping( $index );
		$this->assertArrayHasKey( 'chunks', $mapping[ $index ]['mappings']['properties'] );

		$post_id = self::factory()->post->create( [ 'post_content' => 'Cash use keeps falling.' ] );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => 'About us.',
			]
		);

		// The document as a healthy sync stored it.
		Elasticsearch::factory()->index_document(
			$index,
			'post',
			[
				'ID'        => $post_id,
				'post_id'   => $post_id,
				'post_type' => 'post',
				'chunks'    => [
					[
						'vector' => [ 1.0, 2.0, 3.0 ],
						'text'   => 'Stored chunk',
						'hash'   => 'abc',
						'type'   => 'content',
					],
				],
			]
		);

		$result = $indexable->bulk_index( [ $post_id, $page_id ] );
		Elasticsearch::factory()->refresh_indices();

		$this->assertTrue( $result['errors'] );

		$errors = array_values(
			array_filter(
				$result['items'],
				function ( $item ) {
					return 'prepare_document_error' === ( $item['index']['error']['type'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $errors );
		$this->assertSame( $post_id, $errors[0]['index']['_id'] );
		$this->assertStringContainsString( 'temporarily disabled', $errors[0]['index']['error']['reason'] );

		$documents = Elasticsearch::factory()->get_documents( $index, 'post', [ $post_id, $page_id ], [ 'post_type', 'chunks.text' ] );

		$this->assertSame( 'Stored chunk', $documents[ $post_id ]['chunks'][0]['text'] );
		$this->assertSame( 'page', $documents[ $page_id ]['post_type'] );
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
