<?php
/**
 * kNN Search Feature
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\KnnSearch;

use ElasticPress\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * kNN Search Feature
 */
class KnnSearch extends Feature {
	/**
	 * Default settings
	 *
	 * @var array $default_settings.
	 */
	public $default_settings = [
		'ep_knn_search_min_score' => 0.7,
	];

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'knn_search';

		$this->title = esc_html__( 'kNN Search', 'elasticpress-labs' );

		$this->summary = __( 'kNN Search.', 'elasticpress-labs' );

		$this->requires_feature = 'vector_embeddings';

		parent::__construct();
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
				'key'     => 'ep_knn_search_min_score',
				'label'   => __( 'Minimum score', 'elasticpress-labs' ),
				'help'    => __( 'The minimum score to be used by kNN searches. Input a number between 0 and 1.', 'elasticpress-labs' ),
				'type'    => 'number',
				'default' => $this->default_settings['ep_knn_search_min_score'],
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
		 * @param {float} $min_score The minimum score for KNN search. Default is retrieved from the 'ep_knn_search_min_score' setting.
		 * @return {float} The minimum score for KNN search.
		 */
		return (float) apply_filters( 'ep_knn_search_min_score', $this->get_setting( 'ep_knn_search_min_score' ) );
	}
}
