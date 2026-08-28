<?php
/**
 * Test Co-Authors Plus feature
 *
 * @since  1.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs;
use ElasticPress;

/**
 * CoAuthors Plus test class
 *
 * @since  1.1.0
 */
class TestCoAuthorsPlus extends BaseTestCase {
	/**
	 * Setup each test.
	 *
	 * @since  1.1.0
	 */
	public function set_up() {
		parent::set_up();

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		ElasticPress\Indexables::factory()->get( 'post' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'post' )->sync_manager->reset_sync_queue();

		$instance = new ElasticPressLabs\Feature\CoAuthorsPlus();
		\ElasticPress\Features::factory()->register_feature( $instance );
	}

	/**
	 * Get Co-Authors Plus feature
	 *
	 * @since  1.1.0
	 * @return CoAuthorsPlus
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'co_authors_plus' );
	}

	/**
	 * Get protected function as public
	 *
	 * @since  1.1.0
	 * @param string $function_name Function name
	 * @param string $class_name    Class name
	 * @return ReflectionClass
	 */
	protected function get_protected_function( $function_name, $class_name = 'ElasticPressLabs\Feature\CoAuthorsPlus' ) {
		$reflector = new \ReflectionClass( $class_name );
		$function  = $reflector->getMethod( $function_name );
		$function->setAccessible( true );

		return $function;
	}

	/**
	 * Test construct
	 *
	 * @since  1.1.0
	 */
	public function testConstruct() {
		$instance = $this->get_feature();
		$instance->set_i18n_strings();

		$this->assertEquals( 'co_authors_plus', $instance->slug );
		$this->assertEquals( 'Co-Authors Plus', $instance->title );
	}

	/**
	 * Test Protected Content status is checked during requirements validation.
	 *
	 * @since 2.5.2
	 */
	public function test_protected_content_status_is_checked_during_requirements_validation() {
		ElasticPress\Features::factory()->activate_feature( 'protected_content' );

		$instance = new class() extends ElasticPressLabs\Feature\CoAuthorsPlus {
			/**
			 * Return the protected content feature state.
			 *
			 * @return bool
			 */
			public function is_protected_content_feature_active() {
				return $this->is_protected_content_feature_active;
			}
		};

		$this->assertFalse( $instance->is_protected_content_feature_active() );

		$instance->requirements_status();

		$this->assertTrue( $instance->is_protected_content_feature_active() );
	}

	/**
	 * Test box summary
	 *
	 * @since  1.1.0
	 */
	public function testBoxSummary() {
		ob_start();
		$this->get_feature()->output_feature_box_summary();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Add support for the Co-Authors Plus plugin in the Admin Post List screen by Author name', $output );
	}

	/**
	 * Test filter out author name and id from Elasticsearch query
	 *
	 * @since  1.1.0
	 */
	public function testFilterOutAuthorNameAndId() {
		$feature = $this->get_feature();
		$function_filter_out_author_name_and_id_from_es_filter = $this->get_protected_function( 'filter_out_author_name_and_id_from_es_filter' );

		$this->assertEquals( [], $function_filter_out_author_name_and_id_from_es_filter->invokeArgs( $feature, array( [] ) ) );

		$this->assertEquals( '', $function_filter_out_author_name_and_id_from_es_filter->invokeArgs( $feature, array( '' ) ) );

		$formatted_args = [
			'post_filter' => [
				'bool' => [
					'must' => [
						[
							'term' => [
								'post_author.display_name' => [ 'test' ],
							],
						],
					],
				],
			],
		];

		$filtered_formatted_args = $function_filter_out_author_name_and_id_from_es_filter->invokeArgs( $feature, [ $formatted_args ] );

		$this->assertEmpty( $filtered_formatted_args );

		$formatted_args['post_filter']['bool']['must'][] = [
			'terms' => [
				'post_type.raw' => [ 'post' ],
			],
		];

		$filtered_formatted_args = $function_filter_out_author_name_and_id_from_es_filter->invokeArgs( $feature, [ $formatted_args ] );

		$this->assertNotEmpty( $filtered_formatted_args );
		$this->assertCount( 1, $filtered_formatted_args );
		$this->assertArrayHasKey( 'terms', $filtered_formatted_args[0] );

		$formatted_args['post_filter']['bool']['must'][] = [
			'term' => [
				'post_author.id' => [ 1 ],
			],
		];

		$filtered_formatted_args = $function_filter_out_author_name_and_id_from_es_filter->invokeArgs( $feature, [ $formatted_args ] );

		$this->assertNotEmpty( $filtered_formatted_args );
		$this->assertCount( 1, $filtered_formatted_args );
		$this->assertArrayHasKey( 'terms', $filtered_formatted_args[0] );
	}

	/**
	 * Test settings schema.
	 *
	 * @since 2.5.0
	 */
	public function test_settings_schema() {
		$expected = [
			[
				'default'          => false,
				'key'              => 'active',
				'label'            => 'Enable',
				'requires_feature' => [ 'search' ],
				'requires_sync'    => true,
				'type'             => 'toggle',
			],
			[
				'key'   => 'instructions',
				'label' => '<p>When enabled, this feature integrates ElasticPress with Co-Authors Plus to enhance author-related queries on the frontend. If "Protected Content" is activated, visit the Admin Post List screen by Author name <code>wp-admin/edit.php?author_name=&lt;name&gt;</code> and see correct results.</p>',
				'type'  => 'markup',
			],
		];

		$this->assertSame( $expected, $this->get_feature()->get_settings_schema() );
	}

	/**
	 * Test attribute add in weight dashboard.
	 *
	 * @since 2.5.0
	 */
	public function test_attribute_add_in_weight_dashboard() {
		ElasticPress\Features::factory()->activate_feature( 'co_authors_plus' );
		ElasticPress\Features::factory()->get_registered_feature( 'co_authors_plus' )->setup();

		$search = ElasticPress\Features::factory()->get_registered_feature( 'search' );
		$fields = $search->weighting->get_weightable_fields_for_post_type( 'post' );

		$this->assertArrayHasKey( 'terms.author.name', $fields['attributes']['children'] );
		$this->assertEquals( 'Guest Author', $fields['attributes']['children']['terms.author.name']['label'] );
		$this->assertEquals( 'terms.author.name', $fields['attributes']['children']['terms.author.name']['key'] );
	}

	/**
	 * Test add author default weight.
	 *
	 * @since 2.5.0
	 */
	public function test_add_author_default_weight() {
		ElasticPress\Features::factory()->activate_feature( 'co_authors_plus' );
		ElasticPress\Features::factory()->get_registered_feature( 'co_authors_plus' )->setup();

		$search = ElasticPress\Features::factory()->get_registered_feature( 'search' );
		$fields = $search->weighting->get_post_type_default_settings( 'post' );

		$this->assertArrayHasKey( 'terms.author.name', $fields );
		$this->assertEquals( 1, $fields['terms.author.name']['weight'] );
		$this->assertTrue( $fields['terms.author.name']['enabled'] );
	}

	/**
	 * Test search query returns the posts if search query is a co-author.
	 *
	 * @since 2.5.0
	 */
	public function test_search_query_with_co_authors_plus() {
		global $coauthors_plus;

		ElasticPress\Features::factory()->activate_feature( 'co_authors_plus' );
		ElasticPress\Features::factory()->get_registered_feature( 'co_authors_plus' )->setup();

		$post_id = $this->ep_factory->post->create();

		$user_login        = 'guest-author';
		$user_display_name = 'Guest Author';

		$coauthors_plus->guest_authors->create(
			[
				'display_name' => $user_display_name,
				'user_login'   => $user_login,
			]
		);

		$coauthors_plus->add_coauthors( $post_id, [ $user_login ], true, 'user_login' );

		ElasticPress\Features::factory()->get_registered_feature( 'search' );
		ElasticPress\Indexables::factory()->get( 'post' )->index( $post_id, true );
		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$query = new \WP_Query(
			[

				's' => $user_display_name,
			]
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 1, $query->found_posts );
		$this->assertEquals( $post_id, $query->posts[0]->ID );
	}

	/**
	 * Test ep_coauthors_plus_skip_frontend_integration filter removes author weighting.
	 *
	 * @since 2.5.0
	 */
	public function test_ep_coauthors_plus_skip_frontend_integration() {
		add_filter( 'ep_coauthors_plus_skip_frontend_integration', '__return_true' );

		ElasticPress\Features::factory()->activate_feature( 'co_authors_plus' );
		ElasticPress\Features::factory()->get_registered_feature( 'co_authors_plus' )->setup();

		$search = ElasticPress\Features::factory()->get_registered_feature( 'search' );
		$fields = $search->weighting->get_post_type_default_settings( 'post' );

		$this->assertArrayNotHasKey( 'terms.author.name', $fields );
	}
}
