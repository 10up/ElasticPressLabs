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
		$this->settings_schema = [
			[
				'key'     => 'search_term_embed_method',
				'label'   => __( 'Search Term Embedding Method', 'elasticpress-labs' ),
				'help'    => __( 'The method to use to vectorize the search term. The model used here should match the one used to vectorize your content.', 'elasticpress-labs' ),
				'options' => [
					[
						'label' => __( 'Client side', 'elasticpress-labs' ),
						'value' => 'client-side',
					],
					[
						'label' => __( 'Server side', 'elasticpress-labs' ),
						'value' => 'server-side',
					],
				],
				'type'    => 'radio',
			],
			[
				'key'     => 'api_key',
				'label'   => __( 'OpenAI API Key', 'elasticpress-labs' ),
				'help'    => sprintf(
					wp_kses(
						/* translators: %1$s: OpenAI sign up URL */
						__( 'Don\'t have an OpenAI account yet? <a title="Sign up for an OpenAI account" href="%1$s">Sign up for one</a> in order to get your API key.', 'elasticpress-labs' ),
						[
							'a' => [
								'href'  => [],
								'title' => [],
							],
						]
					),
					esc_url( 'https://platform.openai.com/signup' )
				),
				'type'    => 'text',
				// 'default' => $this->default_settings['api_key'],
			],
			[
				'key'     => 'api_url',
				'help'    => __( 'OpenAI Chat Completion API Url', 'elasticpress-labs' ),
				'label'   => __( 'OpenAI Chat Completion API Url', 'elasticpress-labs' ),
				'type'    => 'text',
				// 'default' => $this->default_settings['api_url'],
			],
			[
				'key'     => 'chat_model',
				'help'    => __( 'OpenAI Chat model', 'elasticpress-labs' ),
				'label'   => __( 'The name of the chat model to use', 'elasticpress-labs' ),
				'type'    => 'text',
				// 'default' => $this->default_settings['chat_model'],
			],
			[
				'key'     => 'number_of_posts',
				'label'   => __( 'Number of posts', 'elasticpress-labs' ),
				'help'    => __( 'Number of posts to be used in the context building', 'elasticpress-labs' ),
				'type'    => 'number',
				// 'default' => $this->default_settings['number_of_posts'],
			],
			[
				'key'     => 'prompt',
				'label'   => __( 'AI Prompt', 'elasticpress-labs' ),
				'help'    => __( 'The <code>{posts}</code> string will be replaced.', 'elasticpress-labs' ),
				'type'    => 'textarea',
				// 'default' => $this->default_settings['prompt'],
			],
			[
				'default' => '0',
				'key'     => 'trigger_ga_event',
				'help'    => __( 'Enable to fire a gtag tracking event when an autosuggest result is clicked.', 'elasticpress' ),
				'label'   => __( 'Trigger Google Analytics events', 'elasticpress' ),
				'type'    => 'checkbox',
			],
			[
				'key'   => 'epio',
				'label' => sprintf(
					/* translators: 1: <a> tag (ElasticPress.io); 2. </a>; 3: <a> tag (KB article); 4. </a>; 5: <a> tag (Site Health Debug Section); 6. </a>; */
					__( 'You are directly connected to %1$sElasticPress.io%2$s, ensuring the most performant Autosuggest experience. %3$sLearn more about what this means%4$s or %5$sclick here for debug information%6$s.', 'elasticpress' ),
					'<a href="123">',
					'</a>',
					'<a href="123">',
					'</a>',
					'<a href="123">',
					'</a>'
				),
				'type'  => 'markup',
			],
			[
				'default' => 'post_type,tax-category,tax-post_tag',
				'key'     => 'facets',
				'label'   => __( 'Filters', 'elasticpress' ),
				'options' => [
					[
						'label' => 'Post type',
						'value' => 'post_type',
					],
					[
						'label' => 'Category (category)',
						'value' => 'tax-category',
					],
					[
						'label' => 'Tag (post_tag)',
						'value' => 'tax-post_tag',
					],
				],
				'type'    => 'multiple',
			],
			[
				'default' => 'mark',
				'help'    => __( 'Select the HTML tag used to highlight search terms.', 'elasticpress' ),
				'key'     => 'highlight_tag',
				'label'   => __( 'Highlight tag', 'elasticpress' ),
				'options' => [
					[
						'label' => __( 'None', 'elasticpress' ),
						'value' => '',
					],
					[
						'label' => 'mark',
						'value' => 'mark',
					],
					[
						'label' => 'span',
						'value' => 'span',
					],
					[
						'label' => 'strong',
						'value' => 'strong',
					],
					[
						'label' => 'em',
						'value' => 'em',
					],
					[
						'label' => 'i',
						'value' => 'i',
					],
				],
				'type'    => 'select',
			],
			[
				'default'          => false,
				'key'              => 'active',
				'label'            => __( 'Enable', 'elasticpress' ),
				// 'requires_feature' => $this->requires_feature,
				// 'requires_sync'    => $this->requires_install_reindex,
				'type'             => 'toggle',
			],
		];
	}
}
