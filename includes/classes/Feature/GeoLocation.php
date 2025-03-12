<?php
/**
 * Geo Location Feature
 *
 * @package ElasticPressLabs
 * @since 2.4.0
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPress\FeatureRequirementsStatus;
use ElasticPress\Features;
use ElasticPressLabs\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * GeoLocation feature.
 */
class GeoLocation extends Feature {
	/**
	 * Whether it is needed to get user coordinates or not.
	 *
	 * @var boolean
	 */
	protected $user_coordinates_needed = false;

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'geo_location';

		$this->requires_install_reindex = true;

		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.2.0', '<' ) ) {
			$this->set_i18n_strings();
		}

		parent::__construct();
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'Geo Location', 'elasticpress-labs' );

		$this->summary = '<p>' . __( 'Allow users to search for posts based on their location.', 'elasticpress-labs' ) . '</p>';
	}

	/**
	 * Set the `settings_schema` attribute
	 *
	 * @return void
	 */
	public function set_settings_schema(): void {
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
	 * Returns requirements status of feature
	 *
	 * Requires the search feature to be activated
	 *
	 * @return FeatureRequirementsStatus
	 */
	public function requirements_status() {
		/** Features Class @var Features $features */
		$features = Features::factory();

		/** Search Feature @var Feature\Search\Search $search */
		$search = $features->get_registered_feature( 'search' );

		if ( ! $search->is_active() ) {
			return new FeatureRequirementsStatus( 2, esc_html__( 'This feature requires the "Post Search" feature to be enabled', 'elasticpress-labs' ) );
		}

		return new FeatureRequirementsStatus( 1 );
	}

	/**
	 * Setup all feature hooks
	 *
	 * @return void
	 */
	public function setup(): void {
		if ( empty( $this->get_setting( 'active' ) ) ) {
			return;
		}

		// How the coordinates of the posts are stored
		add_filter( 'ep_post_mapping', [ $this, 'add_mapping' ], 20, 2 );
		add_filter( 'ep_post_sync_args', [ $this, 'add_post_sync_args' ], 10, 2 );
		add_filter( 'ep_formatted_args', [ $this, 'formatted_args' ], 10, 2 );

		// How we allow users to manage post coordinates
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
		add_action( 'init', [ $this, 'register_meta' ] );

		// How we conditionally change queries to sort (and filter) on distances
		add_action( 'pre_get_posts', [ $this, 'maybe_orderby_geo_distance' ] );

		// How we conditionally include the JS to ask for user coordinates
		add_action( 'wp_footer', [ $this, 'maybe_ask_user_coordinates' ], 19 );
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
		/**
		 * Filter the geo points before they are retrieved from the post meta.
		 *
		 * @since 2.4.0
		 * @hook ep_geo_location_pre_geo_points
		 * @param {array|false} $pre_geo_points Pre geo points.
		 * @param {array} $post_args Post args.
		 * @param {int} $post_id Post ID.
		 * @return {array|false} Pre geo points.
		 */
		$geo_points = apply_filters( 'ep_geo_location_pre_geo_points', false, $post_args, $post_id );

		if ( false === $geo_points ) {
			$geo_points = [
				'lat' => (float) get_post_meta( $post_id, 'ep_latitude', true ),
				'lon' => (float) get_post_meta( $post_id, 'ep_longitude', true ),
			];
		}

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
		$geo_points = apply_filters( 'ep_geo_location_geo_points', (array) $geo_points, $post_args, $post_id );

		// bail if no latitude or longitude.
		if ( empty( $geo_points['lat'] ) || empty( $geo_points['lon'] ) ) {
			return $post_args;
		}

		$post_args['geo_point'] = [
			'location' => [
				'lat' => $geo_points['lat'],
				'lon' => $geo_points['lon'],
			],
		];

		return $post_args;
	}

	/**
	 * Add geo_distance to post filter
	 *
	 * @param array $formatted_args Formatted args.
	 * @param array $args           Args.
	 * @return array Formatted args.
	 */
	public function formatted_args( $formatted_args, $args ): array {
		// Add geo_distance filter if provided
		if ( isset( $args['geo_distance'] ) && isset( $args['geo_distance']['distance'] ) ) {
			$formatted_args['post_filter']['bool']['filter']['geo_distance'] = $args['geo_distance'];
		}

		// Process sorting by geo_distance
		if ( ! empty( $formatted_args['sort'] ) ) {
			$formatted_args['sort'] = $this->process_geo_distance_sort( $formatted_args['sort'], $args );
		}

		return $formatted_args;
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @return void
	 */
	public function admin_scripts(): void {
		wp_enqueue_script(
			'ep_geo_location_editor_script',
			ELASTICPRESS_LABS_URL . 'dist/js/geo-location-editor-script.js',
			Utils\get_asset_info( 'geo-location-editor-script', 'dependencies' ),
			Utils\get_asset_info( 'geo-location-editor-script', 'version' ),
			true
		);

		wp_set_script_translations( 'ep_geo_location_script', 'elasticpress-labs' );

		$google_maps_api_key = $this->get_setting( 'google_maps_api_key' );

		wp_localize_script(
			'ep_geo_location_editor_script',
			'epGeoLocation',
			[
				'hasMapKey'      => ! empty( $google_maps_api_key ),
				'isExternalMeta' => has_filter( 'ep_geo_location_pre_geo_points' ),
			]
		);

		if ( ! empty( $google_maps_api_key ) ) {
			$google_places_api_url = add_query_arg(
				[
					'key'       => $google_maps_api_key,
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
	 * Register meta fields
	 *
	 * @return void
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

	/**
	 * Change search query to sort by geo_distance.
	 *
	 * @param WP_Query $query The WP_Query object.
	 * @return void
	 */
	public function maybe_orderby_geo_distance( $query ): void {
		if ( ! in_array( 'geo_distance', (array) $query->get( 'orderby' ), true ) ) {
			return;
		}

		$user_coordinates = $this->get_user_coordinates();
		if ( ! $user_coordinates ) {
			// Only ask user coordinates if the WP_Query does not have its own coords.
			if ( empty( $query->get( 'geo_distance' ) ) ) {
				$this->user_coordinates_needed = true;
			}

			return;
		}

		/**
		 * If orderby is an indexed array, like [ 'geo_distance' => 'asc ], for example,
		 * we don't need to change it. Otherwise, we try to make geo_distance ASC, but
		 * keep everything else untouched (_score, for example, will likely be DESC).
		 */
		$orderby = (array) $query->get( 'orderby' );
		if ( ! isset( $orderby['geo_distance'] ) ) {
			$order = $query->get( 'order' ) ? $query->get( 'order' ) : 'asc';
			if ( count( $orderby ) === 1 ) {
				$query->set( 'order', $order );
			} else {
				$geo_distance_key = array_search( 'geo_distance', $orderby, true );
				unset( $orderby[ $geo_distance_key ] );
				$query->set( 'orderby', array_merge( [ 'geo_distance' => $order ], $orderby ) );
			}
		}

		if ( empty( $query->get( 'geo_distance' ) ) ) {
			$query->set( 'geo_distance', [ 'geo_point.location' => $user_coordinates ] );
		}
	}

	/**
	 * Process sorting by geo_distance.
	 *
	 * @param array $sort The sort array from formatted_args.
	 * @param array $args The original query args.
	 *
	 * @return array The updated sort array.
	 */
	protected function process_geo_distance_sort( $sort, $args ): array {
		foreach ( $sort as &$sort_item ) {
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
	 * If user coordinates are needed, include the JS to ask for it.
	 *
	 * @return void
	 */
	public function maybe_ask_user_coordinates() {
		/**
		 * Filter whether a JS script to ask user coordinates should or not be included.
		 *
		 * This is useful if you want to include your own script to ask for user coordinates. If that is the case,
		 * the script should set a cookie named `ep_coordinates` with `<latitude>,<longitude>` as its value.
		 *
		 * @since 2.4.0
		 * @hook ep_geo_location_ask_user_coordinates
		 * @param {bool} $should_ask_user_coordinates Whether a JS script to ask user coordinates should or not be included.
		 * @return {bool} New $should_ask_user_coordinates value.
		 */
		$should_ask_user_coordinates = apply_filters( 'ep_geo_location_ask_user_coordinates', $this->user_coordinates_needed );

		if ( ! $should_ask_user_coordinates ) {
			return;
		}

		wp_enqueue_script(
			'ep_geo_location_script',
			ELASTICPRESS_LABS_URL . 'dist/js/geo-location-script.js',
			Utils\get_asset_info( 'geo-location-script', 'dependencies' ),
			Utils\get_asset_info( 'geo-location-script', 'version' ),
			true
		);
	}

	/**
	 * Get user coordinates from the cookie.
	 *
	 * @return array|null
	 */
	protected function get_user_coordinates() {
		$user_coordinates = null;

		if ( ! empty( $_COOKIE['ep_coordinates'] ) ) {
			$coordinates = explode( ',', sanitize_text_field( wp_unslash( $_COOKIE['ep_coordinates'] ) ) );
			$lat         = $coordinates[0] ?? '';
			$lon         = $coordinates[1] ?? '';

			if ( ! empty( $lat ) && ! empty( $lon ) ) {
				$user_coordinates = [
					'lat' => (string) sanitize_text_field( $lat ),
					'lon' => (string) sanitize_text_field( $lon ),
				];
			}
		}

		/**
		 * Filter the user coordinates.
		 *
		 * @since 2.4.0
		 * @hook ep_geo_location_user_coordinates
		 * @param {array|null} $user_coordinates Array with 'lat' and 'lon' keys or null.
		 * @return {array|null} New $user_coordinates value.
		 */
		return apply_filters( 'ep_geo_location_user_coordinates', $user_coordinates );
	}
}
