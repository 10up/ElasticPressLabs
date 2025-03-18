<?php
/**
 * Vector Embeddings
 *
 * This feature configures storage of vector embeddings.
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
class Settings {

	const SETTINGS_KEY = 'ep_vector_embeddings_settings';

	/**
	 * WordPress Hooks
	 */
	public function setup() {
		add_action( 'rest_api_init', [ $this, 'setup_endpoint' ] );
		add_action( 'admin_menu', [ $this, 'add_vector_embedding_submenu_page' ], 15 );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
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
				ELASTICPRESS_LABS_URL . 'dist/js/embeddings-script.js',
				LabsUtils\get_asset_info( 'embeddings-script', 'dependencies' ),
				LabsUtils\get_asset_info( 'embeddings-script', 'version' ),
				true
			);

			wp_set_script_translations( 'ep_vector_embeddings_scripts', 'elasticpress-labs' );

			wp_enqueue_style( 'wp-edit-post' );

			wp_enqueue_style(
				'ep_vector_embeddings_scripts',
				ELASTICPRESS_LABS_URL . 'dist/css/embeddings-script.css',
				[],
				LabsUtils\get_asset_info( 'embeddings-script', 'version' ),
				'all'
			);

			wp_localize_script(
				'ep_vector_embeddings_scripts',
				'epVectorEmbeddings',
				[
					'apiUrl'         => rest_url( 'elasticpress-labs/v1/vector-embeddings' ),
					'settings'       => get_option(
						self::SETTINGS_KEY,
						[
							'postTypeConfig' => $this->get_default_post_type_config(),
						]
					),
					'indexableTypes' => array_keys( $this->get_searchable_post_types() ),
				]
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
	public function get_default_post_type_config() {

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
}
