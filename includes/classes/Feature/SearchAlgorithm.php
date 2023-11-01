<?php
/**
 * Search Algorithm Feature
 *
 * @package ElasticPressLabs
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
	 * Order of the feature in ElasticPress's Dashboard.
	 *
	 * @var integer
	 */
	public $order = 10;

	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'search_algorithm';

		$this->title = esc_html__( 'Search Algorithm Version', 'elasticpress-labs' );

		$this->requires_install_reindex = false;
		$this->default_settings         = [
			'search_algorithm_version' => '3.5',
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

		if ( empty( $settings['active'] ) ) {
			return;
		}

		add_filter( 'ep_post_search_algorithm', [ $this, 'get_search_algorithm_version' ] );
	}

	/**
	 * Display field settings on the Dashboard.
	 */
	public function output_feature_box_settings() {
		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.0.0', '<' ) ) {
			$settings = $this->get_settings();

			if ( ! $settings ) {
				$settings = [];
			}

			$settings = wp_parse_args( $settings, $this->default_settings );

			?>
			<div class="field">
				<div class="field-name status">
					<?php esc_html_e( 'Version', 'elasticpress-labs' ); ?>
				</div>

				<div class="input-wrap">
					<?php
					$search_algorithms = \ElasticPress\SearchAlgorithms::factory()->get_all();
					foreach ( $search_algorithms as $search_algorithm ) {
						?>
						<label>
							<input
								name="settings[search_algorithm_version]"
								type="radio"
								<?php checked( $settings['search_algorithm_version'], $search_algorithm->get_slug() ); ?>
								value="<?php echo esc_attr( $search_algorithm->get_slug() ); ?>">
							<?php echo esc_html( $search_algorithm->get_name() ); ?>
						</label>
						<p class="field-description">
							<?php echo wp_kses_post( $search_algorithm->get_description() ); ?>
						</p>
						<br>
						<?php
					}
					?>
				</div>
			</div>
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
		$search_algorithms = \ElasticPress\SearchAlgorithms::factory()->get_all();
		$options           = [];

		foreach ( $search_algorithms as $search_algorithm ) {
			$options[] = [
				'label' => $search_algorithm->get_name() . '<br><small>' . $search_algorithm->get_description() . '</small>',
				'value' => $search_algorithm->get_slug(),
			];
		}

		$this->settings_schema[] = [
			'default' => '3.5',
			'key'     => 'search_algorithm_version',
			'label'   => __( 'Version', 'elasticpress-labs' ),
			'options' => $options,
			'type'    => 'radio',
		];
	}

	/**
	 * Set the search algorithm
	 *
	 * @param string $search_algorithm The search algorithm slug
	 * @return string
	 */
	public function get_search_algorithm_version( $search_algorithm ) {
		$settings = $this->get_settings();

		return $settings['search_algorithm_version'] ?? $search_algorithm;
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return array $status Status array
	 * @since 2.0
	 */
	public function requirements_status() {
		$status = new \ElasticPress\FeatureRequirementsStatus( 1 );

		$status->message = esc_html__( 'Changes in this feature will be reflected only on the next page reload or expiration of any front-end caches.', 'elasticpress-labs' );

		return $status;
	}
}
