<?php
/**
 * Test synonym feature
 *
 * @package elasticpress
 */

namespace ElasticPressLabsTest;

require __DIR__ . '/../../../vendor/10up/elasticpress/elasticpress.php';

use ElasticPressLabs;
use WP_Mock\Tools\TestCase as BaseTestCase;

/**
 * Meta Key Pattern test class
 */
class TestMetaKeyPattern extends \WP_UnitTestCase {

	/**
	 * Setup each test.
	 *
	 * @since 3.5
	 */
	public function set_up() {
		$instance = new ElasticPressLabs\Feature\MetaKeyPattern();
		\ElasticPress\Features::factory()->register_feature($instance);
	}

	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'meta_key_pattern' );
	}

	protected function get_private_function( $functionName, $className = 'ElasticPressLabs\Feature\MetaKeyPattern' ) {
		$reflector = new \ReflectionClass( $className );
		$function  = $reflector->getMethod( $functionName );
		$function->setAccessible( true );

		return $function;
	}

	public function testConstruct() {
		$instance = $this->get_feature();

        $this->assertEquals( 'meta_key_pattern', $instance->slug );
        $this->assertEquals( 'Meta Key Pattern', $instance->title );
	}

	public function testBoxSummary() {
		ob_start();
		$this->get_feature()->output_feature_box_summary();
        $output = ob_get_clean();

		$this->assertStringContainsString( 'Include or exclude meta key patterns.', $output );
	}

	public function testBoxLong() {
		ob_start();
		$this->get_feature()->output_feature_box_long();
        $output = ob_get_clean();

		$this->assertStringContainsString( 'This feature will give you the most control over the metadata indexed.', $output );
	}

	public function testOutputFeatureBoxSettings() {
		ob_start();
		$this->get_feature()->output_feature_box_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Allow patterns', $output );
		$this->assertStringContainsString( 'Deny patterns', $output );
	}

	public function testIsMatch() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'is_match' );

		$this->assertTrue( $function->invokeArgs( $feature, array( 'meta_key_lorem', '/^meta_/' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'meta_key_lorem', '/^_meta_/' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'meta_key_lorem', '' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'meta_key_lorem', '//' ) ) );
	}

	public function testHasDelimiters() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'has_delimiters' );

		$this->assertTrue( $function->invokeArgs( $feature, array( '/^meta_/' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( '^meta_' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( '' ) ) );
		$this->assertFalse( $function->invokeArgs( $feature, array( 'm' ) ) );
	}

	public function testRemoveDelimiters() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'remove_delimiters' );

		$this->assertSame( '^meta_', $function->invokeArgs( $feature, array( '/^meta_/' ) ) );
		$this->assertEmpty( $function->invokeArgs( $feature, array( '//' ) ) );
	}

	public function testPrepareRegex() {
		$feature  = $this->get_feature();
		$function = $this->get_private_function( 'prepare_regex' );

		$this->assertSame( '/_http:\/\//', $function->invokeArgs( $feature, array( '/_http:///' ) ) );
		$this->assertSame( '/^_meta/', $function->invokeArgs( $feature, array( '^_meta' ) ) );
	}
}
