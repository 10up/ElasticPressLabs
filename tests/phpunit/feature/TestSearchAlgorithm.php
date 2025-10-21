<?php
/**
 * Test the search algorithm feature
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\SearchAlgorithm;

/**
 * SearchAlgorithm test class
 */
class TestSearchAlgorithm extends \WP_UnitTestCase {
	/**
	 * Setup each test.
	 */
	public function set_up() {
		$instance = new SearchAlgorithm();
		\ElasticPress\Features::factory()->register_feature( $instance );
	}

	/**
	 * Get the Search Algorithm feature instance
	 *
	 * @return SearchAlgorithm
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'search_algorithm' );
	}

	/**
	 * Test the constructor
	 *
	 * @group search-algorithm
	 */
	public function test_construct() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `set_i18n_strings` method
	 *
	 * @group search-algorithm
	 */
	public function test_set_i18n_strings() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `output_feature_box_summary` method
	 *
	 * @group search-algorithm
	 */
	public function test_output_feature_box_summary() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `output_feature_box_long` method
	 *
	 * @group search-algorithm
	 */
	public function test_output_feature_box_long() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `setup` method
	 *
	 * @group search-algorithm
	 */
	public function test_setup() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `output_feature_box_settings` method
	 *
	 * @group search-algorithm
	 */
	public function test_output_feature_box_settings() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `set_settings_schema` method
	 *
	 * @group search-algorithm
	 */
	public function test_set_settings_schema() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `get_search_algorithm_version` method
	 *
	 * @group search-algorithm
	 */
	public function test_get_search_algorithm_version() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `requirements_status` method
	 *
	 * @group search-algorithm
	 */
	public function test_requirements_status() {
		$this->markTestIncomplete();
	}

	/**
	 * Test the `fix_search_algorithm_version` method keeps a valid version
	 *
	 * @group search-algorithm
	 */
	public function test_fix_search_algorithm_version_with_valid_value() {
		$feature = $this->get_feature();

		$available = array_keys( \ElasticPress\SearchAlgorithms::factory()->get_all() );
		$this->assertNotEmpty( $available );

		$valid_version = $available[0];
		$new_settings  = [
			'search_algorithm' => [
				'search_algorithm_version' => $valid_version,
			],
		];

		$result = $feature->fix_search_algorithm_version( $new_settings, $feature );

		$this->assertSame( $valid_version, $result['search_algorithm']['search_algorithm_version'] );
	}

	/**
	 * Test the `fix_search_algorithm_version` method resets an invalid version
	 *
	 * @group search-algorithm
	 */
	public function test_fix_search_algorithm_version_with_invalid_value() {
		$feature = $this->get_feature();

		$new_settings = [
			'search_algorithm' => [
				'search_algorithm_version' => 'invalid_version_slug',
			],
		];

		$result = $feature->fix_search_algorithm_version( $new_settings, $feature );

		$this->assertSame(
			$feature->default_settings['search_algorithm_version'],
			$result['search_algorithm']['search_algorithm_version']
		);
	}
}
