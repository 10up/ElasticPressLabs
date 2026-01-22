<?php
/**
 * Disable After Failures trait
 *
 * This trait disables the feature after a certain number of failures.
 *
 * @since 2.5.1
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Traits;

use ElasticPress\FeatureRequirementsStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

trait DisableAfterFailures {
	/**
	 * Get the maximum number of failures allowed.
	 *
	 * @return int
	 */
	protected function get_max_failures_count(): int {
		/**
		 * Filter the maximum number of failures allowed.
		 *
		 * @hook ep_max_failures_count
		 * @since 2.5.1
		 * @param {int}     $max_failures_count The maximum number of failures allowed. Default is 3.
		 * @param {Feature} $feature            The feature object.
		 * @return {int} The maximum number of failures allowed.
		 */
		return (int) apply_filters( 'ep_max_failures_count', 3, $this );
	}

	/**
	 * Get the timeframe for the failures.
	 *
	 * @return int
	 */
	protected function get_max_failures_timeframe(): int {
		/**
		 * Filter the timeframe for the failures.
		 *
		 * @hook ep_max_failures_timeframe
		 * @since 2.5.1
		 * @param {int}     $max_failures_timeframe The timeframe for the failures. Default is 1 hour.
		 * @param {Feature} $feature                The feature object.
		 * @return {int} The timeframe for the failures.
		 */
		return (int) apply_filters( 'ep_max_failures_timeframe', HOUR_IN_SECONDS, $this );
	}

	/**
	 * Get the transient key for the failures.
	 *
	 * @return string
	 */
	protected function get_failures_transient_key(): string {
		/**
		 * Filter the transient key for the failures.
		 *
		 * @hook ep_failures_transient_key
		 * @since 2.5.1
		 * @param {string}  $transient_key The transient key for the failures. Default is "ep_{$feature->slug}_failures".
		 * @param {Feature} $feature       The feature object.
		 * @return {string} The transient key for the failures.
		 */
		return apply_filters( 'ep_failures_transient_key', "ep_{$this->slug}_failures", $this );
	}

	/**
	 * Update the failures count.
	 *
	 * @return void
	 */
	protected function update_failures_count() {
		$transient_key = $this->get_failures_transient_key();
		$failures      = $this->cleanup_failures( (array) get_transient( $transient_key ) );
		$failures[]    = time();
		set_transient( $transient_key, $failures, $this->get_max_failures_timeframe() );
	}

	/**
	 * Cleanup the failures.
	 *
	 * @param array $failures The failures.
	 * @return array The cleaned failures.
	 */
	protected function cleanup_failures( $failures ) {
		$max_allowed_time = time() - $this->get_max_failures_timeframe();

		$failures = array_filter(
			$failures,
			function ( $failure_time ) use ( $max_allowed_time ) {
				return $failure_time > $max_allowed_time;
			}
		);

		// To avoid bloating the transient, we only keep the last $max_failures_count + 1 failures.
		$failures = array_slice( $failures, - ( $this->get_max_failures_count() + 1 ) );

		return $failures;
	}

	/**
	 * If the feature should be temporarily disabled after a certain number of failures.
	 *
	 * @return boolean
	 */
	public function should_disable_after_failures() {
		$failures = $this->cleanup_failures( (array) get_transient( $this->get_failures_transient_key() ) );
		return count( $failures ) > $this->get_max_failures_count();
	}

	/**
	 * Update the requirements status.
	 *
	 * @param FeatureRequirementsStatus $status The feature requirements status object.
	 * @return FeatureRequirementsStatus The feature requirements status object.
	 */
	public function update_requirements_status( FeatureRequirementsStatus $status ) {
		$max_failures_count = $this->get_max_failures_count();

		$status->code      = 3;
		$status->message[] = wp_sprintf(
			/* translators: 1: Maximum number of failures */
			esc_html__( 'The feature has been temporarily disabled after %d failures.', 'elasticpress-labs' ),
			$max_failures_count
		);

		return $status;
	}
}
