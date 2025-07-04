<?php
/**
 * AI Search Summary Feature
 *
 * @since 2.5.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPressLabs\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AI Search Summary feature
 *
 * @since 2.5.0
 */
class AISearchSummary extends Feature {
	/**
	 * Group
	 *
	 * @var string $group.
	 */
	public $group = 'ai';

	/**
	 * Default settings
	 *
	 * @var array $default_settings.
	 */
	public $default_settings = [
		'api_key'         => '',
		'api_url'         => 'https://api.openai.com/v1/chat/completions',
		'chat_model'      => 'o1-mini',
		'number_of_posts' => 5,
		'prompt'          => "You are an assistant in a website and you need to reply to a user search. If you do not know the answer, reply saying you could not find any results. Your answer should come formatted in HTML, but not as a full HTML page, just wrap everything in a div with the 'epio-response' class. Also, do not wrap it with ```html``` tags.

The following JSON object contains the URL and the page content. You should use it as context:

{posts}",
	];

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'ai_search_summary';

		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.2.0', '<' ) ) {
			$this->set_i18n_strings();
		}

		$this->requires_feature = 'vector_embeddings';

		parent::__construct();
	}

	/**
	 * Sets i18n strings.
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'AI Search Summary', 'elasticpress-labs' );

		$this->summary = '<p>' . __( 'AI Search Summary Description', 'elasticpress-labs' ) . '</p>';
	}

	/**
	 * Setup all feature hooks
	 */
	public function setup() {
		add_action( 'init', [ $this, 'register_block' ] );

		// Register REST routes.
		add_action( 'rest_api_init', [ $this, 'setup_endpoint' ] );
	}

	/**
	 * Register block
	 */
	public function register_block() {
		/**
		 * Registering it here so translation works
		 *
		 * @see https://core.trac.wordpress.org/ticket/54797#comment:20
		 */
		wp_register_script(
			'ep-ai-search-summary-block-script',
			ELASTICPRESS_LABS_URL . 'dist/blocks/ai-search-summary-block-script.js',
			Utils\get_asset_info( 'ai-search-summary-block-script.js', 'dependencies' ),
			Utils\get_asset_info( 'ai-search-summary-block-script.js', 'version' ),
			true
		);

		wp_set_script_translations( 'ep-ai-search-summary-block-script', 'elasticpress' );

		register_block_type_from_metadata(
			ELASTICPRESS_LABS_PATH . 'assets/js/blocks/ai-search-summary',
			[
				'render_callback' => [ $this, 'render_block' ],
			]
		);

		wp_register_script(
			'ep-ai-search-summary-block-frontend-script',
			ELASTICPRESS_LABS_URL . 'dist/blocks/ai-search-summary-block-frontend-script.js',
			Utils\get_asset_info( 'ai-search-summary-block-frontend-script', 'dependencies' ),
			Utils\get_asset_info( 'ai-search-summary-block-frontend-script', 'version' ),
			true
		);

		wp_localize_script(
			'ep-ai-search-summary-block-frontend-script',
			'epAISearchSummary',
			[
				'searchQuery'     => ! empty( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'restApiEndpoint' => 'elasticpress-labs/v1/ai-search-summary',
			]
		);

		wp_enqueue_style(
			'ep-ai-search-summary-block-frontend-style',
			ELASTICPRESS_LABS_URL . 'dist/blocks/ai-search-summary-block-frontend-script.css',
			[],
			Utils\get_asset_info( 'ai-search-summary-block-frontend-script', 'version' )
		);
	}

	/**
	 * Render block
	 *
	 * @param array $attributes Block attributes
	 * @return string
	 */
	public function render_block( $attributes ) {
		/**
		 * Filters whether the AI Search Summary block should be displayed.
		 *
		 * This filter allows developers to control the visibility of the AI Search Summary block.
		 * By default, the block is displayed if there is a non-empty search query.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_should_display_block
		 * @param {bool} $should_display Whether the AI Search Summary block should be displayed.
		 * @return {bool} Filtered value indicating whether the AI Search Summary block should be displayed.
		 */
		$should_display = apply_filters( 'ep_ai_search_summary_should_display_block', ! empty( get_search_query() ), $attributes );

		if ( ! $should_display ) {
			return '';
		}

		$attributes = array_merge(
			$attributes,
			[ 'class' => 'wp-block-ep-labs-ai-search-summary' ],
		);

		/**
		 * Filters the HTML tag to be used as the block title.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_block_title_tag
		 * @param {string} $content The content to be filtered.
		 * @return {string} The filtered content.
		 */
		$title_tag = apply_filters( 'ep_ai_search_summary_block_title_tag', 'h2', $attributes );

		/**
		 * Filters the HTML tag to be used as the bottom text.
		 *
		 * This filter allows modification of the content before it is rendered.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_block_title_tag
		 * @param {string} $content The content to be filtered.
		 * @return {string} The filtered content.
		 */
		$note_tag = apply_filters( 'ep_ai_search_summary_block_note_tag', 'p', $attributes );

		// Render block
		ob_start();

		$wrapper_attributes = get_block_wrapper_attributes( $attributes );
		?>
		<section <?php echo wp_kses_data( $wrapper_attributes ); ?>>
			<?php if ( ! empty( $attributes['title'] ) ) : ?>
				<<?php echo $title_tag; ?> class="ep-ai-search-summary--title"><?php echo wp_kses_post( $attributes['title'] ); ?></<?php echo $title_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php endif; ?>
			<div class="ep-ai-search-summary-response"></div>
			<?php if ( ! empty( $attributes['note'] ) ) : ?>
				<<?php echo $note_tag; ?> class="ep-ai-search-summary--note"><?php echo wp_kses_post( $attributes['note'] ); ?></<?php echo $note_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php endif; ?>
		</section>
		<?php

		$block_content = ob_get_clean();

		return $block_content;
	}

	/**
	 * Setup REST endpoints
	 */
	public function setup_endpoint() {
		$controller = new \ElasticPressLabs\REST\AISearchSummary( $this );
		$controller->register_routes();
	}

	/**
	 * Given the user search term/query, get related posts for context, and then get the AI response
	 *
	 * @param string     $search_term    Search term
	 * @param null|array $search_vectors Search term vectors
	 * @return string|\WP_Error
	 */
	public function get_ai_response( $search_term, $search_vectors = null ) {
		if ( ! $search_term ) {
			return '';
		}

		$is_valid_search_term = $this->validate_search_term( $search_term );

		/**
		 * Filter to determine if a search term is valid for the AI Search Summary feature.
		 *
		 * This filter allows customization of the validation logic for search terms
		 * used in the AI Search Summary feature. Developers can use this filter to override the
		 * default validation behavior.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_is_valid_search_term
		 * @param {bool}   $is_valid_search_term Whether the search term is valid. Default is determined by internal logic.
		 * @param {string} $search_term          The search term being validated.
		 * @return {bool} Whether the search term is valid.
		 */
		if ( ! apply_filters( 'ep_ai_search_summary_is_valid_search_term', $is_valid_search_term, $search_term ) ) {
			/**
			 * Filter the response for an invalid search term in the AI Search Summary feature.
			 *
			 * @since 2.5.0
			 * @hook ep_ai_search_summary_invalid_search_term_response
			 * @param {string} $response    The response to return for an invalid search term. Default is an empty string.
			 * @param {string} $search_term The invalid search term that triggered the response.
			 * @return {\WP_Error} Response.
			 */
			return apply_filters( 'ep_ai_search_summary_invalid_search_term_response', new \WP_Error( 'ep-ai-search-summary-invalid-search-term', '' ), $search_term );
		}

		/**
		 * Filters the AI response before it is returned.
		 *
		 * This filter allows developers to short-circuit the AI response generated by the AI Search Summary feature.
		 * Use this if you want to cache responses based on search terms.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_pre_response
		 * @param {null}       $response The   AI response. Default null.
		 * @param {string}     $search_term    The search term provided by the user.
		 * @param {array|null} $search_vectors The search term vectors, if available.
		 * @return {string|null} The filtered AI response.
		 */
		$response = apply_filters( 'ep_ai_search_summary_pre_response', null, $search_term, $search_vectors );
		if ( null !== $response ) {
			return (string) $response;
		}

		$vector_embeddings = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );
		$post_vectors      = $vector_embeddings->get_indexables()['post'];

		$results = $this->get_results( $search_term, $search_vectors );

		$posts_representations = [];
		foreach ( $results as $post_id ) {
			$posts_representations[] = [
				'url'     => get_permalink( $post_id ),
				'content' => implode( '', $post_vectors->get_post_chunks( $post_id ) ),
			];
		}

		$prompt   = $this->get_prompt( $posts_representations );
		$response = $this->ai_api_request( $prompt, $search_term );

		/**
		 * Fires after receiving the response for a RAG (Retrieval-Augmented Generation) post request.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_post_response
		 * @param array|\WP_error $response       The response from the RAG post request.
		 * @param string          $search_term    The search term.
		 * @param array           $search_vectors The search vectors used for the RAG request.
		 * @param string          $prompt         The prompt.
		 */
		do_action( 'ep_ai_search_summary_post_response', $response, $search_term, $search_vectors, $prompt );

		return $response;
	}

	/**
	 * Get the posts to be used as context
	 *
	 * @param string     $search_term    Search term
	 * @param null|array $search_vectors Search term vectors
	 * @return array
	 */
	protected function get_results( $search_term, $search_vectors = null ) {
		$search_term_vectors = ( $search_vectors ) ? $search_vectors : $this->get_search_term_vectors( $search_term );

		$search_feature = \ElasticPress\Features::factory()->get_registered_feature( 'search' );

		$query = [
			'from'    => 0,
			'size'    => (int) $this->get_setting( 'number_of_posts' ),
			'_source' => [
				'includes' => [ 'post_id' ],
			],
			'query'   => [
				'bool' => [
					'must' => [
						[
							'terms' => [
								'post_type.raw' => array_values( $search_feature->get_searchable_post_types() ),
							],
						],
						[
							'terms' => [
								'post_status' => array_values( get_post_stati( array( 'public' => true ) ) ),
							],
						],
						[
							'nested' => [
								'path'  => 'chunks',
								'query' => [
									'script_score' => [
										'query'  => [
											'match_all' => (object) [],
										],
										'script' => [
											'source' => 'cosineSimilarity(params.query_vector, "chunks.vector") + 1.0',
											'params' => [
												'query_vector' => array_map( 'floatval', $search_term_vectors ),
											],
										],
									],
								],
							],
						],
					],
				],
			],
		];

		$query_es = \ElasticPress\Indexables::factory()->get( 'post' )->query_es( $query, [] );
		return isset( $query_es['documents'] ) ? wp_list_pluck( $query_es['documents'], 'post_id' ) : [];
	}

	/**
	 * Generate vectors for the search term
	 *
	 * @param string $search_term The search term
	 * @return array
	 */
	public function get_search_term_vectors( $search_term ) {
		$vector_embeddings = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );

		return $vector_embeddings->generate_embedding( $search_term );
	}

	/**
	 * Generate the prompt for the AI model
	 *
	 * @param array $posts_representations The posts to be used as context
	 * @return string
	 */
	public function get_prompt( $posts_representations ) {
		$posts_representations_str = wp_json_encode( $posts_representations );

		$default_prompt = $this->get_setting( 'prompt' );

		/**
		 * Filters the AI system prompt before it goes to the request.
		 *
		 * This filter allows developers to change the AI prompt set in the plugin settings.
		 * Use this if you want to conditionally manipulate the prompt or implement more sophisticated logic.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_prompt
		 * @param {string} $prompt The prompt as set in the plugin settings.
		 * @return {string} The prompt for the AI model.
		 */
		$prompt = apply_filters( 'ep_ai_search_summary_prompt', $default_prompt );

		return str_replace( '{posts}', $posts_representations_str, $prompt );
	}

	/**
	 * Send a request to the AI API
	 *
	 * @param string $prompt      Prompt for the AI model
	 * @param string $search_term Search query
	 * @return string|\WP_Error
	 */
	public function ai_api_request( $prompt, $search_term ) {
		$headers = [
			'Authorization' => 'Bearer ' . $this->get_setting( 'api_key' ),
			'Content-Type'  => 'application/json',
		];

		$body = [
			'model'    => $this->get_setting( 'chat_model' ),
			'messages' => [
				[
					'role'    => 'system',
					'content' => $prompt,
				],
				[
					'role'    => 'system',
					/**
					 * Filters the system prompt formatting for AI search summary.
					 *
					 * This filter allows customization of the system prompt that formats the AI response.
					 *
					 * @since 2.5.0
					 * @hook ep_ai_search_summary_system_prompt_formatting
					 * @param {string} $system_prompt The default system prompt formatting string.
					 * @param {string} $prompt        The main system prompt being processed. By default, the one set in the plugin settings.
					 * @param {string} $search_term   The search term used in the query.
					 * @return {string} The modified system prompt formatting string.
					 */
					'content' => apply_filters(
						'ep_ai_search_summary_system_prompt_formatting',
						__( 'Send your response as a JSON object with the following keys: "response" (the asnwer, in HTML format) and "references" (an array of objects with the URLs you used to build the response, having "url" and "title" as attributes). Do not wrap the response in any other tags or limiters like "```json". Make sure the JSON object returned is properly escaped. Do not append the list of URLs used to the "response" value, as it will be displayed using the values in "references".', 'elasticpress-labs' ),
						$prompt,
						$search_term
					),
				],
				[
					'role'    => 'user',
					/**
					 * Filters the user prompt for the AI search summary.
					 *
					 * @since 2.5.0
					 * @hook ep_ai_search_summary_user_prompt
					 * @param {string} $search_term The search term to be used as the user prompt.
					 * @return {string} The filtered search term.
					 */
					'content' => apply_filters( 'ep_ai_search_summary_user_prompt', $search_term ),
				],
			],
		];

		$url = $this->get_setting( 'api_url' );

		/**
		 * Filter the options for the post request.
		 *
		 * @hook ep_ai_search_summary_request_options
		 * @since 2.4.0
		 *
		 * @param {array} $options The options for the request.
		 * @param {string} $url The URL for the request.
		 *
		 * @return {array} The options for the request.
		 */
		$options = apply_filters(
			'ep_ai_search_summary_request_options',
			[
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			],
			$url
		);

		$response = wp_remote_post( $url, $options );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'ep_ai_search_summary_request_failed', __( 'An error occurred. Try again later.', 'elasticpress-labs' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'ep_ai_search_summary_non_200_code_' . $code, __( 'An error occurred. Try again later.', 'elasticpress-labs' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$body = json_decode( $body, true );
		return isset( $body['choices'], $body['choices'][0], $body['choices'][0]['message'], $body['choices'][0]['message']['content'] )
			? $body['choices'][0]['message']['content']
			: new \WP_Error( 'ep_ai_search_summary_unformatted_response', __( 'An error occurred. Try again later.', 'elasticpress-labs' ) );
	}

	/**
	 * Set the `settings_schema` attribute
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
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
				'default' => $this->default_settings['api_key'],
			],
			[
				'key'     => 'api_url',
				'help'    => __( 'OpenAI Chat Completion API Url', 'elasticpress-labs' ),
				'label'   => __( 'OpenAI Chat Completion API Url', 'elasticpress-labs' ),
				'type'    => 'text',
				'default' => $this->default_settings['api_url'],
			],
			[
				'key'     => 'chat_model',
				'help'    => __( 'OpenAI Chat model', 'elasticpress-labs' ),
				'label'   => __( 'The name of the chat model to use', 'elasticpress-labs' ),
				'type'    => 'text',
				'default' => $this->default_settings['chat_model'],
			],
			[
				'key'     => 'number_of_posts',
				'label'   => __( 'Number of posts', 'elasticpress-labs' ),
				'help'    => __( 'Number of posts to be used in the context building', 'elasticpress-labs' ),
				'type'    => 'number',
				'default' => $this->default_settings['number_of_posts'],
			],
			[
				'key'     => 'prompt',
				'label'   => __( 'AI Prompt', 'elasticpress-labs' ),
				'help'    => __( 'The <code>{posts}</code> string will be replaced.', 'elasticpress-labs' ),
				'type'    => 'textarea',
				'default' => $this->default_settings['prompt'],
			],
		];
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return FeatureRequirementsStatus Requirements object
	 */
	public function requirements_status() {
		$status = new \ElasticPress\FeatureRequirementsStatus( 1 );

		// Vector support was added in Elasticsearch 7.0.
		if ( version_compare( \ElasticPress\Elasticsearch::factory()->get_elasticsearch_version(), '7.0', '<=' ) ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need to have Elasticsearch with version >7.0.', 'elasticpress-labs' );
		}

		return $status;
	}

	/**
	 * Validates the provided search term.
	 *
	 * This function checks the validity of the given search term
	 * to ensure it meets the required criteria for processing.
	 *
	 * @param string $search_term The search term to validate.
	 * @return bool True if the search term is valid, false otherwise.
	 */
	protected function validate_search_term( string $search_term ): bool {
		$attack_patterns = [
			// Instruction override patterns
			'/ignore previous (instructions|rule|prompt)/i',
			'/disregard (your|all|previous) (instructions|prompt)/i',
			'/forget (your|all) (instructions|prompt)/i',

			// Delimiter exploitation patterns
			'/\<\/?system\>/i',
			'/\<\/?admin\>/i',
			'/\<\/?prompt\>/i',
			'/\<\/?instructions?\>/i',

			// Jailbreak attempts
			'/DAN|Do Anything Now/i',
			'/you are a helpful assistant that only responds/i',
			'/you are in developer mode/i',

			// Role play exploitation
			'/pretend to be/i',
			'/act as if/i',
			'/you are now/i',

			// Payload embedding attempts
			'/\{\{[^}]+\}\}/i',
			'/\[\[[^]]+\]\]/i',
			'/```(system|exec|prompt)/i',

			// Coding context breaking
			'/`\/\/ignore previous code`/i',
			'/\/\*\s*ignore previous\s*\*\//i',
		];

		/**
		 * Filter the attack patterns used in the AI Search Summary feature.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_attack_patterns
		 * @param {array} $attack_patterns The array of attack patterns.
		 * @return {array} The modified array of attack patterns.
		 */
		$attack_patterns = apply_filters( 'ep_ai_search_summary_attack_patterns', $attack_patterns );

		foreach ( $attack_patterns as $pattern ) {
			if ( preg_match( $pattern, $search_term ) ) {
				return false;
			}
		}

		return true;
	}
}
