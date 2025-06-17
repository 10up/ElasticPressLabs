<?php
/**
 * Comments feature
 *
 * @since   3.6.0
 * @package elasticpress
 */

namespace ElasticPressLabs\PostType;

use ElasticPressLabs\PostTypes;
use ElasticPressLabs\PostType;

/**
 * Comments feature class
 */
class Bot extends PostType {

	/**
	 * Initialize feature, setting it's config
	 *
	 * @since 5.3.0
	 */
	public function __construct() {
		$this->slug  = 'ai-bot';
		$this->icon  = 'dashicons-nametag';
		$this->order = 20;

		parent::__construct();
	}

	/**
	 * Setup search functionality.
	 *
	 * @return void
	 */
	public function setup() {
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 * @since 5.3.0
	 */
	public function set_i18n_strings(): void {
		$this->name_plural = esc_html__( 'AI Bots', 'elasticpress' );
		$this->name_single = esc_html__( 'AI Bot', 'elasticpress' );
	}

	/**
	 * Set the `settings_schema` attribute
	 *
	 * @since 5.0.0
	 */
	protected function set_settings_schema() {
		$this->settings_schema = [
			[],
		];
	}
}
