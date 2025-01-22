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
	 * Given an object and its content pieces, return the embeddings and clean up unused embeddings stored.
	 *
	 * @param int    $object_id             The object ID
	 * @param string $object_type           The object type
	 * @param string $object_representation A string representing the object
	 * @return array
	 */
	public function get_updated_embeddings( int $object_id, string $object_type, string $object_representation ): array {
		$embeddings     = [];
		$content_chunks = $this->feature->chunk_content( $object_representation );

		$embeddings = $this->feature->get_embedding( $object_id, $object_type, $content_chunks );

		return $embeddings ?? [];
	}

	/**
	 * Add the embedding data to the post vector sync args.
	 *
	 * @param array $args       The current sync args (an Elasticsearch document)
	 * @param array $embeddings The embeddings to add to the sync args
	 * @return array
	 */
	public function add_chuncks_field_value( array $args, array $embeddings ): array {
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
