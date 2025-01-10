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
class TestSearchTemplates extends \ElasticPressLabsTest\BaseTestCase {
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
		parent::set_up();

		$this->controller = new SearchTemplates();
		add_filter( 'ep_intercept_remote_request', '__return_true' );
	}

	/**
	 * Make sure access is restricted to admin users
	 *
	 * @param string $method   HTTP method.
	 * @param int    $expected HTTP expected status code
	 * @param string $path     Endpoint path
	 * @group rest
	 * @group search-templates
	 * @dataProvider data_provider_endpoints_access
	 */
	public function test_endpoints_access( $method, $expected, $path ) {
		global $wp_rest_server;

		\ElasticPress\Features::factory()->activate_feature( 'search_templates' );
		\ElasticPress\Features::factory()->setup_features();

		$return_http_code = function () use ( $expected ) {
			return [
				'response' => [
					'code'    => $expected,
					'message' => 'Testing message',
				],
			];
		};
		add_filter( 'ep_do_intercept_request', $return_http_code );

		$rest_request = new \WP_REST_Request( $method, $path );
		$response     = rest_do_request( $rest_request );
		$this->assertSame( 401, $response->get_status(), "{$method}::{$path}" );

		$admin_id = $this->ep_factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$rest_request = new \WP_REST_Request( $method, $path );
		$response     = rest_do_request( $rest_request );
		$this->assertSame( $expected, $response->get_status(), "{$method}::{$path}" );

		// Reset the endpoints, so it does not interfere with other tests
		$wp_rest_server = null;
	}

	/**
	 * Data provider for the test_endpoints_access method
	 *
	 * @return array
	 */
	public function data_provider_endpoints_access() {
		return [
			[
				'method'   => 'GET',
				'expected' => 200,
				'path'     => '/elasticpress-labs/v1/search-templates',
			],
			[
				'method'   => 'GET',
				'expected' => 200,
				'path'     => '/elasticpress-labs/v1/search-templates/template1',
			],
			[
				'method'   => 'PUT',
				'expected' => 201,
				'path'     => '/elasticpress-labs/v1/search-templates/template1',
			],
			[
				'method'   => 'DELETE',
				'expected' => 204,
				'path'     => '/elasticpress-labs/v1/search-templates/template1',
			],
		];
	}

	/**
	 * Test the `get_search_templates` method with a generic error
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_templates_generic_error() {
		$generic_wp_error = $this->send_generic_error();
		$this->assertSame( $generic_wp_error, $this->controller->get_search_templates() );
	}

	/**
	 * Test the `get_search_templates` method with an invalid response code
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_templates_invalid_status_code() {
		$this->send_invalid_http_status_code();

		$error = $this->controller->get_search_templates();
		$this->assertEquals( 'invalid_response', $error->get_error_code() );
		$this->assertEquals( 'Testing message', $error->get_error_message() );
	}

	/**
	 * Test the `get_search_templates` method with a proper response
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_templates() {
		$return_http_code = function () {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{"exampleorg-post-1":["template1","template2"], "exampleorg-post-2":["template3","template4"]}',
			];
		};
		add_filter( 'ep_do_intercept_request', $return_http_code );

		$this->assertSame( [ 'template1', 'template2' ], $this->controller->get_search_templates() );
	}

	/**
	 * Test the `get_search_template` method with a generic error
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_template_generic_error() {
		$generic_wp_error = $this->send_generic_error();
		$this->assertSame( $generic_wp_error, $this->controller->get_search_template( new \WP_REST_Request() ) );
	}

	/**
	 * Test the `get_search_template` method with an invalid response code
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_template_invalid_status_code() {
		$this->send_invalid_http_status_code();

		$error = $this->controller->get_search_template( new \WP_REST_Request() );
		$this->assertEquals( 'invalid_response', $error->get_error_code() );
		$this->assertEquals( 'Testing message', $error->get_error_message() );
	}

	/**
	 * Test the `get_search_template` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_get_search_template() {
		$request = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/search-templates/template1' );
		$request->set_param( 'template_name', 'template1' );

		$return_http_code = function ( $response, $query ) {
			$parts = wp_parse_url( $query['url'] );
			parse_str( $parts['query'], $query );

			$this->assertSame( 'template1', $query['template_name'] );

			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{"a": "b"}',
			];
		};
		add_filter( 'ep_do_intercept_request', $return_http_code, 10, 2 );

		$this->assertEquals( (object) [ 'a' => 'b' ], $this->controller->get_search_template( $request ) );
	}

	/**
	 * Test the `update_search_template` method with a generic error
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_update_search_template_generic_error() {
		$generic_wp_error = $this->send_generic_error();
		$this->assertSame( $generic_wp_error, $this->controller->update_search_template( new \WP_REST_Request() ) );
	}

	/**
	 * Test the `update_search_template` method with an invalid response code
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_update_search_template_invalid_status_code() {
		$this->send_invalid_http_status_code();

		$error = $this->controller->update_search_template( new \WP_REST_Request() );
		$this->assertEquals( 'invalid_response', $error->get_error_code() );
		$this->assertEquals( 'Testing message', $error->get_error_message() );
	}

	/**
	 * Test the `update_search_template` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_update_search_template() {
		$request = new \WP_REST_Request( 'PUT', '/elasticpress-labs/v1/search-templates/template1' );
		$request->set_param( 'template_name', 'template1' );

		$return_http_code = function ( $response, $query ) {
			$parts = wp_parse_url( $query['url'] );
			parse_str( $parts['query'], $query );

			$this->assertSame( 'template1', $query['template_name'] );

			return [
				'response' => [ 'code' => 201 ],
				'body'     => '{"a": "b"}',
			];
		};
		add_filter( 'ep_do_intercept_request', $return_http_code, 10, 2 );

		$this->assertEquals( (object) [ 'a' => 'b' ], $this->controller->update_search_template( $request )->get_data() );
	}

	/**
	 * Test the `delete_search_template` method with a generic error
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_delete_search_template_generic_error() {
		$generic_wp_error = $this->send_generic_error();
		$this->assertSame( $generic_wp_error, $this->controller->delete_search_template( new \WP_REST_Request() ) );
	}

	/**
	 * Test the `delete_search_template` method with an invalid response code
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_delete_search_template_invalid_status_code() {
		$this->send_invalid_http_status_code();

		$error = $this->controller->delete_search_template( new \WP_REST_Request() );
		$this->assertEquals( 'invalid_response', $error->get_error_code() );
		$this->assertEquals( 'Testing message', $error->get_error_message() );
	}

	/**
	 * Test the `delete_search_template` method
	 *
	 * @group rest
	 * @group search-templates
	 */
	public function test_delete_search_template() {
		$request = new \WP_REST_Request( 'DELETE', '/elasticpress-labs/v1/search-templates/template1' );
		$request->set_param( 'template_name', 'template1' );

		$return_http_code = function ( $response, $query ) {
			$parts = wp_parse_url( $query['url'] );
			parse_str( $parts['query'], $query );

			$this->assertSame( 'template1', $query['template_name'] );

			return [
				'response' => [ 'code' => 204 ],
				'body'     => '',
			];
		};
		add_filter( 'ep_do_intercept_request', $return_http_code, 10, 2 );

		$this->assertEquals( '', $this->controller->delete_search_template( $request )->get_data() );
	}

	/**
	 * Send a generic WP Error
	 *
	 * @return \WP_Error
	 */
	protected function send_generic_error() {
		$generic_wp_error = new \WP_Error( '0', 'Generic error' );

		$return_callback = function () use ( $generic_wp_error ) {
			return $generic_wp_error;
		};
		add_filter( 'ep_do_intercept_request', $return_callback );

		return $generic_wp_error;
	}

	/**
	 * Send a 500 HTTP response
	 *
	 * @return void
	 */
	protected function send_invalid_http_status_code() {
		$return_http_code = function () {
			return [
				'response' => [
					'code'    => 500,
					'message' => 'Testing message',
				],
			];
		};
		add_filter( 'ep_do_intercept_request', $return_http_code );
	}
}
