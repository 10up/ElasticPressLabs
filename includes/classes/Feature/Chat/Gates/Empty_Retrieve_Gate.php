<?php
/**
 * Required gate: decline when no passages remain.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Refusals;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Empty_Retrieve_Gate: declines the question when the search finds no passages.
 */
class Empty_Retrieve_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'empty_retrieve';

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	public const REASON = 'no_supporting_passage';

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
		return esc_html__( 'Empty retrieve', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Declines the question when the search finds nothing to answer from.', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_stage(): string {
		return self::STAGE_FILTER_PASSAGES;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_required_reason(): string {
		return __( 'Without sources there is nothing to answer from.', 'elasticpress-labs' );
	}

	/**
	 * Refuse when retrieve/re-rank left no passages.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$passages = $context['passages'] ?? [];

		if ( is_array( $passages ) && [] !== $passages ) {
			return Gate_Result::pass();
		}

		return $this->refuse( self::REASON );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return [
			self::REASON => [
				'label'       => __( 'No sources', 'elasticpress-labs' ),
				'message'     => __( 'No relevant sources were found for that question.', 'elasticpress-labs' ),
				'description' => __( 'The search found nothing on this site that answers the question.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_NO_SOURCES,
			],
		];
	}
}
