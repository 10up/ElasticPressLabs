<?php
/**
 * Chat pipeline progress steps.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\Chat\Gates\Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Step slugs a running question reports, and their reader-facing labels.
 *
 * The step is stored apart from the status, so plugins can add steps
 * without new statuses. Any code on the worker can report progress with
 * `do_action( 'ep_chat_step', 'my_step' )` and label it through
 * `ep_chat_step_labels`.
 */
class Steps {
	/**
	 * Retrieve passages.
	 *
	 * @var string
	 */
	public const RETRIEVE = 'retrieve';

	/**
	 * Re-rank passages.
	 *
	 * @var string
	 */
	public const RERANK = 'rerank';

	/**
	 * Generate the answer.
	 *
	 * @var string
	 */
	public const SYNTHESIZE = 'synthesize';

	/**
	 * Report the current step of the running question.
	 *
	 * @param string $step Step slug.
	 * @return void
	 */
	public static function report( string $step ) {
		/**
		 * Fires when the Chat pipeline starts a step. The worker stores the
		 * step and refreshes the job heartbeat, so long steps can report
		 * again to show they are still alive.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_step
		 * @param {string} $step Step slug.
		 */
		do_action( 'ep_chat_step', $step );
	}

	/**
	 * Step labels keyed by step slug.
	 *
	 * @return array
	 */
	public static function get_labels(): array {
		$labels = [
			Gate::STAGE_BEFORE_ASK          => __( 'Reading the question…', 'elasticpress-labs' ),
			self::RETRIEVE                  => __( 'Searching sources…', 'elasticpress-labs' ),
			self::RERANK                    => __( 'Ranking sources…', 'elasticpress-labs' ),
			Gate::STAGE_FILTER_PASSAGES     => __( 'Checking sources…', 'elasticpress-labs' ),
			self::SYNTHESIZE                => __( 'Writing the answer…', 'elasticpress-labs' ),
			Gate::STAGE_VERIFY_ANSWER       => __( 'Checking the answer against sources…', 'elasticpress-labs' ),
			Gates\Claim_Verifier_Gate::STEP => __( 'Double-checking rankings and places…', 'elasticpress-labs' ),
			Gate::STAGE_ATTRIBUTE_ANSWER    => __( 'Adding citations…', 'elasticpress-labs' ),
		];

		/**
		 * Filters the labels Ask shows while a question runs.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_step_labels
		 * @param {array} $labels Labels keyed by step slug.
		 * @return {array} Filtered labels.
		 */
		$labels = apply_filters( 'ep_chat_step_labels', $labels );

		return is_array( $labels ) ? $labels : [];
	}

	/**
	 * Label for a question that has not finished.
	 *
	 * @param string $step   Step slug. Empty when no step was reported.
	 * @param string $status Question status.
	 * @return string
	 */
	public static function label( string $step, string $status ): string {
		if ( Storage::STATUS_QUEUED === $status ) {
			return __( 'Waiting to start…', 'elasticpress-labs' );
		}

		$labels = self::get_labels();

		if ( '' !== $step && ! empty( $labels[ $step ] ) ) {
			return (string) $labels[ $step ];
		}

		return __( 'Thinking…', 'elasticpress-labs' );
	}
}
