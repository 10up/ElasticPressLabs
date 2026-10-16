<?php
/**
 * Test what the AI-generated query knows about the post index
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use ElasticPressLabs\Feature\Chat\Index_Schema;

/**
 * Index_Schema tests.
 */
class TestChatIndexSchema extends BaseTestCase {

	/**
	 * Mapping requests sent to Elasticsearch in the current test.
	 *
	 * @var int
	 */
	protected $mapping_requests = 0;

	/**
	 * Schema with its cache hooks registered.
	 *
	 * @var Index_Schema
	 */
	protected $schema;

	/**
	 * Setup each test: a clean post index with one post in a category (taxonomy fields are added
	 * to the mapping as content is indexed), and no cached schema.
	 */
	public function set_up() {
		parent::set_up();

		Elasticsearch::factory()->delete_index( Indexables::factory()->get( 'post' )->get_index_name() );
		Indexables::factory()->get( 'post' )->put_mapping();

		$this->ep_factory->post->create( [ 'post_title' => 'Indexed' ] );
		Elasticsearch::factory()->refresh_indices();

		delete_option( Index_Schema::OPTION );

		$this->schema = new Index_Schema();
		$this->schema->setup();

		$this->mapping_requests = 0;

		add_action( 'ep_remote_request', [ $this, 'count_mapping_request' ], 10, 2 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_action( 'ep_remote_request', [ $this, 'count_mapping_request' ], 10 );
		remove_action( 'ep_after_put_mapping', [ $this->schema, 'clear' ] );
		remove_action( 'ep_after_sync_index', [ $this->schema, 'clear' ] );
		remove_action( 'created_term', [ $this->schema, 'clear_terms' ], 10 );
		remove_action( 'edited_term', [ $this->schema, 'clear_terms' ], 10 );
		remove_action( 'delete_term', [ $this->schema, 'clear_terms' ], 10 );
		delete_option( Index_Schema::OPTION );

		parent::tear_down();
	}

	/**
	 * Count the mapping requests.
	 *
	 * @param array  $query Remote request.
	 * @param string $type  Request type.
	 * @return void
	 */
	public function count_mapping_request( $query, $type ) {
		unset( $query );

		if ( 'get_mapping' === $type ) {
			++$this->mapping_requests;
		}
	}

	/**
	 * The fields come from the index mapping, limited to the default list: content fields and
	 * the public taxonomies, each with a note. Private fields are left out.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_get_fields_lists_the_default_fields() {
		$fields = ( new Index_Schema() )->get_fields();

		$this->assertSame( 1, $this->mapping_requests );
		$this->assertSame( 'text', $fields['post_title']['type'] );
		$this->assertSame( 'date', $fields['post_date']['type'] );
		$this->assertSame( 'keyword', $fields['post_type.raw']['type'] );
		$this->assertSame( 'keyword', $fields['terms.category.slug']['type'] );
		$this->assertSame( 'text', $fields['terms.category.name']['type'] );
		$this->assertStringContainsString( get_taxonomy( 'category' )->label, $fields['terms.category.slug']['note'] );
		$this->assertNotEmpty( $fields['post_title']['note'] );

		foreach ( [ 'post_password', 'guid', 'ping_status', 'comment_status', 'post_author.login', 'terms.category.term_id', 'post_title.raw' ] as $private ) {
			$this->assertArrayNotHasKey( $private, $fields, $private );
		}
	}

	/**
	 * The mapping is read once and then kept: a second read sends no request.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_mapping_is_cached() {
		( new Index_Schema() )->get_fields();
		$fields = ( new Index_Schema() )->get_fields();

		$this->assertSame( 1, $this->mapping_requests );
		$this->assertArrayHasKey( 'post_title', $fields );
		$this->assertNotEmpty( get_option( Index_Schema::OPTION )['mapping'] );
	}

	/**
	 * A new mapping (a sync with setup) clears the cache.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_put_mapping_clears_the_cache() {
		( new Index_Schema() )->get_fields();

		Indexables::factory()->get( 'post' )->put_mapping();

		$this->assertFalse( get_option( Index_Schema::OPTION ) );

		( new Index_Schema() )->get_fields();

		$this->assertSame( 2, $this->mapping_requests );
	}

	/**
	 * The end of a sync clears the cache: the sync added taxonomy and meta fields.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_sync_end_clears_the_cache() {
		( new Index_Schema() )->get_fields();

		do_action( 'ep_after_sync_index', [] );

		$this->assertFalse( get_option( Index_Schema::OPTION ) );
	}

	/**
	 * A cache older than its lifetime is read again.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_expired_cache_is_rebuilt() {
		( new Index_Schema() )->get_fields();

		$stored          = get_option( Index_Schema::OPTION );
		$stored['built'] = time() - DAY_IN_SECONDS - 1;
		update_option( Index_Schema::OPTION, $stored, false );

		( new Index_Schema() )->get_fields();

		$this->assertSame( 2, $this->mapping_requests );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, get_option( Index_Schema::OPTION )['built'] );
	}

	/**
	 * A cache from another index name or another version is read again.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_cache_from_another_index_or_version_is_rebuilt() {
		( new Index_Schema() )->get_fields();

		$stored          = get_option( Index_Schema::OPTION );
		$stored['index'] = 'another-index';
		update_option( Index_Schema::OPTION, $stored, false );

		( new Index_Schema() )->get_fields();

		$stored            = get_option( Index_Schema::OPTION );
		$stored['version'] = '1.0.0/1.0.0';
		update_option( Index_Schema::OPTION, $stored, false );

		( new Index_Schema() )->get_fields();

		$this->assertSame( 3, $this->mapping_requests );
		$this->assertSame( Indexables::factory()->get( 'post' )->get_index_name(), get_option( Index_Schema::OPTION )['index'] );
	}

	/**
	 * When the mapping cannot be read, there are no fields and nothing is cached, so the next
	 * read tries again.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_unreadable_mapping_is_not_cached() {
		Elasticsearch::factory()->delete_index( Indexables::factory()->get( 'post' )->get_index_name() );

		$this->assertSame( [], ( new Index_Schema() )->get_fields() );
		$this->assertFalse( get_option( Index_Schema::OPTION ) );
	}

	/**
	 * The field list and the notes are filters applied on each read: changing them needs no new
	 * mapping request. `*` stands for one path segment.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_field_filters_apply_without_rebuild() {
		( new Index_Schema() )->get_fields();

		$patterns = static function () {
			return [ 'post_name', 'terms.*.name' ];
		};
		$notes    = static function ( $notes ) {
			$notes['post_name'] = 'URL slug.';

			return $notes;
		};

		add_filter( 'ep_chat_ai_query_fields', $patterns );
		add_filter( 'ep_chat_ai_query_field_notes', $notes );

		$fields = ( new Index_Schema() )->get_fields();

		remove_filter( 'ep_chat_ai_query_fields', $patterns );
		remove_filter( 'ep_chat_ai_query_field_notes', $notes );

		$this->assertSame( 1, $this->mapping_requests );
		$this->assertSame( 'URL slug.', $fields['post_name']['note'] );
		$this->assertArrayHasKey( 'terms.category.name', $fields );
		$this->assertArrayNotHasKey( 'terms.category.name.raw', $fields, '`*` matches one segment only.' );
		$this->assertArrayNotHasKey( 'terms.category.slug', $fields );
		$this->assertArrayNotHasKey( 'post_title', $fields );
	}

	/**
	 * Flattening keeps nested fields and multi-fields as their own paths, and leaves out fields
	 * that are not indexed.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_flatten() {
		$paths = ( new Index_Schema() )->flatten(
			[
				'post_title' => [
					'type'   => 'text',
					'fields' => [
						'raw' => [ 'type' => 'keyword' ],
					],
				],
				'chunks'     => [
					'type'       => 'nested',
					'properties' => [
						'text'   => [
							'type'  => 'text',
							'index' => false,
						],
						'vector' => [ 'type' => 'dense_vector' ],
					],
				],
				'thumbnail'  => [
					'properties' => [
						'ID' => [ 'type' => 'long' ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'post_title'     => 'text',
				'post_title.raw' => 'keyword',
				'chunks'         => 'nested',
				'chunks.vector'  => 'dense_vector',
				'thumbnail'      => 'object',
				'thumbnail.ID'   => 'long',
			],
			$paths
		);
	}

	/**
	 * Listed taxonomies get their used terms, most used first, with plain-text names, up to the
	 * limit. Taxonomies that are not listed get none.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_get_terms_lists_used_terms_of_listed_taxonomies() {
		$this->assertSame( [], ( new Index_Schema() )->get_terms() );

		$popular = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Religion &amp; Politics',
				'slug'     => 'religion-politics-1',
			]
		);
		$rare    = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Science',
				'slug'     => 'science',
			]
		);
		$unused  = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Unused',
				'slug'     => 'unused',
			]
		);

		$this->factory->post->create( [ 'post_category' => [ $popular ] ] );
		$this->factory->post->create( [ 'post_category' => [ $popular, $rare ] ] );
		$this->factory->post->create( [ 'post_category' => [ $popular, $rare ] ] );
		unset( $unused );

		$listed = static function () {
			return [ 'category' ];
		};
		$limit  = static function () {
			return 2;
		};

		add_filter( 'ep_chat_ai_query_listed_taxonomies', $listed );
		add_filter( 'ep_chat_ai_query_term_limit', $limit );

		$terms = ( new Index_Schema() )->get_terms();

		remove_filter( 'ep_chat_ai_query_listed_taxonomies', $listed );
		remove_filter( 'ep_chat_ai_query_term_limit', $limit );

		$this->assertSame(
			[
				'religion-politics-1' => 'Religion & Politics',
				'science'             => 'Science',
			],
			$terms['category']
		);
		$this->assertArrayHasKey( 'category', get_option( Index_Schema::OPTION )['terms'] );
	}

	/**
	 * Adding, editing, or deleting a term clears the stored terms of its taxonomy only.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_term_change_clears_its_taxonomy() {
		$listed = static function () {
			return [ 'category', 'post_tag' ];
		};

		add_filter( 'ep_chat_ai_query_listed_taxonomies', $listed );

		( new Index_Schema() )->get_terms();

		$this->assertSame( [ 'category', 'post_tag' ], array_keys( get_option( Index_Schema::OPTION )['terms'] ) );

		$term = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'New',
			]
		);

		$this->assertSame( [ 'post_tag' ], array_keys( get_option( Index_Schema::OPTION )['terms'] ) );

		( new Index_Schema() )->get_terms();
		wp_update_term( $term, 'category', [ 'name' => 'Renamed' ] );

		$this->assertSame( [ 'post_tag' ], array_keys( get_option( Index_Schema::OPTION )['terms'] ) );

		( new Index_Schema() )->get_terms();
		wp_delete_term( $term, 'category' );

		remove_filter( 'ep_chat_ai_query_listed_taxonomies', $listed );

		$this->assertSame( [ 'post_tag' ], array_keys( get_option( Index_Schema::OPTION )['terms'] ) );
		$this->assertSame( 1, $this->mapping_requests );
	}
}
