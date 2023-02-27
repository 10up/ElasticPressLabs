<?php
/**
 * Users feature
 *
 * @since 2.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPress\Indexables;
use ElasticPress\FeatureRequirementsStatus;

/**
 * Users feature class
 */
class Users extends Feature {
	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'users';

		$this->title = esc_html__( 'Users', 'elasticpress' );

		$this->summary = __( 'Improve user search relevancy and query performance.', 'elasticpress' );

		$this->docs_url = __( 'https://elasticpress.zendesk.com/hc/en-us/articles/360050447492-Configuring-ElasticPress-via-the-Plugin-Dashboard#users', 'elasticpress' );

		$this->requires_install_reindex = true;

		Indexables::factory()->register( new \ElasticPressLabs\Indexable\User\User(), false );

		parent::__construct();
	}

	/**
	 * Hook search functionality
	 */
	public function setup() {
		Indexables::factory()->activate( 'user' );

		add_action( 'init', [ $this, 'search_setup' ] );
	}

	/**
	 * Setup feature on each page load
	 */
	public function search_setup() {
		add_filter( 'ep_elasticpress_enabled', [ $this, 'integrate_search_queries' ], 10, 2 );
	}

	/**
	 * Output feature box long text
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'This feature will empower your website to overcome traditional WordPress user search and query limitations that can present themselves at scale.', 'elasticpress' ); ?></p>
		<p><?php esc_html_e( 'Be aware that storing user data may bound you to certain legal obligations depending on your local government regulations.', 'elasticpress' ); ?></p>
		<?php
	}

	/**
	 * Enable integration on search queries
	 *
	 * @param  bool          $enabled Whether EP is enabled
	 * @param  WP_User_Query $query Current query object.
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
	 * Determine feature reqs status
	 *
	 * @return FeatureRequirementsStatus
	 */
	public function requirements_status() {
		$status = new FeatureRequirementsStatus( 1 );

		return $status;
	}
}
