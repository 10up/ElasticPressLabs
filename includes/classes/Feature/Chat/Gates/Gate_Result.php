<?php
/**
 * Result of one Chat gate.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Pass, refuse-with-reason, or modify context.
 */
class Gate_Result {
	/**
	 * Continue the ladder.
	 *
	 * @var string
	 */
	public const PASS = 'pass';

	/**
	 * Stop and decline.
	 *
	 * @var string
	 */
	public const REFUSE = 'refuse';

	/**
	 * Continue after changing context.
	 *
	 * @var string
	 */
	public const MODIFY = 'modify';

	/**
	 * Stop without an answer because the gate could not do its check (a service failed): the
	 * reader gets an error to retry, not a refusal.
	 *
	 * @var string
	 */
	public const ERROR = 'error';

	/**
	 * Outcome.
	 *
	 * @var string
	 */
	protected $status;

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	protected $reason;

	/**
	 * Reader-safe copy.
	 *
	 * @var string
	 */
	protected $message;

	/**
	 * Context keys to merge on modify.
	 *
	 * @var array
	 */
	protected $context;

	/**
	 * Constructor.
	 *
	 * @param string $status  Outcome.
	 * @param string $reason  Refusal code.
	 * @param string $message Reader-safe copy.
	 * @param array  $context Modified context.
	 */
	public function __construct(
		string $status,
		string $reason = '',
		string $message = '',
		array $context = []
	) {
		$this->status  = $status;
		$this->reason  = $reason;
		$this->message = $message;
		$this->context = $context;
	}

	/**
	 * Continue unchanged.
	 *
	 * @return self
	 */
	public static function pass(): self {
		return new self( self::PASS );
	}

	/**
	 * Decline with a typed reason.
	 *
	 * @param string $reason  Code.
	 * @param string $message Reader-safe copy.
	 * @param array  $context Context keys to merge before stopping (e.g. passages to store).
	 * @return self
	 */
	public static function refuse( string $reason, string $message, array $context = [] ): self {
		return new self( self::REFUSE, $reason, $message, $context );
	}

	/**
	 * Continue after changing context.
	 *
	 * @param array $context Keys to merge.
	 * @return self
	 */
	public static function modify( array $context ): self {
		return new self( self::MODIFY, '', '', $context );
	}

	/**
	 * Stop with an error the reader can retry.
	 *
	 * @param string $code    Error code.
	 * @param string $message Reader-safe copy.
	 * @return self
	 */
	public static function error( string $code, string $message ): self {
		return new self( self::ERROR, $code, $message );
	}

	/**
	 * Whether the ladder should stop with a refusal.
	 *
	 * @return bool
	 */
	public function is_refuse(): bool {
		return self::REFUSE === $this->status;
	}

	/**
	 * Whether the ladder should stop with an error (`get_reason()` is the error code).
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return self::ERROR === $this->status;
	}

	/**
	 * The error as a WP_Error, for REST and storage.
	 *
	 * @return \WP_Error
	 */
	public function to_wp_error(): \WP_Error {
		return new \WP_Error( $this->reason, $this->message, [ 'status' => 503 ] );
	}

	/**
	 * Whether context should be merged.
	 *
	 * @return bool
	 */
	public function is_modify(): bool {
		return self::MODIFY === $this->status;
	}

	/**
	 * Refusal code.
	 *
	 * @return string
	 */
	public function get_reason(): string {
		return $this->reason;
	}

	/**
	 * Reader-safe copy.
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Context to merge.
	 *
	 * @return array
	 */
	public function get_context(): array {
		return $this->context;
	}
}
