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
		\ElasticPress\SearchAlgorithms::factory()->register( new SearchAlgorithm\Knn() );
		\ElasticPress\SearchAlgorithms::factory()->register( new SearchAlgorithm\KnnCosine() );
		\ElasticPress\SearchAlgorithms::factory()->register( new SearchAlgorithm\Hybrid() );
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
}
