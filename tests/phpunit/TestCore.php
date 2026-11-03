<?php
/**
 * Test the core functions
 *
 * @since 2.5.2
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use function ElasticPressLabs\Core\add_screenshots_to_plugin_info;

/**
 * Core functions test class
 */
class TestCore extends BaseTestCase {
	/**
	 * The screenshot captions get the images of the version being described.
	 *
	 * @group core
	 */
	public function test_add_screenshots_to_plugin_info() {
		$plugin_info           = new \stdClass();
		$plugin_info->version  = '2.5.2';
		$plugin_info->sections = [
			'screenshots' => "<ol>\n<li>First <code>caption</code>.</li>\n<li>Second caption.</li>\n</ol>",
		];

		$plugin_info = add_screenshots_to_plugin_info( $plugin_info );

		$base_url = 'https://raw.githubusercontent.com/10up/ElasticPressLabs/2.5.2/.wordpress-org/';

		$this->assertSame(
			"<ol>\n" .
			'<li><a href="' . $base_url . 'screenshot-1.png"><img src="' . $base_url . 'screenshot-1.png" alt="First caption."></a><p>First <code>caption</code>.</p></li>' . "\n" .
			'<li><a href="' . $base_url . 'screenshot-2.png"><img src="' . $base_url . 'screenshot-2.png" alt="Second caption."></a><p>Second caption.</p></li>' . "\n" .
			'</ol>',
			$plugin_info->sections['screenshots']
		);
	}

	/**
	 * Without screenshots or a version, the plugin information does not change.
	 *
	 * @group core
	 */
	public function test_add_screenshots_to_plugin_info_without_screenshots() {
		$plugin_info           = new \stdClass();
		$plugin_info->version  = '2.5.2';
		$plugin_info->sections = [ 'description' => 'Description.' ];

		$this->assertSame( [ 'description' => 'Description.' ], add_screenshots_to_plugin_info( $plugin_info )->sections );

		$plugin_info           = new \stdClass();
		$plugin_info->sections = [ 'screenshots' => '<ol><li>Caption.</li></ol>' ];

		$this->assertSame( '<ol><li>Caption.</li></ol>', add_screenshots_to_plugin_info( $plugin_info )->sections['screenshots'] );
	}
}
