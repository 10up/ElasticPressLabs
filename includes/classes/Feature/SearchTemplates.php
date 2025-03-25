<?php
/**
 * Search Templates Feature
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPress\FeatureRequirementsStatus;
use ElasticPress\Utils;
use ElasticPressLabs\Utils as LabsUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Search Templates feature
 *
 * @since 2.4.0
 */
class SearchTemplates extends Feature {
	/**
	 * URL of the related documentation article
	 *
	 * @var string
	 */
	protected $search_api_docs_url = 'https://www.elasticpress.io/documentation/article/instant-results-post-search-api/';

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'search_templates';

		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.2.0', '<' ) ) {
			$this->set_i18n_strings();
		}

		parent::__construct();
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'Search Templates', 'elasticpress-labs' );

		$this->summary = '<p>' . sprintf(
			/* translators: %s: Search API documentation URL */
			__(
				'Search templates are Elasticsearch queries stored in ElasticPress.io servers used by the <a href="%s" target="_blank">Search API</a>.',
				'elasticpress-labs'
			),
			$this->search_api_docs_url
		) . '</p>' .
			'<p>' . __( 'Please note that all the API fields are still available for custom search templates. Your templates do not to differ in post types, offset, pagination arguments, or even filters, as for those you can still use query parameters. The templates can be used for searching in different fields or applying different scores, for instance.', 'elasticpress-labs' ) . '</p>' .
			'<p>' . __( 'Requires an <a href="https://www.elasticpress.io/" target="_blank">ElasticPress.io plan</a> to function.', 'elasticpress-labs' ) . '</p>';
	}

	/**
	 * Setup all feature hooks
	 */
	public function setup() {
		// Setup the UI.
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 50 );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );

		// Register REST routes.
		add_action( 'rest_api_init', [ $this, 'setup_endpoint' ] );
	}

	/**
	 * Determine feature reqs status
	 *
	 * @return FeatureRequirementsStatus
	 */
	public function requirements_status() {
		$status_code = Utils\is_epio() ? 1 : 2;

		$status = new FeatureRequirementsStatus( $status_code );

		if ( 2 === $status_code ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need an ElasticPress.io account to use this feature.', 'elasticpress-labs' );
		}

		return $status;
	}

	/**
	 * Is this our page.
	 *
	 * @return boolean
	 */
	public function is_search_templates_page() {
		if ( ! function_exists( '\get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		return ( 'elasticpress_page_elasticpress-search-templates' === $screen->base );
	}

	/**
	 * Adds the settings page to the admin menu.
	 *
	 * @return void
	 */
	public function admin_menu() {
		add_submenu_page(
			'elasticpress',
			esc_html__( 'ElasticPress.io Search Templates', 'elasticpress-labs' ),
			esc_html__( 'Search Templates', 'elasticpress-labs' ),
			Utils\get_capability( 'search_templates' ),
			'elasticpress-search-templates',
			[ $this, 'admin_page' ]
		);
	}

	/**
	 * Enqueues scripts and styles.
	 *
	 * @return void
	 */
	public function scripts() {
		if ( ! $this->is_search_templates_page() ) {
			return;
		}

		wp_enqueue_script(
			'ep_search_templates_scripts',
			ELASTICPRESS_LABS_URL . 'dist/js/search-templates-script.js',
			LabsUtils\get_asset_info( 'search-templates-script', 'dependencies' ),
			LabsUtils\get_asset_info( 'search-templates-script', 'version' ),
			true
		);

		wp_set_script_translations( 'ep_search_templates_scripts', 'elasticpress-labs' );

		wp_enqueue_style( 'wp-edit-post' );

		wp_enqueue_style(
			'ep_search_templates_scripts',
			ELASTICPRESS_LABS_URL . 'dist/css/search-templates-script.css',
			[],
			LabsUtils\get_asset_info( 'search-templates-script', 'version' ),
			'all'
		);

		$instant_results = \ElasticPress\Features::factory()->get_registered_feature( 'instant-results' );
		$template        = json_decode( $instant_results->epio_get_search_template() );

		$index_name       = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();
		$endpoint_example = Utils\get_host() . "/api/v1/search/posts/{$index_name}?search={search_term}&template_name={template}";

		wp_localize_script(
			'ep_search_templates_scripts',
			'epSearchTemplates',
			[
				'defaultTemplate' => $template,
				'endpointExample' => $endpoint_example,
				'searchApiDocUrl' => $this->search_api_docs_url,
				'restApiEndpoint' => 'elasticpress-labs/v1/search-templates',
			]
		);
	}

	/**
	 * Setup REST endpoints
	 */
	public function setup_endpoint() {
		$controller = new \ElasticPressLabs\REST\SearchTemplates( $this );
		$controller->register_routes();
	}

	/**
	 * Renders the search templates page.
	 *
	 * @return void
	 */
	public function admin_page() {
		include EP_PATH . '/includes/partials/header.php';

		?>
		<div class="wrap">
			<div id="ep-search-templates"></div>
		</div>
		<?php
	}

	/**
	 * Delete all search templates of the account.
	 *
	 * This is a highly destructive operation and is only called programmatically.
	 *
	 * @return void
	 */
	public function delete_all_search_templates() {
		$response      = \ElasticPress\Elasticsearch::factory()->remote_request( $this->get_search_templates_endpoint() );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $response_body ) ) {
			foreach ( $response_body as $index_name => $templates ) {
				foreach ( $templates as $template ) {
					\ElasticPress\Elasticsearch::factory()->remote_request(
						$this->get_search_template_endpoint( $index_name ) . '?template_name=' . $template,
						[ 'method' => 'DELETE' ]
					);
				}
			}
		}
	}

	/**
	 * EP.io search templates endpoint.
	 *
	 * @return string
	 */
	public function get_search_templates_endpoint(): string {
		return 'api/v1/search/posts/templates';
	}

	/**
	 * EP.io (single) search template endpoint.
	 *
	 * @param null|string $index_name Index name.
	 * @return string
	 */
	public function get_search_template_endpoint( $index_name = null ): string {
		if ( ! $index_name ) {
			$index_name = \ElasticPress\Indexables::factory()->get( 'post' )->get_index_name();
		}

		return "api/v1/search/posts/{$index_name}/template";
	}


	/**
	 * Set the `settings_schema` attribute
	 */
	protected function set_settings_schema() {
		if ( ! $this->is_active() ) {
			return;
		}

		$this->settings_schema = [
			[
				'key'   => 'additional_links',
				'label' => sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( admin_url( 'admin.php?page=elasticpress-search-templates' ) ),
					__( 'Manage search templates', 'elasticpress-labs' )
				),
				'type'  => 'markup',
			],
		];
	}
}
