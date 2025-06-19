<?php
/**
 * Handles registering and storing post type instances
 *
 * @since  2.1
 * @package elasticpress
 */

namespace ElasticPressLabs;

use ElasticPress\PostTypes as FeaturesStore;
use ElasticPress\Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class for storing and managing post types
 */
class PostTypes {

	/**
	 * Stores all features that have been properly included (both active and inactive)
	 *
	 * @since 5.3.0
	 * @var array
	 */
	public $registered_post_types = [];

	/**
	 * Initiate class actions
	 *
	 * @since 5.3.0
	 */
	public function setup() {
		add_action( 'init', array( $this, 'setup_post_types' ), 0 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'maybe_disable_gutenberg' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'setup_meta_fields' ) );
		add_action( 'init', array( $this, 'register_meta_fields' ) );
		add_action( 'save_post', array( $this, 'save_meta_fields' ) );
	}

	/**
	 * Enqueue script.
	 *
	 * @since 5.3.0
	 * @return void
	 */
	public function admin_enqueue_scripts() {

		if ( ! in_array( get_post_type(), array_keys( $this->registered_post_types ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'ep_post_types_script',
			ELASTICPRESS_LABS_URL . 'dist/js/post-types-script.js',
			Utils\get_asset_info( 'post-types-script', 'dependencies' ),
			Utils\get_asset_info( 'post-types-script', 'version' ),
			true
		);

		wp_set_script_translations( 'ep_post_types_script', 'elasticpress' );

		wp_enqueue_style(
			'ep_post_types_script',
			ELASTICPRESS_LABS_URL . 'dist/css/post-types-script.css',
			[ 'wp-components', 'wp-edit-post' ],
			Utils\get_asset_info( 'features-script', 'version' )
		);

		$post_types = $this->registered_post_types;
		$post_types = array_map( fn( $post_type ) => $post_type->get_json(), $post_types );
		$post_types = array_values( $post_types );

		$meta_fields = array_map(
			function ( $value ) {
				return is_array( $value ) && count( $value ) === 1 ? $value[0] : $value;
			},
			get_post_meta( get_the_ID() )
		);

		$data = [
			'activePostType' => get_post_type(),
			'postTypes'      => $post_types,
			'metaFields'     => $meta_fields,
			'nonce'          => wp_create_nonce( 'ep_post_type_save' ),
		];

		wp_localize_script( 'ep_post_types_script', 'epPostTypes', $data );
	}

	/**
	 * Set up all active features
	 *
	 * @since 5.3.0
	 */
	public function setup_post_types() {
		/**
		 * Fires before post types are setup
		 *
		 * @hook ep_setup_post_types
		 * @since 5.3.0
		 */
		do_action( 'ep_setup_post_types' );

		foreach ( $this->registered_post_types as $post_type_slug => $post_type ) {
			$post_type->set_i18n_strings();

			$name_single = $post_type->name_single ?? '';
			$name_plural = $post_type->name_plural ?? '';

			register_post_type(
				$post_type_slug,
				[
					'labels'        => [
						'name'               => $post_type->name_plural,
						'singular_name'      => $post_type->name_single,
						'menu_name'          => $post_type->name_plural,
						'name_admin_bar'     => $post_type->name_single,
						'add_new'            => 'Add New',
						'add_new_item'       => "Add New $post_type->name_single",
						'new_item'           => "New $post_type->name_single",
						'edit_item'          => "Edit $post_type->name_single",
						'view_item'          => "View $post_type->name_single",
						'all_items'          => "All $post_type->name_plural",
						'search_items'       => "Search $post_type->name_plural",
						'not_found'          => "No $post_type->name_plural found.",
						'not_found_in_trash' => "No $post_type->name_plural found in Trash.",
					],
					'public'        => true,
					'has_archive'   => true,
					'show_in_rest'  => true,
					'supports'      => [ 'title' ],
					'menu_position' => $post_type->order ?? '',
					'menu_icon'     => $post_type->icon ?? '',
				]
			);
		}
	}

	/**
	 * Registers meta fields
	 */
	public function register_meta_fields() {
		foreach ( $this->registered_post_types as $slug => $post_type ) {
			foreach ( $post_type->get_settings_schema() as $settings ) {
				register_post_meta(
					$slug,
					$settings['key'],
					[
						'show_in_rest'  => true,
						'type'          => 'string',
						'single'        => true,
						'auth_callback' => '__return_true',
					]
				);
			}
		}
	}

	/**
	 * Registers a meta box for custom fields rendered by a React app.
	 *
	 * @since 5.3.0
	 *
	 * @return void
	 */
	public function setup_meta_fields() {
		add_meta_box(
			'custom_fields_app',
			'Custom Fields (React)',
			function ( $post ) {
				include EP_PATH . '/includes/partials/header.php';
				echo '<div id="ep-post-types-dashboard"></div>';
			}
		);
	}

	/**
	 * Registers a meta box for custom fields rendered by a React app.
	 *
	 * @since 5.3.0
	 * @param int $post_id - id of the current post
	 *
	 * @return void
	 */
	public function save_meta_fields( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['ep_post_type_nonce'] ) || ! wp_verify_nonce( $_POST['ep_post_type_nonce'], 'ep_post_type_save' ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( isset( $this->registered_post_types[ $post_type ] ) ) {
			$settings_schema = $this->registered_post_types[ $post_type ]->get_settings_schema();
			foreach ( $settings_schema as $settings ) {
				$key = $settings['key'];
				if ( isset( $_POST[ $key ] ) ) {
					update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				}
			}
		}
	}

	/**
	 * Registers a Post Type for use in ElasticPress
	 *
	 * @param  PostType $post_type An instance of the PostType class
	 * @since  5.3.0
	 * @return boolean
	 */
	public function register_post_type( PostType $post_type ) {
		$this->registered_post_types[ $post_type->slug ] = $post_type;
		return true;
	}

	/**
	 * Determines whether to disable the Gutenberg block editor for specific post types.
	 *
	 * @since 5.3.0
	 *
	 * @param bool   $use_block_editor Whether the block editor is enabled for this post type.
	 * @param string $current_post_type        The post type being checked.
	 * @return bool  False if the block editor should be disabled for the post type, otherwise the original value.
	 */
	public function maybe_disable_gutenberg( $use_block_editor, $current_post_type ) {
		$disabled_post_types = [];
		foreach ( $this->registered_post_types as $post_type_slug => $post_type ) {
			if ( $post_type->classic_editor_only ) {
				$disabled_post_types[] = $post_type_slug;
			}
		}
		return in_array( $current_post_type, $disabled_post_types, true ) ? false : $use_block_editor;
	}

	/**
	 * Return singleton instance of class
	 *
	 * @return object
	 * @since 2.1
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
