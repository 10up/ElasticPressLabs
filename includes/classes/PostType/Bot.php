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

use ElasticPress\Features;

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

		$this->classic_editor_only = true;

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
	 * @since 5.3.0
	 */
	protected function set_settings_schema() {
		$store    = Features::factory();
		$features = $store->registered_features;

		$selectable_features = [ 'search', 'instant-results', 'autosuggest', 'did-you-mean', 'facets' ];

		$options = [
			[
				'label' => __( 'None', 'elasticpress' ),
				'value' => '',
			],
		];

		foreach ( $selectable_features as $slug ) {
			$feature          = $features[ $slug ];
			$feature_settings = $feature->get_settings_schema();

			$options[] = [
				'label' => $feature->title,
				'value' => $feature->slug,
			];
		}

		$feature_settings_all = [];
		foreach ( $selectable_features as $slug ) {
			$feature          = $features[ $slug ];
			$feature_settings = array_slice( $feature->get_settings_schema(), 1 );

			foreach ( $feature_settings as &$setting ) {
				$setting['requires_fields'] = [
					'conditions' => [
						'feature_selection' => $slug,
					],
				];
			}
			unset( $setting );

			$feature_settings_all = array_merge( $feature_settings_all, $feature_settings );
		}

		$this->settings_schema = [
			[
				'help'    => __( 'Select an AI-enabled ElasticPress Feature to override its configuration.', 'elasticpress' ),
				'key'     => 'feature_selection',
				'label'   => __( 'Feature Selection', 'elasticpress' ),
				'options' => $options,
				'type'    => 'select',
			],
		];

		$this->settings_schema = array_merge( $this->settings_schema, $feature_settings_all );
	}
}
