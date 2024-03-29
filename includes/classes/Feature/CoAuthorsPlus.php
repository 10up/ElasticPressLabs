<?php
/**
 * Co-Author Plus integration Feature
 *
 * @since 1.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPress\Features;
use ElasticPress\FeatureRequirementsStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Co-Authors Plus feature
 *
 * @since 1.1.0
 */
class CoAuthorsPlus extends Feature {

	/**
	 * Order of the feature in ElasticPress's Dashboard.
	 *
	 * @var integer
	 */
	public $order = 10;

	/**
	 * Whether the Protected Content feature is active or not
	 *
	 * @since 2.1.1
	 * @var bool
	 */
	protected $is_protected_content_feature_active = false;

	/**
	 * Initialize feature setting it's config
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->slug = 'co_authors_plus';

		$this->title = esc_html__( 'Co-Authors Plus', 'elasticpress-labs' );

		$this->requires_install_reindex = true;

		$protected_content_feature = Features::factory()->get_registered_feature( 'protected_content' );

		$this->is_protected_content_feature_active = $protected_content_feature && $protected_content_feature->is_active();

		$this->requires_feature = 'protected_content';

		parent::__construct();
	}

	/**
	 * Setup all feature filters
	 *
	 * @since 1.1.0
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( empty( $settings['active'] ) || ! $this->is_protected_content_feature_active ) {
			return;
		}

		add_filter( 'ep_sync_taxonomies', array( $this, 'include_author_term' ) );

		if ( is_admin() ) {
			add_filter( 'ep_post_formatted_args', [ $this, 'include_author_in_es_query' ], 10, 3 );
		}
	}

	/**
	 * Prepare Elasticsearch query to search for posts by author/coauthor
	 *
	 * @since 1.1.0
	 * @param {array}  $formatted_args Formatted Elasticsearch query
	 * @param {array}  $args Query variables
	 * @param WP_Query $wp_query Query part
	 * @return {array} New query
	 */
	public function include_author_in_es_query( $formatted_args, $args, $wp_query ) {
		global $coauthors_plus;

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! $wp_query->is_main_query() ) {
			return $formatted_args;
		}

		$author_name = $wp_query->get( 'author_name' );

		if ( ! $author_name ) {
			return $formatted_args;
		}

		$coauthor = $coauthors_plus->get_coauthor_by( 'login', $author_name );

		if ( ! $coauthor ) {
			return $formatted_args;
		}

		$author_term = $coauthors_plus->get_author_term( $coauthor );

		if ( $author_term ) {
			$formatted_args['post_filter']['bool']['must'] = $this->filter_out_author_name_and_id_from_es_filter( $formatted_args );

			$formatted_args['post_filter']['bool']['must'][] = $this->add_es_filter_for_author_coauthor( $author_term );
		}

		return $formatted_args;
	}

	/**
	 * Filter out the post_author.display_name and post_author.id
	 *
	 * @since 1.1.0
	 * @param array $formatted_args Formatted Elasticsearch query
	 * @return array|mixed
	 */
	protected function filter_out_author_name_and_id_from_es_filter( $formatted_args ) {

		if ( ! isset( $formatted_args['post_filter']['bool']['must'] ) || ! is_array( $formatted_args['post_filter']['bool']['must'] ) ) {
			return $formatted_args;
		}

		return array_values(
			array_filter(
				$formatted_args['post_filter']['bool']['must'],
				function ( $item ) {
					return ! ( isset( $item['term']['post_author.display_name'] ) || isset( $item['term']['post_author.id'] ) );
				}
			)
		);
	}

	/**
	 * Add Elasticsearch filter for author/coauthor
	 *
	 * @since 1.1.0
	 * @param object $author_term The author term on success
	 * @return array
	 */
	protected function add_es_filter_for_author_coauthor( $author_term ) {
		return [
			'bool' => [
				'should' => [
					[
						'terms' => [ 'post_author.login' => [ $author_term->name ] ],
					],
					[
						'bool' => [
							'must' => [
								[
									'terms' => [
										'terms.author.slug' => [ $author_term->slug ],
									],
								],
								[
									'terms' => [
										'terms.author.term_id' => [ $author_term->term_id ],
									],
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Include author taxonomy when indexing posts.
	 *
	 * @since 1.1.0
	 * @param array $taxonomies Selected taxonomies.
	 * @return WP_Taxonomy|false
	 */
	public function include_author_term( $taxonomies ) {
		$taxonomies[] = get_taxonomy( 'author' );
		return $taxonomies;
	}

	/**
	 * Output feature box summary
	 *
	 * @since 1.1.0
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Add support for the Co-Authors Plus plugin in the Admin Post List screen by Author name.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 *
	 * @since 1.1.0
	 */
	public function output_feature_box_long() {
		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.0.0', '<' ) ) {
			?>
			<p><?php echo wp_kses_post( __( 'If using the Co-Authors Plus plugin and the Protected Content feature, enable this feature to visit the Admin Post List screen by Author name <code>wp-admin/edit.php?author_name=&lt;name&gt;</code> and see correct results.', 'elasticpress-labs' ) ); ?></p>
			<?php
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			esc_html__( 'Settings are now generated via the set_settings_schema() method.' ),
			'ElasticPress Labs 2.2.0'
		);
	}

	/**
	 * Set the `settings_schema` attribute
	 *
	 * @since 2.2.0
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
			[
				'key'   => 'instructions',
				'label' => '<p>' . __( 'If using the Co-Authors Plus plugin and the Protected Content feature, enable this feature to visit the Admin Post List screen by Author name <code>wp-admin/edit.php?author_name=&lt;name&gt;</code> and see correct results.', 'elasticpress-labs' ) . '</p>',
				'type'  => 'markup',
			],
		];
	}

	/**
	 * Determine feature reqs status
	 *
	 * @since 1.1.0
	 * @return FeatureRequirementsStatus
	 */
	public function requirements_status() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$status = new FeatureRequirementsStatus( 0 );

		if ( ! \is_plugin_active( 'co-authors-plus/co-authors-plus.php' ) || ! class_exists( '\CoAuthors_Plus' ) ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need to have Co-Authors Plus installed and activated.', 'elasticpress-labs' );
		} elseif ( ! $this->is_protected_content_feature_active ) {
			$status->code    = 1;
			$status->message = esc_html__( 'You need to activate the Protected Content Feature to this feature work properly.', 'elasticpress-labs' );
		}

		return $status;
	}
}
