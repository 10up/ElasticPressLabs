<?php
/**
 * Test Chat question admission
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Jobs\Admission;
use ElasticPressLabs\Feature\Chat\Storage;

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

	/**
	 * A user who asked the most questions allowed in the last hour waits until the oldest of
	 * them is an hour old; older questions and other users' questions do not count.
	 *
	 * @group chat
	 */
	public function test_hourly_cap() {
		$admission = new Admission();
		$user_id   = self::factory()->user->create();
		$other_id  = self::factory()->user->create();
		$post_id   = self::factory()->post->create();
		$ask       = function ( int $user, int $minutes_ago ) use ( $post_id ) {
			wp_insert_comment(
				[
					'comment_post_ID'  => $post_id,
					'comment_type'     => Storage::COMMENT_TYPE_QUESTION,
					'comment_content'  => 'A question',
					'comment_approved' => 1,
					'user_id'          => $user,
					'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $minutes_ago * MINUTE_IN_SECONDS ),
				]
			);
		};

		$this->assertSame( 30, $admission->get_max_per_hour( $user_id ) );

		add_filter(
			'ep_chat_max_questions_per_hour',
			static function () {
				return 3;
			}
		);

		$ask( $user_id, 90 );
		$ask( $user_id, 50 );
		$ask( $user_id, 20 );
		$ask( $other_id, 5 );

		$this->assertTrue( $admission->check_cap( $user_id ) );

		$ask( $user_id, 1 );

		$refused = $admission->check_cap( $user_id );

		$this->assertWPError( $refused );
		$this->assertSame( Admission::ERROR_RATE_LIMITED, $refused->get_error_code() );
		$this->assertSame( 429, $refused->get_error_data()['status'] );
		// The oldest counted question was asked 50 minutes ago: about 10 minutes to wait.
		$this->assertEqualsWithDelta( 10 * MINUTE_IN_SECONDS, $refused->get_error_data()['retry_after'], 5 );
		$this->assertStringContainsString( 'Try again in 10 minutes.', $refused->get_error_message() );

		add_filter( 'ep_chat_max_questions_per_hour', '__return_zero', 20 );

		$this->assertTrue( $admission->check_cap( $user_id ), '0 means no limit.' );
	}
}
