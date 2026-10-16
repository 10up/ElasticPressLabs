<?php
/**
 * Test Chat question admission
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Jobs\Admission;

/**
 * Chat admission test class
 */
class TestChatAdmission extends \WP_UnitTestCase {
	/**
	 * Whether the user's admission lock is free.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	protected function is_lock_free( int $user_id ): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $wpdb->prefix . 'ep_chat_admission_' . $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The lock is held while the callback runs and released after, even when it throws.
	 *
	 * @group chat
	 */
	public function test_lock_is_held_during_callback_and_released() {
		$admission = new Admission();

		$this->assertTrue( $this->is_lock_free( 7 ) );
		$this->assertSame(
			'held',
			$admission->with_lock(
				7,
				function () {
					return $this->is_lock_free( 7 ) ? 'free' : 'held';
				}
			)
		);
		$this->assertTrue( $this->is_lock_free( 7 ) );

		try {
			$admission->with_lock(
				7,
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertTrue( $this->is_lock_free( 7 ) );
	}

	/**
	 * The cap and Retry-After are filterable and never below 1.
	 *
	 * @group chat
	 */
	public function test_cap_and_retry_after_filters() {
		$admission = new Admission();

		$this->assertSame( 1, $admission->get_max_in_flight( 1 ) );
		$this->assertSame( 10, $admission->get_retry_after( 1 ) );

		add_filter( 'ep_chat_max_in_flight', '__return_zero' );
		add_filter(
			'ep_chat_retry_after',
			static function () {
				return 30;
			}
		);

		$this->assertSame( 1, $admission->get_max_in_flight( 1 ) );
		$this->assertSame( 30, $admission->get_retry_after( 1 ) );
	}
}
