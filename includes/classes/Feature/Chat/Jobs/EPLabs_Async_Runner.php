<?php
/**
 * Labs wp-async Chat runner.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

use ElasticPressLabs\Feature\Chat\Capabilities;
use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Fire-and-forget admin-post loopback, same idea as techcrunch/wp-async-task.
 *
 * Named EPLabs_Async_Runner so it does not collide with WP_Async_Task.
 */
class EPLabs_Async_Runner implements Runner {
	/**
	 * admin-post.php action.
	 *
	 * @var string
	 */
	public const ACTION = 'ep_chat_eplabs_async';

	/**
	 * Body posted on shutdown.
	 *
	 * @var array
	 */
	protected $body_data = [];

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return Job_Handle::RUNNER_WP_ASYNC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Register admin-post handlers.
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Queue a shutdown loopback to admin-post.php.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      ep_chat_id and question_id.
	 * @return Job_Handle|\WP_Error
	 */
	public function dispatch( string $question, array $history, array $bot, array $ids ) {
		$question_id = (int) $ids['question_id'];
		$user_id     = get_current_user_id();

		$this->body_data = [
			'action'      => self::ACTION,
			'_token'      => $this->create_token( $question_id, $user_id ),
			'question_id' => $question_id,
			'user_id'     => $user_id,
		];

		add_action( 'shutdown', [ $this, 'launch_on_shutdown' ] );

		return Job_Handle::queued(
			(int) $ids['ep_chat_id'],
			$question_id,
			Job_Handle::MODE_ASYNC,
			$this->get_slug()
		);
	}

	/**
	 * POST to admin-post.php after the web response is sent.
	 *
	 * @return void
	 */
	public function launch_on_shutdown() {
		if ( empty( $this->body_data ) ) {
			return;
		}

		wp_remote_post(
			admin_url( 'admin-post.php' ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => $this->should_verify_ssl(),
				'body'      => $this->body_data,
			]
		);

		$this->body_data = [];
	}

	/**
	 * Worker entry from admin-post.php.
	 *
	 * The action is public (no cookies on the loopback). The token only
	 * authorizes processing that specific queued question as its author.
	 *
	 * @return void
	 */
	public function handle() {
		ignore_user_abort( true );

		$question_id = absint( wp_unslash( $_POST['question_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$user_id     = absint( wp_unslash( $_POST['user_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token       = sanitize_text_field( wp_unslash( $_POST['_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $this->verify_token( $token, $question_id, $user_id ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		wp_set_current_user( $user_id );

		if ( ! Capabilities::current_user_can( Capabilities::ASK ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		if ( ! $this->is_authorized_question( $question_id, $user_id ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		( new Job_Processor() )->process(
			$question_id,
			Job_Handle::MODE_ASYNC,
			$this->get_slug()
		);

		wp_die( '', '', [ 'response' => 200 ] );
	}

	/**
	 * Verify TLS on the loopback unless this is a local environment.
	 *
	 * @return bool
	 */
	protected function should_verify_ssl(): bool {
		$verify = 'local' !== wp_get_environment_type();

		return (bool) apply_filters( 'https_local_ssl_verify', $verify );
	}

	/**
	 * Time-limited token that does not include the logged-in session.
	 *
	 * wp_create_nonce() hashes the session cookie. The loopback has no
	 * cookies, so that nonce never verifies.
	 *
	 * @param int $question_id Question comment ID.
	 * @param int $user_id     User ID from the Ask request.
	 * @return string
	 */
	protected function create_token( int $question_id, int $user_id ): string {
		return $this->hash_token( $question_id, $user_id, wp_nonce_tick() );
	}

	/**
	 * Accept the current or previous nonce tick.
	 *
	 * @param string $token       Posted token.
	 * @param int    $question_id Question comment ID.
	 * @param int    $user_id     Posted user ID.
	 * @return bool
	 */
	protected function verify_token( string $token, int $question_id, int $user_id ): bool {
		if ( '' === $token || $question_id < 1 || $user_id < 1 ) {
			return false;
		}

		$tick = wp_nonce_tick();

		return hash_equals( $this->hash_token( $question_id, $user_id, $tick ), $token )
			|| hash_equals( $this->hash_token( $question_id, $user_id, $tick - 1 ), $token );
	}

	/**
	 * Hash one token tick.
	 *
	 * @param int   $question_id Question comment ID.
	 * @param int   $user_id     User ID.
	 * @param float $tick        wp_nonce_tick() value.
	 * @return string
	 */
	protected function hash_token( int $question_id, int $user_id, $tick ): string {
		return wp_hash(
			self::ACTION . '|' . $question_id . '|' . $user_id . '|' . $tick,
			'nonce'
		);
	}

	/**
	 * The token is only allowed to finish this user's queued question.
	 *
	 * @param int $question_id Posted question comment ID.
	 * @param int $user_id     Posted user ID.
	 * @return bool
	 */
	protected function is_authorized_question( int $question_id, int $user_id ): bool {
		$comment = get_comment( $question_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return false;
		}

		if ( Storage::COMMENT_TYPE_QUESTION !== $comment->comment_type ) {
			return false;
		}

		if ( (int) $comment->user_id !== $user_id ) {
			return false;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		return $post && Storage::POST_TYPE === $post->post_type;
	}
}
