<?php
/**
 * Users integration Feature
 *
 * @since 2.1
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPress\Features;
use ElasticPress\Indexables as Indexables;
use ElasticPressLabs\Indexable as Indexable;
use ElasticPress\FeatureRequirementsStatus as FeatureRequirementsStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Users feature
 *
 * @since 2.1
 */
class Users extends Feature {

	/**
	 * Order of the feature in ElasticPress's Dashboard.
	 *
	 * @var integer
	 */
	public $order = 10;

	/**
	 * Initialize User feature setting
	 */
	public function __construct() {
		$this->slug = 'users';

		$this->title = esc_html__( 'Users', 'elasticpress-labs' );

		// Check if users feature is already active or not
		$user_feature = Features::factory()->get_registered_feature( 'users' );
		$this->is_user_feature_active = ! empty ( $user_feature );
		$this->requires_install_reindex = true;
		if ( false === $this->is_user_feature_active ) {
			$folder_path = ELASTICPRESS_LABS_PATH . 'includes/classes/Indexable/User/*';
			foreach ( glob( $folder_path ) as $file ) {
				require_once $file;
			}
			Indexables::factory()->register( new Indexable\User\User(), false );
		} 

		parent::__construct();
	}

	/**
	 * Setup all feature filters
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( empty( $settings['active'] ) || true === $this->is_user_feature_active ) {
			return;
		}

		Indexables::factory()->activate( 'user' );

		add_action( 'init', [ $this, 'search_setup' ] );
	}

	/**
	 * Setup feature on each page load
	 *
	 * @since  2.1
	 */
	public function search_setup() {
		add_filter( 'ep_elasticpress_enabled', [ $this, 'integrate_search_queries' ], 10, 2 );
	}

	/**
	 * Enable integration on search queries
	 *
	 * @param  bool          $enabled Whether EP is enabled
	 * @param  WP_User_Query $query Current query object.
	 * @since  2.1
	 * @return bool
	 */
	public function integrate_search_queries( $enabled, $query ) {
		if ( ! is_a( $query, 'WP_User_Query' ) ) {
			return $enabled;
		}

		if ( isset( $query->query_vars['ep_integrate'] ) && ! filter_var( $query->query_vars['ep_integrate'], FILTER_VALIDATE_BOOLEAN ) ) {
			$enabled = false;
		} elseif ( ! empty( $query->query_vars['search'] ) ) {
			$enabled = true;
		}

		return $enabled;
	}

	/**
	 * Output feature box summary
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Improve user search relevancy and query performance.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'This feature will empower your website to overcome traditional WordPress user search and query limitations that can present themselves at scale.', 'elasticpress-labs' ); ?></p>
		<p><?php esc_html_e( 'Be aware that storing user data may bound you to certain legal obligations depending on your local government regulations.', 'elasticpress-labs' ); ?></p>
		<?php
	}

		/**
	 * Tell user whether requirements for User feature are met or not.
	 *
	 * @return array $status Status array
	 * @since 2.1
	 */
	public function requirements_status() {
		$status = new FeatureRequirementsStatus( 0 );

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$url = admin_url( 'network/admin.php?page=elasticpress-sync' );
		} else {
			$url = admin_url( 'admin.php?page=elasticpress-sync' );
		}
		if ( true === $this->is_user_feature_active ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need ElasticPress 5.0 or above to use this feature', 'elasticpress-labs' );
		} else {
			$status->code    = 1;
			$status->message = sprintf(
				/* translators: Sync Page URL */
				__( 'Changes in this feature will only be applied after you <a href="%1$s">delete all data and sync</a>.', 'elasticpress-labs' ),
				esc_url( $url )
			);
		}

		return $status;
	}
}
