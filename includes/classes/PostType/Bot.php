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

		$this->overridable_features = [ 'vector_embeddings', 'semantic_search', 'ai_search_summary' ];

		parent::__construct();
	}

	/**
	 * Default settings
	 *
	 * @var array $default_settings.
	 */
	public $default_settings = [
		'user_prompt' => "You are an assistent in a website and you need to reply to a user search. If you do not know the answer, reply saying you could not find any results. Your answer should come formatted in HTML, but not as a full HTML page, just wrap everything in a div with the 'epio-response' class. Also, do not wrap it with ```html``` tags.

The following JSON object contains the URL and the page content. You should use it as context:

{posts}",
		'ai_feature_config' => [
			'highlight_excerpt' => '1',
		],
	];

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
	 * Pulls each overridable feature's setting schema
	 *
	 * @since 5.3.0
	 */
	public function set_overridable_features(): void {
		$store    = Features::factory();
		$features = $store->registered_features;
		$options  = [
			[
				'label' => __( 'None', 'elasticpress' ),
				'value' => '',
			],
		];

		$feature_settings_all = [];
		foreach ( $this->overridable_features as $slug ) {
			$feature          = $features[ $slug ];
			$feature_settings = $feature->get_settings_schema();

			$options[] = [
				'label' => $feature->title,
				'value' => $feature->slug,
			];

			$feature          = $features[ $slug ];
			$feature_settings = array_slice( $feature->get_settings_schema(), 1 );

			$group = [
				'type'   => 'field_group',
				'key'    => $feature->slug . '_config',
				'label'  => $feature->title,
				'fields' => $feature_settings,
				'requires_fields' => [
					'conditions' => [
						'feature_selection' => $slug,
					],
				],
			];

			$feature_settings_all[] = $group;

		}
		$this->settings_schema[] = [
			'help'    => __( 'Select an AI-enabled ElasticPress Feature to override its configuration.', 'elasticpress' ),
			'key'     => 'feature_selection',
			'label'   => __( 'Feature Selection', 'elasticpress' ),
			'options' => $options,
			'type'    => 'select',
		];

		$this->settings_schema = array_merge( $this->settings_schema, $feature_settings_all );
	}

	/**
	 * Set the `settings_schema` attribute
	 *
	 * @since 5.3.0
	 */
	protected function set_settings_schema() {
		$this->settings_schema = [
			[
				'key'     => 'user_prompt',
				'label'   => __( 'AI User Prompt', 'elasticpress-labs' ),
				'help'    => __( 'The <code>{posts}</code> string will be replaced.', 'elasticpress-labs' ),
				'type'    => 'textarea',
				'default' => $this->default_settings['user_prompt'],
			],
			[
				'key'     => 'system_prompt',
				'label'   => __( 'AI System Prompt', 'elasticpress-labs' ),
				'help'    => __( 'The <code>{posts}</code> string will be replaced.', 'elasticpress-labs' ),
				'type'    => 'textarea',
				'default' => $this->default_settings['user_prompt'],
			],
			[
				'type'   => 'field_group',
				'key'    => 'ai_feature_config',
				'label'  => __( 'AI Feature Configuration', 'elasticpress' ),
				'fields' => [
					[
						'default' => '0',
						'help'    => __( 'Enable to wrap search terms in HTML tags in results for custom styling. The wrapping HTML tag comes with the <code>ep-highlight</code> class for easy styling.' ),
						'key'     => 'highlight_enabled',
						'label'   => __( 'Highlight search terms', 'elasticpress' ),
						'type'    => 'checkbox',
					],
					[
						'default' => '0',
						'help'    => __( 'By default, WordPress strips HTML from content excerpts. Enable when using <code>the_excerpt()</code> to display search results.', 'elasticpress' ),
						'key'     => 'highlight_excerpt',
						'label'   => __( 'Highlight search terms in excerpts', 'elasticpress' ),
						'type'    => 'checkbox',
					],
				],
			],
		];
		$this->set_overridable_features();
	}
}
