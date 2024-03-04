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
	 * Helps us keep track of actions that have fired
	 *
	 * @var array
	 * @since 2.3.0
	 */
	protected $fired_actions = array();

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
