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
	 * Add a vector field to the Elasticsearch mapping.
	 *
	 * @param array $mapping      Current mapping.
	 * @param bool  $quantization Whether to use quantization for the vector field. Default false.
	 * @return array
	 */
	public function add_vector_mapping_field( array $mapping, bool $quantization = true ): array {
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();

		// Don't add the field if it already exists.
		if ( isset( $mapping['mappings']['properties']['chunks'], $mapping['mappings']['properties']['text_chunks'] ) ) {
			return $mapping;
		}

		// Add the default vector field mapping.
		$mapping['mappings']['properties']['chunks'] = [
			'type'       => 'nested',
			'properties' => [
				'vector' => [
					'type' => 'dense_vector',
					'dims' => $this->feature->get_dimensions(),
				],
			],
		];

		$mapping['mappings']['properties']['text_chunks']['type'] = 'text';

		// Add extra vector fields for newer versions of Elasticsearch.
		if ( version_compare( $es_version, '8.0', '>=' ) ) {
			// The index (true or false, default true) and similarity (l2_norm, dot_product or cosine) fields
			// were added in 8.0. The similarity field must be set if index is true.
			$mapping['mappings']['properties']['chunks']['properties']['vector'] = array_merge(
				$mapping['mappings']['properties']['chunks']['properties']['vector'],
				[
					'index'      => true,
					'similarity' => 'cosine',
				]
			);

			// The element_type field was added in 8.6. This can be either float (default) or byte.
			if ( version_compare( $es_version, '8.6', '>=' ) ) {
				$mapping['mappings']['properties']['chunks']['properties']['vector']['element_type'] = 'float';
			}

			// The int8_hnsw type was added in 8.12.
			if ( $quantization && version_compare( $es_version, '8.12', '>=' ) ) {
				// This is supposed to result in better performance but slightly less accurate results.
				// See https://www.elastic.co/guide/en/elasticsearch/reference/8.13/knn-search.html#knn-search-quantized-example.
				// Can test with this on and off and compare results to see what works best.
				$mapping['mappings']['properties']['chunks']['properties']['vector']['index_options']['type'] = 'int8_hnsw';
			}
		}

		return $mapping;
	}

	/**
	 * Add the embedding data to the post vector sync args.
	 *
	 * @param array $args       The current sync args (an Elasticsearch document)
	 * @param array $embeddings The embeddings to add to the sync args
	 * @return array
	 */
	public function add_chunks_field_value( array $args, array $embeddings ): array {
		// If we still don't have embeddings, return early.
		if ( empty( $embeddings ) ) {
			return $args;
		}

		// Add the embeddings data to the sync args.
		$args['chunks'] = [];

		foreach ( $embeddings as $embedding ) {
			$args['chunks'][] = [
				'vector' => array_map( 'floatval', $embedding ),
			];
		}

		return $args;
	}
}
