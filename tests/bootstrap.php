<?php
/**
 * ElasticPress Labs test bootstrap
 *
 * @since 2.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

if ( ! file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
	throw new PHPUnit_Framework_Exception(
		'ERROR' . PHP_EOL . PHP_EOL .
		'You must use Composer to install the test suite\'s dependencies!' . PHP_EOL
	);
}

require_once __DIR__ . '/../vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Make sure we only test on 1 shard because any more will lead to inconsitent results
 *
 * @since 2.1.0
 */
function test_shard_number() {
	return 1;
}

/**
 * Bootstrap EP Labs plugin
 *
 * @since 2.1.0
 */
function load_plugin() {
	$host = getenv( 'EP_HOST' );

	if ( empty( $host ) ) {
		$host = 'http://127.0.0.1:9200';
	}

	include_once __DIR__ . '/../vendor/woocommerce/woocommerce.php';

	update_option( 'ep_host', $host );
	update_site_option( 'ep_host', $host );

	add_filter( 'ep_default_index_number_of_shards', __NAMESPACE__ . '\test_shard_number' );

	$tries = 5;
	$sleep = 3;

	do {
		$response = wp_remote_get( $host );
		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			// Looks good!
			break;
		} else {
			printf( "\nInvalid response from ES, sleeping %d seconds and trying again...\n", intval( $sleep ) );
			sleep( $sleep );
		}
	} while ( --$tries );

	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		exit( 'Could not connect to ElasticPress server.' );
	}
}
tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\load_plugin' );

/**
 * Setup WooCommerce for tests
 *
 * @since 2.1.0
 */
function setup_wc() {
	if ( ! class_exists( '\WC_Install' ) ) {
		return;
	}

	define( 'WP_UNINSTALL_PLUGIN', true );

	update_option( 'woocommerce_status_options', array( 'uninstall_data' => 1 ) );
	include_once __DIR__ . '/../vendor/woocommerce/uninstall.php';

	\WC_Install::install();

	$GLOBALS['wp_roles'] = new \WP_Roles();

	echo 'Installing WooCommerce version ' . \WC()->version . ' ...' . PHP_EOL; // phpcs:ignore
}
tests_add_filter( 'setup_theme', __NAMESPACE__ . '\setup_wc' );

/**
 * Completely skip looking up translations
 *
 * @since 2.1.0
 * @return array
 */
function skip_translations_api() {
	return [
		'translations' => [],
	];
}
tests_add_filter( 'translations_api', __NAMESPACE__ . '\skip_translations_api' );

require_once $_tests_dir . '/includes/functions.php';
require_once $_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/phpunit/BaseTestCase.php';
require_once __DIR__ . '/phpunit/factory/UserFactory.php';