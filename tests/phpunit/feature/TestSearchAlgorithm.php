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
	 * Clean up after each test.
	 *
	 * @since 2.6.0
	 */
	public function tear_down() {
		\ElasticPress\SearchAlgorithms::factory()->unregister( 'test_hidden' );
		\ElasticPress\SearchAlgorithms::factory()->unregister( 'test_not_hidden' );

		parent::tear_down();
	}

	/**
	 * Register a test search algorithm.
	 *
	 * @since 2.6.0
	 * @param string $slug   Algorithm slug.
	 * @param bool   $hidden Value of its `$hidden` property.
	 * @return \ElasticPress\SearchAlgorithm
	 */
	protected function register_algorithm( string $slug, bool $hidden ) {
		$algorithm = new class() extends \ElasticPress\SearchAlgorithm {
			/**
			 * Slug.
			 *
			 * @var string
			 */
			public $slug = '';

			/**
			 * Whether the algorithm is hidden.
			 *
			 * @var bool
			 */
			public $hidden = false;

			/**
			 * Search algorithm slug.
			 *
			 * @return string
			 */
			public function get_slug(): string {
				return $this->slug;
			}

			/**
			 * Search algorithm name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return 'Test';
			}

			/**
			 * Search algorithm description.
			 *
			 * @return string
			 */
			public function get_description(): string {
				return 'Test';
			}

			/**
			 * Return the Elasticsearch `query` clause.
			 *
			 * @param string $indexable_slug Indexable slug
			 * @param string $search_term    Search term(s)
			 * @param array  $search_fields  Search fields
			 * @param array  $query_vars     Query vars
			 * @return array
			 */
			protected function get_raw_query( string $indexable_slug, string $search_term, array $search_fields, array $query_vars ): array {
				return [];
			}
		};

		$algorithm->slug   = $slug;
		$algorithm->hidden = $hidden;

		\ElasticPress\SearchAlgorithms::factory()->register( $algorithm );

		return $algorithm;
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
		$this->register_algorithm( 'test_hidden', true );
		$this->register_algorithm( 'test_not_hidden', false );

		$field   = wp_list_filter( $this->get_feature()->get_settings_schema(), [ 'key' => 'search_algorithm_version' ] );
		$options = wp_list_pluck( reset( $field )['options'], 'value' );

		$this->assertContains( 'test_not_hidden', $options );
		$this->assertContains( '4.0', $options );
		$this->assertNotContains( 'test_hidden', $options );
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

	/**
	 * Test the `get_public_search_algorithms` method leaves out hidden algorithms only
	 *
	 * @since 2.6.0
	 * @group search-algorithm
	 */
	public function test_get_public_search_algorithms() {
		$this->register_algorithm( 'test_hidden', true );
		$this->register_algorithm( 'test_not_hidden', false );

		$public = SearchAlgorithm::get_public_search_algorithms();

		$this->assertArrayHasKey( 'test_not_hidden', $public );
		$this->assertArrayHasKey( '4.0', $public );
		$this->assertArrayNotHasKey( 'test_hidden', $public );

		// A hidden algorithm stays registered, so code can still use it by slug.
		$this->assertSame( 'test_hidden', \ElasticPress\SearchAlgorithms::factory()->get( 'test_hidden' )->get_slug() );
	}

	/**
	 * Test the `is_hidden_search_algorithm` method
	 *
	 * @since 2.6.0
	 * @group search-algorithm
	 */
	public function test_is_hidden_search_algorithm() {
		$this->assertTrue( SearchAlgorithm::is_hidden_search_algorithm( $this->register_algorithm( 'test_hidden', true ) ) );
		$this->assertFalse( SearchAlgorithm::is_hidden_search_algorithm( $this->register_algorithm( 'test_not_hidden', false ) ) );
	}

	/**
	 * Test that an algorithm class without the `$hidden` property is public
	 *
	 * @since 2.6.0
	 * @group search-algorithm
	 */
	public function test_algorithm_without_hidden_property_is_public() {
		$algorithm = \ElasticPress\SearchAlgorithms::factory()->get( '4.0' );

		$this->assertFalse( property_exists( $algorithm, 'hidden' ) );
		$this->assertFalse( SearchAlgorithm::is_hidden_search_algorithm( $algorithm ) );
		$this->assertArrayHasKey( '4.0', SearchAlgorithm::get_public_search_algorithms() );
	}

	/**
	 * Test that a hidden algorithm cannot be saved as the site's algorithm
	 *
	 * @since 2.6.0
	 * @group search-algorithm
	 */
	public function test_hidden_algorithm_is_not_saved() {
		$this->register_algorithm( 'test_hidden', true );

		$feature = $this->get_feature();
		$default = $feature->default_settings['search_algorithm_version'];
		$fixed   = $feature->fix_search_algorithm_version(
			[ 'search_algorithm' => [ 'search_algorithm_version' => 'test_hidden' ] ],
			$feature
		);
		$read    = $feature->maybe_apply_default_search_algorithm_version(
			[ 'search_algorithm' => [ 'search_algorithm_version' => 'test_hidden' ] ]
		);
		$clean   = $feature->sanitize_settings_callback( [ 'search_algorithm_version' => 'test_hidden' ] );

		$this->assertSame( $default, $fixed['search_algorithm']['search_algorithm_version'] );
		$this->assertSame( $default, $read['search_algorithm']['search_algorithm_version'] );
		$this->assertSame( $default, $clean['search_algorithm_version'] );
	}
}
