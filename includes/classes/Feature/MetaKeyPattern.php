<?php
/**
 * Meta key pattern Feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use \ElasticPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Your feature class.
 */
class MetaKeyPattern extends \ElasticPress\Feature {

	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'meta_key_pattern';

		$this->title = esc_html__( 'Meta Key Pattern', 'elasticpress-labs' );

		$this->requires_install_reindex = false;
		$this->default_settings         = [
			'meta_key_allow_pattern' => '',
			'meta_key_deny_pattern'  => '',
		];

		parent::__construct();
	}

	/**
	 * Output feature box summary.
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Include or exclude meta key patterns.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'This feature will give you the most control over the metadata indexed.' ); ?></p>
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

		add_filter( 'ep_prepare_meta_data', array( $this, 'exclude_meta_key_patterns' ), 5, 2 );
		add_filter( 'ep_prepare_meta_data', array( $this, 'include_meta_key_patterns' ), 10, 2 );
		add_filter(
			'ep_weighting_configuration_for_search',
			array( $this, 'update_weighting_configuration_for_search' )
		);
		add_action( 'update_postmeta', array( $this, 'delete_transient_on_meta_update' ), 10, 3 );
		add_action( 'wp_ajax_epl_meta_key_pattern_after_save', array( $this, 'after_save_settings' ) );
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
				<label for="meta_key_allow_pattern">
					<?php esc_html_e( 'Allow patterns', 'elasticpress-labs' ); ?>
				</label>
			</div>
			<div class="input-wrap">
				<textarea
					class="setting-field large-text code"
					id="meta_key_allow_pattern"
					rows="4"
					name="settings[meta_key_allow_pattern]"
				><?php echo empty( $settings['meta_key_allow_pattern'] ) ? '' : esc_textarea( $settings['meta_key_allow_pattern'] ); ?></textarea>
				<p class="field-description">
					<?php esc_html_e( 'Separate multiple regular expressions with line breaks.', 'elasticpress-labs' ); ?>
					<?php esc_html_e( 'Include the weight of the pattern adding a pipe (|) followed by a number. Example: /^[a-z]/|5', 'elasticpress-labs' ); ?>
				</p>
			</div>
		</div>

		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status">
				<label for="meta_key_deny_pattern">
					<?php esc_html_e( 'Deny patterns', 'elasticpress-labs' ); ?>
				</label>
			</div>
			<div class="input-wrap">
				<textarea
					class="setting-field large-text code"
					id="meta_key_deny_pattern"
					rows="4"
					name="settings[meta_key_deny_pattern]"
				><?php echo empty( $settings['meta_key_deny_pattern'] ) ? '' : esc_textarea( $settings['meta_key_deny_pattern'] ); ?></textarea>
				<p class="field-description">
					<?php esc_html_e( 'Separate multiple regular expressions with line breaks.', 'elasticpress-labs' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Include meta key patterns
	 *
	 * @param  array $meta Existing post meta.
	 * @return array
	 */
	public function include_meta_key_patterns( $meta ) {
		$allow_meta_key_list = $this->get_allowed_meta_key_list( array_keys( $meta ) );

		if ( ! empty( $allow_meta_key_list ) ) {
			add_filter(
				'ep_prepare_meta_allowed_protected_keys',
				function( $meta ) use ( $allow_meta_key_list ) {
					return array_unique(
						array_merge(
							$meta,
							$allow_meta_key_list
						)
					);
				}
			);
		}

		return $meta;
	}

	/**
	 * Get allowed meta key list
	 *
	 * @param {array} $meta Meta data
	 * @return array
	 */
	private function get_allowed_meta_key_list( $meta ) {
		$allowed_patterns = $this->get_allowed_meta_key_patterns();

		$allow_meta_key_list = array();

		foreach ( $meta as $meta_key ) {
			if ( $this->is_match_some_pattern( $allowed_patterns, $meta_key ) ) {
				$allow_meta_key_list[] = $meta_key;
			}
		}

		return $allow_meta_key_list;
	}

	/**
	 * Check if a value match some pattern
	 *
	 * @param {array}  $patterns The patterns
	 * @param {string} $value The value to be verified
	 * @return bool
	 */
	private function is_match_some_pattern( $patterns, $value ) {
		foreach ( $patterns as $pattern ) {
			if ( $this->is_match( $value, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get allowed meta key patterns
	 *
	 * @return array
	 */
	private function get_allowed_meta_key_patterns() {
		$settings = $this->get_settings();

		$allowed_patterns = preg_split( "/\r\n|\n|\r/", $settings['meta_key_allow_pattern'] );

		$allowed_patterns = array_map(
			function( $pattern ) {
				return preg_replace( '/\|[0-9]+$/', '', $pattern );
			},
			$allowed_patterns
		);

		return $allowed_patterns;
	}

	/**
	 * Exclude meta key patterns
	 *
	 * @param  array $meta Existing post meta.
	 * @return array
	 */
	public function exclude_meta_key_patterns( $meta ) {
		$denied_patterns = $this->get_denied_meta_key_patterns();

		if ( ! empty( $denied_patterns ) ) {
			foreach ( $meta as $meta_key => $meta_value ) {
				foreach ( $denied_patterns as $pattern ) {
					if ( $this->is_match( $meta_key, $pattern ) ) {
						unset( $meta[ $meta_key ] );
						break;
					}
				}
			}
		}

		return $meta;
	}

	/**
	 * Get denied meta key patterns
	 *
	 * @return array
	 */
	private function get_denied_meta_key_patterns() {
		$settings = $this->get_settings();

		$denied_patterns = preg_split( "/\r\n|\n|\r/", $settings['meta_key_deny_pattern'] );

		return $denied_patterns;
	}

	/**
	 * Update the weighting configuration for search
	 *
	 * @param  {array} $weight_config Current weight config
	 * @return array
	 */
	public function update_weighting_configuration_for_search( $weight_config ) {
		global $wpdb;

		$post_meta = get_transient( 'custom_ep_distinct_post_meta' );

		if ( ! $post_meta ) {

			$meta_keys = $wpdb->get_col(
				"SELECT DISTINCT meta_key
            	FROM $wpdb->postmeta"
			);

			$post_meta = $this->get_allowed_meta_key_list( $meta_keys );

			set_transient( 'custom_ep_distinct_post_meta', $post_meta );
		}

		if ( empty( $weight_config ) ) {
			$search     = \ElasticPress\Features::factory()->get_registered_feature( 'search' );
			$post_types = $search->get_searchable_post_types();
			$weighting  = new \ElasticPress\Feature\Search\Weighting();

			foreach ( $post_types as $post_type ) {
				$weight_config[ $post_type ] = $weighting->get_post_type_default_settings( $post_type );
			}
		}

		foreach ( $weight_config as $post_type => $fields ) {
			foreach ( $post_meta as $meta_key ) {
				$weight_config[ $post_type ][ "meta.{$meta_key}.value" ] = [
					'enabled' => 1,
					'weight'  => $this->get_weight_by_meta_key( $meta_key ),
				];
			}
		}

		return $weight_config;
	}

	/**
	 * Detele transient on meta key update
	 *
	 * @param {int}    $meta_id ID of metadata entry to update
	 * @param {int}    $object_id Post ID
	 * @param {string} $meta_key Metadata key
	 * @return void
	 */
	public function delete_transient_on_meta_update( $meta_id, $object_id, $meta_key ) {
		$meta_key_patterns = $this->get_allowed_meta_key_patterns();

		if ( ! empty( $meta_key_patterns ) ) {
			if ( $this->is_match_some_pattern( $meta_key_patterns, $meta_key ) ) {
				delete_transient( 'custom_ep_distinct_post_meta' );
			}
		}
	}

	/**
	 * Get weight by metadata key
	 *
	 * @param {string} $meta_key Metadata key
	 * @return int
	 */
	private function get_weight_by_meta_key( $meta_key ) {
		$patterns        = $this->get_allowed_meta_key_patterns();
		$pattern_matched = '';
		$default_weight  = 1;

		foreach ( $patterns as $pattern ) {
			if ( $this->is_match( $meta_key, $pattern ) ) {
				$pattern_matched = $pattern;
				break;
			}
		}

		if ( ! empty( $pattern_matched ) ) {
			$weight = $this->get_weight_pattern( $pattern_matched );
		}

		return $weight ? $weight : $default_weight;
	}

	/**
	 * Get weight of the pattern
	 *
	 * @param {string} $pattern Pattern to retrieve
	 * @return int|boolean
	 */
	private function get_weight_pattern( $pattern ) {
		$settings = $this->get_settings();

		$save_patterns = preg_split( "/\r\n|\n|\r/", $settings['meta_key_allow_pattern'] );

		foreach ( $save_patterns as $save_pattern ) {
			if ( $pattern === $save_pattern ) {
				break;
			}

			if ( preg_match( '/^' . preg_quote( $pattern, '/' ) . '/', $save_pattern ) ) {
				$weight = (int) str_replace( $pattern . '|', '', $save_pattern );

				return $weight ? $weight : false;
			}
		}

		return false;
	}

	/**
	 * Check if value match the regular expression
	 *
	 * @param {string} $value The value to be verified
	 * @param {string} $pattern The regular expression
	 * @return boolean
	 */
	private function is_match( $value, $pattern ) {
		$pattern = trim( $pattern );

		return ! empty( $pattern ) && '//' !== $pattern && preg_match( $this->prepare_regex( $pattern ), $value );
	}

	/**
	 * Check if the regular expression has delimiters
	 *
	 * @param {string} $value A regular expression
	 * @return boolean
	 */
	private function has_delimiters( $value ) {
		return ! empty( $value ) && '/' === $value[0] && '/' === $value[-1];
	}

	/**
	 * Remove delimiter from a regular expression
	 *
	 * @param {string} $value A regular expression
	 * @return boolean
	 */
	private function remove_delimiters( $value ) {
		return substr( $value, 1, -1 );
	}

	/**
	 * Prepare a regular expression to be used
	 *
	 * @param {string} $value A regular expression
	 * @return boolean
	 */
	private function prepare_regex( $value ) {
		if ( $this->has_delimiters( $value ) ) {
			$value = $this->remove_delimiters( $value );
		}

		return '/' . str_replace( '/', '\/', $value ) . '/';
	}

	/**
	 * Do actions after save the settings
	 *
	 * @return void
	 */
	public function after_save_settings() {
		if ( ! check_ajax_referer( 'epl_nonce', 'nonce', false ) ) {
			wp_send_json_error();
			exit;
		}

		delete_transient( 'custom_ep_distinct_post_meta' );
		update_site_option( 'epl_last_save_meta_key_patterns', time() );

		wp_send_json_success();
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return array $status Status array
	 * @since 2.4
	 */
	public function requirements_status() {
		$last_save = get_site_option( 'epl_last_save_meta_key_patterns' );
		$last_sync = get_site_option( 'ep_last_sync' );

		$status = new ElasticPress\FeatureRequirementsStatus( 0 );
		if ( ! isset( $_GET['do_sync'] ) && $last_save > $last_sync ) { // phpcs:ignore WordPress.Security.NonceVerification
			$status->message = [];
			$status->code    = 1;

			if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
				$url = admin_url( 'network/admin.php?page=elasticpress&do_sync' );
			} else {
				$url = admin_url( 'admin.php?page=elasticpress&do_sync' );
			}

			$status->message[] = sprintf(
				/* translators: Sync Page URL */
				__( 'You will need to <a href="%1$s">run a sync</a> to update your index.', 'elasticpress-labs' ),
				esc_url( $url )
			);
		}
		return $status;
	}

}
