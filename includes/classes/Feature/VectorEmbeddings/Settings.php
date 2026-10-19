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
	 * Settings, loaded once per instance.
	 *
	 * @since 2.6.0
	 * @var array|null
	 */
	protected $settings = null;

	/**
	 * WordPress Hooks
	 */
	public function setup() {
		add_action( 'rest_api_init', [ $this, 'setup_endpoint' ] );
		add_action( 'admin_menu', [ $this, 'add_vector_embedding_submenu_page' ], 15 );
		add_action( 'admin_init', [ $this, 'set_current_screen' ], 11 );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
		add_filter( 'elasticpress_general_ep_screens', [ $this, 'add_vector_embeddings_screen' ] );
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
		if ( empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		$page = sanitize_key( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( $page, 'elasticpress' ) ) {
			return false;
		}

		return 'elasticpress-vector-embeddings' === $page;
	}

	/**
	 * Set the current screen.
	 *
	 * @since 2.5.1
	 * @return void
	 */
	public function set_current_screen() {
		// If EP version is less than 5.3.3, setting the current screen breaks the header.
		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.3.3', '<' ) ) {
			return;
		}

		if ( ! $this->is_vector_embeddings_page() ) {
			return;
		}

		\ElasticPress\Screen::factory()->set_current_screen( 'vector-embeddings' );
	}

	/**
	 * Enqueue scripts and styles for the settings page.
	 */
	public function scripts() {
		if ( ! $this->is_vector_embeddings_page() ) {
			return;
		}

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
				'apiUrl'             => rest_url( 'elasticpress-labs/v1/vector-embeddings' ),
				'settings'           => $this->get_settings(),
				'indexablePostTypes' => array_keys( $this->get_searchable_post_types() ),
				'embeddingsFiltered' => has_filter( 'ep_embeddings_is_embeddable' ),
			]
		);
	}

	/**
	 * Add the vector embeddings screen to the list of ElasticPress general screens.
	 *
	 * This way, JS and CSS assets for the header are enqueued.
	 *
	 * @since 2.5.1
	 * @param array $screens The list of screens.
	 * @return array The list of screens.
	 */
	public function add_vector_embeddings_screen( $screens ) {
		$screens[] = 'vector-embeddings';
		return $screens;
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
						'enabled'      => true,
					];
				},
				$public_taxonomies
			);

			// this is the shape of a post type configuration.
			$return[] = [
				'embeddable'            => true, // whether to allow embeddings for this post type.
				'embeddingMode'         => 'automatic', // Whether to use auto or manual embedding.
				'enableFieldsIndexing'  => false, // Whether to flagging content inclusion via post meta.
				'fieldsIndexingInclude' => [], // Meta fields used to flag content for inclusion.
				'fieldsIndexingExclude' => [], // Meta fields used to flag content for exclusion. A post with an exluded
				'fieldsEmbedding'       => [ 'post_title', 'post_content' ], // Fields to use for embedding generation.
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
	 * Get the current settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		// Cached per instance, not per request, so saved changes are seen by new instances.
		if ( null === $this->settings ) {
			$this->settings = get_option(
				self::SETTINGS_KEY,
				[
					'postTypeConfig' => $this->get_default_post_type_config(),
					'chunkSize'      => 100,
					'chunkOverlap'   => 50,
				]
			);
		}

		return $this->settings;
	}

	/**
	 * Get the post type configuration for a given post type.
	 *
	 * @param string $post_type The post type to get the configuration for.
	 * @return array The post type configuration.
	 */
	public function get_post_type_config( $post_type ) {
		$post_type_config = array_filter(
			$this->get_settings()['postTypeConfig'],
			function ( $config ) use ( $post_type ) {
				return $config['key'] === $post_type;
			}
		);

		if ( empty( $post_type_config ) ) {
			return [];
		}

		// get the first element of the array
		return reset( $post_type_config );
	}

	/**
	 * Check if a post type is embeddable.
	 *
	 * @param string $post_type The post type to check.
	 * @return bool True if the post type is embeddable, false otherwise.
	 */
	public function is_post_type_embeddable( $post_type ) {
		$config = $this->get_post_type_config( $post_type );
		if ( empty( $config ) ) {
			return false;
		}

		return ! empty( $config['embeddable'] );
	}

	/**
	 * Check if a post is embeddable based on its post type settings, the per-post
	 * flags, and the Indexing Criteria (taxonomy and meta include / exclude rules).
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is embeddable, false otherwise.
	 */
	public function is_embeddable( $post_id ) {
		$embeddable = $this->compute_embeddable( (int) $post_id );

		/**
		 * Filter whether a post is embeddable.
		 *
		 * Receives the result of the post type settings and Indexing Criteria, so a
		 * callback can adjust it instead of replacing every check.
		 *
		 * @hook ep_embeddings_is_embeddable
		 * @since 2.5.0, 2.6.0 receives the computed value instead of null
		 * @param {bool} $embeddable Whether the post is embeddable.
		 * @param {int}  $post_id    The ID of the post to check.
		 * @return {bool} Whether the post is embeddable.
		 */
		return (bool) apply_filters( 'ep_embeddings_is_embeddable', $embeddable, $post_id );
	}

	/**
	 * Whether a post is embeddable, before the `ep_embeddings_is_embeddable` filter.
	 *
	 * - Password-protected posts never are: their text would be stored with the chunks and
	 *   returned by searches, past the password. A site that wants them can still use the filter.
	 * - Manual mode: only posts flagged to be included.
	 * - Automatic mode: posts flagged to be excluded are skipped; then an exclude
	 *   rule wins over an include rule; with include rules for this post type, only
	 *   matching posts are embedded; without them, every post is.
	 *
	 * @since 2.6.0
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function compute_embeddable( int $post_id ): bool {
		if ( '' !== (string) get_post_field( 'post_password', $post_id ) ) {
			return false;
		}

		$config = $this->get_post_type_config( get_post_type( $post_id ) );

		if ( empty( $config ) || empty( $config['embeddable'] ) ) {
			return false;
		}

		if ( 'manual' === ( $config['embeddingMode'] ?? 'automatic' ) ) {
			return (bool) get_post_meta( $post_id, 'ep_embedding_include', true );
		}

		if ( get_post_meta( $post_id, 'ep_embedding_exclude', true ) ) {
			return false;
		}

		if ( $this->is_excluded_by_taxonomy( $post_id ) || $this->is_excluded_by_meta( $post_id ) ) {
			return false;
		}

		if ( $this->is_included_by_taxonomy( $post_id ) || $this->is_included_by_meta( $post_id ) ) {
			return true;
		}

		return ! $this->has_include_rules( $config );
	}

	/**
	 * Enabled taxonomy rules of a post type, with term IDs as integers.
	 *
	 * @since 2.6.0
	 * @param array $config Post type configuration.
	 * @return array[] Each with `name`, `termsInclude`, and `termsExclude`.
	 */
	protected function get_taxonomy_rules( array $config ): array {
		$rules = [];

		foreach ( (array) ( $config['taxonomies'] ?? [] ) as $taxonomy ) {
			if ( empty( $taxonomy['name'] ) || ( isset( $taxonomy['enabled'] ) && ! $taxonomy['enabled'] ) ) {
				continue;
			}

			$rules[] = [
				'name'         => (string) $taxonomy['name'],
				'termsInclude' => array_map( 'intval', (array) ( $taxonomy['termsInclude'] ?? [] ) ),
				'termsExclude' => array_map( 'intval', (array) ( $taxonomy['termsExclude'] ?? [] ) ),
			];
		}

		return $rules;
	}

	/**
	 * Meta keys of a post type rule list, when meta rules are turned on.
	 *
	 * @since 2.6.0
	 * @param array  $config Post type configuration.
	 * @param string $key    `fieldsIndexingInclude` or `fieldsIndexingExclude`.
	 * @return string[]
	 */
	protected function get_meta_rules( array $config, string $key ): array {
		if ( empty( $config['enableFieldsIndexing'] ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'strval', (array) ( $config[ $key ] ?? [] ) ) ) );
	}

	/**
	 * Whether a post type has any include rule (taxonomy terms or meta keys).
	 *
	 * @since 2.6.0
	 * @param array $config Post type configuration.
	 * @return bool
	 */
	protected function has_include_rules( array $config ): bool {
		foreach ( $this->get_taxonomy_rules( $config ) as $rule ) {
			if ( ! empty( $rule['termsInclude'] ) ) {
				return true;
			}
		}

		return [] !== $this->get_meta_rules( $config, 'fieldsIndexingInclude' );
	}

	/**
	 * Whether a post has a term from any of the given lists.
	 *
	 * @since 2.6.0
	 * @param int    $post_id   Post ID.
	 * @param string $rule_list `termsInclude` or `termsExclude`.
	 * @return bool
	 */
	protected function has_term_in_rules( int $post_id, string $rule_list ): bool {
		$config = $this->get_post_type_config( get_post_type( $post_id ) );

		if ( empty( $config ) ) {
			return false;
		}

		foreach ( $this->get_taxonomy_rules( $config ) as $rule ) {
			if ( empty( $rule[ $rule_list ] ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $rule['name'] );

			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( in_array( (int) $term->term_id, $rule[ $rule_list ], true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a post has a truthy value for any of the given meta rule keys.
	 *
	 * @since 2.6.0
	 * @param int    $post_id Post ID.
	 * @param string $key     `fieldsIndexingInclude` or `fieldsIndexingExclude`.
	 * @return bool
	 */
	protected function has_meta_in_rules( int $post_id, string $key ): bool {
		$config = $this->get_post_type_config( get_post_type( $post_id ) );

		if ( empty( $config ) ) {
			return false;
		}

		foreach ( $this->get_meta_rules( $config, $key ) as $field ) {
			if ( get_post_meta( $post_id, $field, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a post is excluded by taxonomy conditions.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Ignores taxonomy rules turned off on the settings screen.
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is excluded, false otherwise.
	 */
	public function is_excluded_by_taxonomy( $post_id ) {
		return $this->has_term_in_rules( (int) $post_id, 'termsExclude' );
	}

	/**
	 * Check if a post is included by taxonomy conditions.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Ignores taxonomy rules turned off on the settings screen.
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is included, false otherwise.
	 */
	public function is_included_by_taxonomy( $post_id ) {
		return $this->has_term_in_rules( (int) $post_id, 'termsInclude' );
	}

	/**
	 * Check if a post is excluded by meta conditions.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Only applies when field indexing is turned on.
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is excluded, false otherwise.
	 */
	public function is_excluded_by_meta( $post_id ) {
		return $this->has_meta_in_rules( (int) $post_id, 'fieldsIndexingExclude' );
	}

	/**
	 * Check if a post is included by meta conditions.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Only applies when field indexing is turned on.
	 * @param int $post_id The ID of the post to check.
	 * @return bool True if the post is included, false otherwise.
	 */
	public function is_included_by_meta( $post_id ) {
		return $this->has_meta_in_rules( (int) $post_id, 'fieldsIndexingInclude' );
	}

	/**
	 * Get the fields used for embedding content.
	 *
	 * @param int $post_id The ID of the post to get the fields for.
	 * @return array The fields used for embedding content.
	 */
	public function get_embedding_fields( $post_id ) {
		$config = $this->get_post_type_config( get_post_type( $post_id ) );

		if ( empty( $config ) ) {
			return [];
		}

		return $config['fieldsEmbedding'] ?? [];
	}

	/**
	 * Get the default inclusion rule.
	 *
	 * Default Inclusion Rule Help
	 *
	 * The Default Inclusion Rule determines how posts are included or excluded for vector embeddings
	 * based on taxonomy conditions.
	 *
	 * - Taxonomy with `termsInclude`:
	 *     - If a taxonomy has `termsInclude` values, only posts with the specified terms will be included.
	 *     - Posts without these terms will be excluded.
	 *     - Default behavior: Posts are not included by default.
	 *
	 * - Taxonomy with `termsExclude`:
	 *     - If a taxonomy has `termsExclude` values, posts with the specified terms will be excluded.
	 *     - Posts without these terms will be included.
	 *     - Default behavior: Posts are included by default.
	 *
	 * - Both `termsInclude` and `termsExclude`:
	 *     - If both `termsInclude` and `termsExclude` are set for a taxonomy, the rule defaults to excluding
	 *       posts that match `termsExclude`, even if they also match `termsInclude`.
	 *     - Default behavior: Posts are not included by default.
	 *
	 * Scenarios:
	 *
	 * - No Rules Set:
	 *     - If no `termsInclude` or `termsExclude` values are set, all posts are included by default.
	 *
	 * - Conflicting Rules:
	 *     - If conflicting rules are set, this system prioritizes `termsExclude` over `termsInclude`.
	 *
	 * Examples:
	 *
	 * - Example 1: A taxonomy has `termsInclude` set to "Category A".
	 *     - Posts in "Category A" are included.
	 *     - Posts not in "Category A" are excluded.
	 *
	 * - Example 2: A taxonomy has `termsExclude` set to "Tag B".
	 *     - Posts with "Tag B" are excluded.
	 *     - Posts without "Tag B" are included.
	 *
	 * - Example 3: A taxonomy has both `termsInclude` ("Category A") and `termsExclude` ("Tag B").
	 *     - Posts with "Tag B" are always excluded, even if they are in "Category A".
	 *     - Only posts in "Category A" that do not have "Tag B" are included.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 No longer used to decide whether a post is embedded; checks the include rules of every post type.
	 * @return bool Whether to include posts by default.
	 */
	public function get_default_inclusion_rule() {
		$post_types = (array) ( $this->get_settings()['postTypeConfig'] ?? [] );

		if ( empty( $post_types ) ) {
			return false; // No rules set - posts are not included by default.
		}

		// Kept for backward compatibility: true when no post type has an include rule.
		// Embedding decisions use the rules of the post's own type (see compute_embeddable()).
		foreach ( $post_types as $post_type_config ) {
			if ( $this->has_include_rules( (array) $post_type_config ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get chunk size set in settings
	 *
	 * @return int
	 */
	public function get_chunk_size() {
		return $this->get_settings()['chunkSize'] ?? 100;
	}

	/**
	 * Get chunk overlap set in settings
	 *
	 * @return int
	 */
	public function get_chunk_overlap() {
		return $this->get_settings()['chunkOverlap'] ?? 50;
	}
}
