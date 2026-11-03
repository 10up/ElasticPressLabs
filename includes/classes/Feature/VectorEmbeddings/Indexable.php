<?php
/**
 * Vector Embeddings - Indexable
 *
 * As each indexable type (posts, terms, comments, users) uses different hooks, this abstract class is used to
 * keep implementations independent.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

use ElasticPress\Elasticsearch;

/**
 * Vector Embeddings Indexable abstract class
 */
abstract class Indexable {
	/**
	 * VectorEmbeddings instance
	 *
	 * @var VectorEmbeddings
	 */
	protected $feature;

	/**
	 * Class constructor
	 *
	 * @param VectorEmbeddings $feature The VectorEmbeddings feature instance
	 */
	public function __construct( VectorEmbeddings $feature ) {
		$this->feature = $feature;
	}

	/**
	 * Add the vector fields to the Elasticsearch mapping.
	 *
	 * Fields already in the mapping are kept as they are; only missing ones are added,
	 * including missing sub-fields of `chunks` and `ep_embeddings_control`.
	 *
	 * @since 2.5.0
	 * @param array $mapping      Current mapping.
	 * @param bool  $quantization Whether to use quantization for the vector field. Default false.
	 * @return array
	 */
	public function add_vector_mapping_field( array $mapping, bool $quantization = true ): array {
		if ( ! isset( $mapping['mappings'], $mapping['mappings']['properties'] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'The mapping is not valid.', 'elasticpress-labs' ),
				'ElasticPress Labs 2.5.1'
			);
			return $mapping;
		}

		$mapping['mappings']['properties'] = $this->merge_missing_properties(
			$mapping['mappings']['properties'],
			$this->get_vector_mapping_properties( $quantization )
		);

		return $mapping;
	}

	/**
	 * The mapping properties this feature adds.
	 *
	 * `chunks` holds one nested document per chunk:
	 *
	 * - `vector`: the embedding;
	 * - `text`: the exact text that was embedded, returned with a matching chunk
	 *   (in `_source` only, not searchable: post fields already cover keyword search);
	 * - `hash`: identifies that text, so an unchanged chunk keeps its vector on resync;
	 * - `type`: what the chunk comes from: `content`, `taxonomy`, or `meta`.
	 *
	 * @since 2.6.0
	 * @param bool $quantization Whether to use quantization for the vector field.
	 * @return array
	 */
	protected function get_vector_mapping_properties( bool $quantization ): array {
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();

		$vector = [
			'type' => 'dense_vector',
			'dims' => $this->feature->get_dimensions(),
		];

		// Add extra vector fields for newer versions of Elasticsearch.
		if ( version_compare( $es_version, '8.0', '>=' ) ) {
			// The index (true or false, default true) and similarity (l2_norm, dot_product or cosine) fields
			// were added in 8.0. The similarity field must be set if index is true.
			$vector['index']      = true;
			$vector['similarity'] = 'cosine';

			// The element_type field was added in 8.6. This can be either float (default) or byte.
			if ( version_compare( $es_version, '8.6', '>=' ) ) {
				$vector['element_type'] = 'float';
			}

			// The int8_hnsw type was added in 8.12.
			if ( $quantization && version_compare( $es_version, '8.12', '>=' ) ) {
				// This is supposed to result in better performance but slightly less accurate results.
				// See https://www.elastic.co/guide/en/elasticsearch/reference/8.13/knn-search.html#knn-search-quantized-example.
				// Can test with this on and off and compare results to see what works best.
				$vector['index_options']['type'] = 'int8_hnsw';
			}
		}

		return [
			'ep_embeddings_control' => [
				'properties' => [
					'is_processing' => [
						'type' => 'boolean',
					],
					'errors'        => [
						'type' => 'text',
					],
					'text_chunks'   => [
						'type' => 'text',
					],
				],
			],
			'chunks'                => [
				'type'       => 'nested',
				'properties' => [
					'vector' => $vector,
					'text'   => [
						'type'  => 'text',
						'index' => false,
					],
					'hash'   => [
						'type'       => 'keyword',
						'index'      => false,
						'doc_values' => false,
					],
					'type'   => [
						'type'  => 'keyword',
						'index' => false,
					],
				],
			],
		];
	}

	/**
	 * Add the target fields missing from existing mapping properties.
	 *
	 * Recurses into `properties`. A field that exists is never changed.
	 *
	 * @since 2.6.0
	 * @param array $properties Existing properties.
	 * @param array $target     Target properties.
	 * @return array
	 */
	protected function merge_missing_properties( array $properties, array $target ): array {
		foreach ( $target as $name => $definition ) {
			if ( ! isset( $properties[ $name ] ) || ! is_array( $properties[ $name ] ) ) {
				$properties[ $name ] = $definition;
				continue;
			}

			if ( isset( $definition['properties'] ) && isset( $properties[ $name ]['properties'] ) && is_array( $properties[ $name ]['properties'] ) ) {
				$properties[ $name ]['properties'] = $this->merge_missing_properties( $properties[ $name ]['properties'], $definition['properties'] );
			}
		}

		return $properties;
	}

	/**
	 * Compare the fields this indexable adds to the mapping with an index's stored mapping.
	 *
	 * Fields that are missing can be added to the existing index with `PUT _mapping`.
	 * Fields whose stored definition differs (dimensions, similarity, index options) cannot:
	 * they need the index to be recreated (a sync with `--setup`).
	 *
	 * @since 2.6.0
	 * @param array $stored_properties `mappings.properties` of the stored index mapping.
	 * @param array $target_properties Optional. `mappings.properties` the index should have, as a
	 *                                 new index gets them. Only this indexable's fields are compared.
	 *                                 Defaults to the fields this indexable adds.
	 * @return array {
	 *     @type array    $add       Properties to send with `PUT _mapping`. Empty when nothing is missing.
	 *     @type string[] $added     Dotted paths of the fields in `$add`.
	 *     @type string[] $conflicts Dotted paths of the settings that differ, e.g. `chunks.vector.dims`.
	 * }
	 */
	public function get_mapping_changes( array $stored_properties, array $target_properties = [] ): array {
		$own_properties = $this->get_vector_mapping_properties( true );
		$target         = $target_properties ? array_intersect_key( $target_properties, $own_properties ) : $own_properties;

		return $this->diff_mapping_properties( $target, $stored_properties, '' );
	}

	/**
	 * Recursively compare target mapping properties with stored ones.
	 *
	 * @since 2.6.0
	 * @param array  $target Target properties.
	 * @param array  $stored Stored properties.
	 * @param string $prefix Dotted path of the parent field.
	 * @return array See get_mapping_changes().
	 */
	protected function diff_mapping_properties( array $target, array $stored, string $prefix ): array {
		$changes = [
			'add'       => [],
			'added'     => [],
			'conflicts' => [],
		];

		foreach ( $target as $name => $definition ) {
			$path = $prefix . $name;

			if ( ! isset( $stored[ $name ] ) || ! is_array( $stored[ $name ] ) ) {
				$changes['add'][ $name ] = $definition;
				$changes['added'][]      = $path;
				continue;
			}

			if ( isset( $definition['properties'] ) ) {
				$target_type = $definition['type'] ?? 'object';
				$stored_type = $stored[ $name ]['type'] ?? 'object';

				if ( $target_type !== $stored_type ) {
					$changes['conflicts'][] = "{$path}.type";
					continue;
				}

				$sub = $this->diff_mapping_properties( $definition['properties'], $stored[ $name ]['properties'] ?? [], "{$path}." );

				if ( $sub['add'] ) {
					$changes['add'][ $name ] = array_merge(
						isset( $definition['type'] ) ? [ 'type' => $definition['type'] ] : [],
						[ 'properties' => $sub['add'] ]
					);
				}

				$changes['added']     = array_merge( $changes['added'], $sub['added'] );
				$changes['conflicts'] = array_merge( $changes['conflicts'], $sub['conflicts'] );
				continue;
			}

			$changes['conflicts'] = array_merge(
				$changes['conflicts'],
				$this->diff_field_settings( $definition, $stored[ $name ], $path )
			);
		}

		return $changes;
	}

	/**
	 * Settings of a field definition that differ from the stored field.
	 *
	 * Only settings present in both are compared: Elasticsearch leaves out some defaults,
	 * and adds others (like HNSW parameters) that the target does not set.
	 *
	 * @since 2.6.0
	 * @param array  $target Target field definition.
	 * @param array  $stored Stored field definition.
	 * @param string $path   Dotted path of the field.
	 * @return string[] Dotted paths of the differing settings.
	 */
	protected function diff_field_settings( array $target, array $stored, string $path ): array {
		$conflicts = [];

		foreach ( $target as $setting => $value ) {
			if ( ! array_key_exists( $setting, $stored ) ) {
				continue;
			}

			// ElasticPress.io sets the dimensions itself.
			if ( 'dims' === $setting && (int) $value < 1 ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$conflicts = array_merge(
					$conflicts,
					is_array( $stored[ $setting ] ) ? $this->diff_field_settings( $value, $stored[ $setting ], "{$path}.{$setting}" ) : [ "{$path}.{$setting}" ]
				);
				continue;
			}

			if ( $this->normalize_mapping_value( $value ) !== $this->normalize_mapping_value( $stored[ $setting ] ) ) {
				$conflicts[] = "{$path}.{$setting}";
			}
		}

		return $conflicts;
	}

	/**
	 * Normalize a mapping value for comparison (Elasticsearch may return `"true"` or `1024` as strings).
	 *
	 * @since 2.6.0
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function normalize_mapping_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	}

	/**
	 * Add the embedding data to the post vector sync args.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Added the `$chunk_items` parameter.
	 * @param array $args        The current sync args (an Elasticsearch document)
	 * @param array $embeddings  The embeddings to add to the sync args
	 * @param array $chunk_items Optional. The chunks the embeddings were made from, in the same
	 *                           order: `[ 'text' => string, 'type' => string, 'hash' => string ]` each.
	 * @return array
	 */
	public function add_chunks_field_value( array $args, array $embeddings, array $chunk_items = [] ): array {
		// If we still don't have embeddings, return early.
		if ( empty( $embeddings ) ) {
			return $args;
		}

		// Only pair texts with vectors when every vector has its chunk.
		$chunk_items = count( $chunk_items ) === count( $embeddings ) ? array_values( $chunk_items ) : [];

		/**
		 * Filters whether each chunk's text is stored with its vector.
		 *
		 * The text is stored in `_source` only (it is not searchable), and adds to the index
		 * size roughly as much as the post content. Without it, semantic search returns no
		 * matching chunks, and AI Chat has no passages to answer from.
		 *
		 * @hook ep_embeddings_store_chunk_text
		 * @since 2.6.0
		 * @param {bool}  $store       Whether to store the text. Default true.
		 * @param {array} $args        The Elasticsearch document.
		 * @param {array} $chunk_items The chunks: `text` and `type` each.
		 * @return {bool} New value.
		 */
		$store_text = (bool) apply_filters( 'ep_embeddings_store_chunk_text', true, $args, $chunk_items );

		// Add the embeddings data to the sync args.
		$args['chunks'] = [];

		foreach ( array_values( $embeddings ) as $index => $embedding ) {
			$chunk = [
				'vector' => array_map( 'floatval', $embedding ),
			];

			if ( isset( $chunk_items[ $index ] ) ) {
				if ( $store_text ) {
					$chunk['text'] = (string) ( $chunk_items[ $index ]['text'] ?? '' );
				}

				$chunk['type'] = (string) ( $chunk_items[ $index ]['type'] ?? '' );

				if ( ! empty( $chunk_items[ $index ]['hash'] ) ) {
					$chunk['hash'] = (string) $chunk_items[ $index ]['hash'];
				}
			}

			$args['chunks'][] = $chunk;
		}

		return $args;
	}

	/**
	 * Determine the way to send the text chunks to the Elasticsearch server.
	 *
	 * Depending on the size of the text chunks, it can either be sent using the index action args
	 * (the JSON object where we determine the index to be used, etc.) or as a regular field in the Elasticsearch.
	 * To avoid overhead, we prefer to send it as an index action arg, but sometimes it is just too big for it.
	 *
	 * @param array $text_chunks The text chunks
	 * @return string The method. Can be 'index_action_args' or 'es_doc_field'.
	 */
	protected function get_text_chunks_sending_method( $text_chunks ) {
		$post_chunks_size = mb_strlen( wp_json_encode( $text_chunks ), '8bit' );

		/**
		 * Filter to determine the threshold size for the text chunks to be sent as an index action arg.
		 *
		 * @hook ep_embeddings_sending_method_limit
		 * @since 2.4.0
		 *
		 * @param {int} $size The size limit in bytes. Defaults to 200kb.
		 * @return {int} The new $size value.
		 */
		$size_limit = apply_filters( 'ep_embeddings_sending_method_limit', 200 * KB_IN_BYTES );

		$method = $post_chunks_size < $size_limit ? 'index_action_args' : 'es_doc_field';

		/**
		 * Filter to determine the method to be used to send the text chunks.
		 *
		 * Unless you are implementing a custom solution, return should be either 'index_action_args' or 'es_doc_field'.
		 *
		 * @hook ep_embeddings_sending_method
		 * @since 2.4.0
		 *
		 * @param {string} $method           The method to be used.
		 * @param {int}    $text_chunks      The text chunks being analyzed.
		 * @param {int}    $post_chunks_size The determined size of the text chunks.
		 * @param {int}    $size_limit       The size limit in bytes. Defaults to 200kb.
		 * @return {string} The new $method value.
		 */
		return apply_filters( 'ep_embeddings_sending_method', $method, $text_chunks, $post_chunks_size, $size_limit );
	}
}
