<?php
/**
 * ElasticPressLabs base test class
 *
 * @since 2.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

/**
 * Base test class
 */
class BaseTestCase extends \WP_UnitTestCase {
	/**
	 * Holds the factory object
	 *
	 * @var obj
	 */
	protected $ep_factory;

	/**
	 * Set up the test case.
	 *
	 * @var obj
	 */
	public function set_up() {
		$this->setup_factory();
		parent::set_up();
	}

	/**
	 * Setup factory
	 */
	protected function setup_factory() {
		$this->ep_factory       = new \stdClass();
		$this->ep_factory->user = new UserFactory();
	}
}
