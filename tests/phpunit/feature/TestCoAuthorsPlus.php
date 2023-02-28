<?php
/**
 * Test Co-Authors Plus feature
 *
 * @since  1.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs;

/**
 * CoAuthors Plus test class
 *
 * @since  1.1.0
 */
class TestCoAuthorsPlus extends \WP_UnitTestCase {
	/**
	 * Setup each test.
	 *
	 * @since  1.1.0
	 */
	public function set_up() {
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
	 * Test constrcut
	 *
	 * @since  1.1.0
	 */
	public function testConstruct() {
		$instance = $this->get_feature();

		$this->assertEquals( 'co_authors_plus', $instance->slug );
		$this->assertEquals( 'Co-Authors Plus', $instance->title );
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
	 * Test box long text
	 *
	 * @since  1.1.0
	 */
	public function testBoxLong() {
		ob_start();
		$this->get_feature()->output_feature_box_long();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'If using the Co-Authors Plus plugin and the Protected Content feature, enable this feature to visit the Admin Post List screen by Author name <code>wp-admin/edit.php?author_name=&lt;name&gt;</code> and see correct results.', $output );
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
}
