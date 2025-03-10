<?php
/**
 * Vector Embeddings Embeddable class.
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

use ElasticPressLabs\Feature\VectorEmbeddings\SettingsPage;

/**
 * Utility class for Vector Embeddings.
 */
class Embeddable {

	/**
	 * Configuration settings for the utility.
	 *
	 * @var array
	 */
	private $settings;
	/**
	 * Flag to indicate if the post type is embeddable.
	 *
	 * @var bool
	 */
	private $embeddable;
	/**
	 * The mode of embedding being used.
	 *
	 * @var string
	 */
	private $embedding_mode;
	/**
	 * List of fields to include in indexing.
	 *
	 * @var array
	 */
	private $fields_indexing_include;
	/**
	 * List of fields to exclude from indexing.
	 *
	 * @var array
	 */
	private $fields_indexing_exclude;
	/**
	 * Fields that are subject to embedding.
	 *
	 * @var array
	 */
	private $enable_fields_indexing;
	/**
	 * The post type being processed.
	 *
	 * @var string
	 */
	private $post_type;
	/**
	 * Configuration settings for the post type.
	 *
	 * @var array
	 */
	private $post_type_config;
	/**
	 * List of taxonomies associated with the post type.
	 *
	 * @var array
	 */
	private $taxonomies;

	/**
	 * Class constructor.
	 *
	 * @param int $post_id The ID of the post to check.
	 */
	public function __construct( $post_id ) {
		$post_type = get_post_type( $post_id );

		$this->post_type = $post_type;
		$this->settings  = get_option( SettingsPage::SETTINGS_KEY );

		$post_type_config = array_filter(
			$this->settings['postTypeConfig'],
			function ( $config ) use ( $post_type ) {
				return $config['key'] === $post_type;
			}
		);

		if ( empty( $post_type_config ) ) {
			return;
		}

		// get the first element of the array
		$this->post_type_config = reset( $post_type_config );

		$this->embeddable              = $this->post_type_config['embeddable'] ?? false;
		$this->embedding_mode          = $this->post_type_config['embedding_mode'] ?? 'automatic';
		$this->enable_fields_indexing  = $this->post_type_config['enable_fields_indexing'] ?? false;
		$this->fields_indexing_include = $this->post_type_config['fields_indexing_include'] ?? [];
		$this->fields_indexing_exclude = $this->post_type_config['fields_indexing_exclude'] ?? [];
		$this->taxonomies              = $this->post_type_config['taxonomies'] ?? [];
	}

	/**
	 * Check if a post is embeddable based on taxonomy and post meta conditions.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is embeddable, false otherwise.
	 */
	public function is_embeddable( $post_id ) {
		if ( 'manual' === $this->embedding_mode ) {
			return get_post_meta( $post_id, 'ep_embeddings_control', true );
		}

		if ( ! $this->embeddable ) {
			return false;
		}

		if ( $this->enable_fields_indexing && ( $this->is_excluded_by_taxonomy( $post_id ) || $this->is_excluded_by_meta( $post_id ) ) ) {
			return false;
		}

		if ( $this->is_included_by_taxonomy( $post_id ) || $this->is_included_by_meta( $post_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a post is excluded by taxonomy conditions.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is excluded, false otherwise.
	 */
	private function is_excluded_by_taxonomy( $post_id ) {
		foreach ( $this->taxonomies as $taxonomy ) {
			if ( $taxonomy['enabled'] ) {
				$terms_exclude = $taxonomy['termsExclude'];

				$assigned_terms = wp_get_post_terms( $post_id, $taxonomy['name'] );
				$term_ids       = wp_list_pluck( $assigned_terms, 'term_id' );

				if ( ! empty( $terms_exclude ) ) {
					$has_term = array_filter(
						$terms_exclude,
						function ( $term ) use ( $term_ids ) {
							return in_array( $term, $term_ids, true );
						}
					);

					if ( ! empty( $has_term ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Check if a post is included by taxonomy conditions.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is included, false otherwise.
	 */
	private function is_included_by_taxonomy( $post_id ) {
		foreach ( $this->taxonomies as $taxonomy ) {
			if ( $taxonomy['enabled'] ) {
				$terms_include = $taxonomy['termsInclude'];

				$assigned_terms = wp_get_post_terms( $post_id, $taxonomy['name'] );
				$term_ids       = wp_list_pluck( $assigned_terms, 'term_id' );

				if ( ! empty( $terms_include ) ) {
					$has_term = array_filter(
						$terms_include,
						function ( $term ) use ( $term_ids ) {
							return in_array( $term, $term_ids, true );
						}
					);

					if ( ! empty( $has_term ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Check if a post is excluded by post meta conditions.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is excluded, false otherwise.
	 */
	private function is_excluded_by_meta( $post_id ) {
		if ( ! empty( $this->fields_indexing_exclude ) ) {
			$meta = get_post_meta( $post_id );

			$has_field = array_filter(
				$this->fields_indexing_exclude,
				function ( $field ) use ( $meta ) {
					return ! empty( $meta[ $field ] );
				}
			);

			if ( ! empty( $has_field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a post is included by post meta conditions.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is included, false otherwise.
	 */
	private function is_included_by_meta( $post_id ) {
		if ( ! empty( $this->fields_indexing_include ) ) {
			$meta = get_post_meta( $post_id );

			$has_field = array_filter(
				$this->fields_indexing_include,
				function ( $field ) use ( $meta ) {
					return ! empty( $meta[ $field ] );
				}
			);

			if ( ! empty( $has_field ) ) {
				return true;
			}
		}

		return false;
	}
}
