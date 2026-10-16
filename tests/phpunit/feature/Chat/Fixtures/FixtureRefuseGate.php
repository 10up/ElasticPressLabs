<?php
/**
 * Refusing gate fixture for Chat tests.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Gates\Base_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Result;

/**
 * Required gate that always refuses.
 */
class FixtureRefuseGate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Stage.
	 *
	 * @var string
	 */
	protected $stage;

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
	 * Constructor.
	 *
	 * @param string $slug    Slug.
	 * @param string $stage   Stage.
	 * @param string $reason  Refusal code.
	 * @param string $message Reader-safe copy.
	 */
	public function __construct( string $slug, string $stage, string $reason, string $message ) {
		$this->slug    = $slug;
		$this->stage   = $stage;
		$this->reason  = $reason;
		$this->message = $message;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return $this->slug;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_stage(): string {
		return $this->stage;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_required(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_refusal_reasons(): array {
		return [
			$this->reason => [
				'label'       => $this->slug,
				'message'     => $this->message,
				'description' => 'Always (test fixture).',
			],
		];
	}

	/**
	 * Always refuse.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		unset( $context );

		return Gate_Result::refuse( $this->reason, $this->message );
	}
}
