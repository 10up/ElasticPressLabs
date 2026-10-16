<?php
/**
 * Fixture gate that records the answer it saw.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Gates\Base_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Result;

/**
 * Required gate that passes and records `content` from each run.
 */
class FixtureSpyGate extends Base_Gate {
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
	 * Answers seen, by reference.
	 *
	 * @var array
	 */
	protected $seen;

	/**
	 * Constructor.
	 *
	 * @param string $slug  Slug.
	 * @param string $stage Stage.
	 * @param array  $seen  Answers seen, filled by reference.
	 */
	public function __construct( string $slug, string $stage, array &$seen ) {
		$this->slug  = $slug;
		$this->stage = $stage;
		$this->seen  = &$seen;
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
		return 'Fixture spy';
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
	 * Record the answer and pass.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$this->seen[] = (string) ( $context['content'] ?? '' );

		return Gate_Result::pass();
	}
}
