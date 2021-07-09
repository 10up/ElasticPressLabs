<?php
/**
 * Co-Author Plus integration Feature
 *
 * @since 1.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature as Feature;
use ElasticPress\Features as Features;
use ElasticPress\FeatureRequirementsStatus as FeatureRequirementsStatus;

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

		parent::__construct();
	}

	/**
	 * Setup all feature filters
	 *
	 * @since 1.1.0
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( $settings['active'] && $this->is_protected_content_feature_active ) {
			add_filter( 'ep_sync_taxonomies', array( $this, 'include_author_term' ) );

			if ( is_admin() ) {
				add_filter( 'ep_post_formatted_args', [ $this, 'include_author_in_es_query' ], 10, 3 );
			}
		}
	}

	/**
	 * Prepare Elasticsearch query to search for posts by author/coauthor
	 *
	 * @since 1.1.0
	 * @param {array} $formatted_args Formatted Elasticsearch query
	 * @param {array} $args Query variables
	 * @param {array} $wp_query Query part
	 * @return {array} New query
	 */
	public function include_author_in_es_query( $formatted_args, $args, $wp_query ) {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! $wp_query->is_main_query() ) {
			return $formatted_args;
		}

		$author_name = $wp_query->get( 'author_name' );

		if ( ! $author_name ) {
			return $formatted_args;
		}

		global $coauthors_plus;

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
				function( $item ) {
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
		<p><?php esc_html_e( 'Add support for Co-Authors Plus plugin.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 *
	 * @since 1.1.0
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'You need to active the Protected Content feature.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Determine feature reqs status
	 *
	 * @since 1.1.0
	 * @return FeatureRequirementsStatus
	 */
	public function requirements_status() {
		$status = new FeatureRequirementsStatus( 0 );

		if ( ! is_plugin_active( 'co-authors-plus/co-authors-plus.php' ) || ! class_exists( 'CoAuthors_Plus' ) ) {
			$status->code    = 2;
			$status->message = esc_html__( 'You need to have Co-Authors Plus installed and activated.', 'elasticpress-labs' );
		} elseif ( ! $this->is_protected_content_feature_active ) {
			$status->code    = 1;
			$status->message = esc_html__( 'You need to activate the Protected Content Feature to this feature work properly.', 'elasticpress-labs' );
		}

		return $status;
	}
}
