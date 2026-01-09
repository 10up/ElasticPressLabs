<?php
/**
 * Semantic Search Feature
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\SemanticSearch;

use ElasticPress\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Semantic Search Feature
 */
class SemanticSearch extends Feature {
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
		'search_min_score' => 0.7,
	];

	/**
	 * The algorithms supported by the feature.
	 *
	 * @var array $algorithms.
	 */
	protected $algorithms = [];

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'semantic_search';

		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.2.0', '<' ) ) {
			$this->set_i18n_strings();
		}

		$this->requires_feature = 'vector_embeddings';

		parent::__construct();
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return FeatureRequirementsStatus Requirements object
	 */
	public function requirements_status() {
		$status = new \ElasticPress\FeatureRequirementsStatus( 1 );

		$es_version = \ElasticPress\Elasticsearch::factory()->get_elasticsearch_version();

		// Vector support was added in Elasticsearch 7.0.
		if ( $es_version && version_compare( $es_version, '7.0', '<' ) ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need to have Elasticsearch with version >7.0.', 'elasticpress-labs' );
		}

		if ( version_compare( $es_version, '7.0', '>' ) && version_compare( $es_version, '8.0', '<' ) ) {
			$status->code    = 1;
			$status->message = esc_html__( 'With Elasticsearch version 7, only the "kNN Cosine" algorithm is available.', 'elasticpress-labs' );
		}

		return $status;
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 * @since 2.5.0
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'Semantic Search', 'elasticpress-labs' );

		$this->summary = __( 'Enable kNN Search. To use a kNN search algorithm, enable the Search Algorithm Version feature and select one of the kNN variations.', 'elasticpress-labs' );
	}

	/**
	 * Connects the Module with WordPress using Hooks and/or Filters.
	 *
	 * @return void
	 */
	public function setup() {
		$this->algorithms = [
			new SearchAlgorithm\KnnCosine(),
		];

		$es_version = \ElasticPress\Elasticsearch::factory()->get_elasticsearch_version();

		if ( version_compare( $es_version, '8.0', '>=' ) ) {
			$this->algorithms[] = new SearchAlgorithm\Hybrid();
			$this->algorithms[] = new SearchAlgorithm\Knn();
		}

		foreach ( $this->algorithms as $algorithm ) {
			\ElasticPress\SearchAlgorithms::factory()->register( $algorithm );
		}

		$vector_embeddings = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );
		$is_epio           = 'epio' === $vector_embeddings->get_setting( 'ep_embeddings_generator' );
		$search_algorithm  = \ElasticPress\Indexables::factory()->get( 'post' )->get_search_algorithm( '', [], [] );

		if ( $is_epio && in_array( $search_algorithm, $this->algorithms, true ) ) {
			add_filter( 'ep_query_request_args', [ $this, 'add_vector_embeddings_header' ], 10, 6 );
			add_action( 'wp_enqueue_scripts', [ $this, 'add_autosuggest_http_header' ] );
		}
	}

	/**
	 * Set the `settings_schema` attribute
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
			[
				'key'     => 'search_min_score',
				'label'   => __( 'Minimum score', 'elasticpress-labs' ),
				'help'    => __( 'The minimum score to be used by kNN searches. Input a number between 0 and 1.', 'elasticpress-labs' ),
				'type'    => 'number',
				'default' => $this->default_settings['search_min_score'],
			],
		];
	}

	/**
	 * Get the min_score setting.
	 *
	 * @return float
	 */
	public function get_min_score() {
		/**
		 * Filters the minimum score for KNN (k-Nearest Neighbors) search.
		 *
		 * @since 2.5.0
		 * @param {float} $min_score The minimum score for KNN search. Default is retrieved from the 'search_min_score' setting.
		 * @return {float} The minimum score for KNN search.
		 */
		return (float) apply_filters( 'ep_semantic_search_min_score', $this->get_setting( 'search_min_score' ) );
	}

	/**
	 * Add the vector embeddings header to the request arguments.
	 *
	 * @param array  $request_args The request arguments.
	 * @param string $path The path of the request.
	 * @param string $index The index of the request.
	 * @param string $type The type of the request.
	 * @param array  $query The query of the request.
	 * @param array  $query_args The query arguments of the request.
	 * @return array The request arguments.
	 */
	public function add_vector_embeddings_header( $request_args, $path, $index, $type, $query, $query_args ) {
		$request_args['headers']['EP-Vector-Embeddings-Search-Term'] = $query_args['s'] ? rawurlencode( $query_args['s'] ) : '';

		return $request_args;
	}

	/**
	 * Add the vector embeddings header to the request arguments.
	 *
	 * @return void
	 */
	public function add_autosuggest_http_header() {
		wp_add_inline_script(
			'elasticpress-autosuggest',
			"const epAutosuggestFetchOptions = (fetchOptions) => {
				fetchOptions.headers['EP-Vector-Embeddings-Search-Term'] = fetchOptions.headers['EP-Search-Term'];
				return fetchOptions;
			};
			wp.hooks.addFilter('ep.Autosuggest.fetchOptions', 'myTheme/epAutosuggestFetchOptions', epAutosuggestFetchOptions);",
			'before'
		);
	}
}
