<?php
/**
 * Geo Location Feature
 *
 * @package ElasticPressLabs
 * @since 2.4.0
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPressLabs\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * GeoLocation feature.
 */
class GeoLocation extends Feature {
	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'geo_location';

		$this->requires_install_reindex = true;

		$this->title = esc_html__( 'Geo Location', 'elasticpress-labs' );

		$this->summary = '<p>' . __( 'Geo Location feature allows you to search for posts based on their location.', 'elasticpress-labs' ) . '</p>';

		parent::__construct();
	}

	/**
	 * Setup all feature hooks
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( empty( $settings['active'] ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
		add_action( 'init', [ $this, 'register_meta' ] );

		add_filter( 'ep_post_mapping', [ $this, 'add_mapping' ], 20, 2 );
		add_filter( 'ep_post_sync_args', [ $this, 'add_post_sync_args' ], 10, 2 );
		add_filter( 'ep_formatted_args', [ $this, 'formatted_args' ], 10, 2 );
	}

	/**
	 * Enqueue admin scripts
	 */
	public function admin_scripts(): void {
		wp_enqueue_script(
			'ep_geo_location_script',
			ELASTICPRESS_LABS_URL . 'dist/js/geo-location-script.js',
			Utils\get_asset_info( 'geo-location-script', 'dependencies' ),
			Utils\get_asset_info( 'geo-location-script', 'version' ),
			true
		);

		wp_set_script_translations( 'ep_geo_location_script', 'elasticpress-labs' );

		$settings = $this->get_settings();

		wp_localize_script(
			'ep_geo_location_script',
			'epGeoLocation',
			[
				'has_map_key' => ! empty( $settings['google_maps_api_key'] ),
			]
		);

		if ( ! empty( $settings['google_maps_api_key'] ) ) {
			$google_places_api_url = add_query_arg(
				[
					'key'       => $settings['google_maps_api_key'],
					'libraries' => 'places',
				],
				'https://maps.googleapis.com/maps/api/js'
			);

			wp_enqueue_script(
				'google-places-api',
				$google_places_api_url,
				[],
				ELASTICPRESS_LABS_VERSION,
				true
			);
		}
	}

	/**
	 * Set the `settings_schema` attribute
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
			[
				'default' => '',
				'help'    => __( 'Providing a Google Maps API key enables an autocomplete address field that automatically fetches the latitude and longitude.', 'elasticpress-labs' ),
				'key'     => 'google_maps_api_key',
				'label'   => __( 'Google Maps API Key', 'elasticpress-labs' ),
				'type'    => 'text',
			],
		];
	}

	/**
	 * Register meta fields
	 */
	public function register_meta(): void {
		register_post_meta(
			'',
			'ep_latitude',
			[
				'type'         => 'number',
				'description'  => esc_html__( 'Latitude', 'elasticpress-labs' ),
				'single'       => true,
				'show_in_rest' => true,
			]
		);

		register_post_meta(
			'',
			'ep_longitude',
			[
				'type'         => 'number',
				'description'  => esc_html__( 'Longitude', 'elasticpress-labs' ),
				'single'       => true,
				'show_in_rest' => true,
			]
		);

		register_post_meta(
			'',
			'ep_address',
			[
				'type'         => 'string',
				'description'  => esc_html__( 'Address', 'elasticpress-labs' ),
				'single'       => true,
				'show_in_rest' => true,
			]
		);
	}

	/**
	 * Add mapping for geo_point.
	 *
	 * @param array $mapping Mapping.
	 * @return array Mapping.
	 */
	public function add_mapping( $mapping ): array {
		$mapping['mappings']['properties']['geo_point'] = [
			'properties' => [
				'location' => [
					'type'             => 'geo_point',
					'ignore_malformed' => true,
				],
			],
		];

		return $mapping;
	}

	/**
	 * Add geo_point to post sync args.
	 *
	 * @param array   $post_args Post arguments.
	 * @param integer $post_id   Post ID.
	 * @return array Post sync args.
	 */
	public function add_post_sync_args( $post_args, $post_id ): array {
		$geo_points = [
			'location' => [
				'lat' => (float) get_post_meta( $post_id, 'ep_latitude', true ),
				'lon' => (float) get_post_meta( $post_id, 'ep_longitude', true ),
			],
		];

		/**
			* Filter the geo points before they are added to the post sync args.
			*
			* @since 2.4.0
			* @hook ep_geo_location_geo_points
			* @param {array} $geo_points Geo points.
			* @param {array} $post_args Post args.
			* @param {int} $post_id Post ID.
			* @return {array} Geo points.
			*/
		$geo_points = apply_filters( 'ep_geo_location_geo_points', $geo_points, $post_args, $post_id );

		// bail if no latitude or longitude.
		if ( empty( $geo_points['location']['lat'] ) || empty( $geo_points['location']['lon'] ) ) {
			return $post_args;
		}

		$post_args['geo_point'] = $geo_points;

		return $post_args;
	}

	/**
	 * Add geo_distance to post filter
	 *
	 * @param array $formatted_args Formatted args.
	 * @param array $args           Args.
	 * @return array Formatted args.
	 */
	public function formatted_args( $formatted_args, $args ) {
		// Add geo_distance filter if provided
		if ( isset( $args['geo_distance'] ) ) {
			$formatted_args['post_filter']['bool']['filter']['geo_distance'] = $args['geo_distance'];
		}

		// Process sorting by geo_distance
		if ( ! empty( $formatted_args['sort'] ) ) {
			$formatted_args['sort'] = $this->process_geo_distance_sort( $formatted_args['sort'], $args );
		}

		return $formatted_args;
	}

	/**
	 * Process sorting by geo_distance.
	 *
	 * @param array $sort The sort array from formatted_args.
	 * @param array $args The original query args.
	 * @return array The updated sort array.
	 */
	protected function process_geo_distance_sort( $sort, $args ) {
		foreach ( $sort as $key => &$sort_item ) {
			if ( isset( $sort_item['geo_distance'] ) ) {
				// Rename 'geo_distance' to '_geo_distance'
				$sort_item['_geo_distance'] = $sort_item['geo_distance'];

				// Add geo_point.location if provided in args
				if ( isset( $args['geo_distance']['geo_point.location'] ) ) {
					$sort_item['_geo_distance']['geo_point.location'] = $args['geo_distance']['geo_point.location'];
				}

				// Remove the old 'geo_distance' key
				unset( $sort_item['geo_distance'] );
			}
		}

		return $sort;
	}

	/**
	 * Get coordinates for an address.
	 *
	 * @param string $address Address.
	 * @return array Coordinates.
	 */
	public function get_coordinates( $address ): array {
		$settings = $this->get_settings();
		if ( empty( $settings['google_maps_api_key'] ) ) {
			return [];
		}

		$url = add_query_arg(
			[
				'address' => rawurldecode( $address ),
				'key'     => $settings['google_maps_api_key'],
			],
			'https://maps.googleapis.com/maps/api/geocode/json'
		);

		$response = wp_remote_get( $url );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );
		if ( empty( $data->results ) ) {
			return [];
		}

		$location = $data->results[0]->geometry->location;
		return [
			'lat' => $location->lat,
			'lon' => $location->lng,
		];
	}
}
