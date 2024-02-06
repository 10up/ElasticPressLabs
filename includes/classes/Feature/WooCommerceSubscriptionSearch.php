<?php
/**
 * WooCommerce Subscription Search Feature
 *
 * @since 2.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerceSubscriptionSearch class.
 */
class WooCommerceSubscriptionSearch extends \ElasticPress\Feature {

	/**
	 * Order of the feature in ElasticPress's Dashboard.
	 *
	 * @var integer
	 */
	public $order = 10;

	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'woocommerce_subscription_search';

		$this->title = esc_html__( 'WooCommerce Admin Subscription Search', 'elasticpress-labs' );

		$this->requires_install_reindex = true;

		parent::__construct();
	}

	/**
	 * Output feature box summary.
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Have WooCommerce Subscription admin search use EP.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'By default, WooCommerce Subscriptions does not use ElasticPress. This tells it to index and search those, just like ElasticPress does for orders.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Setup your feature functionality.
	 * Use this method to hook your feature functionality to ElasticPress or WordPress.
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( empty( $settings['active'] ) ) {
			return;
		}

		add_filter( 'ep_woocommerce_orders_supported_post_types', [ $this, 'adjust_supported_post_types' ] );
		add_filter( 'ep_woocommerce_admin_searchable_post_types', [ $this, 'admin_searchable_post_types' ] );
		add_action( 'ep_woocommerce_hook_search_fields', [ $this, 'maybe_hook_woocommerce_search_fields' ], 1 );
	}

	/**
	 * Add subscription as a supported post type.
	 *
	 * @param array $supported_post_types Initial post types supported
	 * @return array
	 */
	public function adjust_supported_post_types( $supported_post_types ) {
		$supported_post_types[] = 'shop_subscription';

		return $supported_post_types;
	}

	/**
	 * Include subscription as a searchable type for admins.
	 *
	 * @param array $searchable_post_types Initial post types supported
	 * @return array
	 */
	public function admin_searchable_post_types( $searchable_post_types ) {
		$searchable_post_types[] = 'shop_subscription';

		return $searchable_post_types;
	}

	/**
	 * Sets woocommerce meta search fields to an empty array if we are integrating the main query with ElasticSearch
	 *
	 * Behavior is nearly identical to the identical function in the WooCommerce.php class of ElasticPress
	 *
	 * @param \WP_Query $query Current query
	 */
	public function maybe_hook_woocommerce_search_fields( $query ) {
		global $pagenow, $wp, $wc_list_table, $wp_filter;

		if ( 'edit.php' !== $pagenow || empty( $wp->query_vars['s'] ) || 'shop_subscription' !== $wp->query_vars['post_type'] || ! isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		remove_action( 'parse_query', [ $wc_list_table, 'search_custom_fields' ] );
		/**
		 * Removing the action the gross way because WCS_Admin_Post_Types can't be referenced without recreating.
		 *
		 * See https://github.com/Automattic/woocommerce-subscriptions-core/blob/trunk/includes/class-wc-subscriptions-core-plugin.php#L174
		 */
		foreach ( $wp_filter['parse_query']->callbacks as $top_keys => $actions ) {
			foreach ( $actions as $key => $action ) {
				if ( 'shop_subscription_search_custom_fields' === $action['function'][1] ) {
					unset( $wp_filter['parse_query']->callbacks[ $top_keys ][ $key ] );
				}
			}
		}
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return array $status Status array
	 */
	public function requirements_status() {
		$status = new \ElasticPress\FeatureRequirementsStatus( 1 );

		$woocommerce_feature       = \ElasticPress\Features::factory()->get_registered_feature( 'woocommerce' );
		$protected_content_feature = \ElasticPress\Features::factory()->get_registered_feature( 'protected_content' );

		if ( ! $this->is_subscription_plugin_activated() ) {
			$status->code    = 2;
			$status->message = esc_html__( 'This feature requires the WooCommerce Subscriptions plugin to be activated.', 'elasticpress-labs' );
		} elseif ( ! $woocommerce_feature->is_active() || ! $protected_content_feature->is_active() ) {
			$status->code    = 2;
			$status->message = esc_html__( 'This feature requires the WooCommerce and Protected Content features to be enabled.', 'elasticpress-labs' );
		} else {
			$status->message = esc_html__( 'Changes in this feature will be reflected only on the next page reload or expiration of any front-end caches.', 'elasticpress-labs' );
		}

		return $status;
	}

	/**
	 * Check if WooCommerce Subscriptions plugin is activated.
	 *
	 * @return bool
	 */
	public function is_subscription_plugin_activated(): bool {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		return \is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) && class_exists( '\WC_Subscriptions' );
	}
}
