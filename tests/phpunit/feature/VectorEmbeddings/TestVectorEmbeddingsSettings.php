<?php
/**
 * Test Vector Embeddings Indexing Criteria (which posts are embedded)
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\VectorEmbeddings\Settings;

/**
 * Vector Embeddings settings test class
 */
class TestVectorEmbeddingsSettings extends \WP_UnitTestCase {
	/**
	 * Post type configurations returned for the settings option.
	 *
	 * @var array
	 */
	protected $post_type_config = [];

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		add_filter(
			'pre_option_' . Settings::SETTINGS_KEY,
			function () {
				return [
					'postTypeConfig' => $this->post_type_config,
					'chunkSize'      => 100,
					'chunkOverlap'   => 50,
				];
			}
		);
	}

	/**
	 * A post type configuration.
	 *
	 * @param string $post_type Post type.
	 * @param array  $overrides Keys to override.
	 * @return array
	 */
	protected function config( string $post_type, array $overrides = [] ): array {
		return array_merge(
			[
				'embeddable'            => true,
				'embeddingMode'         => 'automatic',
				'enableFieldsIndexing'  => false,
				'fieldsIndexingInclude' => [],
				'fieldsIndexingExclude' => [],
				'fieldsEmbedding'       => [ 'post_title', 'post_content' ],
				'label'                 => $post_type,
				'key'                   => $post_type,
				'taxonomies'            => [
					[
						'name'         => 'category',
						'label'        => 'Categories',
						'termsInclude' => [],
						'termsExclude' => [],
						'enabled'      => true,
					],
				],
			],
			$overrides
		);
	}

	/**
	 * Set the category rules on a configuration.
	 *
	 * @param array $config      Configuration.
	 * @param array $include_ids Term IDs to include.
	 * @param array $exclude_ids Term IDs to exclude.
	 * @param bool  $enabled     Whether the taxonomy rule is enabled.
	 * @return array
	 */
	protected function with_category_rules( array $config, array $include_ids, array $exclude_ids = [], bool $enabled = true ): array {
		$config['taxonomies'][0]['termsInclude'] = $include_ids;
		$config['taxonomies'][0]['termsExclude'] = $exclude_ids;
		$config['taxonomies'][0]['enabled']      = $enabled;

		return $config;
	}

	/**
	 * Whether a post is embeddable with a fresh settings instance.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function embeddable( int $post_id ): bool {
		return ( new Settings() )->is_embeddable( $post_id );
	}

	/**
	 * Test the post type switch and the per-post flags.
	 *
	 * @group vector-embeddings
	 */
	public function test_post_type_switch_and_post_flags() {
		$post_id = $this->factory()->post->create();

		$this->post_type_config = [];
		$this->assertFalse( $this->embeddable( $post_id ) );

		$this->post_type_config = [ $this->config( 'post', [ 'embeddable' => false ] ) ];
		$this->assertFalse( $this->embeddable( $post_id ) );

		$this->post_type_config = [ $this->config( 'post' ) ];
		$this->assertTrue( $this->embeddable( $post_id ) );

		update_post_meta( $post_id, 'ep_embedding_exclude', 1 );
		$this->assertFalse( $this->embeddable( $post_id ) );
		delete_post_meta( $post_id, 'ep_embedding_exclude' );

		$this->post_type_config = [ $this->config( 'post', [ 'embeddingMode' => 'manual' ] ) ];
		$this->assertFalse( $this->embeddable( $post_id ) );
		update_post_meta( $post_id, 'ep_embedding_include', 1 );
		$this->assertTrue( $this->embeddable( $post_id ) );
	}

	/**
	 * Test taxonomy include and exclude rules apply in automatic mode.
	 *
	 * @group vector-embeddings
	 */
	public function test_taxonomy_rules() {
		$included = $this->factory()->category->create();
		$excluded = $this->factory()->category->create();

		$in    = $this->factory()->post->create( [ 'post_category' => [ $included ] ] );
		$out   = $this->factory()->post->create( [ 'post_category' => [ $excluded ] ] );
		$both  = $this->factory()->post->create( [ 'post_category' => [ $included, $excluded ] ] );
		$other = $this->factory()->post->create();

		// Exclude only: everything but the excluded term.
		$this->post_type_config = [ $this->with_category_rules( $this->config( 'post' ), [], [ $excluded ] ) ];
		$this->assertTrue( $this->embeddable( $in ) );
		$this->assertFalse( $this->embeddable( $out ) );
		$this->assertTrue( $this->embeddable( $other ) );

		// Include: only posts with the term; exclude still wins.
		$this->post_type_config = [ $this->with_category_rules( $this->config( 'post' ), [ $included ], [ $excluded ] ) ];
		$this->assertTrue( $this->embeddable( $in ) );
		$this->assertFalse( $this->embeddable( $out ) );
		$this->assertFalse( $this->embeddable( $both ) );
		$this->assertFalse( $this->embeddable( $other ) );

		// Term IDs saved as strings still match.
		$this->post_type_config = [ $this->with_category_rules( $this->config( 'post' ), [ (string) $included ] ) ];
		$this->assertTrue( $this->embeddable( $in ) );
		$this->assertFalse( $this->embeddable( $other ) );

		// A disabled taxonomy rule is ignored.
		$this->post_type_config = [ $this->with_category_rules( $this->config( 'post' ), [ $included ], [ $excluded ], false ) ];
		$this->assertTrue( $this->embeddable( $out ) );
		$this->assertTrue( $this->embeddable( $other ) );
	}

	/**
	 * Test meta include and exclude rules apply only when turned on.
	 *
	 * @group vector-embeddings
	 */
	public function test_meta_rules() {
		$flagged = $this->factory()->post->create();
		$hidden  = $this->factory()->post->create();
		$plain   = $this->factory()->post->create();

		update_post_meta( $flagged, 'is_eligible', '1' );
		update_post_meta( $hidden, 'is_hidden', '1' );

		$rules = [
			'fieldsIndexingInclude' => [ 'is_eligible' ],
			'fieldsIndexingExclude' => [ 'is_hidden' ],
		];

		$this->post_type_config = [ $this->config( 'post', $rules ) ];
		$this->assertTrue( $this->embeddable( $hidden ) );
		$this->assertTrue( $this->embeddable( $plain ) );

		$this->post_type_config = [ $this->config( 'post', array_merge( $rules, [ 'enableFieldsIndexing' => true ] ) ) ];
		$this->assertTrue( $this->embeddable( $flagged ) );
		$this->assertFalse( $this->embeddable( $hidden ) );
		$this->assertFalse( $this->embeddable( $plain ) );
	}

	/**
	 * Test another post type's include rules do not affect this one.
	 *
	 * @group vector-embeddings
	 */
	public function test_rules_are_per_post_type() {
		$term    = $this->factory()->category->create();
		$post_id = $this->factory()->post->create();

		$this->post_type_config = [
			$this->config( 'post' ),
			$this->with_category_rules( $this->config( 'page' ), [ $term ] ),
		];

		$this->assertTrue( $this->embeddable( $post_id ) );
	}

	/**
	 * Test the filter receives the computed value and can adjust it.
	 *
	 * @group vector-embeddings
	 */
	public function test_filter_adjusts_computed_value() {
		$excluded = $this->factory()->category->create();
		$post_id  = $this->factory()->post->create( [ 'post_category' => [ $excluded ] ] );
		$seen     = [];

		$this->post_type_config = [ $this->with_category_rules( $this->config( 'post' ), [], [ $excluded ] ) ];

		add_filter(
			'ep_embeddings_is_embeddable',
			static function ( $embeddable, $id ) use ( &$seen ) {
				$seen[] = [ $embeddable, $id ];

				return $embeddable;
			},
			10,
			2
		);

		$this->assertFalse( $this->embeddable( $post_id ) );
		$this->assertSame( [ [ false, $post_id ] ], $seen );

		add_filter( 'ep_embeddings_is_embeddable', '__return_true', 20 );
		$this->assertTrue( $this->embeddable( $post_id ) );
	}

	/**
	 * Test saved settings are read by a new instance.
	 *
	 * @group vector-embeddings
	 */
	public function test_new_instance_reads_current_settings() {
		$this->post_type_config = [ $this->config( 'post' ) ];
		$this->assertTrue( ( new Settings() )->is_post_type_embeddable( 'post' ) );

		$this->post_type_config = [ $this->config( 'post', [ 'embeddable' => false ] ) ];
		$this->assertFalse( ( new Settings() )->is_post_type_embeddable( 'post' ) );
	}
}
