<?php
/**
 * Vector Embeddings
 *
 * This feature enables storage of vector embeddings, a numerical representation of the
 * indexed content that can capture semantic relationships and similarities between data points.
 * These embeddings are often used by AI models to process and understand complex information
 * more efficiently and are used for features like natural language processing, recommendations and computer vision.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

use ElasticPressLabs\Utils as LabsUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Vector Embeddings feature
 */
class SettingsPage {

	const SETTINGS_KEY = 'ep_vector_embeddings_settings';

	/**
	 * WordPress Hooks
	 */
	public function setup() {
		add_action( 'rest_api_init', [ $this, 'setup_endpoint' ] );
		add_action( 'admin_menu', [ $this, 'add_vector_embedding_submenu_page' ], 15 );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
		add_action( 'init', [ $this, 'register_post_meta' ] );
	}

	/**
	 * Setup REST endpoints
	 */
	public function setup_endpoint() {
		$controller = new \ElasticPressLabs\REST\VectorEmbeddingSettings();
		$controller->register_routes();
	}

	/**
	 * Add the Vector Embeddings submenu page.
	 */
	public function add_vector_embedding_submenu_page() {
		add_submenu_page(
			'elasticpress',
			esc_html__( 'Vector Embeddings', 'elasticpress-labs' ),
			esc_html__( 'Vector Embeddings', 'elasticpress-labs' ),
			'manage_options',
			'elasticpress-vector-embeddings',
			[ $this, 'render_settings_page' ],
			15
		);
	}

	/**
	 * Renders the settings page
	 */
	public function render_settings_page() {
		include EP_PATH . '/includes/partials/header.php'; ?>
		<div class="wrap">
			<div id="ep-vector-embeddings"></div>
		</div>
		<?php
	}

	/**
	 * Check if we are on the Vector Embeddings page.
	 *
	 * @return boolean
	 */
	public function is_vector_embeddings_page() {
		if ( ! function_exists( '\get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		return ( 'elasticpress_page_elasticpress-vector-embeddings' === $screen->base );
	}

	/**
	 * Enqueue scripts and styles for the settings page.
	 */
	public function scripts() {
		if ( $this->is_vector_embeddings_page() ) {

			wp_enqueue_script(
				'ep_vector_embeddings_scripts',
				ELASTICPRESS_LABS_URL . 'dist/js/vector-embeddings-script.js',
				LabsUtils\get_asset_info( 'vector-embeddings-script', 'dependencies' ),
				LabsUtils\get_asset_info( 'vector-embeddings-script', 'version' ),
				true
			);

			wp_set_script_translations( 'ep_vector_embeddings_scripts', 'elasticpress-labs' );

			wp_enqueue_style( 'wp-edit-post' );

			wp_enqueue_style(
				'ep_vector_embeddings_scripts',
				ELASTICPRESS_LABS_URL . 'dist/css/vector-embeddings-script.css',
				[],
				LabsUtils\get_asset_info( 'vector-embeddings-script', 'version' ),
				'all'
			);

			wp_localize_script(
				'ep_vector_embeddings_scripts',
				'epVectorEmbeddings',
				[
					'apiUrl'                 => rest_url( 'elasticpress-labs/v1/vector-embeddings' ),
					'postTypeConfigurations' => $this->get_default_or_saved_settings(),
					'indexableTypes'         => array_keys( $this->get_searchable_post_types() ),
					'chunk_size'             => 150,
					'overlap_size'           => 25,
				]
			);
		}
		if ( 'post.php' === $GLOBALS['pagenow'] ?? '' ) {
			wp_enqueue_script(
				'ep_vector_embeddings_post_script',
				ELASTICPRESS_LABS_URL . 'dist/js/vector-embeddings-editor-script.js',
				[ 'wp-i18n', 'wp-element', 'wp-components', 'wp-api-fetch' ],
				LabsUtils\get_asset_info( 'vector-embeddings-post-script', 'version' ),
				true
			);
		}
	}

	/**
	 * Get the default embeddable post type configurations. If options are saved, return those instead.
	 *
	 * Get a list of searchable post types, add their taxonomies, and set the inclusion to include.
	 *
	 * @return array
	 */
	public function get_default_or_saved_settings() {

		$saved = get_option( self::SETTINGS_KEY, [] );

		// return saved value if exists.
		if ( ! empty( $saved['postTypeConfig'] ) ) {
			return $saved['postTypeConfig'];
		}

		// else, generate the default settings.
		$post_types = $this->get_searchable_post_types();

		$return = [];

		foreach ( $post_types as $post_type ) {
			$post_type_object  = get_post_type_object( $post_type );
			$object_taxonomies = get_object_taxonomies( $post_type, 'objects' );

			// only use public taxonomies.
			$public_taxonomies = array_filter(
				$object_taxonomies,
				function ( $taxonomy ) {
					return $taxonomy->public && 'post_format' !== $taxonomy->name;
				}
			);

			$public_taxonomies = array_map(
				function ( $taxonomy ) {
					return [
						'name'         => $taxonomy->name,
						'label'        => $taxonomy->label,
						'termsInclude' => [],
						'termsExclude' => [],
						'enabled'      => false,
					];
				},
				$public_taxonomies
			);

			// this is the shape of a post type configuration.
			$return[] = [
				'embeddable'            => false, // whether to allow embeddings for this post type.
				'embeddingMode'         => 'automatic', // Whether to use auto or manual embedding.
				'enablefieldsIndexing'  => false, // Whether to flagging content inclusion via post meta.
				'fieldsIndexingInclude' => [], // Meta fields used to flag content for inclusion.
				'fieldsIndexingExclude' => [], // Meta fields used to flag content for exclusion. A post with an exluded
				'fieldsEmbedding'       => [], // Fields to use for embedding generation.
				'label'                 => $post_type_object->label, // Label used for settings panel.
				'key'                   => $post_type, // post type name used for key in the settings object.
				'taxonomies'            => $public_taxonomies, // Taxonomies to consider for vector embedding.
			];
		}

		return $return;
	}

	/**
	 * Get the post types that are applicable for indexing.
	 *
	 * @return array
	 */
	public function get_searchable_post_types() {
		$search = \ElasticPress\Features::factory()->get_registered_feature( 'search' );

		return $search->get_searchable_post_types();
	}

	/**
	 * Determine whether a post can be indexed with Vector Embeddings
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return boolean
	 */
	public function is_embeddable( $post_id ) {
		$allowed_to_be_embedded = false;

		$post_type = get_post_type( $post_id );
		$settings  = get_option( self::SETTINGS_KEY, [] );

		$post_type_config = array_filter(
			$settings['postTypeConfig'],
			function ( $config ) use ( $post_type ) {
				return $config['key'] === $post_type;
			}
		);

		$post_type_config = reset( $post_type_config ); // array_map returns an array of arrays.

		if ( empty( $post_type_config ) ) {
			return false;
		}

		$embeddable     = $post_type_config['embeddable'];
		$taxonomies     = $post_type_config['taxonomies'];
		$fields_include = $post_type_config['fieldsIndexingInclude'];
		$fields_exclude = $post_type_config['fieldsIndexingExclude'];
		$enable_fields  = $post_type_config['enablefieldsIndexing'];

		if ( ! $embeddable ) {
			return false;
		}

		// taxonomy inclusion
		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy['enabled'] ) {
				$terms_include = $taxonomy['termsInclude'];
				$terms_exclude = $taxonomy['termsExclude'];

				$assigned_terms = wp_get_post_terms( $post_id, $taxonomy['name'] );

				$term_ids = wp_list_pluck( $assigned_terms, 'term_id' );

				// check if any of the terms are in the exclude list. If so, bail.
				if ( ! empty( $terms_exclude ) ) {
					$has_term = array_filter(
						$terms_exclude,
						function ( $term ) use ( $term_ids ) {
							return in_array( $term, $term_ids, true );
						}
					);

					if ( ! empty( $has_term ) ) {
						if ( defined( 'WP_CLI' ) && WP_CLI ) {
							\WP_CLI::line( "Post ID: {$post_id} has a term that is excluded." );
						}
						return false;
					}
				}

				// check if any of the terms are in the include list.
				if ( ! empty( $terms_include ) ) {
					$has_term = array_filter(
						$terms_include,
						function ( $term ) use ( $term_ids ) {
							return in_array( $term, $term_ids, true );
						}
					);

					$allowed_to_be_embedded = ! empty( $has_term );
				}
			}
		}

		// post meta inclusion
		if ( $enable_fields ) {
			// does the post have any of the fields that are used to flag content for exclusion? Bail if condition is met.
			if ( ! empty( $fields_exclude ) ) {
				$meta = get_post_meta( $post_id );

				$has_field = array_filter(
					$fields_exclude,
					function ( $field ) use ( $meta ) {
						return ! empty( $meta[ $field ] );
					}
				);

				if ( ! empty( $has_field ) ) {
					if ( defined( 'WP_CLI' ) && WP_CLI ) {
						\WP_CLI::line( "Post ID: {$post_id} has a field that is excluded." );
					}
					return false;
				}
			}

			// Does the post have any of the fields that are used to flag content for inclusion ?
			if ( ! empty( $fields_include ) ) {
				$meta = get_post_meta( $post_id );

				$has_field = array_filter(
					$fields_include,
					function ( $field ) use ( $meta ) {
						return ! empty( $meta[ $field ] );
					}
				);

				if ( empty( $has_field ) ) {
					if ( defined( 'WP_CLI' ) && WP_CLI ) {
						\WP_CLI::line( "Post ID: {$post_id} does not have a field that is included." );
					}
				}
			}

			return $allowed_to_be_embedded;
		}
	}

	/**
	 * Register post meta for Vector Embeddings
	 */
	public function register_post_meta() {
		register_post_meta(
			'post',
			'ep_allow_vector_embedding',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'boolean',
			]
		);
	}
}
