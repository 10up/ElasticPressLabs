<?php
/**
 * Plugin Name: Set Geo Location Coordinates
 * Description: Set Geo Location Coordinates for ElasticPress tests.
 * Version:     1.0.0
 * Author:      10up Inc.
 * License:     GPLv2 or later
 *
 * @package ElasticPress_Tests_E2e
 */

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
