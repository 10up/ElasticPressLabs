<?php
/**
 * Gate (optional, on by default): add citation markers and provenance to the answer.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Citations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Citation_Attributor_Gate (deterministic).
 *
 * Runs on the last stage, after the answer passed every check. Replaces the answer with the
 * same text plus `[[k]]` markers (see Citations) and adds their `provenance`. Never refuses.
 */
class Citation_Attributor_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'citation_attributor';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_label(): string {
		return esc_html__( 'Citation attributor', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Links each sentence of the answer to the source passage it comes from.', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_stage(): string {
		return self::STAGE_ATTRIBUTE_ANSWER;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool {
		return true;
	}

	/**
	 * Add markers to the answer and their provenance to the context.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$passages = is_array( $context['passages'] ?? null ) ? $context['passages'] : [];
		$result   = ( new Citations() )->attribute( (string) ( $context['content'] ?? '' ), $passages );

		return Gate_Result::modify( $result );
	}
}
