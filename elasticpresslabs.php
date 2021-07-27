<?php
/**
 * Plugin Name:       ElasticPress Labs
 * Plugin URI:        https://github.com/10up/ElasticPressLabs
 * Description:       A developer focused interface to commonly ElasticPress plugin issues.
 * Version:           1.1.0
 * Requires at least: 4.9
 * Requires PHP:      7.2
 * Author:            10up
 * Author URI:        https://10up.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elasticpress-labs
 * Domain Path:       /languages
 *
 * @package           ElasticPressLabs
 */

// Useful global constants.
define( 'ELASTICPRESS_LABS_VERSION', '1.1.0' );
define( 'ELASTICPRESS_LABS_URL', plugin_dir_url( __FILE__ ) );
define( 'ELASTICPRESS_LABS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ELASTICPRESS_LABS_INC', ELASTICPRESS_LABS_PATH . 'includes/' );

// Include files.
require_once ELASTICPRESS_LABS_INC . 'functions/core.php';

// Activation/Deactivation.
register_activation_hook( __FILE__, '\ElasticPressLabs\Core\activate' );
register_deactivation_hook( __FILE__, '\ElasticPressLabs\Core\deactivate' );

// Bootstrap.
ElasticPressLabs\Core\setup();

// Require Composer autoloader if it exists.
if ( file_exists( ELASTICPRESS_LABS_PATH . '/vendor/autoload.php' ) ) {
	require_once ELASTICPRESS_LABS_PATH . 'vendor/autoload.php';
}

/**
 * Load the ElasticPress Feature
 *
 * @return void
 */
function load_my_elasticpress_feature() {
	if ( class_exists( '\ElasticPress\Features' ) ) {
		// Include your class file.
		require ELASTICPRESS_LABS_INC . 'classes/Feature/ElasticPressLabs.php';
		// Register your feature in ElasticPress.
		\ElasticPress\Features::factory()->register_feature(
			new ElasticPressLabs()
		);
	}
}
add_action( 'plugins_loaded', 'load_my_elasticpress_feature', 11 );
