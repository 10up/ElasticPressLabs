<?php
/**
 * Test synonym feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs;

/**
 * Meta Key Pattern test class
 */
class TestMetaKeyPattern extends \WP_UnitTestCase {

	/**
	 * Setup each test.
	 */
	public function set_up() {
		$instance = new ElasticPressLabs\Feature\MetaKeyPattern();
		\ElasticPress\Features::factory()->register_feature( $instance );
	}

	/**
	 * Get Meta Key Pattern feature
	 *
	 * @return MetaKeyPattern
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'meta_key_pattern' );
	}

	/**
	 * Get protected function as public
	 *
	 * @param string $function_name Function name
	 * @param string $class_name    Class name
	 * @return ReflectionClass
	 */
	protected function get_private_function( $function_name, $class_name = 'ElasticPressLabs\Feature\MetaKeyPattern' ) {
		$reflector = new \ReflectionClass( $class_name );
		$function  = $reflector->getMethod( $function_name );
		$function->setAccessible( true );

		return $function;
	}

	/**
	 * Test constrcut
	 *
	 * @group MetaKeyPattern
	 */
	public function testConstruct() {
		$instance = $this->get_feature();

		$this->assertEquals( 'meta_key_pattern', $instance->slug );
		$this->assertEquals( 'Meta Key Pattern', $instance->title );
	}

	/**
	 * Test the `output_feature_box_summary` method.
	 *
	 * @group MetaKeyPattern
	 */
	public function testBoxSummary() {
		ob_start();
		$this->get_feature()->output_feature_box_summary();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Include or exclude meta key patterns.', $output );
	}

	/**
	 * Test the `is_match` method.
	 *
	 * @group MetaKeyPattern
	 */
	public function testIsMatch() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'is_match' );

		$this->assertTrue( $function->invokeArgs( $feature, array( 'meta_key_lorem', '/^meta_/' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'meta_key_lorem', '/^_meta_/' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'meta_key_lorem', '' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'meta_key_lorem', '//' ) ) );
	}

	/**
	 * Test the `has_delimiters` method.
	 *
	 * @group MetaKeyPattern
	 */
	public function testHasDelimiters() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'has_delimiters' );

		$this->assertTrue( $function->invokeArgs( $feature, array( '/^meta_/' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( '^meta_' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( '' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'm' ) ) );
	}

	/**
	 * Test the `remove_delimiters` method.
	 *
	 * @group MetaKeyPattern
	 */
	public function testRemoveDelimiters() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'remove_delimiters' );

		$this->assertSame( '^meta_', $function->invokeArgs( $feature, array( '/^meta_/' ) ) );
		$this->assertEmpty( $function->invokeArgs( $feature, array( '//' ) ) );
	}

	/**
	 * Test the `prepare_regex` method.
	 *
	 * @group MetaKeyPattern
	 */
	public function testPrepareRegex() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'prepare_regex' );

		$this->assertSame( '/_http:\/\//', $function->invokeArgs( $feature, array( '/_http:///' ) ) );
		$this->assertSame( '/^_meta/', $function->invokeArgs( $feature, array( '^_meta' ) ) );
	}
}
