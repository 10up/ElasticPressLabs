<?php
/**
 * Test Boolean Search Operators feature
 *
 * @since  1.2.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs;

/**
 * Boolean Search Operators test class
 *
 * @since  1.2.0
 */
class TestBooleanSearchOperators extends \WP_UnitTestCase {
	/**
	 * Setup each test.
	 *
	 * @since  1.2.0
	 */
	public function setUp() {
		$instance = new ElasticPressLabs\Feature\BooleanSearchOperators();
		\ElasticPress\Features::factory()->register_feature($instance);
	}

	/**
	 * Get Boolean Search Operators feature
	 *
	 * @since  1.2.0
	 * @return BooleanSearchOperators
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'boolean_search_operators' );
	}

	/**
	 * Test constrcut
	 *
	 * @since  1.2.0
	 */
	public function testConstruct() {
		$instance = $this->get_feature();

        $this->assertEquals( 'boolean_search_operators', $instance->slug );
        $this->assertEquals( 'Boolean Search Operators', $instance->title );
	}

	/**
	 * Test box summary
	 *
	 * @since  1.2.0
	 */
	public function testBoxSummary() {
		ob_start();
		$this->get_feature()->output_feature_box_summary();
        $output = ob_get_clean();

		$this->assertContains( 'Allow boolean operators in search queries', $output );
	}

	/**
	 * Test box long text
	 *
	 * @since  1.2.0
	 */
	public function testBoxLong() {
		ob_start();
		$this->get_feature()->output_feature_box_long();
        $output = ob_get_clean();

		$this->assertContains( 'Allows users to search using the following boolean operators:', $output );
	}

}
