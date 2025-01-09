<?php
/**
 * Test search templates REST controller
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest\REST;

use ElasticPressLabs\REST\SearchTemplates;

/**
 * SearchTemplates test class
 */
class TestSearchTemplates extends \WP_UnitTestCase {
	/**
	 * Controller instance
	 *
	 * @var SearchTemplates
	 */
	protected $controller;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		$this->controller = new SearchTemplates();
	}

	/**
	 * Test the `get_search_templates` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_templates() {
		$this->markTestIncomplete();
	}
	/**
	 * Test the `get_search_template` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_template() {
		$this->markTestIncomplete();
	}
	/**
	 * Test the `update_search_template` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_update_search_template() {
		$this->markTestIncomplete();
	}
	/**
	 * Test the `delete_search_template` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_delete_search_template() {
		$this->markTestIncomplete();
	}
}
