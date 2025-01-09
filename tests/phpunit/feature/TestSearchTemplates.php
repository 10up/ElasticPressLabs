<?php
/**
 * Test the search templates feature
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\SearchTemplates;

/**
 * SearchTemplates test class
 */
class TestSearchTemplates extends \WP_UnitTestCase {
	/**
	 * Setup each test.
	 */
	public function set_up() {
		$instance = new SearchTemplates();
		\ElasticPress\Features::factory()->register_feature( $instance );
	}

	/**
	 * Get the Search Templates feature instance
	 *
	 * @return SearchTemplates
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'search_templates' );
	}

	/**
	 * Test the `requirements_status` method
	 *
	 * @group search-templates
	 */
	public function test_requirements_status() {
		// Should return 2 when not ep.io
		$status = $this->get_feature()->requirements_status();

		$this->assertEquals( 2, $status->code );

		// Should return 1 if ep.io
		$ep_host = function () {
			return 'elasticpress.io/random-string';
		};
		add_filter( 'ep_host', $ep_host );
		$status = $this->get_feature()->requirements_status();

		$this->assertEquals( 1, $status->code );
	}

	/**
	 * Test the `is_search_templates_page` method
	 *
	 * @group search-templates
	 */
	public function test_is_search_templates_page() {
		set_current_screen();
		$this->assertFalse( $this->get_feature()->is_search_templates_page() );

		set_current_screen( 'elasticpress_page_elasticpress-search-templates' );
		$this->assertTrue( $this->get_feature()->is_search_templates_page() );
	}

	/**
	 * Test the `setup_endpoint` method
	 *
	 * @group search-templates
	 */
	public function test_setup_endpoint() {
		$wp_rest_server = rest_get_server();

		$routes = $wp_rest_server->get_routes( 'elasticpress-labs/v1' );
		$this->assertEmpty( $routes );

		$this->get_feature()->setup_endpoint();

		$routes = $wp_rest_server->get_routes( 'elasticpress-labs/v1' );
		$this->assertSame(
			[
				'/elasticpress-labs/v1',
				'/elasticpress-labs/v1/search-templates',
				'/elasticpress-labs/v1/search-templates/(?P<template_name>[\w-]+)',
			],
			array_keys( $routes )
		);
	}

	/**
	 * Test the `set_settings_schema` method
	 *
	 * @group search-templates
	 */
	public function test_set_settings_schema() {
		$expected = [
			[
				'default'          => false,
				'key'              => 'active',
				'label'            => 'Enable',
				'requires_feature' => false,
				'requires_sync'    => false,
				'type'             => 'toggle',
			],
			[
				'key'   => 'additional_links',
				'label' => '<a href="http://example.org/wp-admin/admin.php?page=elasticpress-search-templates">Manage search templates</a>',
				'type'  => 'markup',
			],
		];

		$this->assertSame( $expected, $this->get_feature()->get_settings_schema() );
	}
}
