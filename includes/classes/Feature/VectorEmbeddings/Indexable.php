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
	 * @param int    $object_id      The object ID
	 * @param string $object_type    The object type
	 * @param array  $content_pieces Content pieces to get embeddings for
	 * @return array
	 */
	public function get_updated_embeddings( int $object_id, string $object_type, array $content_pieces ): array {
		$all_hashes = $this->feature->storage->get_all_object_hashes( $object_id, 'post' );

		$hashes_in_use = [];
		$embeddings    = [];
		foreach ( $content_pieces as $content_piece ) {
			$content_chunks = $this->feature->chunk_content( $content_piece );

			// Get the embeddings for each chunk.
			if ( ! empty( $content_chunks ) ) {
				foreach ( $content_chunks as $chunk ) {
					$hash = $this->feature->storage->hash_content( $chunk );

					$hashes_in_use[] = $hash;

					if ( isset( $all_hashes[ $hash ] ) ) {
						$embeddings[] = $all_hashes[ $hash ];
						continue;
					}

					$embedding = $this->feature->get_embedding( $object_id, $object_type, $chunk );
					if ( $embedding ) {
						$embeddings[] = $embedding;
					}
				}
			}
		}

		$hashes_in_use = array_unique( $hashes_in_use );

		$unused_hashes = array_diff( array_keys( $all_hashes ), $hashes_in_use );
		foreach ( $unused_hashes as $unused_hash ) {
			$this->feature->storage->delete( $object_id, $object_type, $unused_hash );
		}

		return $embeddings;
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
