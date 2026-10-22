<?php
/**
 * What the AI-generated query can know about the post index.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The fields of the post index a model may use in a query, and the term values of the taxonomies
 * it may filter on.
 *
 * Reading the mapping and the terms is the slow part, and both rarely change, so they are kept in
 * two options: the whole mapping as `path => type`, and the terms of each listed taxonomy. Both are
 * cleared when ElasticPress puts a new mapping and when a sync ends (taxonomy and meta fields are
 * added to the mapping as content is indexed), when ElasticPress or Labs is updated, when the index
 * name changes, and after a day (`ep_chat_index_schema_ttl`), for fields indexed between syncs. A
 * taxonomy's terms are cleared when one of its terms is added, edited, or deleted; the mapping,
 * being in its own option, is not written again then. Which fields are offered, their notes, and
 * which taxonomies are listed are filters, applied on each read, so changing them needs no rebuild.
 */
class Index_Schema {
	/**
	 * Option that keeps the mapping.
	 *
	 * @var string
	 */
	public const OPTION = 'ep_chat_index_schema';

	/**
	 * Option that keeps the term values, with the build time of the mapping they go with.
	 *
	 * @var string
	 */
	public const TERMS_OPTION = 'ep_chat_index_schema_terms';

	/**
	 * Most terms kept per listed taxonomy, before the `ep_chat_ai_query_term_limit` filter.
	 *
	 * @var int
	 */
	protected const MAX_TERMS = 500;

	/**
	 * Register the hooks that clear the cache.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'ep_after_put_mapping', [ $this, 'clear' ] );
		add_action( 'ep_after_sync_index', [ $this, 'clear' ] );
		add_action( 'created_term', [ $this, 'clear_terms' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'clear_terms' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'clear_terms' ], 10, 3 );
	}

	/**
	 * Forget the mapping and the term values. The next read builds them again.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION );
		delete_option( self::TERMS_OPTION );
	}

	/**
	 * Forget the term values of one taxonomy, after one of its terms changed.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function clear_terms( $term_id, $tt_id, $taxonomy ) {
		unset( $term_id, $tt_id );

		$stored = get_option( self::TERMS_OPTION );

		if ( ! is_array( $stored ) || ! isset( $stored['terms'][ $taxonomy ] ) ) {
			return;
		}

		unset( $stored['terms'][ $taxonomy ] );
		update_option( self::TERMS_OPTION, $stored, false );
	}

	/**
	 * The fields a model may use, with their types and notes.
	 *
	 * @return array<string, array{type: string, note: string}> By field path (`post_title`,
	 *                                                          `terms.category.slug`), in mapping
	 *                                                          order. Empty when the mapping cannot
	 *                                                          be read.
	 */
	public function get_fields(): array {
		$mapping = $this->get_stored()['mapping'];
		$pattern = $this->to_regex( $this->get_field_patterns() );
		$notes   = $this->get_notes();
		$fields  = [];

		if ( '' === $pattern ) {
			return [];
		}

		foreach ( $mapping as $path => $type ) {
			if ( preg_match( $pattern, (string) $path ) ) {
				$fields[ $path ] = [
					'type' => (string) $type,
					'note' => (string) ( $notes[ $path ] ?? '' ),
				];
			}
		}

		return $fields;
	}

	/**
	 * The terms of each listed taxonomy, most used first.
	 *
	 * @return array<string, array<string, string>> Taxonomy => [ slug => name ].
	 */
	public function get_terms(): array {
		$taxonomies = $this->get_listed_taxonomies();

		if ( ! $taxonomies ) {
			return [];
		}

		$schema  = $this->get_stored();
		$stored  = get_option( self::TERMS_OPTION );
		$changed = false;
		$limit   = $this->get_term_limit();
		$terms   = [];

		// Terms read for an older mapping are read again.
		if ( ! is_array( $stored ) || ( $stored['built'] ?? 0 ) !== $schema['built'] || ! is_array( $stored['terms'] ?? null ) ) {
			$stored = [
				'built' => $schema['built'],
				'terms' => [],
			];
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! isset( $stored['terms'][ $taxonomy ] ) || ! is_array( $stored['terms'][ $taxonomy ] ) ) {
				$stored['terms'][ $taxonomy ] = $this->read_terms( $taxonomy );
				$changed                      = true;
			}

			$terms[ $taxonomy ] = array_slice( $stored['terms'][ $taxonomy ], 0, $limit, true );
		}

		if ( $changed && $schema['mapping'] ) {
			update_option( self::TERMS_OPTION, $stored, false );
		}

		return $terms;
	}

	/**
	 * The stored mapping, read again when missing or out of date.
	 *
	 * @return array{index: string, version: string, built: int, mapping: array<string, string>}
	 */
	protected function get_stored(): array {
		$index   = $this->get_index_name();
		$version = $this->get_version();
		$stored  = get_option( self::OPTION );

		if (
			is_array( $stored )
			&& ( $stored['index'] ?? '' ) === $index
			&& ( $stored['version'] ?? '' ) === $version
			&& (int) ( $stored['built'] ?? 0 ) + $this->get_ttl() > time()
			&& ! empty( $stored['mapping'] )
			&& is_array( $stored['mapping'] )
		) {
			$stored['built'] = (int) $stored['built'];

			return $stored;
		}

		$stored = [
			'index'   => $index,
			'version' => $version,
			'built'   => time(),
			'mapping' => $this->read_mapping(),
		];

		// An index that cannot be read is not kept, so the next question tries again.
		if ( $stored['mapping'] ) {
			update_option( self::OPTION, $stored, false );
		}

		return $stored;
	}

	/**
	 * Read the post index mapping from Elasticsearch, as `path => type`. Multi-fields are paths
	 * too (`post_title.raw`).
	 *
	 * @return array<string, string> Empty when the mapping cannot be read.
	 */
	protected function read_mapping(): array {
		$indexable = \ElasticPress\Indexables::factory()->get( 'post' );

		if ( ! $indexable ) {
			return [];
		}

		$mapping = $indexable->get_mapping();
		$index   = is_array( $mapping ) ? reset( $mapping ) : null;

		if ( ! is_array( $index ) || ! is_array( $index['mappings']['properties'] ?? null ) ) {
			return [];
		}

		return $this->flatten( $index['mappings']['properties'] );
	}

	/**
	 * Mapping properties as `path => type`, depth first. Fields that are not indexed
	 * (`index: false`, such as the text of the chunks) cannot be searched, so they are left out.
	 *
	 * @param array  $properties Mapping properties.
	 * @param string $prefix     Path of the parent field, with a trailing dot.
	 * @return array<string, string>
	 */
	public function flatten( array $properties, string $prefix = '' ): array {
		$paths = [];

		foreach ( $properties as $name => $config ) {
			if ( ! is_array( $config ) || false === ( $config['index'] ?? true ) ) {
				continue;
			}

			$path           = $prefix . $name;
			$paths[ $path ] = (string) ( $config['type'] ?? 'object' );

			foreach ( (array) ( $config['fields'] ?? [] ) as $sub_name => $sub_config ) {
				if ( is_array( $sub_config ) && false !== ( $sub_config['index'] ?? true ) ) {
					$paths[ $path . '.' . $sub_name ] = (string) ( $sub_config['type'] ?? 'object' );
				}
			}

			if ( is_array( $config['properties'] ?? null ) ) {
				$paths = array_merge( $paths, $this->flatten( $config['properties'], $path . '.' ) );
			}
		}

		return $paths;
	}

	/**
	 * Read the most used terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string, string> Slug => name.
	 */
	protected function read_terms( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => self::MAX_TERMS,
				'orderby'    => 'count',
				'order'      => 'DESC',
			]
		);
		$found = [];

		if ( ! is_array( $terms ) ) {
			return [];
		}

		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$found[ (string) $term->slug ] = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $found;
	}

	/**
	 * Taxonomies of the post types Chat searches that are public.
	 *
	 * @return string[]
	 */
	public function get_taxonomies(): array {
		$taxonomies = [];

		foreach ( ( new Retriever() )->get_post_types() as $post_type ) {
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->public ) {
					$taxonomies[ $taxonomy->name ] = $taxonomy->name;
				}
			}
		}

		return array_values( $taxonomies );
	}

	/**
	 * Patterns of the fields a model may use. `*` stands for one path segment.
	 *
	 * @return string[]
	 */
	protected function get_field_patterns(): array {
		$patterns = [
			'post_title',
			'post_title.suggest',
			'term_suggest',
			'post_excerpt',
			'post_content',
			'post_date',
			'post_modified',
			'post_type.raw',
			'post_author.display_name',
			'chunks',
			'chunks.vector',
		];

		foreach ( $this->get_taxonomies() as $taxonomy ) {
			$patterns[] = 'terms.' . $taxonomy . '.name';
			$patterns[] = 'terms.' . $taxonomy . '.slug';
		}

		/**
		 * Filters which fields of the post index the AI-generated query may use. Each pattern is a
		 * field path; `*` stands for one path segment (`meta.*.value`). By default: title (and its
		 * partial-word field), term names for partial words, excerpt,
		 * content, dates, post type, author name, the content chunk vectors, and the name and slug
		 * of the public taxonomies of the post types Chat searches. Fields the index does not
		 * search (`index: false`) are never offered.
		 *
		 * The question steers the query, so anyone who can ask can, in effect, search these fields
		 * for exact phrases (within the posts Chat searches). Add only fields whose values those
		 * people may see: a meta field that is not shown on the site is better left out.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ai_query_fields
		 * @param {string[]} $patterns Field patterns.
		 * @return {string[]} Filtered patterns.
		 */
		$patterns = apply_filters( 'ep_chat_ai_query_fields', $patterns );

		return array_values( array_filter( array_map( 'strval', is_array( $patterns ) ? $patterns : [] ) ) );
	}

	/**
	 * Notes that tell the model what a field holds.
	 *
	 * @return array<string, string> Field path => note.
	 */
	protected function get_notes(): array {
		$notes = [
			'post_title'               => __( 'Title.', 'elasticpress-labs' ),
			'post_title.suggest'       => __( 'Title, matching parts of words.', 'elasticpress-labs' ),
			'term_suggest'             => __( 'Term names, matching parts of words.', 'elasticpress-labs' ),
			'post_excerpt'             => __( 'Summary.', 'elasticpress-labs' ),
			'post_content'             => __( 'Full text.', 'elasticpress-labs' ),
			'post_date'                => __( 'Publish date.', 'elasticpress-labs' ),
			'post_modified'            => __( 'Last update date.', 'elasticpress-labs' ),
			'post_type.raw'            => __( 'Content type.', 'elasticpress-labs' ),
			'post_author.display_name' => __( 'Author name.', 'elasticpress-labs' ),
			'chunks'                   => __( 'Passages of the content, each with its own vector. Only the vector can be searched.', 'elasticpress-labs' ),
			'chunks.vector'            => __( 'Passage vector, for semantic (kNN) search.', 'elasticpress-labs' ),
		];

		foreach ( $this->get_taxonomies() as $taxonomy ) {
			$label = (string) get_taxonomy( $taxonomy )->label;

			/* translators: %s: taxonomy name, for example Categories */
			$notes[ 'terms.' . $taxonomy . '.name' ] = sprintf( __( '%s: term name.', 'elasticpress-labs' ), $label );
			/* translators: %s: taxonomy name, for example Categories */
			$notes[ 'terms.' . $taxonomy . '.slug' ] = sprintf( __( '%s: term slug, exact.', 'elasticpress-labs' ), $label );
		}

		/**
		 * Filters the notes that tell the model what each field of the post index holds.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ai_query_field_notes
		 * @param {array} $notes Field path => note.
		 * @return {array} Filtered notes.
		 */
		$notes = apply_filters( 'ep_chat_ai_query_field_notes', $notes );

		return is_array( $notes ) ? array_map( 'strval', $notes ) : [];
	}

	/**
	 * Taxonomies whose terms are listed for the model, so it filters on slugs that exist.
	 *
	 * @return string[]
	 */
	protected function get_listed_taxonomies(): array {
		/**
		 * Filters the taxonomies whose terms (name and slug) are listed for the AI-generated query.
		 * Each listed term is part of every query prompt, so list small taxonomies only. A slug the
		 * model writes for a taxonomy that is not listed is matched by name instead.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ai_query_listed_taxonomies
		 * @param {string[]} $taxonomies Taxonomy slugs. Default none.
		 * @return {string[]} Filtered taxonomies.
		 */
		$taxonomies = apply_filters( 'ep_chat_ai_query_listed_taxonomies', [] );

		return array_values( array_unique( array_filter( array_map( 'strval', is_array( $taxonomies ) ? $taxonomies : [] ) ) ) );
	}

	/**
	 * Most terms listed per taxonomy.
	 *
	 * @return int
	 */
	protected function get_term_limit(): int {
		/**
		 * Filters how many terms of each listed taxonomy are listed for the AI-generated query, most
		 * used first.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ai_query_term_limit
		 * @param {int} $limit Terms. Default 100, at most 500.
		 * @return {int} Filtered limit.
		 */
		return min( self::MAX_TERMS, max( 1, (int) apply_filters( 'ep_chat_ai_query_term_limit', 100 ) ) );
	}

	/**
	 * Seconds the mapping is kept.
	 *
	 * @return int
	 */
	protected function get_ttl(): int {
		/**
		 * Filters how long the post index mapping is kept for the AI-generated query. It is also
		 * cleared when ElasticPress puts a mapping or a sync ends; this limit catches taxonomy and
		 * meta fields that content indexed between syncs added.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_index_schema_ttl
		 * @param {int} $ttl Seconds. Default one day.
		 * @return {int} Filtered seconds.
		 */
		return max( 1, (int) apply_filters( 'ep_chat_index_schema_ttl', DAY_IN_SECONDS ) );
	}

	/**
	 * Name of the post index.
	 *
	 * @return string
	 */
	protected function get_index_name(): string {
		$indexable = \ElasticPress\Indexables::factory()->get( 'post' );

		return $indexable ? (string) $indexable->get_index_name() : '';
	}

	/**
	 * ElasticPress and Labs versions: an update can change the mapping.
	 *
	 * @return string
	 */
	protected function get_version(): string {
		return ( defined( 'EP_VERSION' ) ? EP_VERSION : '' ) . '/' . ELASTICPRESS_LABS_VERSION;
	}

	/**
	 * One regex for field patterns: exact paths, `*` for one path segment.
	 *
	 * @param string[] $patterns Patterns.
	 * @return string Empty when there are no patterns.
	 */
	protected function to_regex( array $patterns ): string {
		$parts = [];

		foreach ( $patterns as $pattern ) {
			$parts[] = str_replace( '\*', '[^.]+', preg_quote( $pattern, '/' ) );
		}

		return $parts ? '/^(?:' . implode( '|', $parts ) . ')$/' : '';
	}
}
