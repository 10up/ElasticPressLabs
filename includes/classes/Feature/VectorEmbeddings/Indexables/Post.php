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

		// Only trigger embeddings when external embeddings are turned off
		if ( ! $this->feature->get_setting( 'ep_embeddings_external_embedding' ) ) {
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
		return $this->add_vector_mapping_field( $mapping );
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

		if ( ! is_array( $embeddings ) ) {
			return $args;
		}

		return $this->add_chunks_field_value( $args, $embeddings );
	}

	/**
	 * Whether or not we should add the vector field to the post.
	 *
	 * @param int $post_id The Post ID
	 * @return boolean
	 */
	public function should_add_vector_field_to_post( int $post_id ): bool {
		$post = get_post( $post_id );

		/**
		 * Filter whether the vector field should or not be added to the post.
		 *
		 * @hook ep_embeddings_should_add_vector_field_to_post
		 * @since 2.4.0
		 *
		 * @param {bool} $should_add Whether the vector field should or not be added to the post.
		 * @param {int}  $post_id    The post ID.
		 * @return {bool} The new $should_add value.
		 */
		return apply_filters( 'ep_embeddings_should_add_vector_field_to_post', ! empty( $post ), $post_id );
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

		/**
		 * Filter the main content of a post before being split into chunks.
		 *
		 * @hook ep_embeddings_post_main_content
		 * @since 2.4.0
		 *
		 * @param {string}   $main_content Title, excerpt, and content of a post.
		 * @param {\WP_Post} $post         The post being processed.
		 * @return {string} The final main content representation.
		 */
		$main_content = apply_filters( 'ep_embeddings_post_main_content', $main_content, $post );

		$chunks = $this->feature->chunk_content( $main_content );

		$taxonomies = $this->get_embeddable_taxonomies( $post_id, $post->post_type );
		if ( $taxonomies ) {
			$post_terms_str = $this->get_post_terms( $post, $taxonomies );
			if ( $post_terms_str ) {
				$chunks = [ ...$chunks, ...$this->feature->chunk_content( $post_terms_str ) ];
			}
		}

		$meta_fields = $this->get_embeddable_meta( $post_id, $post->post_type );
		if ( $meta_fields ) {
			$post_meta_str = $this->get_post_meta( $post, $meta_fields );
			if ( $post_meta_str ) {
				$chunks = [ ...$chunks, ...$this->feature->chunk_content( $post_meta_str ) ];
			}
		}

		return $chunks;
	}

	/**
	 * Return the list of taxonomies that should be included in the post representation.
	 *
	 * @param integer $post_id   The post ID.
	 * @param string  $post_type The post type.
	 * @return array
	 */
	protected function get_embeddable_taxonomies( int $post_id, string $post_type ): array {
		$search_feature = \ElasticPress\Features::factory()->get_registered_feature( 'search' );
		$weighting      = $search_feature->weighting->get_weighting_configuration_with_defaults();
		if ( empty( $weighting[ $post_type ] ) ) {
			/**
			 * Filter the list of taxonomies which terms should be included in the post representation.
			 *
			 * @hook ep_embeddings_post_embeddable_taxonomies
			 * @since 2.4.0
			 *
			 * @param {array}  $embeddable_taxonomies Array of taxonomy names.
			 * @param {int}    $post_id               The post ID.
			 * @param {string} $post_type             The post type.
			 * @return {array} The list of taxonomy names.
			 */
			return apply_filters( 'ep_embeddings_post_embeddable_taxonomies', [], $post_id, $post_type );
		}

		$post_type_weighting = $weighting[ $post_type ];

		$taxonomies = array_reduce(
			array_keys( $post_type_weighting ),
			function ( $acc, $field ) use ( $post_type_weighting ) {
				if ( $post_type_weighting[ $field ]['enabled'] && preg_match( '/terms\.(.*)\.name/', $field, $matches ) ) {
					$acc[] = $matches[1];
				}
				return $acc;
			},
			[]
		);

		// This filter is documented above.
		return apply_filters( 'ep_embeddings_post_embeddable_taxonomies', $taxonomies, $post_id, $post_type );
	}

	/**
	 * Get the representation of the post terms.
	 *
	 * @param \WP_Post $post       The post object
	 * @param array    $taxonomies Taxonomies to be added.
	 * @return string
	 */
	protected function get_post_terms( $post, $taxonomies ): string {
		$post_terms_str = '';
		$post_terms     = [];
		foreach ( $taxonomies as $tax_name ) {
			$terms = get_the_terms( $post, $tax_name );
			if ( is_array( $terms ) ) {
				$post_terms[ $tax_name ] = array_map(
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

		/**
		 * Filter the string that represents the list of terms associated with this post.
		 *
		 * @hook ep_embeddings_post_terms_str
		 * @since 2.4.0
		 *
		 * @param {string}  $post_terms_str String with post terms.
		 * @param {WP_Post} $post           The post.
		 * @return {string} The string with post terms.
		 */
		return apply_filters( 'ep_embeddings_post_terms_str', $post_terms_str, $post );
	}

	/**
	 * Return the list of metafields that should be included in the post representation.
	 *
	 * @param integer $post_id   The post ID.
	 * @param string  $post_type The post type.
	 * @return array
	 */
	protected function get_embeddable_meta( int $post_id, string $post_type ): array {
		$search_feature = \ElasticPress\Features::factory()->get_registered_feature( 'search' );
		$weighting      = $search_feature->weighting->get_weighting_configuration_with_defaults();
		if ( empty( $weighting[ $post_type ] ) ) {
			/**
			 * Filter the list of metafields which values should be included in the post representation.
			 *
			 * @hook ep_embeddings_post_embeddable_meta
			 * @since 2.4.0
			 *
			 * @param {array}  $embeddable_meta Array of meta keys.
			 * @param {int}    $post_id         The post ID.
			 * @param {string} $post_type       The post type.
			 * @return {array} The list of meta keys.
			 */
			return apply_filters( 'ep_embeddings_post_embeddable_meta', [], $post_id, $post_type );
		}

		$post_type_weighting = $weighting[ $post_type ];

		$meta_fields = array_reduce(
			array_keys( $post_type_weighting ),
			function ( $acc, $field ) use ( $post_type_weighting ) {
				if ( $post_type_weighting[ $field ]['enabled'] && preg_match( '/meta\.(.*)\.value/', $field, $matches ) ) {
					$acc[] = $matches[1];
				}
				return $acc;
			},
			[]
		);

		// This filter is documented above.
		return apply_filters( 'ep_embeddings_post_embeddable_meta', $meta_fields, $post_id, $post_type );
	}

	/**
	 * Get the representation of the post meta.
	 *
	 * @param \WP_Post $post        The post object
	 * @param array    $meta_fields List of metafields
	 * @return string
	 */
	protected function get_post_meta( $post, $meta_fields ): string {
		$meta_str = '';
		$values   = [];
		foreach ( $meta_fields as $meta_field ) {
			$values[ $meta_field ] = get_post_meta( $post->ID, $meta_field, true );
		}
		$values = array_filter( $values );

		if ( ! empty( $values ) ) {
			$meta_str .= "# Metadata\n";
			foreach ( $values as $meta_field => $value ) {
				$meta_str .= "## {$meta_field}: {$value}\n";
			}
		}

		/**
		 * Filter the string that represents the meta fields associated with this post.
		 *
		 * @hook ep_embeddings_post_meta_str
		 * @since 2.4.0
		 *
		 * @param {string}  $post_terms_str String with post terms.
		 * @param {WP_Post} $post           The post.
		 * @return {string} The string with post terms.
		 */
		return apply_filters( 'ep_embeddings_post_meta_str', $meta_str, $post );
	}
}
