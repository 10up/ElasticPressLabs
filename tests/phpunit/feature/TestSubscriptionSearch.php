<?php
/**
 * Test subscription search feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

require __DIR__ . '/../../../vendor/10up/elasticpress/elasticpress.php';

use ElasticPressLabs;
use WP_Mock\Tools\TestCase as BaseTestCase;

/**
 * Subscription Search test class
 *
 * @since  2.0.0
 */
class TestSubscriptionSearch extends \WP_UnitTestCase {
	/**
	 * Setup each test.
	 *
	 * @since  2.0.0
	 */
	public function setUp() {
		$instance = new ElasticPressLabs\Feature\SubscriptionSearch();
		\ElasticPress\Features::factory()->register_feature($instance);
		\ElasticPress\Features::factory()->activate_feature( 'protected_content' );
		\ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		\ElasticPress\Features::factory()->setup_features();
	}

	/**
	 * Get Subscription Search feature
	 *
	 * @since  2.0.0
	 * @return BooleanSearchOperators
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'subscription_search' );
	}

	/**
	 * Test search integration is on for shop subscriptions
	 *
	 * @since 2.0.0
	 * @group woocommerce
	 */
	public function testSearchOnShopSubscriptionAdmin() {

		\ElasticPress\Features::factory()->post->create(
			array(
				'post_content' => 'findme',
				'post_type'    => 'shop_subscription',
			)
		);

		\ElasticPress\Elasticsearch::factory()->refresh_indices();

		$args = array(
			's'         => 'findme',
			'post_type' => 'shop_subscription',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 1, $query->post_count );
		$this->assertEquals( 1, $query->found_posts );
	}
}
