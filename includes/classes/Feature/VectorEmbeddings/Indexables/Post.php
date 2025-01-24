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

		$post_chunks = $this->get_post_chunks( $post_id );
		$embeddings  = $this->feature->get_embedding( $post_id, 'post', $post_chunks );

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
	 * Return a representation of a post.
	 *
	 * By default includes the title, the slug, and the post content, but could also add
	 * meta fields and taxonomy terms, for example.
	 *
	 * @param int $post_id The Post ID
	 * @return array
	 */
	public function get_post_chunks( int $post_id ): array {
		$post = get_post( $post_id );

		$main_content = '';

		$title = get_the_title( $post_id );
		if ( $title ) {
			$main_content .= "# Title\n{$title}\n\n";
		}

		if ( ! empty( $post->post_excerpt ) ) {
			$excerpt       = get_the_excerpt( $post_id );
			$main_content .= "# Summary\n{$excerpt}\n\n";
		}

		$content = get_the_content( $post_id );
		if ( $content ) {
			$main_content .= "# Content\n{$content}\n\n";
		}

		$chunks = $this->feature->chunk_content( $main_content );

		$post_terms_str = $this->get_post_terms( $post );
		if ( $post_terms_str ) {
			$chunks = [ ...$chunks, ...$this->feature->chunk_content( $post_terms_str ) ];
		}

		$post_meta_str = $this->get_post_meta( $post );
		if ( $post_meta_str ) {
			$chunks = [ ...$chunks, ...$this->feature->chunk_content( $post_meta_str ) ];
		}

		return $chunks;
	}

	/**
	 * Get the representation of the post terms.
	 *
	 * @param \WP_Post $post The post object
	 * @return string
	 */
	protected function get_post_terms( $post ): string {
		$post_terms_str       = '';
		$post_terms           = [];
		$indexable            = \ElasticPress\Indexables::factory()->get( 'post' );
		$indexable_taxonomies = $indexable->get_indexable_post_taxonomies( $post );
		$taxonomy_by_names    = wp_list_pluck( $indexable_taxonomies, 'label', 'name' );
		foreach ( $taxonomy_by_names as $tax_name => $tax_label ) {
			$terms = get_the_terms( $post, $tax_name );
			if ( is_array( $terms ) ) {
				$post_terms[ $tax_label ] = array_map(
					function ( $term ) {
						return $term->name;
					},
					$terms
				);
			}
		}

		if ( ! empty( $post_terms ) ) {
			$post_terms_str .= "# Taxonomy Terms\n";
			foreach ( $post_terms as $tax_label => $terms ) {
				$post_terms_str .= "## {$tax_label}: ";
				$post_terms_str .= implode( ', ', $terms ) . "\n";
			}
		}

		return $post_terms_str;
	}

	/**
	 * Get te representation of the post meta.
	 *
	 * @param \WP_Post $post The post object
	 * @return string
	 */
	protected function get_post_meta( $post ): string {
		$meta_str      = '';
		$meta_to_index = [
			'footnotes',
			'searchwp_content_pdf_metadata',
		];
		$values        = [];
		if ( ! empty( $meta_to_index ) ) {
			foreach ( $meta_to_index as $meta_field ) {
				$values[ $meta_field ] = get_post_meta( $post->ID, $meta_field, true );
			}
		}
		$values = array_filter( $values );

		if ( ! empty( $values ) ) {
			$meta_str .= "# Metadata\n";
			foreach ( $values as $meta_field => $value ) {
				$meta_str .= "## {$meta_field}: {$value}\n";
			}
		}

		return $meta_str;
	}
}
