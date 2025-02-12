<?php
/**
 * Test Geo Location feature
 *
 * @since 2.3.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress;
use ElasticPress\Features;
use ElasticPressLabs\Feature\GeoLocation;

/**
 * Geo Location test class
 */
class TestGeoLocation extends BaseTestCase {

	/**
	 * Setup each test
	 */
	public function set_up() {
		global $wpdb;
		parent::set_up();
		$wpdb->suppress_errors();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		ElasticPress\Indexables::factory()->get( 'post' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'post' )->sync_manager->reset_sync_queue();

		$instance = new GeoLocation();
		Features::factory()->register_feature( $instance );
		Features::factory()->activate_feature( 'geo_location' );
		Features::factory()->setup_features();
	}

	/**
	 * Get External Content feature
	 *
	 * @return GeoLocation
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'geo_location' );
	}

	/**
	 * Test construct
	 *
	 * @group geo-location
	 */
	public function test_construct() {
		$instance = $this->get_feature();

		$this->assertEquals( 'geo_location', $instance->slug );
		$this->assertEquals( 'Geo Location', $instance->title );
	}

	/**
	 * Test requirements_status
	 *
	 * @group geo-location
	 */
	public function test_requirements_status() {
		$requirements_status = $this->get_feature()->requirements_status();

		$this->assertSame( 0, $requirements_status->code );
	}

	/**
	 * Tests if the feature requires reindexing.
	 *
	 * @group geo-location
	 */
	public function test_require_indexing() {
		$instance = $this->get_feature();

		$this->assertTrue( $instance->requires_install_reindex );
	}

	/**
	 * Test the mapping.
	 *
	 * @group geo-location
	 */
	public function test_mapping() {
		$mapping = ElasticPress\Indexables::factory()->get( 'post' )->generate_mapping();

		$expected_result = [
			'properties' => array(
				'location' => array(
					'type'             => 'geo_point',
					'ignore_malformed' => true,
				),
			),
		];
		$this->assertSame( $expected_result, $mapping['mappings']['properties']['geo_point'] );
	}

	/**
	 * Test if the geo_point exists if post have meta.
	 *
	 * @group geo-location
	 */
	public function test_geo_point_exists_if_post_have_meta() {
		$post_id = $this->ep_factory->post->create(
			[
				'meta_input' => [
					'ep_latitude'  => 10,
					'ep_longitude' => 20,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$post = \ElasticPress\Indexables::factory()->get( 'post' )->get( $post_id );

		$expected_result = [
			'location' => [
				'lat' => 10,
				'lon' => 20,
			],
		];
		$this->assertArrayHasKey( 'geo_point', $post );
		$this->assertSame( $expected_result, $post['geo_point'] );
	}

	/**
	 * Test if the geo_point not exists if post have no meta.
	 *
	 * @group geo-location
	 */
	public function test_geo_point_not_exists_if_post_have_no_meta() {
		$post_id = $this->ep_factory->post->create();
		ElasticPress\Elasticsearch::factory()->refresh_indices();
		$post = \ElasticPress\Indexables::factory()->get( 'post' )->get( $post_id );

		$this->assertArrayNotHasKey( 'geo_point', $post );
	}

	/**
	 * Test if the geo_point not exists if post have only latitude.
	 *
	 * @group geo-location
	 */
	public function test_geo_point_not_exists_if_post_have_only_latitude() {
		$post_id = $this->ep_factory->post->create(
			[
				'meta_input' => [
					'ep_latitude' => 10,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();
		$post = \ElasticPress\Indexables::factory()->get( 'post' )->get( $post_id );

		$this->assertArrayNotHasKey( 'geo_point', $post );
	}

	/**
	 * Test if the geo_point not exists if post have only longitude.
	 *
	 * @group geo-location
	 */
	public function test_geo_point_not_exists_if_post_have_only_longitude() {
		$post_id = $this->ep_factory->post->create(
			[
				'meta_input' => [
					'ep_longitude' => 20,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();
		$post = \ElasticPress\Indexables::factory()->get( 'post' )->get( $post_id );

		$this->assertArrayNotHasKey( 'geo_point', $post );
	}

	/**
	 * Tests WP_Query with geo_distance.
	 *
	 * @group geo-location
	 */
	public function test_query() {
		$this->create_test_posts();

			$query = new \WP_Query(
				[
					'ep_integrate' => true,
					'post_type'    => 'post',
					'geo_distance' => array(
						'distance'           => '1km',
						'geo_point.location' => array(
							'lat' => 40.712776,
							'lon' => -74.005974,
						),
					),
				]
			);

			$this->assertTrue( $query->elasticsearch_success );
			$this->assertEquals( 1, $query->found_posts );
	}

	/**
	 * Tests WP_Query returns post within expected distance.
	 *
	 * @group geo-location
	 */
	public function test_query_returns_post_within_expected_distance() {
		$this->create_test_posts();

		$query = new \WP_Query(
			[
				'ep_integrate' => true,
				'post_type'    => 'post',
				'geo_distance' => array(
					'distance'           => '60km',
					'geo_point.location' => array(
						'lat' => 40.712776,
						'lon' => -74.005974,
					),
				),
			]
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 3, $query->found_posts );
	}

	/**
	 * Tests WP_Query returns post within expected distance and order by distance.
	 *
	 * @group geo-location
	 */
	public function test_search_with_orderby_desc() {
		$this->create_test_posts();

		$query = new \WP_Query(
			[
				'ep_integrate' => true,
				'post_type'    => 'post',
				'geo_distance' => array(
					'distance'           => '1000km',
					'geo_point.location' => array(
						'lat' => 40.712776,
						'lon' => -74.005974,
					),
				),
				'order'        => 'desc',
				'orderby'      => 'geo_distance',
			]
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 4, $query->found_posts );
		$this->assertEquals( 'Boston', $query->posts[0]->post_title );
		$this->assertEquals( 'Stamford', $query->posts[1]->post_title );
		$this->assertEquals( 'Jersey City', $query->posts[2]->post_title );
		$this->assertEquals( 'New York City', $query->posts[3]->post_title );
	}

	/**
	 * Tests WP_Query returns post within expected distance and order by distance.
	 *
	 * @group geo-location
	 */
	public function test_search_with_orderby_asc() {
		$this->create_test_posts();

		$query = new \WP_Query(
			[
				'ep_integrate' => true,
				'post_type'    => 'post',
				'geo_distance' => array(
					'distance'           => '1000km',
					'geo_point.location' => array(
						'lat' => 40.712776,
						'lon' => -74.005974,
					),
				),
				'order'        => 'asc',
				'orderby'      => 'geo_distance',
			]
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 4, $query->found_posts );
		$this->assertEquals( 'New York City', $query->posts[0]->post_title );
		$this->assertEquals( 'Jersey City', $query->posts[1]->post_title );
		$this->assertEquals( 'Stamford', $query->posts[2]->post_title );
		$this->assertEquals( 'Boston', $query->posts[3]->post_title );
	}

	/**
	 * Tests get_coordinates method.
	 *
	 * @group geo-location
	 */
	public function test_get_coordinates() {
		// Set the Google Maps API key.
		add_filter(
			'option_ep_feature_settings',
			function ( $value ) {
				$value['geo_location']['google_maps_api_key'] = 'test_api_key';
				return $value;
			}
		);

		// Mock the HTTP request to Google Maps API.
		add_filter(
			'pre_http_request',
			function () {
				$response = [
					'response' => [
						'code' => 200,
					],
					'body'     => wp_json_encode(
						[
							'results' => [
								[
									'geometry' => [
										'location' => [
											'lat' => 40.712776,
											'lng' => -74.005974,
										],
									],
								],
							],
						]
					),
				];

				return $response;
			}
		);

		$coordinates = $this->get_feature()->get_coordinates( 'New York City' );
		$this->assertEquals( 40.712776, $coordinates['lat'] );
		$this->assertEquals( -74.005974, $coordinates['lon'] );
	}

	/**
	 * Tests get_coordinates method with no API key.
	 *
	 * @group geo-location
	 */
	public function test_get_coordinates_with_no_api_key() {
		$coordinates = $this->get_feature()->get_coordinates( 'New York City' );
		$this->assertEmpty( $coordinates );
	}

	/**
	 * Tests get_coordinates method with API error.
	 *
	 * @group geo-location
	 */
	public function test_get_coordinates_with_api_error() {
		// Set the Google Maps API key.
		add_filter(
			'option_ep_feature_settings',
			function ( $value ) {
				$value['geo_location']['google_maps_api_key'] = 'test_api_key';
				return $value;
			}
		);

		// Mock the HTTP request to Google Maps API.
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Error' );
			}
		);

		$coordinates = $this->get_feature()->get_coordinates( 'New York City' );
		$this->assertEmpty( $coordinates );
	}

	/**
	 * Tests `ep_geo_location_geo_points` filter.
	 *
	 * @group geo-location
	 */
	public function test_location_geo_points_filter() {
		add_filter(
			'ep_geo_location_geo_points',
			function ( $geo_points ) {
				$geo_points = [
					'lat' => 10000,
					'lon' => 20000,
				];

				return $geo_points;
			}
		);

		$post_id = $this->ep_factory->post->create(
			[
				'meta_input' => [
					'ep_latitude'  => 10,
					'ep_longitude' => 20,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$post = \ElasticPress\Indexables::factory()->get( 'post' )->get( $post_id );

		$expected_result = [
			'location' => [
				'lat' => 10000,
				'lon' => 20000,
			],
		];

		$this->assertArrayHasKey( 'geo_point', $post );
		$this->assertSame( $expected_result, $post['geo_point'] );
	}

	/**
	 * Tests `ep_geo_location_pre_geo_points` filter.
	 *
	 * @group geo-location
	 */
	public function test_location_pre_geo_points() {
		add_filter(
			'ep_geo_location_pre_geo_points',
			function ( $geo_points ) {
				$geo_points = [
					'lat' => 10000,
					'lon' => 20000,
				];

				return $geo_points;
			}
		);

		$post_id = $this->ep_factory->post->create(
			[
				'meta_input' => [
					'ep_latitude'  => 10,
					'ep_longitude' => 20,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$post = \ElasticPress\Indexables::factory()->get( 'post' )->get( $post_id );

		$expected_result = [
			'location' => [
				'lat' => 10000,
				'lon' => 20000,
			],
		];

		$this->assertArrayHasKey( 'geo_point', $post );
		$this->assertSame( $expected_result, $post['geo_point'] );
	}

	/**
	 * Creates test posts with geolocation data for various cities.
	 *
	 * The distances between New York City and the other cities are:
	 * - New York City -> Jersey City = 3.18 km
	 * - New York City -> Stamford = 54.57 km
	 * - New York City -> Boston = 306.11 km
	 * - New York City -> Chicago = 1,144.29 km
	 */
	protected function create_test_posts() {
		$this->ep_factory->post->create(
			[
				'post_title' => 'New York City',
				'meta_input' => [
					'ep_latitude'  => 40.712776,
					'ep_longitude' => -74.005974,
				],
			]
		);

		$this->ep_factory->post->create(
			[
				'post_title' => 'Stamford',
				'meta_input' => [
					'ep_latitude'  => 41.053430,
					'ep_longitude' => -73.538734,
				],
			]
		);

		$this->ep_factory->post->create(
			[
				'post_title' => 'Jersey City',
				'meta_input' => [
					'ep_latitude'  => 40.717754,
					'ep_longitude' => -74.043143,
				],
			]
		);

		$this->ep_factory->post->create(
			[
				'post_title' => 'Boston',
				'meta_input' => [
					'ep_latitude'  => 42.360081,
					'ep_longitude' => -71.058884,
				],
			]
		);

		$this->ep_factory->post->create(
			[
				'post_title' => 'Chicago',
				'meta_input' => [
					'ep_latitude'  => 41.878113,
					'ep_longitude' => -87.629799,
				],
			]
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();
	}
}
