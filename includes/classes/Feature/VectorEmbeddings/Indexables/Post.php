<?php
/**
 * Vector Embeddings - Post Indexable
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings\Indexables;

use ElasticPressLabs\Feature\VectorEmbeddings\Indexable;

/**
 * Vector Embeddings - Post Indexable class
 */
class Post extends Indexable {
	/**
	 * Setup hooks
	 */
	public function setup() {
		// Alter post and term mapping to store our vector embeddings
		add_filter( 'ep_post_mapping', [ $this, 'add_post_vector_field_mapping' ] );

		// Exclude designated meta field holding the vector embeddings from search
		add_filter( 'ep_prepare_meta_excluded_public_keys', [ $this, 'exclude_vector_meta' ] );

		// Only trigger embeddings when external embeddings are turned off
		if ( ! $this->feature->get_setting( 'ep_external_embedding' ) ) {
			add_filter( 'ep_post_sync_args_post_prepare_meta', [ $this, 'add_vector_field_to_post_sync' ], 10, 2 );
		}
	}

	/**
	 * Add our vector field mapping to the Elasticsearch post index.
	 *
	 * @param array $mapping Current mapping.
	 * @return array
	 */
	public function add_post_vector_field_mapping( array $mapping ): array {
		return $this->feature->add_vector_mapping_field( $mapping );
	}

	/**
	 * Exclude our vector meta from being synced.
	 *
	 * @param array $excluded_keys Current excluded keys.
	 * @return array
	 */
	public function exclude_vector_meta( array $excluded_keys ): array {
		$excluded_keys[] = $this->feature->get_setting( 'ep_vector_embeddings_meta_field' );
		return $excluded_keys;
	}

	/**
	 * Add the embedding data to the post vector sync args.
	 *
	 * @param array $args Current sync args.
	 * @param int   $post_id Post ID being synced.
	 * @return array
	 */
	public function add_vector_field_to_post_sync( array $args, int $post_id ): array {
		// No need to add vector data if no content exists.
		$post = get_post( $post_id );
		if ( empty( $post->post_content ) ) {
			return $args;
		}
		$meta_field = $this->feature->get_setting( 'ep_vector_embeddings_meta_field' );

		// Try to use the stored embeddings first.
		$embeddings = get_post_meta( $post_id, $meta_field, true );

		// If they don't exist, make API requests to generate them.
		if ( ! $embeddings ) {
			$embeddings = [];

			$content_chunks = $this->feature->chunk_content( $post->post_content );

			// Get the embeddings for each chunk.
			if ( ! empty( $content_chunks ) ) {
				foreach ( $content_chunks as $chunk ) {
					$embedding = $this->feature->get_embedding( $chunk );

					if ( $embedding && ! is_wp_error( $embedding ) ) {
						$embeddings[] = array_map( 'floatval', $embedding );
					}
				}
			}

			// Add embeddings for title.
			$title_embedding = $this->feature->get_embedding( $this->feature->normalize_content( $post->post_title ) );
			if ( $title_embedding && ! is_wp_error( $title_embedding ) ) {
				$embeddings[] = array_map( 'floatval', $title_embedding );
			}

			// Add embeddings for slug.
			$slug_embedding = $this->feature->get_embedding( $post->post_name );
			if ( $slug_embedding && ! is_wp_error( $slug_embedding ) ) {
				$embeddings[] = array_map( 'floatval', $slug_embedding );
			}

			// Store the embeddings for future use.
			if ( ! empty( $embeddings ) ) {
				update_post_meta( $post_id, $meta_field, $embeddings );
			}
		}

		// If we still don't have embeddings, return early.
		if ( ! $embeddings || empty( $embeddings ) ) {
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
