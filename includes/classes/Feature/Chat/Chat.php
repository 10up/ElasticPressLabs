<?php
/**
 * Chat Feature
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPress\Feature;
use ElasticPress\FeatureRequirementsStatus;
use ElasticPressLabs\ConnectionTest;
use ElasticPressLabs\ConnectionTestable;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Jobs\Dispatcher;
use ElasticPressLabs\Feature\Chat\Jobs\Reaper;
use ElasticPressLabs\Feature\Chat\Jobs\Retention;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Chat Feature
 */
class Chat extends Feature implements ConnectionTestable {
	/**
	 * Settings field type for the answer instructions: a text area with a prompt preview,
	 * rendered by the chat-settings script.
	 *
	 * @var string
	 */
	public const INSTRUCTIONS_FIELD_TYPE = 'ep_chat_instructions';

	/**
	 * Group
	 *
	 * @var string $group.
	 */
	public $group = 'ai';

	/**
	 * Default settings: the re-ranker connection, then the code defaults of Settings::FIELDS
	 * (added by the constructor).
	 *
	 * @var array
	 */
	public $default_settings = [
		'rerank_api_key' => '',
		'rerank_api_url' => '',
		'rerank_model'   => '',
	];

	/**
	 * Initialize feature setting its config
	 */
	public function __construct() {
		$this->slug = 'chat';

		foreach ( Settings::FIELDS as $key => $field ) {
			$this->default_settings[ $key ] = Settings::to_stored( $field['default'] );
		}

		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.2.0', '<' ) ) {
			$this->set_i18n_strings();
		}

		$this->requires_feature = 'vector_embeddings';

		// setup() does not run while Chat is off or temporarily disabled, but its sweep may
		// still be scheduled.
		add_action( Reaper::HOOK, [ $this, 'maybe_unschedule_reaper' ] );

		parent::__construct();
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'Chat', 'elasticpress-labs' );

		$this->summary = __( 'Answers questions in wp-admin from your published content. Needs Vector Embeddings and an AI provider.', 'elasticpress-labs' );

		// Four boxes: the models (needed, so open), then the optional re-ranker, the chat bot
		// defaults, and the advanced limits. Collapsible on ElasticPress 5.4+.
		$this->field_group_map = [
			'chat_models'   => [
				'label'       => esc_html__( 'Models', 'elasticpress-labs' ),
				'collapsible' => true,
				'initialOpen' => true,
			],
			'chat_rerank'   => [
				'label'       => esc_html__( 'Re-ranker (optional)', 'elasticpress-labs' ),
				'collapsible' => true,
				'initialOpen' => false,
			],
			'chat_defaults' => [
				'label'       => esc_html__( 'Chat bot defaults', 'elasticpress-labs' ),
				'collapsible' => true,
				'initialOpen' => false,
			],
			'chat_advanced' => [
				'label'       => esc_html__( 'Advanced', 'elasticpress-labs' ),
				'collapsible' => true,
				'initialOpen' => false,
			],
		];
	}

	/**
	 * Field groups, with the re-ranker's state on its header, so a closed box still says it.
	 *
	 * @return array
	 */
	public function get_field_group_map(): array {
		$map = parent::get_field_group_map();

		if ( isset( $map['chat_rerank'] ) && ! isset( $map['chat_rerank']['badge'] ) ) {
			$map['chat_rerank']['badge'] = $this->is_rerank_set_up()
				? __( 'Set up', 'elasticpress-labs' )
				: __( 'Not set up', 'elasticpress-labs' );
		}

		return $map;
	}

	/**
	 * Whether the re-ranker connection has a URL and a model.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public function is_rerank_set_up(): bool {
		return '' !== trim( (string) $this->get_setting( 'rerank_api_url' ) ) && '' !== trim( (string) $this->get_setting( 'rerank_model' ) );
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return FeatureRequirementsStatus Requirements object
	 */
	public function requirements_status() {
		$status = new FeatureRequirementsStatus( FeatureRequirementsStatus::MANUALLY_ENABLED );

		$vector_embeddings = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );

		if ( ! $vector_embeddings || ! $vector_embeddings->is_active() ) {
			$status->code    = FeatureRequirementsStatus::FORCE_DISABLED;
			$status->message = esc_html__( 'Chat requires the Vector Embeddings feature to be active.', 'elasticpress-labs' );

			return $status;
		}

		// Temporarily disabled, not force disabled: ElasticPress turns a force-disabled feature
		// off, and would not turn Chat back on once a provider is set up.
		if ( ! Models::is_ai_available() ) {
			$status->code    = FeatureRequirementsStatus::TEMPORARILY_DISABLED;
			$status->message = wp_kses_post(
				sprintf(
					/* translators: %s: Connectors screen URL */
					__( 'Chat needs an AI provider. <a href="%s">Set one up in Connectors</a>.', 'elasticpress-labs' ),
					esc_url( admin_url( 'options-connectors.php' ) )
				)
			);
		}

		return $status;
	}

	/**
	 * Connects the feature with WordPress using hooks and/or filters.
	 *
	 * On every request: the post types (kept out of the index, and their comments out of other
	 * comment queries), the stale-job sweep, and keeping the index schema cache current. The
	 * rest only where Chat runs: wp-admin, REST, cron, and WP-CLI, so a front-end page load does
	 * not build it.
	 *
	 * @return void
	 */
	public function setup() {
		$storage = new Storage();
		$storage->setup();

		$bots = new Bots();
		$bots->setup();

		$reaper = new Reaper();
		$reaper->setup();

		$retention = new Retention();
		$retention->setup();

		// Terms can change on any request, and the schema the query writer reads must follow.
		$index_schema = new Index_Schema();
		$index_schema->setup();

		// REST_REQUEST is only defined after `init`.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->setup_runtime();
		} else {
			add_action( 'rest_api_init', [ $this, 'setup_runtime' ], 0 );
		}
	}

	/**
	 * Connects the parts of the feature that answer questions and show the screens.
	 *
	 * @return void
	 */
	public function setup_runtime() {
		$admin = new Admin();
		$admin->setup();

		Gates::registry();

		$dispatcher = new Dispatcher();
		$dispatcher->setup();

		$summarizer = new Summarizer();
		$summarizer->setup();

		$intent = new Intent();
		$intent->setup();

		$clarifier = new Clarifier();
		$clarifier->setup();

		$abilities = new Abilities();
		$abilities->setup();

		$query_generator = new Query_Generator();
		$query_generator->setup();

		AI_Query::register();

		add_action( 'rest_api_init', [ $this, 'setup_endpoint' ] );
	}

	/**
	 * After Chat is turned on, seed a default bot if none exist.
	 *
	 * @return void
	 */
	public function post_activation() {
		parent::post_activation();

		$bots = new Bots();
		$bots->ensure_default();
	}

	/**
	 * Stop the stale-job sweep when Chat is turned off.
	 *
	 * @return void
	 */
	public function post_deactivation() {
		parent::post_deactivation();

		Reaper::unschedule();
	}

	/**
	 * Stop the stale-job sweep when it fires while Chat is not active.
	 *
	 * @return void
	 */
	public function maybe_unschedule_reaper() {
		if ( ! $this->is_active() ) {
			Reaper::unschedule();
		}
	}

	/**
	 * Register Chat REST routes.
	 *
	 * @return void
	 */
	public function setup_endpoint() {
		$chat = new \ElasticPressLabs\REST\Chat();
		$chat->register_routes();

		$bots = new \ElasticPressLabs\REST\ChatBots();
		$bots->register_routes();
	}

	/**
	 * Settings in four boxes: models, re-ranker (optional), chat bot defaults, and advanced. A
	 * "Manage chat bots" button comes first when Chat is on. Help is one line; longer details are
	 * behind "More".
	 *
	 * @return void
	 */
	protected function set_settings_schema() {
		$this->settings_schema = [];

		if ( $this->is_active() ) {
			$this->settings_schema[] = [
				'key'   => 'manage_bots',
				'label' => sprintf(
					'<p><a class="button" href="%1$s">%2$s</a></p>',
					esc_url( admin_url( 'admin.php?page=' . Admin::BOTS_PAGE_SLUG ) ),
					esc_html__( 'Manage chat bots', 'elasticpress-labs' )
				),
				'type'  => 'markup',
			];
		}

		$this->settings_schema[] = [
			'key'   => 'cost_note',
			'label' => '<div class="notice notice-info inline"><p>' . esc_html__( 'Each question calls your AI provider several times (to read the question, write the answer, and run the optional checks), and the provider bills each call. By default, anyone who can write posts can ask. Use the hourly limit under Advanced to keep usage in check.', 'elasticpress-labs' ) . '</p></div>',
			'type'  => 'markup',
		];

		$models      = Models::get_list();
		$model_type  = $this->supports_field_type( 'combobox' ) ? 'combobox' : 'select';
		$model_notes = '';

		foreach ( $models['errors'] as $provider => $message ) {
			/* translators: 1: AI provider ID, 2: error message */
			$model_notes .= ' ' . sprintf( __( 'The models of %1$s could not be loaded (%2$s); a saved model of that provider is kept.', 'elasticpress-labs' ), '<code>' . esc_html( $provider ) . '</code>', esc_html( $message ) );
		}

		$model_field = function ( string $key, string $empty_label ) use ( $model_type, $model_notes ): array {
			return [
				'key'              => $key,
				'label'            => Settings::get_label( $key ),
				'help'             => $this->get_help_html( $key ) . $model_notes,
				'type'             => $model_type,
				'options'          => Models::get_options( $empty_label ),
				'default'          => '',
				'field_group_slug' => 'chat_models',
			];
		};

		// Models: the answer model, then the smaller tasks.
		$this->settings_schema[] = $model_field( 'answer_model', __( 'Site default model', 'elasticpress-labs' ) );
		$this->settings_schema[] = $this->get_subheading( 'chat_models_tasks', __( 'Smaller tasks', 'elasticpress-labs' ), __( 'A small, fast model works well for these.', 'elasticpress-labs' ), 'chat_models' );
		$this->settings_schema[] = $model_field( 'intent_model', __( 'The chat bot’s answer model', 'elasticpress-labs' ) );
		$this->settings_schema[] = $model_field( 'query_model', __( 'The question model', 'elasticpress-labs' ) ) + $this->get_ai_method_condition();
		$this->settings_schema[] = $model_field( 'summary_model', __( 'The chat bot’s answer model', 'elasticpress-labs' ) );
		$this->settings_schema[] = $model_field( 'verifier_model', __( 'Same as the answer model', 'elasticpress-labs' ) );

		// Re-ranker: the connection, its test, and the default minimum score.
		$this->settings_schema[] = $this->get_subheading( 'chat_rerank_intro', '', __( 'Re-ranks the search results by relevance before the model reads them. Each chat bot turns it on or off.', 'elasticpress-labs' ), 'chat_rerank' );
		$this->settings_schema[] = [
			'key'              => 'rerank_api_url',
			'label'            => __( 'API URL', 'elasticpress-labs' ),
			'help'             => __( 'A Cohere-compatible <code>/rerank</code> endpoint, such as LiteLLM’s.', 'elasticpress-labs' ),
			'type'             => 'text',
			'default'          => $this->default_settings['rerank_api_url'],
			'field_group_slug' => 'chat_rerank',
		];
		$this->settings_schema[] = [
			'key'              => 'rerank_api_key',
			'label'            => __( 'API key', 'elasticpress-labs' ),
			'help'             => __( 'Separate from the AI providers’ keys. Leave it empty for a re-ranker that needs none.', 'elasticpress-labs' ),
			'type'             => 'text',
			'default'          => $this->default_settings['rerank_api_key'],
			'field_group_slug' => 'chat_rerank',
		];
		$this->settings_schema[] = [
			'key'              => 'rerank_model',
			'label'            => __( 'Model', 'elasticpress-labs' ),
			'help'             => Settings::to_help_html(
				__( 'The model name sent to the re-ranker.', 'elasticpress-labs' ),
				__( 'For Bedrock models through LiteLLM, use the full foundation-model ARN, not the short model group name. Example: bedrock/arn:aws:bedrock:us-east-1::foundation-model/cohere.rerank-v3-5:0', 'elasticpress-labs' )
			),
			'type'             => 'text',
			'default'          => $this->default_settings['rerank_model'],
			'field_group_slug' => 'chat_rerank',
		];

		$test = ConnectionTest::get_field( $this, 'chat_rerank' );

		if ( $test ) {
			$this->settings_schema[] = $test;
		}

		$this->settings_schema[] = $this->get_number_field( 'rerank_floor', 'chat_rerank' );

		// Chat bot defaults.
		$this->settings_schema[] = $this->get_subheading( 'chat_defaults_intro', '', __( 'Chat bots use these unless they change them.', 'elasticpress-labs' ), 'chat_defaults' );
		$this->settings_schema[] = [
			'key'              => 'answer_instructions',
			'label'            => Settings::get_label( 'answer_instructions' ),
			'help'             => $this->get_help_html( 'answer_instructions' ),
			'type'             => $this->supports_field_type( self::INSTRUCTIONS_FIELD_TYPE ) ? self::INSTRUCTIONS_FIELD_TYPE : 'textarea',
			'default'          => '',
			'field_group_slug' => 'chat_defaults',
		];
		$this->settings_schema[] = $this->get_number_field( 'retrieve_count', 'chat_defaults' );
		$this->settings_schema[] = $this->get_number_field( 'history_messages', 'chat_defaults' );

		$this->settings_schema[] = $this->get_choice_field( 'decline_display', 'chat_defaults' );

		$this->settings_schema[] = $this->get_subheading( 'chat_defaults_search', __( 'Search', 'elasticpress-labs' ), '', 'chat_defaults' );
		$this->settings_schema[] = $this->get_choice_field( 'search_algorithm', 'chat_defaults' );
		$this->settings_schema[] = $this->get_choice_field( 'search_fallback', 'chat_defaults' ) + $this->get_ai_method_condition();

		$this->settings_schema[] = $this->get_subheading( 'chat_defaults_before', __( 'Before searching', 'elasticpress-labs' ), '', 'chat_defaults' );

		foreach ( [ 'intent_enabled', 'date_range_enabled', 'clarify_enabled' ] as $key ) {
			$this->settings_schema[] = $this->get_checkbox_field( $key );
		}

		$this->settings_schema[] = $this->get_subheading( 'chat_defaults_after', __( 'After answering', 'elasticpress-labs' ), '', 'chat_defaults' );
		$this->settings_schema[] = $this->get_checkbox_field( 'summary_enabled' );

		// Advanced.
		$this->settings_schema[] = $this->get_subheading( 'chat_advanced_intro', '', __( 'Most sites do not need to change these.', 'elasticpress-labs' ), 'chat_advanced' );

		foreach ( [ 'passage_chunks', 'passage_word_budget', 'max_in_flight', 'max_per_hour', 'retry_after', 'retention_days' ] as $key ) {
			$this->settings_schema[] = $this->get_number_field( $key, 'chat_advanced' );
		}
	}

	/**
	 * Help HTML of a setting: the short line, then "More".
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	protected function get_help_html( string $key ): string {
		return Settings::to_help_html( Settings::get_help( $key ), Settings::get_details( $key ) );
	}

	/**
	 * A choice setting as radio buttons, each with its one-line description.
	 *
	 * @param string $key   Setting key.
	 * @param string $group Field group.
	 * @return array
	 */
	protected function get_choice_field( string $key, string $group ): array {
		$labels       = Settings::get_choice_labels( $key );
		$descriptions = Settings::get_choice_descriptions( $key );

		return [
			'key'              => $key,
			'label'            => Settings::get_label( $key ),
			'help'             => $this->get_help_html( $key ),
			'type'             => 'radio',
			'options'          => array_map(
				static fn( $value, $label ) => [
					'value' => (string) $value,
					'label' => sprintf(
						'%1$s<span class="ep-chat-choice-description">%2$s</span>',
						esc_html( $label ),
						esc_html( $descriptions[ $value ] ?? '' )
					),
				],
				array_keys( $labels ),
				$labels
			),
			'default'          => Settings::to_stored( Settings::get_default( $key ) ),
			'field_group_slug' => $group,
		];
	}

	/**
	 * Show a field only while the search method is an AI search method (see
	 * Settings::get_ai_search_methods()): no field at all when none is registered. Older
	 * ElasticPress versions read a condition as one exact value, so there the field is always
	 * shown.
	 *
	 * @return array `requires_fields` for the settings schema, or nothing.
	 */
	protected function get_ai_method_condition(): array {
		if ( ! $this->supports_any_of_conditions() ) {
			return [];
		}

		return [
			'requires_fields' => [
				'conditions' => [ 'search_algorithm' => Settings::get_ai_search_methods() ],
			],
		];
	}

	/**
	 * Whether the settings screen reads a condition value that is a list as "any of these"
	 * (ElasticPress 5.4.0 or later).
	 *
	 * @return bool
	 */
	protected function supports_any_of_conditions(): bool {
		return defined( 'EP_VERSION' ) && version_compare( EP_VERSION, '5.4.0', '>=' );
	}

	/**
	 * A number field (the minimum score is text: empty means none, which a number field refuses).
	 *
	 * @param string $key   Setting key.
	 * @param string $group Field group.
	 * @return array
	 */
	protected function get_number_field( string $key, string $group ): array {
		return [
			'key'              => $key,
			'label'            => Settings::get_label( $key ),
			'help'             => $this->get_help_html( $key ),
			'type'             => 'rerank_floor' === $key ? 'text' : 'number',
			'default'          => $this->default_settings[ $key ],
			'field_group_slug' => $group,
		];
	}

	/**
	 * An on / off default of the chat bots.
	 *
	 * @param string $key Setting key.
	 * @return array
	 */
	protected function get_checkbox_field( string $key ): array {
		return [
			'key'              => $key,
			'label'            => Settings::get_label( $key ),
			'help'             => $this->get_help_html( $key ),
			'type'             => 'checkbox',
			'default'          => $this->default_settings[ $key ],
			'field_group_slug' => 'chat_defaults',
		];
	}

	/**
	 * A heading and / or one line of text inside a box.
	 *
	 * @param string $key   Unique key (not a setting).
	 * @param string $title Heading (may be empty).
	 * @param string $text  Text (may be empty).
	 * @param string $group Field group.
	 * @return array
	 */
	protected function get_subheading( string $key, string $title, string $text, string $group ): array {
		$html = '' !== $title ? '<strong class="ep-chat-settings-heading">' . esc_html( $title ) . '</strong>' : '';

		if ( '' !== $text ) {
			$html .= '<span class="ep-chat-settings-intro">' . esc_html( $text ) . '</span>';
		}

		return [
			'key'              => $key,
			'label'            => '<p class="ep-chat-settings-subheading">' . $html . '</p>',
			'type'             => 'markup',
			'field_group_slug' => $group,
		];
	}

	/**
	 * The re-ranker settings the connection test reads.
	 *
	 * @return string[]
	 */
	public function get_connection_fields(): array {
		return [ 'rerank_api_url', 'rerank_api_key', 'rerank_model' ];
	}

	/**
	 * Re-rank two short passages with these settings.
	 *
	 * @param array $settings Chat settings, with the unsaved connection fields.
	 * @return string|\WP_Error
	 */
	public function test_connection( array $settings ) {
		$url   = trim( (string) ( $settings['rerank_api_url'] ?? '' ) );
		$model = trim( (string) ( $settings['rerank_model'] ?? '' ) );

		if ( '' === $model ) {
			return new \WP_Error( 'ep_chat_rerank_test_no_model', __( 'Fill in the model first.', 'elasticpress-labs' ) );
		}

		$reranker  = new Cohere_Compatible_Reranker( $url, trim( (string) ( $settings['rerank_api_key'] ?? '' ) ), $model );
		$documents = [
			[
				'id'   => 1,
				'text' => 'A connection test passage.',
			],
			[
				'id'   => 2,
				'text' => 'Another short passage.',
			],
		];
		$body      = ConnectionTest::send( $url, $reranker->get_request_options( 'connection test', $documents ) );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$scored = $reranker->normalize_results( $body, $documents );

		if ( is_wp_error( $scored ) || ! $scored ) {
			return new \WP_Error( 'ep_chat_rerank_test_no_scores', __( 'The service answered, but not with re-ranking scores. Check the API URL and the model.', 'elasticpress-labs' ) );
		}

		return __( 'Connected. The re-ranker scored the test passages.', 'elasticpress-labs' );
	}

	/**
	 * Hooks for the ElasticPress features screen, which renders the Chat settings whether or not
	 * Chat is on. ElasticPress versions without custom field types get a plain text area.
	 *
	 * @return void
	 */
	public function setup_settings_screen() {
		if ( ! function_exists( '\ElasticPress\Utils\get_settings_field_types' ) ) {
			return;
		}

		add_filter( 'ep_settings_field_types', [ $this, 'add_settings_field_types' ] );
		// Before ElasticPress (10), so the control is registered when the screen renders.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_script' ], 9 );
	}

	/**
	 * Add the answer instructions field type.
	 *
	 * @param string[] $types Field types.
	 * @return string[]
	 */
	public function add_settings_field_types( $types ) {
		$types   = (array) $types;
		$types[] = self::INSTRUCTIONS_FIELD_TYPE;

		return $types;
	}

	/**
	 * Enqueue the script that renders the answer instructions field on the features screen.
	 *
	 * @return void
	 */
	public function enqueue_settings_script() {
		if ( 'dashboard' !== \ElasticPress\Screen::factory()->get_current_screen() ) {
			return;
		}

		$asset = \ElasticPressLabs\Utils\get_asset_info( 'chat-settings-script' );

		if ( empty( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			'ep_chat_settings_script',
			ELASTICPRESS_LABS_URL . 'dist/js/chat-settings-script.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ep_chat_settings_script', 'elasticpress-labs' );

		wp_enqueue_style(
			'ep_chat_settings_script',
			ELASTICPRESS_LABS_URL . 'dist/css/chat-settings-script.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'ep_chat_settings_script',
			'epChatSettings',
			[
				'fieldType'       => self::INSTRUCTIONS_FIELD_TYPE,
				'previewEndpoint' => 'elasticpress-labs/v1/chat-bots/prompt-preview',
			]
		);
	}

	/**
	 * Refuse out-of-range values and unknown models when the settings are saved.
	 *
	 * @param mixed $value Chat settings sent to the features REST route.
	 * @return mixed|\WP_Error
	 */
	public function sanitize_settings_callback( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( array_keys( Settings::FIELDS ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				continue;
			}

			$clean = Settings::sanitize( $key, $value[ $key ] );

			// Search algorithms come and go with other features: one that is gone is not an error,
			// the setting goes back to its default (as the Search Algorithm feature does).
			if ( is_wp_error( $clean ) && in_array( $key, [ 'search_algorithm', 'search_fallback' ], true ) ) {
				$clean = null;
			}

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$value[ $key ] = Settings::to_stored( $clean );
		}

		return $value;
	}

	/**
	 * Whether the installed ElasticPress renders a settings field type.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	protected function supports_field_type( string $type ): bool {
		return function_exists( '\ElasticPress\Utils\get_settings_field_types' )
			&& in_array( $type, \ElasticPress\Utils\get_settings_field_types(), true );
	}
}
