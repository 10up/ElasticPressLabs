<?php
/**
 * Test subscription search feature
 *
 * @since 2.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs;

/**
 * Subscription Search test class
 *
 * @since  2.0.0
 */
class TestWooCommerceSubscriptionSearch extends BaseTestCase {
	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		\ElasticPress\register_indexable_posts();

		$instance = new \ElasticPressLabs\Feature\WooCommerceSubscriptionSearch();
		\ElasticPress\Features::factory()->register_feature( $instance );

		\ElasticPress\Features::factory()->activate_feature( 'protected_content' );
		\ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		\ElasticPress\Features::factory()->activate_feature( 'woocommerce_subscription_search' );

		\ElasticPress\Features::factory()->setup_features();

		\ElasticPress\Indexables::factory()->get( 'post' )->delete_index();
		\ElasticPress\Indexables::factory()->get( 'post' )->put_mapping();
		\ElasticPress\Indexables::factory()->get( 'post' )->sync_manager->sync_queue = [];
	}

	/**
	 * Test search integration is on for shop subscriptions
	 *
	 * @group woocommerce
	 */
	public function testSearchOnShopSubscriptionAdmin() {
		global $wp_the_query;

		/**
		 * The post type is registered by the WooCommerce add-on
		 */
		$add_type = function ( $post_types ) {
			$post_types['shop_subscription'] = 'shop_subscription';
			return $post_types;
		};
		add_filter( 'ep_indexable_post_types', $add_type );

		/**
		 * This is needed to store the order as a subscription.
		 */
		$set_type = function ( $data ) {
			$data['post_type'] = 'shop_subscription';
			return $data;
		};
		add_filter( 'woocommerce_new_order_data', $set_type );

		/**
		 * Adding the order type, so WooCommerce can iterate of items (although inexistent)
		 */
		wc_register_order_type( 'shop_subscription', [] );

		$subscription = new \WC_Order();
		$subscription->set_billing_first_name( 'findme' );
		$subscription->save();

		\ElasticPress\Indexables::factory()->get( 'post' )->index( $subscription->get_id() );
		\ElasticPress\Elasticsearch::factory()->refresh_indices();

		set_current_screen( 'edit.php' );
		$this->assertTrue( is_admin() );

		\ElasticPress\Features::factory()->setup_features();

		$query = new \WP_Query();

		$args = array(
			's'            => 'findme',
			'post_type'    => 'shop_subscription',
			'post_status'  => 'wc-pending',
			'has_password' => true,
		);

		$wp_the_query = $query;
		$query->query( $args );

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 1, $query->post_count );
		$this->assertEquals( 1, $query->found_posts );
	}
}
