<?php
/**
 * Plugin Name:       ElasticPress Labs
 * Plugin URI:        https://github.com/10up/ElasticPressLabs
 * Description:       A developer focused interface to commonly ElasticPress plugin issues.
 * Version:           2.0.0
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
define( 'ELASTICPRESS_LABS_VERSION', '2.0.0' );
define( 'ELASTICPRESS_LABS_URL', plugin_dir_url( __FILE__ ) );
define( 'ELASTICPRESS_LABS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ELASTICPRESS_LABS_INC', ELASTICPRESS_LABS_PATH . 'includes/' );

define( 'ELASTICPRESS_LABS_MIN_EP_VERSION', '4.3.0' );

/**
 * PSR-4-ish autoloading
 *
 * @since 2.1
 */
spl_autoload_register(
	function( $class ) {
		// project-specific namespace prefix.
		$prefix = 'ElasticPressLabs\\';

		// base directory for the namespace prefix.
		$base_dir = __DIR__ . '/includes/classes/';

		// does the class use the namespace prefix?
		$len = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );

		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// if the file exists, require it.
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

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
