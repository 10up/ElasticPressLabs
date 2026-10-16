<?php
/**
 * Test adding the Vector Embeddings fields to existing indices
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Features;
use ElasticPress\Indexables;
use ElasticPressLabs\Feature\VectorEmbeddings\Indexables\Post\Post;
use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;
use ElasticPressLabs\Upgrades;

/**
 * Vector Embeddings mapping upgrade test class
 */
class TestVectorEmbeddingsMapping extends \WP_UnitTestCase {

	/**
	 * Post index name.
	 *
	 * @var string
	 */
	protected $index;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		Features::factory()->register_feature( new VectorEmbeddings() );

		add_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );

		$this->index = Indexables::factory()->get( 'post' )->get_index_name();
		Elasticsearch::factory()->delete_index( $this->index );
	}

	/**
	 * Restore the regular post index.
	 */
	public function tear_down() {
		Elasticsearch::factory()->delete_index( $this->index );
		Indexables::factory()->get( 'post' )->put_mapping();

		parent::tear_down();
	}

	/**
	 * Whether the feature is active in the settings.
	 *
	 * @var bool
	 */
	protected $active = true;

	/**
	 * Local embeddings generator; the feature is active unless a test turns it off.
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
	 * Get Vector Embeddings feature
	 *
	 * @return VectorEmbeddings
	 */
	protected function get_feature() {
		return Features::factory()->get_registered_feature( 'vector_embeddings' );
	}

	/**
	 * The mapping fields Vector Embeddings adds.
	 *
	 * @return array
	 */
	protected function get_target_properties(): array {
		$mapping = ( new Post( $this->get_feature() ) )->add_vector_mapping_field( [ 'mappings' => [ 'properties' => [] ] ] );

		return $mapping['mappings']['properties'];
	}

	/**
	 * Create the post index with the given properties.
	 *
	 * @param array $properties Mapping properties.
	 */
	protected function create_index( array $properties ) {
		$this->assertTrue(
			Elasticsearch::factory()->put_mapping(
				$this->index,
				[
					'mappings' => [
						'properties' => array_merge(
							[ 'post_title' => [ 'type' => 'text' ] ],
							$properties
						),
					],
				]
			)
		);
	}

	/**
	 * Stored mapping properties of the post index.
	 *
	 * @return array
	 */
	protected function get_stored_properties(): array {
		$mapping = Elasticsearch::factory()->get_mapping( $this->index );

		return $mapping[ $this->index ]['mappings']['properties'] ?? [];
	}

	/**
	 * An index created while the feature was off gets every field, and a second run finds
	 * nothing to do.
	 *
	 * @group vector-embeddings
	 */
	public function test_adds_all_fields_to_an_index_without_them() {
		$this->create_index( [] );

		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::APPLIED, $result['status'], $result['message'] );
		$this->assertStringContainsString( 'chunks', $result['message'] );
		$this->assertStringContainsString( 'ep_embeddings_control', $result['message'] );

		$stored = $this->get_stored_properties();
		$this->assertSame( 'nested', $stored['chunks']['type'] );
		$this->assertSame( 'dense_vector', $stored['chunks']['properties']['vector']['type'] );
		$this->assertSame( $this->get_feature()->get_dimensions(), (int) $stored['chunks']['properties']['vector']['dims'] );
		$this->assertSame( 'text', $stored['chunks']['properties']['text']['type'] );
		$this->assertSame( 'keyword', $stored['chunks']['properties']['hash']['type'] );
		$this->assertSame( 'keyword', $stored['chunks']['properties']['type']['type'] );
		$this->assertSame( 'text', $stored['post_title']['type'] );

		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::SKIPPED, $result['status'] );
		$this->assertStringContainsString( 'up to date', $result['message'] );
	}

	/**
	 * Only the missing sub-fields are added; the existing ones are left alone.
	 *
	 * @group vector-embeddings
	 */
	public function test_adds_only_missing_sub_fields() {
		$properties = $this->get_target_properties();
		unset( $properties['ep_embeddings_control']['properties']['errors'] );

		$this->create_index( $properties );

		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::APPLIED, $result['status'], $result['message'] );
		$this->assertStringContainsString( 'ep_embeddings_control.errors', $result['message'] );
		$this->assertStringNotContainsString( 'chunks', $result['message'] );
		$this->assertSame( 'text', $this->get_stored_properties()['ep_embeddings_control']['properties']['errors']['type'] );
	}

	/**
	 * An index with the chunks shape of Labs 2.5 (vector only) gets the new chunk fields
	 * in place, and its vector field is left alone.
	 *
	 * @group vector-embeddings
	 */
	public function test_upgrades_previous_chunks_shape_in_place() {
		$properties = $this->get_target_properties();
		unset(
			$properties['chunks']['properties']['text'],
			$properties['chunks']['properties']['hash'],
			$properties['chunks']['properties']['type']
		);

		$this->create_index( $properties );
		$vector_before = $this->get_stored_properties()['chunks']['properties']['vector'];

		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::APPLIED, $result['status'], $result['message'] );
		$this->assertStringContainsString( 'added chunks.text, chunks.hash, chunks.type.', $result['message'] );

		$stored = $this->get_stored_properties();
		$this->assertSame( $vector_before, $stored['chunks']['properties']['vector'] );
		$this->assertSame(
			[
				'type'  => 'text',
				'index' => false,
			],
			$stored['chunks']['properties']['text']
		);
		$this->assertFalse( $stored['chunks']['properties']['hash']['index'] );
		$this->assertFalse( $stored['chunks']['properties']['hash']['doc_values'] );
		$this->assertFalse( $stored['chunks']['properties']['type']['index'] );
	}

	/**
	 * A new index created by ElasticPress gets the chunk fields from the mapping filter.
	 *
	 * @group vector-embeddings
	 */
	public function test_new_index_gets_chunk_fields() {
		$indexable = new Post( $this->get_feature() );
		add_filter( 'ep_post_mapping', [ $indexable, 'add_post_vector_field_mapping' ] );

		Indexables::factory()->get( 'post' )->put_mapping();

		remove_filter( 'ep_post_mapping', [ $indexable, 'add_post_vector_field_mapping' ] );

		$chunks = $this->get_stored_properties()['chunks'];

		$this->assertSame( 'nested', $chunks['type'] );
		$this->assertSame( [ 'hash', 'text', 'type', 'vector' ], $this->sorted_keys( $chunks['properties'] ) );
		$this->assertSame( Upgrades::SKIPPED, $this->get_feature()->ensure_mapping()['status'] );
	}

	/**
	 * The mapping filter keeps fields that are already defined and adds the missing ones,
	 * and applying it twice changes nothing.
	 *
	 * @group vector-embeddings
	 */
	public function test_mapping_filter_adds_only_missing_fields() {
		$indexable = new Post( $this->get_feature() );
		$custom    = [
			'type' => 'dense_vector',
			'dims' => 8,
		];

		$mapping = $indexable->add_vector_mapping_field(
			[
				'mappings' => [
					'properties' => [
						'post_title' => [ 'type' => 'text' ],
						'chunks'     => [
							'type'       => 'nested',
							'properties' => [ 'vector' => $custom ],
						],
					],
				],
			]
		);

		$properties = $mapping['mappings']['properties'];

		$this->assertSame( $custom, $properties['chunks']['properties']['vector'] );
		$this->assertSame( [ 'hash', 'text', 'type', 'vector' ], $this->sorted_keys( $properties['chunks']['properties'] ) );
		$this->assertArrayHasKey( 'ep_embeddings_control', $properties );
		$this->assertSame( [ 'type' => 'text' ], $properties['post_title'] );
		$this->assertSame( $mapping, $indexable->add_vector_mapping_field( $mapping ) );
	}

	/**
	 * Sorted keys of an array.
	 *
	 * @param array $data Array.
	 * @return array
	 */
	protected function sorted_keys( array $data ): array {
		$keys = array_keys( $data );
		sort( $keys );
		return $keys;
	}

	/**
	 * A vector field with other dimensions cannot be changed in place: the routine fails,
	 * asks for a full sync, and changes nothing.
	 *
	 * @group vector-embeddings
	 */
	public function test_conflicting_vector_fails_without_changes() {
		$properties = $this->get_target_properties();
		$properties['chunks']['properties']['vector']['dims'] = 8;
		unset( $properties['ep_embeddings_control'] );

		$this->create_index( $properties );

		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::FAILED, $result['status'] );
		$this->assertStringContainsString( 'chunks.vector.dims', $result['message'] );
		$this->assertStringContainsString( '--setup', $result['message'] );

		$stored = $this->get_stored_properties();
		$this->assertSame( 8, (int) $stored['chunks']['properties']['vector']['dims'] );
		$this->assertArrayNotHasKey( 'ep_embeddings_control', $stored );
	}

	/**
	 * A dry run reports the fields it would add and changes nothing.
	 *
	 * @group vector-embeddings
	 */
	public function test_dry_run_reports_without_changes() {
		$this->create_index( [] );

		$result = $this->get_feature()->ensure_mapping( true );

		$this->assertSame( Upgrades::PENDING, $result['status'] );
		$this->assertStringContainsString( 'would add', $result['message'] );
		$this->assertArrayNotHasKey( 'chunks', $this->get_stored_properties() );
	}

	/**
	 * A missing index is skipped: it gets the fields when it is created.
	 *
	 * @group vector-embeddings
	 */
	public function test_missing_index_is_skipped() {
		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::SKIPPED, $result['status'] );
		$this->assertStringContainsString( 'does not exist', $result['message'] );
		$this->assertFalse( Elasticsearch::factory()->index_exists( $this->index ) );
	}

	/**
	 * While the feature is inactive, the index stays without its fields.
	 *
	 * @group vector-embeddings
	 */
	public function test_inactive_feature_leaves_index_alone() {
		$this->active = false;
		$this->create_index( [] );

		$result = $this->get_feature()->ensure_mapping();

		$this->assertSame( Upgrades::SKIPPED, $result['status'] );
		$this->assertStringContainsString( 'not active', $result['message'] );
		$this->assertArrayNotHasKey( 'chunks', $this->get_stored_properties() );
		$this->assertSame( Upgrades::SKIPPED, ( new Upgrades() )->upgrade_vector_embeddings_mapping( false )['status'] );
		$this->assertArrayNotHasKey( 'chunks', $this->get_stored_properties() );
	}

	/**
	 * Activating the feature adds its fields to the existing index.
	 *
	 * @group vector-embeddings
	 */
	public function test_post_activation_adds_fields() {
		$this->create_index( [] );

		$this->get_feature()->post_activation();

		$this->assertArrayHasKey( 'chunks', $this->get_stored_properties() );
	}

	/**
	 * Settings Elasticsearch adds or returns in another type are not conflicts.
	 *
	 * @group vector-embeddings
	 */
	public function test_mapping_changes_ignore_elasticsearch_defaults() {
		$properties = $this->get_target_properties();
		$vector     = &$properties['chunks']['properties']['vector'];

		$vector['dims'] = (string) $vector['dims'];

		if ( isset( $vector['index_options'] ) ) {
			$vector['index_options'] += [
				'm'               => 16,
				'ef_construction' => 100,
			];
		}

		unset( $vector );

		$changes = ( new Post( $this->get_feature() ) )->get_mapping_changes( $properties );

		$this->assertSame(
			[
				'add'       => [],
				'added'     => [],
				'conflicts' => [],
			],
			$changes
		);
	}

	/**
	 * A field of another type is a conflict, not something to add.
	 *
	 * @group vector-embeddings
	 */
	public function test_mapping_changes_report_type_conflicts() {
		$changes = ( new Post( $this->get_feature() ) )->get_mapping_changes(
			[
				'chunks' => [
					'type'       => 'object',
					'properties' => [],
				],
			]
		);

		$this->assertSame( [ 'chunks.type' ], $changes['conflicts'] );
		$this->assertSame( [ 'ep_embeddings_control' ], $changes['added'] );
	}
}
