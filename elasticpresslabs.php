<?php
/**
 * Plugin Name:       ElasticPress Labs
 * Plugin URI:        https://github.com/10up/ElasticPressLabs
 * Description:       A developer focused interface to commonly ElasticPress plugin issues.
 * Version:           2.3.0
 * Requires at least: 5.6
 * Requires PHP:      7.0
 * Author:            10up
 * Author URI:        https://10up.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elasticpress-labs
 * Domain Path:       /languages
 * Update URI:        https://github.com/10up/ElasticPressLabs
 *
 * @package           ElasticPressLabs
 */

// Useful global constants.
define( 'ELASTICPRESS_LABS_VERSION', '2.3.0' );
define( 'ELASTICPRESS_LABS_URL', plugin_dir_url( __FILE__ ) );
define( 'ELASTICPRESS_LABS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ELASTICPRESS_LABS_INC', ELASTICPRESS_LABS_PATH . 'includes/' );
define( 'ELASTICPRESS_LABS_MAIN_FILE', __FILE__ );

define( 'ELASTICPRESS_LABS_MIN_EP_VERSION', '4.3.0' );

/**
 * Generate a notice if autoload fails.
 *
 * @since 2.1.1
 */
function ep_labs_autoload_notice() {
	$message = esc_html__( 'Error: Please run $ composer install in the ElasticPress Labs plugin directory.', 'elasticpress-labs' );
	printf( '<div class="notice notice-error"><p>%s</p></div>', $message ); // @codingStandardsIgnoreLine Text is escaped in the variable already.
	error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

// Require the autoloader if it exists. Do not run any other code otherwise
if ( file_exists( ELASTICPRESS_LABS_PATH . '/vendor/autoload.php' ) ) {
	require_once ELASTICPRESS_LABS_PATH . 'vendor/autoload.php';
} else {
	add_action( 'admin_notices', 'ep_labs_autoload_notice' );
	return;
}

// Include files.
require_once ELASTICPRESS_LABS_INC . 'functions/core.php';
require_once ELASTICPRESS_LABS_INC . 'functions/utils.php';

// Activation/Deactivation.
register_activation_hook( __FILE__, '\ElasticPressLabs\Core\activate' );
register_deactivation_hook( __FILE__, '\ElasticPressLabs\Core\deactivate' );

// Bootstrap.
ElasticPressLabs\Core\setup();
