<?php
/**
 * Meta key pattern Feature
 *
 * @package ElasticPressLabs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Your feature class.
 */
class ElasticPressLabs extends \ElasticPress\Feature {

	/**
	 * Registered subfeatures
	 *
	 * @var array
	 */
	public $subfeatures = [];

	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'elasticpress_labs';

		$this->title = esc_html__( 'ElasticPress Labs', 'elasticpress-labs' );

		$this->requires_install_reindex = false;
		$this->default_settings         = [];

		parent::__construct();
	}

	/**
	 * Output feature box summary.
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Enable or disable the ElastisPress Labs Features.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'This feature will give you some new features.' ); ?></p>
		<?php
	}

	/**
	 * Setup your feature functionality.
	 * Use this method to hook your feature functionality to ElasticPress or WordPress.
	 */
	public function setup() {
		$this->register_subfeatures();
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

		foreach ( $this->subfeatures as $subfeature ) {
			$this->subfeature_field(
				$subfeature->slug,
				$subfeature->title,
				$subfeature->description
			);
		}
	}

	/**
	 * Add a subfeature with your informations.
	 *
	 * @param  array $subfeature The subfeature information
	 * @return void
	 */
	private function add_to_subfeatures( $subfeature ) {
		$this->subfeatures[] = (object) $subfeature;
	}

	/**
	 * Display the subfeature field.
	 *
	 * @param  string $slug The slug of the field
	 * @param  string $label The label of the field
	 * @param  string $description The description of the field
	 * @return void
	 */
	private function subfeature_field( $slug, $label, $description ) {
		$settings = $this->get_settings();

		$id_field_enabled  = $slug . '_enabled';
		$id_field_disabled = $slug . '_disabled';
		$name_field        = $slug . '_subfeature';

		?>
		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status">
				<?php echo esc_html( $label ); ?>
			</div>
			<div class="input-wrap">
				<label for="<?php echo esc_attr( $id_field_enabled ); ?>">
					<input
						name="<?php echo esc_attr( $name_field ); ?>"
						id="<?php echo esc_attr( $id_field_enabled ); ?>"
						data-field-name="<?php echo esc_attr( $name_field ); ?>"
						class="setting-field"
						<?php checked( (bool) $settings[ $name_field ] ); ?>
						type="radio"
						value="1"
					><?php esc_html_e( 'Register feature', 'elasticpress-labs' ); ?>
				</label>
				<br>
				<label for="<?php echo esc_attr( $id_field_disabled ); ?>">
					<input
						name="<?php echo esc_attr( $name_field ); ?>"
						id="<?php echo esc_attr( $id_field_disabled ); ?>"
						data-field-name="<?php echo esc_attr( $name_field ); ?>"
						class="setting-field"
						<?php checked( (bool) $settings[ $name_field ], false ); ?>
						type="radio"
						value="0"
					><?php esc_html_e( 'Unregister feature', 'elasticpress-labs' ); ?>
				</label>

				<p class="field-description">
					<?php echo esc_html( $description ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if the subfeature is active.
	 *
	 * @param string $slug The slug of the subfeature
	 * @return boolean
	 */
	private function is_subfeature_active( $slug ) {
		$settings = $this->get_settings();

		return isset( $settings[ $slug . '_subfeature' ] ) && $settings[ $slug . '_subfeature' ];
	}

	/**
	 * Register subfeatures as Features if activated.
	 */
	private function register_subfeatures() {
		$settings = $this->get_settings();

		$features_dir = plugin_dir_path( __FILE__ );

		$current_file = __FILE__;

		$features_files = array_diff(
			glob( "{$features_dir}*.php" ),
			array( $current_file )
		);

		foreach ( $features_files as $filename ) {
			require ELASTICPRESS_LABS_INC . 'classes/Feature/' . basename( $filename );

			$class_name = 'ElasticPressLabs\Feature\\' . basename( $filename, '.php' );

			if ( class_exists( $class_name ) ) {
				$subfeature = new $class_name();

				ob_start();
				$subfeature->output_feature_box_summary();
				$description = wp_strip_all_tags( ob_get_clean(), true );

				$this->add_to_subfeatures(
					array(
						'slug'        => $subfeature->slug,
						'title'       => $subfeature->title,
						'description' => $description,
					)
				);

				if ( $settings['active'] && $this->is_subfeature_active( $subfeature->slug ) ) {
					\ElasticPress\Features::factory()->register_feature( $subfeature );
					$subfeature->setup();
				}
			}
		}
	}
}
