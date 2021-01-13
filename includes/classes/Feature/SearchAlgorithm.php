<?php
/**
 * Search Algorithm Feature
 *
 * @package ElasticpressLabs
 */

namespace ElasticPressLabs\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SearchAlgorithm class.
 */
class SearchAlgorithm extends \ElasticPress\Feature {
	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'search_algorithm';

		$this->title = esc_html__( 'Search Algorithm Version', 'elasticpress-labs' );

		$this->requires_install_reindex = false;
		$this->default_settings         = [
			'feature_search_algorithm_version_setting' => '3.5',
		];

		parent::__construct();
	}

	/**
	 * Output feature box summary.
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Change the version of the search algorithm.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'By default, the ElasticPress uses version 3.5 but you can change to version 3.4.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Setup your feature functionality.
	 * Use this method to hook your feature functionality to ElasticPress or WordPress.
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( $settings['active'] && '3.5' !== $settings['feature_search_algorithm_version_setting'] ) {
			add_filter( 'ep_search_algorithm_version', array( $this, 'get_search_algorithm_version' ) );
		}
	}

	/**
	 * Display field settings on the Dashboard.
	 */
	public function output_feature_box_settings() {
		$settings = $this->get_settings();

		if ( ! $settings ) {
			$settings = [];
		}

		$settings = wp_parse_args( $settings, $this->default_settings );

		?>
		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status">
				<label for="feature_my_feature_setting">
					<?php esc_html_e( 'Version', 'elasticpress-labs' ); ?>
				</label>
			</div>

			<div class="input-wrap">
				<label for="input_version-3.5">
					<input
						name="feature_search_algorithm_version_setting"
						id="input_version-3.5"
						data-field-name="feature_search_algorithm_version_setting"
						class="setting-field"
						<?php checked( $settings['feature_search_algorithm_version_setting'], '3.5' ); ?>
						type="radio"
						value="3.5"
					>3.5
				</label>
				<p class="field-description">
					<?php esc_html_e( 'This version searches for the existence of all words in the search first, then returns results based on how closely those words appear.', 'elasticpress-labs' ); ?>
				</p>
				<br>
				<label for="input_version-3.4">
					<input
						name="feature_search_algorithm_version_setting"
						id="input_version-3.4"
						data-field-name="feature_search_algorithm_version_setting"
						class="setting-field"
						<?php checked( $settings['feature_search_algorithm_version_setting'], '3.4' ); ?>
						type="radio"
						value="3.4">3.4
				</label>
				<p class="field-description">
					<?php esc_html_e( 'This version uses a fuzzy match approach which includes results that have misspellings, and also includes matches on only some of the words in the search.', 'elasticpress-labs' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the search algorithm version.
	 */
	public function get_search_algorithm_version() {
		$settings = $this->get_settings();

		return $settings['feature_search_algorithm_version_setting'];
	}
}
