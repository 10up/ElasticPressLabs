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
}
