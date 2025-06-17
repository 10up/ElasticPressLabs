<?php
/**
 * Handles registering and storing post type instances
 *
 * @since  2.1
 * @package elasticpress
 */

namespace ElasticPressLabs;

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
					'supports'      => [ 'title', 'editor', 'thumbnail' ],
					'menu_position' => $post_type->order ?? '',
					'menu_icon'     => $post_type->icon ?? '',
				]
			);
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
