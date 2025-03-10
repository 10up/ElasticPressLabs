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

		$this->summary = '<p>' . __( 'Geo Location feature allows you to search for posts based on their location.', 'elasticpress-labs' ) . '</p>';
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

		add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
		add_action( 'init', [ $this, 'register_meta' ] );

		add_filter( 'ep_post_mapping', [ $this, 'add_mapping' ], 20, 2 );
		add_filter( 'ep_post_sync_args', [ $this, 'add_post_sync_args' ], 10, 2 );
		add_filter( 'ep_formatted_args', [ $this, 'formatted_args' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'maybe_orderby_geo_distance' ] );

		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'parse_request', [ $this, 'maybe_change_cookie' ] );

		add_action( 'wp_footer', [ $this, 'maybe_ask_user_coordinates' ], 19 );
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @return void
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

		$google_maps_api_key = $this->get_setting( 'google_maps_api_key' );

		wp_localize_script(
			'ep_geo_location_script',
			'epGeoLocation',
			[
				'has_map_key'      => ! empty( $google_maps_api_key ),
				'is_external_meta' => has_filter( 'ep_geo_location_pre_geo_points' ),
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
	 * Change search query to sort by geo_distance.
	 *
	 * @param WP_Query $query The WP_Query object.
	 * @return void
	 */
	public function maybe_orderby_geo_distance( $query ): void {
		if ( ! $this->should_set_geo_distance_parameters( $query ) ) {
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

		$query->set( 'orderby', 'geo_distance' );

		if ( empty( $query->get( 'order' ) ) ) {
			$query->set( 'order', 'ASC' );
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

	/**
	 * Check if the cookie with the location needs to be changed.
	 */
	public function maybe_change_cookie() {
		if (
			empty( $_REQUEST['ep_geo_location_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['ep_geo_location_nonce'] ) ), 'ep_geo_location' )
		) {
			return;
		}

		if ( ! isset( $_REQUEST['ep_geo_location_show'] ) ) {
			return;
		}

		// if the user has disabled the location, remove the cookie.
		if ( '0' === $_REQUEST['ep_geo_location_show'] ) {
			setcookie( 'ep_coordinates', '', time() - DAY_IN_SECONDS, '/' );
		}

		if ( '1' === $_REQUEST['ep_geo_location_show'] && ! empty( $_REQUEST['ep_lat'] ) && ! empty( $_REQUEST['ep_lon'] ) ) {
			$cookie_value = array_map( 'sanitize_text_field', [ sanitize_text_field( wp_unslash( $_REQUEST['ep_lat'] ) ), sanitize_text_field( wp_unslash( $_REQUEST['ep_lon'] ) ) ] );
			$cookie_value = implode( ',', $cookie_value );

			setcookie( 'ep_coordinates', $cookie_value, time() + YEAR_IN_SECONDS * 10, '/' );
		}

		// Remove the nonce and other query params.
		unset( $_REQUEST['ep_geo_location_nonce'] );
		unset( $_REQUEST['_wp_http_referer'] );
		unset( $_REQUEST['ep_lat'] );
		unset( $_REQUEST['ep_lon'] );
		unset( $_REQUEST['ep_geo_location_show'] );

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_uri = wp_parse_url( $request_uri );

		wp_safe_redirect( $request_uri['path'] . '?' . build_query( $_REQUEST ) );
		die();
	}

	/**
	 * Register the block.
	 *
	 * @return void
	 */
	public function register_block(): void {
		/**
		 * Registering it here so translation works
		 *
		 * @see https://core.trac.wordpress.org/ticket/54797#comment:20
		 */
		wp_register_script(
			'ep-geo-location-script',
			ELASTICPRESS_LABS_URL . 'dist/blocks/geo-location-block-script.js',
			Utils\get_asset_info( 'geo-location-block-script', 'dependencies' ),
			Utils\get_asset_info( 'geo-location-block-script', 'version' ),
			true
		);
		wp_set_script_translations( 'ep-geo-location-script', 'elasticpress' );

		wp_register_style(
			'ep-geo-location-view-style',
			ELASTICPRESS_LABS_URL . 'dist/blocks/geo-location-block-script.css',
			[],
			Utils\get_asset_info( 'ep-geo-location-view-script', 'version' ),
			'all'
		);

		register_block_type_from_metadata(
			ELASTICPRESS_LABS_PATH . 'assets/js/blocks/geo-location',
			[
				'render_callback' => [ $this, 'render_block' ],
			]
		);
	}

	/**
	 * Enqueue assets for the block.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_register_script(
			'ep-geo-location-view-script',
			ELASTICPRESS_LABS_URL . 'dist/blocks/geo-location-block-view-script.js',
			Utils\get_asset_info( 'geo-location-block-view-script', 'dependencies' ),
			Utils\get_asset_info( 'geo-location-block-view-script', 'version' ),
			true
		);

		wp_register_style(
			'ep-geo-location-view-style',
			ELASTICPRESS_LABS_URL . 'dist/blocks/geo-location-block-script.css',
			[],
			Utils\get_asset_info( 'ep-geo-location-view-script', 'version' ),
			'all'
		);
	}

	/**
	 * Render the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block output.
	 */
	public function render_block( $attributes ): string {
		/**
		 * Prior to WP 6.1, if you set `viewScript` while using a `render_callback` function,
		 * the script was not enqueued.
		 *
		 * @see https://core.trac.wordpress.org/changeset/54367
		 */
		if ( version_compare( get_bloginfo( 'version' ), '6.1', '<' ) ) {
			wp_enqueue_script( 'ep-geo-location-view-script' );
		}

		$text_without_location = ! empty( $attributes['textWithoutLocation'] ) ? $attributes['textWithoutLocation'] : '';
		$text_with_location    = ! empty( $attributes['textWithLocation'] ) ? $attributes['textWithLocation'] : '';

		$button_text_without_location = ! empty( $attributes['buttonTextWithoutLocation'] ) ? $attributes['buttonTextWithoutLocation'] : '';
		$button_text_with_location    = ! empty( $attributes['buttonTextWithLocation'] ) ? $attributes['buttonTextWithLocation'] : '';

		$has_user_location = ! empty( $_COOKIE['ep_coordinates'] );

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_uri = wp_parse_url( $request_uri );
		wp_parse_str( $request_uri['query'] ?? '', $query_params );

		// Add empty lat and lon to the query params.
		$query_params = array_merge(
			$query_params,
			[
				'ep_lat' => '',
				'ep_lon' => '',
			]
		);

		ob_start();
		?>
		<form class="form" method="POST" action="">
			<?php wp_nonce_field( 'ep_geo_location', 'ep_geo_location_nonce' ); ?>
			<?php foreach ( $query_params as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<input type="hidden" name="ep_geo_location_show" value="<?php echo ! $has_user_location ? '1' : '0'; ?>">
			<p class="ep-geo-location__label"><?php echo $has_user_location ? esc_html( $text_with_location ) : esc_html( $text_without_location ); ?></p>
			<button type="submit" class="wp-element-button ep-geo-location__submit-button">
				<?php
				echo $has_user_location ? esc_html( $button_text_with_location )
				: esc_html( $button_text_without_location );
				?>
			</button>
			<p class="ep-geo-location__error"><?php esc_html_e( 'Error retrieving location data. Please ensure that location services are enabled.', 'elasticpress-labs' ); ?></p>
		</form>
		<?php
		$block_content = ob_get_clean();

		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'wp-block-elasticpress-geo-location' ] );

		return sprintf(
			'<div %1$s>%2$s</div>',
			wp_kses_data( $wrapper_attributes ),
			$block_content
		);
	}

	/**
	 * If user coordinates are needed, include the JS to ask for it.
	 *
	 * @return void
	 */
	public function maybe_ask_user_coordinates() {
		if ( ! $this->user_coordinates_needed ) {
			return;
		}

		wp_enqueue_script(
			'ep_geo_location_frontend_script',
			ELASTICPRESS_LABS_URL . 'dist/js/geo-location-front-end-script.js',
			Utils\get_asset_info( 'geo-location-front-end-script', 'dependencies' ),
			Utils\get_asset_info( 'geo-location-front-end-script', 'version' ),
			true
		);
	}

	/**
	 * Whether geo_distance parameters should be set.
	 *
	 * @param WP_Query $query WP_Query object.
	 * @return boolean
	 */
	protected function should_set_geo_distance_parameters( $query ) {
		if ( 'geo_distance' === $query->get( 'orderby' ) ) {
			return true;
		}

		if ( isset( $_REQUEST['ep_geo_distance_sort'] ) && filter_var( wp_unslash( $_REQUEST['ep_geo_distance_sort'] ), FILTER_VALIDATE_BOOLEAN ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}

		return false;
	}

	/**
	 * Get user coordinates from the cookie.
	 *
	 * @return array|null
	 */
	protected function get_user_coordinates() {
		if ( empty( $_COOKIE['ep_coordinates'] ) ) {
			return;
		}

		$coordinates = explode( ',', sanitize_text_field( wp_unslash( $_COOKIE['ep_coordinates'] ) ) );

		$lat = $coordinates[0];
		$lon = $coordinates[1];

		if ( empty( $lat ) || empty( $lon ) ) {
			return;
		}

		return [
			'lat' => (string) sanitize_text_field( $lat ),
			'lon' => (string) sanitize_text_field( $lon ),
		];
	}
}
