<?php
/**
 * Test the vector embeddings settings REST controller
 *
 * @since 2.5.2
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest\REST;

use ElasticPressLabs\Feature\VectorEmbeddings\Settings;
use ElasticPressLabs\REST\VectorEmbeddingSettings;

/**
 * VectorEmbeddingSettings test class
 */
class TestVectorEmbeddingSettings extends \ElasticPressLabsTest\BaseTestCase {
	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		add_action( 'rest_api_init', [ new VectorEmbeddingSettings(), 'register_routes' ] );
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::SETTINGS_KEY );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		delete_option( Settings::SETTINGS_KEY );
		parent::tear_down();
	}

	/**
	 * Settings as the settings screen sends them.
	 *
	 * @return array
	 */
	protected function get_settings() {
		return [
			'chunkSize'          => 100,
			'chunkOverlap'       => 50,
			'postTypeConfig'     => [
				[
					'embeddable'            => true,
					'embeddingMode'         => 'automatic',
					'enableFieldsIndexing'  => false,
					'fieldsIndexingInclude' => [],
					'fieldsIndexingExclude' => [],
					'fieldsEmbedding'       => [ 'post_title', 'post_content' ],
					'label'                 => 'Posts',
					'key'                   => 'post',
					'taxonomies'            => [
						[
							'name'         => 'category',
							'label'        => 'Categories',
							'termsInclude' => [],
							'termsExclude' => [ 3 ],
							'enabled'      => true,
						],
					],
				],
			],
			'indexablePostTypes' => [ 'post', 'page' ],
			'embeddingsFiltered' => '',
		];
	}

	/**
	 * Save the settings.
	 *
	 * @param array|null $settings The settings to send. Defaults to valid settings.
	 * @return \WP_REST_Response
	 */
	protected function save( $settings = null ) {
		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/vector-embeddings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $settings ?? $this->get_settings() ) );

		return rest_do_request( $request );
	}

	/**
	 * Only who can manage ElasticPress can save the settings; nobody else changes the option.
	 *
	 * @group rest
	 * @group vector-embeddings
	 */
	public function test_save_needs_the_elasticpress_capability() {
		$this->assertSame( 401, $this->save()->get_status() );
		$this->assertFalse( get_option( Settings::SETTINGS_KEY ) );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, $this->save()->get_status() );
		$this->assertFalse( get_option( Settings::SETTINGS_KEY ) );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 200, $this->save()->get_status() );
		$this->assertSame( 100, get_option( Settings::SETTINGS_KEY )['chunkSize'] );
	}

	/**
	 * The payload of GHSA-2p6m-2xvh-9m2p is rejected even for an administrator.
	 *
	 * @group rest
	 * @group vector-embeddings
	 */
	public function test_save_rejects_the_advisory_payload() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->save(
			[
				'chunking'       => [],
				'mode'           => 'junk',
				'postTypeConfig' => [],
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( get_option( Settings::SETTINGS_KEY ) );
	}

	/**
	 * Settings with a missing or invalid value are rejected and the option is not changed.
	 *
	 * @param callable $change Changes the valid settings into invalid ones.
	 * @dataProvider invalid_settings_provider
	 * @group rest
	 * @group vector-embeddings
	 */
	public function test_save_rejects_invalid_settings( $change ) {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 400, $this->save( $change( $this->get_settings() ) )->get_status() );
		$this->assertFalse( get_option( Settings::SETTINGS_KEY ) );
	}

	/**
	 * Invalid settings, as changes to the valid ones.
	 *
	 * @return array
	 */
	public function invalid_settings_provider() {
		return [
			'missing chunk size'       => [
				function ( $settings ) {
					unset( $settings['chunkSize'] );
					return $settings;
				},
			],
			'chunk size of zero'       => [
				function ( $settings ) {
					$settings['chunkSize'] = 0;
					return $settings;
				},
			],
			'chunk size too large'     => [
				function ( $settings ) {
					$settings['chunkSize'] = 301;
					return $settings;
				},
			],
			'negative chunk overlap'   => [
				function ( $settings ) {
					$settings['chunkOverlap'] = -1;
					return $settings;
				},
			],
			'missing post types'       => [
				function ( $settings ) {
					unset( $settings['postTypeConfig'] );
					return $settings;
				},
			],
			'post type without key'    => [
				function ( $settings ) {
					unset( $settings['postTypeConfig'][0]['key'] );
					return $settings;
				},
			],
			'invalid post type key'    => [
				function ( $settings ) {
					$settings['postTypeConfig'][0]['key'] = 'Not a post type';
					return $settings;
				},
			],
			'unknown embedding mode'   => [
				function ( $settings ) {
					$settings['postTypeConfig'][0]['embeddingMode'] = 'junk';
					return $settings;
				},
			],
			'embeddable not a boolean' => [
				function ( $settings ) {
					$settings['postTypeConfig'][0]['embeddable'] = 'maybe';
					return $settings;
				},
			],
			'term ID not an integer'   => [
				function ( $settings ) {
					$settings['postTypeConfig'][0]['taxonomies'][0]['termsExclude'] = [ 'abc' ];
					return $settings;
				},
			],
			'taxonomy without name'    => [
				function ( $settings ) {
					unset( $settings['postTypeConfig'][0]['taxonomies'][0]['name'] );
					return $settings;
				},
			],
		];
	}

	/**
	 * Properties the schema does not list are not saved, so values only displayed by
	 * the settings screen and keys renamed since older versions do not block a save.
	 *
	 * @group rest
	 * @group vector-embeddings
	 */
	public function test_save_removes_unknown_properties() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$settings = $this->get_settings();

		$settings['mode']                                        = 'junk';
		$settings['postTypeConfig'][0]['enablefieldsIndexing']   = false;
		$settings['postTypeConfig'][0]['taxonomies'][0]['extra'] = 'value';

		$this->assertSame( 200, $this->save( $settings )->get_status() );

		$expected = $this->get_settings();
		unset( $expected['indexablePostTypes'], $expected['embeddingsFiltered'] );

		$this->assertSame( $expected, get_option( Settings::SETTINGS_KEY ) );
	}

	/**
	 * Term IDs are saved as integers, as the taxonomy rules compare them strictly.
	 *
	 * @group rest
	 * @group vector-embeddings
	 */
	public function test_save_stores_term_ids_as_integers() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$settings = $this->get_settings();

		$settings['postTypeConfig'][0]['taxonomies'][0]['termsInclude'] = [ '5' ];

		$this->assertSame( 200, $this->save( $settings )->get_status() );
		$this->assertSame( [ 5 ], get_option( Settings::SETTINGS_KEY )['postTypeConfig'][0]['taxonomies'][0]['termsInclude'] );
	}
}
