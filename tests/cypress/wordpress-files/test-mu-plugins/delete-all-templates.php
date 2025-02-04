<?php
/**
 * Plugin Name: Delete all search templates
 *
 * @package ElasticPress_Tests_E2e
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * WP-CLI command to delete all search templates in the account
 */
function ep_tests_delete_all_search_templates() {
	$docker_cid = get_docker_cid();
	if ( ! $docker_cid ) {
		WP_CLI::error( 'Docker CID not set.' );
	}

	$search_templates = \ElasticPress\Features::factory()->get_registered_feature( 'search_templates' );
	if ( $search_templates ) {
		$search_templates->delete_all_search_templates();
		WP_CLI::success( 'All templates deleted.' );
	} else {
		WP_CLI::error( 'Search templates feature not activated.' );
	}
}
WP_CLI::add_command( 'elasticpress-tests delete-all-search-templates', 'ep_tests_delete_all_search_templates' );
