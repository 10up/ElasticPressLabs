<?php
/**
 * A feature whose settings screen can test its connection to an external service.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Features with a "Test connection" button (see ConnectionTest).
 */
interface ConnectionTestable {
	/**
	 * The settings the test reads. Their unsaved values on the settings screen are sent with
	 * the test, so a connection can be checked before it is saved.
	 *
	 * @return string[] Setting keys.
	 */
	public function get_connection_fields(): array;

	/**
	 * Send one small request with these settings.
	 *
	 * @param array $settings The feature's settings, with the unsaved connection fields.
	 * @return string|\WP_Error A short success message, or what went wrong.
	 */
	public function test_connection( array $settings );
}
