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
		if ( ! $this->should_add_vector_field_to_post( $post_id ) ) {
			return $args;
		}

		$content_pieces = $this->get_object_content_pieces( $post_id );
		$embeddings     = $this->get_updated_embeddings( $post_id, 'post', $content_pieces );

		return $this->add_chuncks_field_value( $args, $embeddings );
	}

	/**
	 * Whether or not we should add the vector field to the post.
	 *
	 * @param int $post_id The Post ID
	 * @return boolean
	 */
	public function should_add_vector_field_to_post( int $post_id ): bool {
		$post = get_post( $post_id );
		return ! empty( $post );
	}

	/**
	 * Return all content pieces for a given post ID.
	 *
	 * By default includes the title, the slug, and the post content, but could also add
	 * meta fields and taxonomy terms, for example.
	 *
	 * @param int $post_id The Post ID
	 * @return array
	 */
	public function get_object_content_pieces( int $post_id ): array {
		$post = get_post( $post_id );

		return [
			$post->post_content,
			$post->post_title,
			$post->post_name,
		];
	}
}
