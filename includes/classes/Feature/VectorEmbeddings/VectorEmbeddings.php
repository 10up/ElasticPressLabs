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
use ElasticPressLabs\Traits\DisableAfterFailures;
use ElasticPressLabs\Upgrades;
use WP_Error;

use function ElasticPressLabs\Utils\preg_replace_callback_safe;
use function ElasticPressLabs\Utils\preg_replace_safe;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Vector Embeddings feature
 */
class VectorEmbeddings extends Feature {
	use DisableAfterFailures;

	/**
	 * Group
	 *
	 * @var string $group.
	 */
	public $group = 'ai';

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
		'ep_embeddings_generator'       => 'openai',
		'ep_embeddings_api_key'         => '',
		'ep_embeddings_api_url'         => 'https://api.openai.com/v1/embeddings',
		'ep_embeddings_embedding_model' => 'text-embedding-3-small',
		'ep_embeddings_dimensions'      => 512,
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

		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.2.0', '<' ) ) {
			$this->set_i18n_strings();
		}

		$this->requires_install_reindex = true;

		try {
			if ( $this->is_epio_available() ) {
				$this->default_settings['ep_embeddings_generator'] = 'epio';
			}
		} catch ( \Exception $e ) {
			// no-op
		}

		parent::__construct();
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 * @since 2.5.0
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'Vector Embeddings', 'elasticpress-labs' );

		$this->summary = __(
			'This feature enables storage of vector embeddings, a numerical representation of the indexed content that can capture semantic relationships and similarities between data points. These embeddings are often used by AI models to process and understand complex information more efficiently and are used for features like natural language processing, recommendations and computer vision.',
			'elasticpress-labs'
		);

		$this->field_group_map = [
			'ep_embeddings_openai' => [
				'label'           => esc_html__( 'OpenAI Connection Details', 'elasticpress-labs' ),
				'requires_fields' => [
					'conditions' => [
						'ep_embeddings_generator' => 'openai',
					],
				],
			],
		];
	}

	/**
	 * Connects the Module with WordPress using Hooks and/or Filters.
	 *
	 * @return void
	 */
	public function setup() {
		$this->indexables = $this->create_indexables();

		foreach ( $this->indexables as $indexable ) {
			$indexable->setup();
		}

		add_filter( 'ep_query_logger_allowed_log_types', [ $this, 'add_vector_embeddings_to_allowed_log_types' ] );

		if ( 'epio' === $this->get_setting( 'ep_embeddings_generator' ) ) {
			add_filter( 'ep_status_report_reports', [ $this, 'add_status_report' ] );
		}
	}

	/**
	 * Pre-handle feature activation
	 *
	 * @since 2.5.1
	 * @return void
	 */
	public function pre_handle_feature_activation() {
		$this->setup_failures_count();
	}

	/**
	 * Add the feature's fields to existing indices when the feature is activated.
	 *
	 * An index created before the feature was active has none of them.
	 *
	 * @since 2.6.0
	 */
	public function post_activation() {
		parent::post_activation();

		$this->ensure_mapping();
	}

	/**
	 * Add the fields this feature needs to the existing indices, without recreating them.
	 *
	 * Only while the feature is active: an index can stay without the fields until the
	 * feature is activated (`post_activation()` calls this again then).
	 *
	 * Missing fields (all of them, if the index was created while the feature was off) are
	 * added with `PUT _mapping`. Fields whose definition differs, like the vector dimensions,
	 * cannot be changed in place: those indices need a sync with `--setup`.
	 *
	 * @since 2.6.0
	 * @param bool $dry_run Report what would change, without changing anything.
	 * @return array `[ 'status' => 'applied'|'pending'|'skipped'|'failed', 'message' => string ]`
	 */
	public function ensure_mapping( bool $dry_run = false ): array {
		if ( ! $this->is_active() ) {
			return [
				'status'  => Upgrades::SKIPPED,
				'message' => __( 'Vector Embeddings is not active. Its fields are added when it is activated.', 'elasticpress-labs' ),
			];
		}

		if ( ! Elasticsearch::factory()->get_elasticsearch_version( true ) ) {
			return [
				'status'  => Upgrades::FAILED,
				'message' => __( 'Could not connect to Elasticsearch.', 'elasticpress-labs' ),
			];
		}

		$results = [];

		foreach ( $this->get_mapping_indexables() as $slug => $indexable ) {
			$ep_indexable = \ElasticPress\Indexables::factory()->get( $slug );

			if ( $ep_indexable ) {
				$results[] = $this->ensure_index_mapping( $ep_indexable, $indexable, $dry_run );
			}
		}

		if ( ! $results ) {
			return [
				'status'  => Upgrades::SKIPPED,
				'message' => __( 'No index to update.', 'elasticpress-labs' ),
			];
		}

		// The most important status wins.
		$statuses = wp_list_pluck( $results, 'status' );
		$status   = Upgrades::SKIPPED;

		foreach ( [ Upgrades::FAILED, Upgrades::NEEDS_ACTION, Upgrades::APPLIED, Upgrades::PENDING ] as $candidate ) {
			if ( in_array( $candidate, $statuses, true ) ) {
				$status = $candidate;
				break;
			}
		}

		return [
			'status'  => $status,
			'message' => implode( ' ', wp_list_pluck( $results, 'message' ) ),
		];
	}

	/**
	 * The indexables whose mapping this feature changes, keyed by ElasticPress indexable slug.
	 *
	 * Built on demand when the feature has not been set up (it is being activated).
	 *
	 * @since 2.6.0
	 * @return Indexable[]
	 */
	protected function get_mapping_indexables(): array {
		if ( ! empty( $this->indexables ) ) {
			return $this->indexables;
		}

		return $this->create_indexables();
	}

	/**
	 * Create the Vector Embeddings indexables, keyed by ElasticPress indexable slug.
	 *
	 * @since 2.6.0
	 * @return Indexable[]
	 */
	protected function create_indexables(): array {
		return [
			'post' => new Indexables\Post\Post( $this ),
		];
	}

	/**
	 * The fields this feature adds, as a new index would get them: from the ElasticPress
	 * mapping, after the `ep_config_mapping` filter.
	 *
	 * @since 2.6.0
	 * @param \ElasticPress\Indexable $ep_indexable ElasticPress indexable.
	 * @param Indexable               $indexable    Vector Embeddings indexable.
	 * @return array Mapping properties.
	 */
	protected function get_target_mapping_properties( \ElasticPress\Indexable $ep_indexable, Indexable $indexable ): array {
		// The fields are added here too, for when the feature is being activated and not hooked yet.
		$mapping = $indexable->add_vector_mapping_field( $ep_indexable->generate_mapping() );

		/** This filter is documented in elasticpress/includes/classes/Elasticsearch.php */
		$mapping = apply_filters( 'ep_config_mapping', $mapping, $ep_indexable->get_index_name() );

		return (array) ( $mapping['mappings']['properties'] ?? [] );
	}

	/**
	 * Add the missing fields to one index.
	 *
	 * @since 2.6.0
	 * @param \ElasticPress\Indexable $ep_indexable ElasticPress indexable.
	 * @param Indexable               $indexable    Vector Embeddings indexable.
	 * @param bool                    $dry_run      Report only.
	 * @return array `status` and `message`.
	 */
	protected function ensure_index_mapping( \ElasticPress\Indexable $ep_indexable, Indexable $indexable, bool $dry_run ): array {
		$elasticsearch = Elasticsearch::factory();
		$index         = $ep_indexable->get_index_name();

		if ( ! $elasticsearch->index_exists( $index ) ) {
			return [
				'status'  => Upgrades::SKIPPED,
				/* translators: %s: index name */
				'message' => sprintf( __( 'Index %s does not exist yet. It gets the fields when it is created.', 'elasticpress-labs' ), $index ),
			];
		}

		$mapping = $elasticsearch->get_mapping( $index );

		if ( ! isset( $mapping[ $index ]['mappings'] ) ) {
			return [
				'status'  => Upgrades::FAILED,
				/* translators: %s: index name */
				'message' => sprintf( __( 'Could not read the mapping of index %s.', 'elasticpress-labs' ), $index ),
			];
		}

		$changes = $indexable->get_mapping_changes(
			(array) ( $mapping[ $index ]['mappings']['properties'] ?? [] ),
			$this->get_target_mapping_properties( $ep_indexable, $indexable )
		);

		// Nothing to retry: the index stays like this until it is recreated.
		if ( $changes['conflicts'] ) {
			return [
				'status'  => Upgrades::NEEDS_ACTION,
				'message' => sprintf(
					/* translators: 1: index name, 2: list of mapping settings */
					__( 'Index %1$s: %2$s differ from what Vector Embeddings needs and cannot be changed in place. Run a full sync with --setup to recreate the index.', 'elasticpress-labs' ),
					$index,
					implode( ', ', $changes['conflicts'] )
				),
			];
		}

		if ( ! $changes['add'] ) {
			return [
				'status'  => Upgrades::SKIPPED,
				/* translators: %s: index name */
				'message' => sprintf( __( 'Index %s is up to date.', 'elasticpress-labs' ), $index ),
			];
		}

		$added = implode( ', ', $changes['added'] );

		if ( $dry_run ) {
			return [
				'status'  => Upgrades::PENDING,
				/* translators: 1: index name, 2: list of fields */
				'message' => sprintf( __( 'Index %1$s: would add %2$s.', 'elasticpress-labs' ), $index, $added ),
			];
		}

		$response = $elasticsearch->remote_request(
			$index . '/_mapping',
			[
				'method'  => 'PUT',
				'body'    => wp_json_encode( [ 'properties' => $changes['add'] ] ),
				'timeout' => 30,
			],
			[],
			'put_mapping'
		);

		/** This action is documented in elasticpress/includes/classes/Elasticsearch.php */
		do_action( 'ep_after_put_mapping', $index, $response );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$body   = is_wp_error( $response ) ? [] : json_decode( wp_remote_retrieve_body( $response ), true );
			$reason = is_wp_error( $response ) ? $response->get_error_message() : ( $body['error']['reason'] ?? wp_remote_retrieve_response_message( $response ) );

			return [
				'status'  => Upgrades::FAILED,
				/* translators: 1: index name, 2: error message */
				'message' => sprintf( __( 'Index %1$s: could not update the mapping: %2$s', 'elasticpress-labs' ), $index, $reason ),
			];
		}

		return [
			'status'  => Upgrades::APPLIED,
			/* translators: 1: index name, 2: list of fields */
			'message' => sprintf( __( 'Index %1$s: added %2$s.', 'elasticpress-labs' ), $index, $added ),
		];
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return FeatureRequirementsStatus Requirements object
	 * @throws \Exception If the ElasticPress plugin is not updated. Handled by the method itself.
	 */
	public function requirements_status() {
		$status = new \ElasticPress\FeatureRequirementsStatus( 1 );

		$status->message = [];

		// Vector support was added in Elasticsearch 7.0.
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();
		if ( $es_version && version_compare( $es_version, '7.0', '<' ) ) {
			$status->code      = 2;
			$status->message[] = esc_html__( 'You need to have Elasticsearch with version >7.0.', 'elasticpress-labs' );
			return $status;
		}

		$recommended_bulk_setting = 30;
		if ( Utils\get_option( 'ep_bulk_setting', 350 ) > $recommended_bulk_setting ) {
			if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
				$url = admin_url( 'network/admin.php?page=elasticpress-settings' );
			} else {
				$url = admin_url( 'admin.php?page=elasticpress-settings' );
			}

			$status->message[] = wp_kses_post(
				sprintf(
					/* translators: 1: Settings URL, 2: Recommended bulk setting, 3: WP-CLI URL */
					__( 'While using OpenAI, as each content piece is processed individually, consider using a low number of items per sync cycle in the <a href="%1$s">Settings page</a>, like %2$d. You can also try running it via <a href="%3$s">WP-CLI</a>.', 'elasticpress-labs' ),
					esc_url( $url ),
					absint( $recommended_bulk_setting ),
					esc_url( 'https://10up.github.io/ElasticPress/tutorial-wp-cli.html' )
				)
			);
		}

		if ( $this->should_disable_after_failures() ) {
			$status = $this->update_requirements_status( $status );
			return $status;
		}

		if ( ! $this->is_epio_beta_available() ) {
			return $status;
		}

		if ( Utils\is_epio() ) {
			try {
				if ( ! $this->is_epio_available() ) {
					$status->message[] = wp_kses_post(
						sprintf(
							/* translators: %s: Upgrade URL */
							__( 'Your current ElasticPress.io plan does not include the vector embeddings service. <a href="%s" target="_blank">Upgrade now!</a>', 'elasticpress-labs' ),
							'https://www.elasticpress.io/resources/articles/how-to-upgrade-your-elasticpress-io-plan/'
						)
					);
				}
			} catch ( \Exception $e ) {
				$error_message = $e->getMessage();

				$status->message[] = 'old_ep_version' === $error_message
					? esc_html__( 'You need to update the ElasticPress plugin to use this feature.', 'elasticpress-labs' )
					: $error_message;
			}
		}

		return $status;
	}

	/**
	 * Set the `settings_schema` attribute
	 */
	public function set_settings_schema() {
		$generator = [
			'key'     => 'ep_embeddings_generator',
			'label'   => __( 'Generator', 'elasticpress-labs' ),
			'options' => [
				[
					'label' => __( 'External embedding processing', 'elasticpress-labs' ),
					'value' => 'external',
				],
				[
					'label' => __( 'OpenAI', 'elasticpress-labs' ),
					'value' => 'openai',
				],
			],
			'help'    => __( '<b>External embedding processing:</b> An external process is providing the vector_embeddings meta field.<br><b>OpenAI:</b> Use the fields below to generate the embeddings.', 'elasticpress-labs' ),
			'default' => 'openai',
			'type'    => 'radio',
		];

		try {
			if ( $this->is_epio_available() ) {
				array_unshift(
					$generator['options'],
					[
						'label' => __( 'ElasticPress.io', 'elasticpress-labs' ),
						'value' => 'epio',
					]
				);

				$generator['help']    = __( '<b>ElasticPress.io:</b> Your vectors will be generated by the ElasticPress.io service.<br>', 'elasticpress-labs' ) . $generator['help'];
				$generator['default'] = 'epio';
			}
		} catch ( \Exception $e ) {
			// no-op
		}

		$this->settings_schema = [
			...$this->settings_schema,
			$generator,
			[
				'key'              => 'ep_embeddings_api_key',
				'label'            => __( 'OpenAI API Key', 'elasticpress-labs' ),
				'help'             => sprintf(
					wp_kses(
						/* translators: 1: OpenAI sign up URL, 2: OpenAI API keys URL */
						__( 'Don\'t have an OpenAI account yet? <a title="Sign up for an OpenAI account" href="%1$s">Sign up for one</a> in order to get your API key.<br>If you already have an account, <a title="Get your API key from the OpenAI website" href="%2$s">generate an API key</a>.', 'elasticpress-labs' ),
						[
							'a'  => [
								'href'  => [],
								'title' => [],
							],
							'br' => [],
						]
					),
					esc_url( 'https://platform.openai.com/signup' ),
					esc_url( 'https://platform.openai.com/api-keys' )
				),
				'type'             => 'text',
				'field_group_slug' => 'ep_embeddings_openai',
			],
			[
				'help'             => __( 'OpenAI Embeddings API Url', 'elasticpress-labs' ),
				'key'              => 'ep_embeddings_api_url',
				'label'            => __( 'OpenAI Embeddings API Url', 'elasticpress-labs' ),
				'type'             => 'text',
				'default'          => $this->default_settings['ep_embeddings_api_url'],
				'field_group_slug' => 'ep_embeddings_openai',
			],
			[
				'help'             => __( 'OpenAI Embedding model', 'elasticpress-labs' ),
				'key'              => 'ep_embeddings_embedding_model',
				'label'            => __( 'The name of the embedding model to use', 'elasticpress-labs' ),
				'type'             => 'text',
				'default'          => $this->default_settings['ep_embeddings_embedding_model'],
				'field_group_slug' => 'ep_embeddings_openai',
			],
			[
				'help'             => __( 'Embedding model dimensions', 'elasticpress-labs' ),
				'key'              => 'ep_embeddings_dimensions',
				'label'            => __( 'The number of dimensions supported by your embedding model', 'elasticpress-labs' ),
				'type'             => 'number',
				'default'          => $this->default_settings['ep_embeddings_dimensions'],
				'field_group_slug' => 'ep_embeddings_openai',
			],
		];
	}

	/**
	 * Add vector embeddings to the allowed log types.
	 *
	 * @param array $allowed_log_types The allowed log types.
	 * @return array The allowed log types.
	 */
	public function add_vector_embeddings_to_allowed_log_types( $allowed_log_types ) {
		$allowed_log_types['vector_embeddings'] = [ $this, 'is_query_error' ];

		return $allowed_log_types;
	}

	/**
	 * Check if the request is an error.
	 *
	 * @param array $query The query.
	 * @return boolean Whether the request is an error.
	 */
	public function is_query_error( $query ) {
		if ( is_wp_error( $query['request'] ) ) {
			return true;
		}

		$response_code = wp_remote_retrieve_response_code( $query['request'] );

		return ( $response_code < 200 || $response_code > 299 );
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
			\WP_CLI::debug(
				sprintf(
					/* translators: 1: object type, 2: object id. */
					__( 'Generating embedding for %1$s ID: %2$s', 'elasticpress-labs' ),
					$object_type,
					$object_id
				)
			);
		}

		return $this->generate_embedding( $text );
	}

	/**
	 * Generate an embedding for a particular piece of text.
	 *
	 * @param string|array $text Text (or array of strings) to generate the embedding for.
	 * @return array|boolean|WP_Error
	 * @throws \Exception If trying to use ElasticPress.io as a generator.
	 */
	public function generate_embedding( $text = '' ) {
		if ( 'epio' === $this->get_setting( 'ep_embeddings_generator' ) ) {
			throw new \Exception( 'ElasticPress.io does not support vector embeddings requests.' );
		}

		$request  = new VectorEmbeddingsRequest( $this );
		$response = $request->send_request( $text );

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
			$this->update_failures_count();
			return $response;
		}

		if ( empty( $response['data'] ) ) {
			$this->update_failures_count();
			return new WP_Error( 'no_data', esc_html__( 'No data returned from the embedding service.', 'elasticpress-labs' ) );
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
	 * Normalizes content into plain text.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Added the `$apply_the_content` parameter.
	 * @param string $content           Content to normalize.
	 * @param bool   $apply_the_content Optional. Whether to run the `the_content` filters (blocks,
	 *                                  shortcodes, embeds). Default true. Without them, shortcodes
	 *                                  are removed without running.
	 * @return string
	 */
	public function normalize_content( string $content = '', bool $apply_the_content = true ): string {
		$content = $apply_the_content ? (string) apply_filters( 'the_content', $content ) : strip_shortcodes( $content );

		// Replace bytes that are not valid UTF-8, so the patterns below (with the `u` modifier) work.
		if ( function_exists( 'mb_scrub' ) ) {
			$content = mb_scrub( $content, 'UTF-8' );
		}

		// Remove code and markup that is never read as text (scripts, structured data, styles),
		// with its contents: the later steps replace tags with separators, after which
		// wp_strip_all_tags() could no longer find these elements and would keep their contents.
		// Every step keeps the text as it was when its pattern fails (backtrack limit, invalid
		// UTF-8), so a post is never embedded empty.
		$content = preg_replace_safe( '#<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', $content );

		// Strip shortcodes but keep internal caption text.
		$content = preg_replace_safe( '#\[.+\](.+)\[/.+\]#', '$1', $content );

		// Replace HTML linebreaks with newlines.
		$content = preg_replace_safe( '#<br\s?/?>#i', "\n\n", $content );

		$separator = $this->get_separator_pattern();

		// Tables become Markdown, so each figure keeps its row and column labels.
		$content = $this->tables_to_markdown( $content );

		// Headings become Markdown heading lines, so chunks can follow the sections.
		$content = $this->headings_to_markdown( $content, $separator );

		// Keep text from separate elements apart, so table cells, list items, and chart
		// labels do not run together ("2026" + "12" + "46" must not become "20261246").
		$content = $this->separate_elements( $content, $separator );

		// Strip all HTML tags.
		$content = wp_strip_all_tags( $content );

		// Decode entities after the tags are gone, so "&amp;" reads "&" instead of vanishing.
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Non-breaking and other Unicode spaces become plain spaces.
		$content = preg_replace_safe( '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $content );

		return $content;
	}

	/**
	 * Replace every HTML table with a Markdown table.
	 *
	 * The innermost tables are converted first, so a nested table ends up on one
	 * line inside its outer table's cell.
	 *
	 * @since 2.6.0
	 * @param string $content HTML.
	 * @return string
	 */
	protected function tables_to_markdown( string $content ): string {
		/**
		 * Filters whether tables are turned into Markdown tables before embedding.
		 *
		 * @hook ep_embeddings_tables_as_markdown
		 * @since 2.6.0
		 * @param {bool} $enabled Whether to convert tables. Default true.
		 * @return {bool} Whether to convert tables.
		 */
		if ( ! class_exists( '\DOMDocument' ) || ! apply_filters( 'ep_embeddings_tables_as_markdown', true ) || false === stripos( $content, '<table' ) ) {
			return $content;
		}

		// Innermost table: no other "<table" between its start and its end.
		$pattern = '#<table\b[^>]*>(?:(?!<table\b).)*?</table>#is';
		$guard   = 0;

		while ( $guard++ < 50 && preg_match( $pattern, $content ) ) {
			$converted = preg_replace_callback_safe(
				$pattern,
				function ( $found ) {
					return "\n\n" . $this->table_to_markdown( $found[0] ) . "\n\n";
				},
				$content
			);

			if ( $converted === $content ) {
				break;
			}

			$content = $converted;
		}

		return $content;
	}

	/**
	 * Convert one HTML table (without nested tables) to a Markdown table.
	 *
	 * The header is the `<thead>` row, or the first row when every cell is a `<th>`,
	 * or else the first row. Cell text is escaped again as HTML, so the later steps
	 * (stripping tags, decoding entities) turn it back into the same text.
	 *
	 * @since 2.6.0
	 * @param string $html Table HTML.
	 * @return string Markdown, or the plain text of the table if it has no rows.
	 */
	protected function table_to_markdown( string $html ): string {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		// DOM properties (parentNode, childNodes, nodeName, textContent) are camelCase.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$rows       = [];
		$header_row = null;

		foreach ( $document->getElementsByTagName( 'tr' ) as $tr ) {
			$cells   = [];
			$all_th  = true;
			$in_head = $tr->parentNode && 'thead' === strtolower( $tr->parentNode->nodeName );

			foreach ( $tr->childNodes as $cell ) {
				$name = strtolower( $cell->nodeName );

				if ( 'td' !== $name && 'th' !== $name ) {
					continue;
				}

				$all_th  = $all_th && 'th' === $name;
				$cells[] = $this->markdown_cell( $cell->textContent );
			}

			if ( ! $cells ) {
				continue;
			}

			if ( null === $header_row && ( $in_head || $all_th ) ) {
				$header_row = count( $rows );
			}

			$rows[] = $cells;
		}

		if ( ! $rows ) {
			return $html;
		}

		$header = $rows[ $header_row ?? 0 ];
		unset( $rows[ $header_row ?? 0 ] );

		$columns = max( array_map( 'count', array_merge( [ $header ], $rows ) ) );
		$line    = static function ( array $cells ) use ( $columns ) {
			return '| ' . implode( ' | ', array_pad( $cells, $columns, '' ) ) . ' |';
		};

		$lines = [];
		$title = $document->getElementsByTagName( 'caption' )->item( 0 );

		if ( $title && '' !== $this->markdown_cell( $title->textContent ) ) {
			$lines[] = $this->markdown_cell( $title->textContent );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$lines[] = $line( $header );
		$lines[] = $line( array_fill( 0, $columns, '---' ) );

		foreach ( $rows as $cells ) {
			$lines[] = $line( $cells );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Cell text for a Markdown table: one line, pipes escaped, HTML-escaped.
	 *
	 * @since 2.6.0
	 * @param string $text Cell text (decoded).
	 * @return string
	 */
	protected function markdown_cell( string $text ): string {
		$text = trim( preg_replace_safe( '/\s+/u', ' ', $text ) );
		$text = str_replace( '|', '\\|', $text );

		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Replace every HTML heading with a Markdown heading line (`## Methodology`).
	 *
	 * Content headings are level 2 or deeper, whatever their tag: level 1 is kept for the
	 * sections Labs writes around the content (`# Title`, `# Summary`, `# Content`).
	 *
	 * @since 2.6.0
	 * @param string $content   HTML.
	 * @param string $separator Optional. Pattern from get_separator_pattern().
	 * @return string
	 */
	protected function headings_to_markdown( string $content, string $separator = '' ): string {
		$separator = '' !== $separator ? $separator : $this->get_separator_pattern();

		return preg_replace_callback_safe(
			'#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is',
			function ( $found ) use ( $separator ) {
				$text = trim( preg_replace_safe( '/\s+/u', ' ', wp_strip_all_tags( $this->separate_elements( $found[2], $separator ) ) ) );

				if ( '' === $text ) {
					return "\n";
				}

				return "\n\n" . str_repeat( '#', max( 2, (int) $found[1] ) ) . ' ' . $text . "\n\n";
			},
			$content
		);
	}

	/**
	 * Put a separator at element boundaries before tags are stripped.
	 *
	 * Block-level elements get a new line; every other element gets a space, except the
	 * inline formatting tags listed here, which sit inside words and sentences.
	 *
	 * @since 2.6.0
	 * @param string $content   HTML.
	 * @param string $separator Optional. Pattern from get_separator_pattern().
	 * @return string
	 */
	protected function separate_elements( string $content, string $separator = '' ): string {
		$block = 'address|article|aside|blockquote|caption|dd|details|dialog|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|hr|li|main|nav|ol|p|pre|section|summary|table|tbody|tfoot|thead|tr|ul';

		$content = preg_replace_safe( '#</?(?:' . $block . ')\b[^>]*>#i', "\n", $content );

		return preg_replace_safe( '' !== $separator ? $separator : $this->get_separator_pattern(), ' ', $content );
	}

	/**
	 * Pattern of the tags that get a space in separate_elements(): every tag but the
	 * inline formatting ones.
	 *
	 * @since 2.6.0
	 * @return string
	 */
	protected function get_separator_pattern(): string {
		/**
		 * Filters the inline tags that join text without a separator when content is
		 * prepared for embeddings. Every other tag gets a space.
		 *
		 * @hook ep_embeddings_inline_tags
		 * @since 2.6.0
		 * @param {array} $tags Tag names.
		 * @return {array} Tag names.
		 */
		$inline = (array) apply_filters(
			'ep_embeddings_inline_tags',
			[ 'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'strong', 'time', 'u', 'var', 'wbr' ]
		);
		$inline = implode( '|', array_map( 'preg_quote', array_filter( array_map( 'strval', $inline ) ) ) );

		return '' !== $inline
			? '#<(?!/?(?:' . $inline . ')\b)/?[a-z][a-z0-9-]*\b[^>]*>#i'
			: '#</?[a-z][a-z0-9-]*\b[^>]*>#i';
	}

	/**
	 * How content is split into chunks.
	 *
	 * - `paragraphs` (default): at paragraph and section boundaries, see Chunker.
	 * - `words`: fixed windows of words with an overlap (chunk_content()), as before 2.6.0.
	 *
	 * ElasticPress.io chunks content on its side, so it always gets the `words` path
	 * (which sends the whole content).
	 *
	 * @since 2.6.0
	 * @return string
	 */
	public function get_chunking_strategy(): string {
		if ( 'epio' === $this->get_setting( 'ep_embeddings_generator' ) ) {
			return 'words';
		}

		/**
		 * Filter how content is split into chunks.
		 *
		 * @hook ep_embeddings_chunking_strategy
		 * @since 2.6.0
		 * @param {string}           $strategy `paragraphs` (default) or `words`.
		 * @param {VectorEmbeddings} $feature  Feature instance.
		 * @return {string} New strategy.
		 */
		$strategy = apply_filters( 'ep_embeddings_chunking_strategy', 'paragraphs', $this );

		return 'words' === $strategy ? 'words' : 'paragraphs';
	}

	/**
	 * Chunk content into smaller pieces with an overlap.
	 *
	 * @since 2.5.0
	 * @since 2.6.0 Added the `$apply_the_content` parameter.
	 * @param string $content           Content to chunk.
	 * @param int    $chunk_size        Size of each chunk, in words.
	 * @param int    $overlap_size      Overlap size for each chunk, in words.
	 * @param bool   $apply_the_content Optional. Whether to run the `the_content` filters. Default true.
	 * @return array
	 */
	public function chunk_content( string $content = '', int $chunk_size = 150, $overlap_size = 25, bool $apply_the_content = true ): array {
		// Normalize our content.
		$content = $this->normalize_content( $content, $apply_the_content );
		if ( ! $content ) {
			return [];
		}

		// Remove multiple whitespaces.
		$content = preg_replace_safe( '/[ \t\r\f]+/', ' ', $content );

		// Remove multiple new lines.
		$content = preg_replace_safe( '/[\n\v]{2,}/', "\n\n", $content );

		// For ElasticPress.io, we chunk the content on the service side.
		if ( 'epio' === $this->get_setting( 'ep_embeddings_generator' ) ) {
			/**
			 * Filter a chunk of text.
			 *
			 * With the `paragraphs` chunking strategy, the chunk already starts with its
			 * context line, and a second argument describes it.
			 *
			 * @hook ep_embeddings_chunk
			 * @since 2.4.0, 2.6.0 added $chunk_info (`paragraphs` strategy only)
			 * @param {string} $chunk      The chunk being processed.
			 * @param {array}  $chunk_info `type` (content, taxonomy, meta) and `headings` (heading path).
			 * @return {string} The modified chunk.
			 */
			return (array) apply_filters( 'ep_embeddings_chunk', $content );
		}

		// Split text by single whitespace.
		$words = explode( ' ', $content );

		$chunks     = [];
		$text_count = count( $words );
		$step       = max( 1, $chunk_size - (int) $overlap_size );

		// Iterate through & chunk data with an overlap.
		for ( $i = 0; $i < $text_count; $i += $step ) {
			// Join a set of words into a string.
			$chunk = implode(
				' ',
				array_slice(
					$words,
					$i,
					$chunk_size
				)
			);

			// This filter is documented above.
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
	 * Check if the Beta version of the ElasticPress.io service is available.
	 *
	 * @return bool
	 */
	protected function is_epio_beta_available(): bool {
		/**
		 * Filter an initial check if the ElasticPress.io service is available.
		 *
		 * @hook ep_embeddings_is_epio_beta_available
		 * @since 2.4.0
		 *
		 * @param {bool} $initial_check The check.
		 * @return {bool} The check new value.
		 */
		return (bool) apply_filters( 'ep_embeddings_is_epio_beta_available', false );
	}

	/**
	 * Check if the ElasticPress.io service is available and the vector embeddings service is enabled.
	 *
	 * @return bool
	 * @throws \ElasticPress\Exception\NotFoundException If the ElasticPress.io service is not available.
	 */
	protected function is_epio_available(): bool {
		if ( ! $this->is_epio_beta_available() ) {
			return false;
		}

		if ( ! Utils\is_epio() ) {
			return false;
		}

		// Try to get the ElasticPressIo service.
		$error_code = 'old_ep_version';
		try {
			$epio = \ElasticPress\get_container()->get( '\ElasticPress\ElasticPressIo' );
			if ( ! method_exists( $epio, 'is_service_available' ) ) {
				throw new \ElasticPress\Exception\NotFoundException( $error_code );
			}
		} catch ( \ElasticPress\Exception\NotFoundException $th ) { // This exception we know how to handle.
			throw new \ElasticPress\Exception\NotFoundException( $error_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $epio->is_service_available( 'vectorEmbeddings' );
	}
}
