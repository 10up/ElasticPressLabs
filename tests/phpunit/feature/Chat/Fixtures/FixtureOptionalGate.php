<?php
/**
 * Optional gate fixture for Chat tests.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Gates\Base_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Result;

/**
 * Optional before-ask gate that tags context when it runs.
 */
class FixtureOptionalGate extends Base_Gate {
	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'fixture_optional';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Fixture optional';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_stage(): string {
		return self::STAGE_BEFORE_ASK;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_required(): bool {
		return false;
	}

	/**
	 * Mark context so tests can see this gate ran.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$context['fixture_optional'] = true;

		return Gate_Result::modify( $context );
	}
}
