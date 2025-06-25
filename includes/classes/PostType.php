<?php
/**
 * PostType class to be initiated for all post types.
 *
 * All post types extend this class.
 *
 * @since 5.3.0
 * @package elasticpress
 */

namespace ElasticPressLabs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Post Type abstract class
 */
abstract class PostType {
	/**
	 * The order in the WordPress admin bar
	 *
	 * @var int
	 * @since 5.3.0
	 */
	public $slug;

	/**
	 * The order in the WordPress admin bar
	 *
	 * @var int
	 * @since 5.3.0
	 */
	public $icon;

	/**
	 * The order in the WordPress admin bar
	 *
	 * @var int
	 * @since 5.3.0
	 */
	public $order;

	/**
	 * The order in the WordPress admin bar
	 *
	 * @var int
	 * @since 5.3.0
	 */
	public $name_plural;

	/**
	 * The order in the WordPress admin bar
	 *
	 * @var int
	 * @since 5.3.0
	 */
	public $name_singular;

	/**
	 * The order in the WordPress admin bar
	 *
	 * @var int
	 * @since 5.3.0
	 */
	public $classic_editor_only;

	/**
	 * Settings description
	 *
	 * @since 5.3.0
	 * @var array
	 */
	protected $settings_schema = [];

	/**
	 * Run on every page load for post type to set itself up
	 *
	 * @since 5.3.0
	 */
	abstract public function setup();

	/**
	 * Create feature
	 *
	 * @since 5.3.0
	 */
	public function __construct() {
		/**
		 * Fires when Feature object is created
		 *
		 * @hook ep_post_type_create
		 * @param {PostType} $post_type Current post type
		 * @since  5.3.0
		 */
		do_action( 'ep_post_type_create', $this );
	}

	/**
	 * Sets the i18n strings for the feature.
	 *
	 * @return void
	 * @since 5.3.0
	 */
	public function set_i18n_strings(): void {
	}

	/**
	 * Get a JSON representation of the feature
	 *
	 * @since 5.3.0
	 * @return string
	 */
	public function get_json() {
		$feature_desc = [
			'slug'           => $this->slug,
			'settingsSchema' => $this->get_settings_schema(),
		];

		if ( property_exists( $this, 'default_settings' ) && ! empty( $this->default_settings ) ) {
			$feature_desc['defaultSettings'] = $this->default_settings;
		}

		return $feature_desc;
	}

	/**
	 * Return the feature settings schema
	 *
	 * @since 5.3.0
	 * @return array
	 */
	public function get_settings_schema() {
		if ( [] === $this->settings_schema ) {
			$this->set_settings_schema();
		}

		/**
		 * Filter the settings schema of a feature
		 *
		 * @hook ep_post_type_settings_schema
		 * @since 5.3.0
		 * @param {array}   $settings_schema True if the feature is available
		 * @param {string}  $feature_slug    Feature slug
		 * @param {Feature} $feature         Feature object
		 * @return {array} New $settings_schema value
		 */
		return apply_filters( 'ep_post_type_settings_schema', $this->settings_schema, $this );
	}

	/**
	 * Sets the settings_schema
	 *
	 * @since 5.3.0
	 */
	protected function set_settings_schema() {
	}
}
