<?php
/**
 * Vector Embeddings
 *
 * This feature enables storage of vector embeddings, a numerical representation of the
 * indexed content that can capture semantic relationships and similarities between data points.
 * These embeddings are often used by AI models to process and understand complex information
 * more efficiently and are used for features like natural language processing, recommendations and computer vision.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

use ElasticPress\Feature;
use ElasticPress\Elasticsearch;
use ElasticPress\Utils;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Vector Embeddings feature
 */
class VectorEmbeddings extends Feature {
	/**
	 * Array of VectorEmbeddings\Indexable objects
	 *
	 * @var array
	 */
	protected $indexables = [];

	/**
	 * Default settings
	 *
	 * @var array $default_settings.
	 */
	public $default_settings = [
		'ep_embeddings_api_key'            => '',
		'ep_embeddings_api_url'            => 'https://api.openai.com/v1/embeddings',
		'ep_embeddings_embedding_model'    => 'text-embedding-3-small',
		'ep_embeddings_dimensions'         => 512,
		'ep_embeddings_external_embedding' => '0',
		'ep_embeddings_use_epio'           => '0',
	];

	/**
	 * Settings Page Module
	 *
	 * @var SettingsPage
	 */
	public $settings_page;

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'vector_embeddings';

		$this->title = esc_html__( 'Vector Embeddings', 'elasticpress-labs' );

		$this->requires_install_reindex = true;

		$this->summary = __(
			'This feature enables storage of vector embeddings, a numerical representation of the indexed content that can capture semantic relationships and similarities between data points. These embeddings are often used by AI models to process and understand complex information more efficiently and are used for features like natural language processing, recommendations and computer vision.',
			'elasticpress-labs'
		);

		// Set up settings page sub-module
		if ( $this->is_active() ) {
			$this->settings_page = new Settings();
			$this->settings_page->setup();
		}
		parent::__construct();
	}

	/**
	 * Connects the Module with WordPress using Hooks and/or Filters.
	 *
	 * @return void
	 */
	public function setup() {
		$this->indexables['post'] = new Indexables\Post\Post( $this );
		$this->indexables['post']->setup();

		if ( $this->get_setting( 'ep_embeddings_use_epio' ) ) {
			add_filter( 'ep_status_report_reports', [ $this, 'add_status_report' ] );
		}
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return FeatureRequirementsStatus Requirements object
	 */
	public function requirements_status() {
		$status = new \ElasticPress\FeatureRequirementsStatus( 1 );

		// Vector support was added in Elasticsearch 7.0.
		if ( version_compare( Elasticsearch::factory()->get_elasticsearch_version(), '7.0', '<=' ) ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need to have Elasticsearch with version >7.0.', 'elasticpress-labs' );
		}

		return $status;
	}

	/**
	 * Set the `settings_schema` attribute
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
			[
				'key'   => 'ep_embeddings_api_key',
				'label' => __( 'OpenAI API Key', 'elasticpress-labs' ),
				'help'  => sprintf(
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
				'type'  => 'text',
			],
			[
				'help'    => __( 'OpenAI Embeddings API Url', 'elasticpress-labs' ),
				'key'     => 'ep_embeddings_api_url',
				'label'   => __( 'OpenAI Embeddings API Url', 'elasticpress-labs' ),
				'type'    => 'text',
				'default' => $this->default_settings['ep_embeddings_api_url'],
			],
			[
				'help'    => __( 'OpenAI Embedding model', 'elasticpress-labs' ),
				'key'     => 'ep_embeddings_embedding_model',
				'label'   => __( 'The name of the embedding model to use', 'elasticpress-labs' ),
				'type'    => 'text',
				'default' => $this->default_settings['ep_embeddings_embedding_model'],
			],
			[
				'help'    => __( 'Embedding model dimensions', 'elasticpress-labs' ),
				'key'     => 'ep_embeddings_dimensions',
				'label'   => __( 'The number of dimensions supported by your embedding model', 'elasticpress-labs' ),
				'type'    => 'number',
				'default' => $this->default_settings['ep_embeddings_dimensions'],
			],
			[
				'key'   => 'ep_embeddings_external_embedding',
				'help'  => __( 'Enable this if an external process is providing the vector_embeddings meta field provided above with content. This will disable ElasticPress\'s control over embedding generation', 'elasticpress-labs' ),
				'label' => __( 'External embedding processing', 'elasticpress-labs' ),
				'type'  => 'checkbox',
			],
		];

		if ( Utils\is_epio() ) {
			$this->settings_schema[] = [
				'key'   => 'ep_embeddings_use_epio',
				'help'  => __( 'Enable this if you want to use ElasticPress.io to vectorize your content.', 'elasticpress-labs' ),
				'label' => __( 'Use EP.io', 'elasticpress-labs' ),
				'type'  => 'checkbox',
			];
		}
	}

	/**
	 * Add a new status report
	 *
	 * @param array $reports Status reports.
	 * @return array
	 */
	public function add_status_report( $reports ) {
		$reports[] = new StatusReport();

		return $reports;
	}

	/**
	 * Get an embedding from a given strings or array of strings.
	 *
	 * @param int          $object_id   The Object ID.
	 * @param string       $object_type The Object type.
	 * @param string|array $text        String or array of strings to get the embedding for.
	 * @return array|null|WP_Error
	 */
	public function get_embedding( int $object_id, string $object_type, $text ) {
		// Generate the embedding.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::line( "Generating embedding for {$object_type} ID: {$object_id}" );
		}

		return $this->generate_embedding( $text );
	}

	/**
	 * Generate an embedding for a particular piece of text.
	 *
	 * @param string|array $text Text (or array of strings) to generate the embedding for.
	 * @return array|boolean|WP_Error
	 */
	public function generate_embedding( $text = '' ) {
		/**
		 * Filter the URL for the post request.
		 *
		 * @hook ep_embeddings_api_url
		 * @since 2.4.0
		 *
		 * @param {string} $url The URL for the request.
		 *
		 * @return {string} The URL for the request.
		 */
		$url = apply_filters( 'ep_embeddings_api_url', $this->get_setting( 'ep_embeddings_api_url' ) );

		/**
		 * Filter the request body before sending to OpenAI.
		 *
		 * @hook ep_embeddings_request_body
		 * @since 2.4.0
		 *
		 * @param {array} $body Request body that will be sent to OpenAI.
		 * @param {string} $text Text we are getting embeddings for.
		 *
		 * @return {array} Request body.
		 */
		$body = apply_filters(
			'ep_embeddings_request_body',
			[
				'model'      => $this->get_setting( 'ep_embeddings_embedding_model' ),
				'input'      => (array) $text,
				'dimensions' => $this->get_dimensions(),
			],
			$text
		);

		/**
		 * Filter the options for the post request.
		 *
		 * @hook ep_embeddings_options
		 * @since 2.4.0
		 *
		 * @param {array} $options The options for the request.
		 * @param {string} $url The URL for the request.
		 *
		 * @return {array} The options for the request.
		 */
		$options = apply_filters(
			'ep_embeddings_options',
			[
				'body'    => wp_json_encode( $body ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			],
			$url
		);

		$this->add_headers( $options );

		// Make our API request.
		$response = $this->get_result(
			wp_remote_post(
				$url,
				$options
			)
		);

		/**
		 * Filter the response of the request.
		 *
		 * @hook ep_embeddings_request_response
		 * @since 2.4.0
		 *
		 * @param {array|WP_Error} $response The request response.
		 * @param {array|string}   $text     The text that was sent to be processed.
		 * @return {array|WP_Error} The request response.
		 */
		$response = apply_filters( 'ep_embeddings_request_response', $response, $text );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data'] ) ) {
			return new WP_Error( 'no_data', esc_html__( 'No data returned from OpenAI.', 'elasticpress-labs' ) );
		}

		$return = [];

		// Parse out the embeddings response.
		foreach ( $response['data'] as $data ) {
			if ( ! isset( $data['embedding'] ) || ! is_array( $data['embedding'] ) ) {
				continue;
			}

			if ( is_string( $text ) ) {
				$return = $data['embedding'];
				break;
			}

			$return[] = $data['embedding'];
		}

		return $return;
	}

	/**
	 * Get results from the response.
	 *
	 * @param object $response The API response.
	 * @return array|WP_Error
	 */
	public function get_result( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers      = wp_remote_retrieve_headers( $response );
		$content_type = false;

		if ( ! empty( $headers ) ) {
			$content_type = isset( $headers['content-type'] ) ? $headers['content-type'] : false;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( false === $content_type || false !== strpos( $content_type, 'application/json' ) ) {
			$json = json_decode( $body, true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				if ( empty( $json['error'] ) ) {
					return $json;
				} else {
					$message = $json['error']['message'] ?? esc_html__( 'An error occured', 'elasticpresslabs' );
					return new WP_Error( $code, $message );
				}
			} else {
				return new WP_Error( 'Invalid JSON: ' . json_last_error_msg(), $body );
			}
		} elseif ( $content_type && false !== strpos( $content_type, 'audio/mpeg' ) ) {
			return $response;
		} else {
			return new WP_Error( 'Invalid content type', $response );
		}
	}

	/**
	 * Normalizes content into plain text.
	 *
	 * @param string $content Content to normalize.
	 * @return string
	 */
	public function normalize_content( string $content = '' ): string {
		$content = apply_filters( 'the_content', $content );

		// Strip shortcodes but keep internal caption text.
		// Revert it if shortcodes are not balanced and preg_replace errors out.
		$pre_content = $content;
		$content     = preg_replace( '#\[.+\](.+)\[/.+\]#', '$1', $content );
		if ( null === $content ) {
			$content = $pre_content;
		}

		// Strip HTML entities.
		$content = preg_replace( '/&#?[a-z0-9]{2,8};/i', '', $content );

		// Replace HTML linebreaks with newlines.
		$content = preg_replace( '#<br\s?/?>#', "\n\n", $content );

		// Strip all HTML tags.
		$content = wp_strip_all_tags( $content );

		return $content;
	}

	/**
	 * Chunk content into smaller pieces with an overlap.
	 *
	 * @param string $content      Content to chunk.
	 * @param int    $chunk_size   Size of each chunk, in words.
	 * @param int    $overlap_size Overlap size for each chunk, in words.
	 * @return array
	 */
	public function chunk_content( string $content = '', int $chunk_size = 150, $overlap_size = 25 ): array {
		// Normalize our content.
		$content = $this->normalize_content( $content );
		if ( ! $content ) {
			return [];
		}

		// Remove multiple whitespaces.
		$content = preg_replace( '/[ \t\r\f]+/', ' ', $content );

		// Remove multiple new lines.
		$content = preg_replace( '/[\n\v]{2,}/', "\n\n", $content );

		// Split text by single whitespace.
		$words = explode( ' ', $content );

		$chunks     = [];
		$text_count = count( $words );

		// Iterate through & chunk data with an overlap.
		for ( $i = 0; $i < $text_count; $i += $chunk_size ) {
			// Join a set of words into a string.
			$chunk = implode(
				' ',
				array_slice(
					$words,
					max( $i - $overlap_size, 0 ),
					$i + $chunk_size
				)
			);

			/**
			 * Filter a chunk of text.
			 *
			 * @hook ep_embeddings_chunk
			 * @since 2.4.0
			 *
			 * @param {string} $chunk The chunk being processed.
			 * @return {string} The modified chunk.
			 */
			$chunk = apply_filters( 'ep_embeddings_chunk', $chunk );

			array_push( $chunks, $chunk );
		}

		return $chunks;
	}

	/**
	 * Get the number of dimensions for the embeddings.
	 *
	 * @return int
	 */
	public function get_dimensions(): int {
		$calc_dimensions = max( 1, min( 4096, $this->get_setting( 'ep_embeddings_dimensions' ) ) );

		/**
		 * Filter the dimensions we want for each embedding.
		 *
		 * Useful if you want to increase or decrease the length
		 * of each embedding.
		 *
		 * @hook ep_embeddings_dimensions
		 * @since 2.4.0
		 *
		 * @param {int} $dimensions The default dimensions.
		 * @return {int} The dimensions.
		 */
		return (int) apply_filters( 'ep_embeddings_dimensions', $calc_dimensions );
	}

	/**
	 * Return the array of indexables.
	 *
	 * @return array
	 */
	public function get_indexables() {
		return $this->indexables;
	}

	/**
	 * Add the headers.
	 *
	 * @param array $options The header options, passed by reference.
	 */
	public function add_headers( array &$options = [] ) {
		if ( empty( $options['headers'] ) ) {
			$options['headers'] = [];
		}

		if ( ! isset( $options['headers']['Authorization'] ) ) {
			$options['headers']['Authorization'] = $this->get_auth_header();
		}

		if ( ! isset( $options['headers']['Content-Type'] ) ) {
			$options['headers']['Content-Type'] = 'application/json';
		}
	}

	/**
	 * Get the auth header.
	 *
	 * @return string
	 */
	public function get_auth_header() {
		return 'Bearer ' . $this->get_setting( 'ep_embeddings_api_key' );
	}
}
