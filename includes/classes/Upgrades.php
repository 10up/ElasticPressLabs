<?php
/**
 * Upgrade routines.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs;

use ElasticPress\Features;
use ElasticPress\Utils;

/**
 * Runs the routines needed when ElasticPress Labs is updated.
 *
 * Works like the ElasticPress Upgrades class: each routine belongs to the Labs
 * version that introduced it, and runs once when a site moves past that version.
 * Differences:
 *
 * - routines run on `admin_init` for users who can manage ElasticPress (never on front-end
 *   requests), one run at a time, or through `wp elasticpress-labs run-upgrades`;
 * - the stored version only moves forward after every routine succeeded, so a
 *   failed routine (Elasticsearch down, for example) runs again later;
 * - after a failure, automatic runs wait an hour and an admin notice lists the failures;
 * - a routine that cannot finish without someone's action (like a sync with `--setup`)
 *   does not block the version: a dismissible notice says what to do, and the routines run
 *   again after the next full mapping update to check it.
 *
 * Routines must be idempotent: they can run again with `--routine` or `--force`.
 * A routine receives `$dry_run` and returns
 * `[ 'status' => 'applied'|'pending'|'skipped'|'needs_action'|'failed', 'message' => string ]`.
 * `pending` is what a dry run returns for work it would do.
 */
class Upgrades {
	/**
	 * Option that stores the last Labs version whose routines all ran.
	 */
	const VERSION_OPTION = 'ep_labs_version';

	/**
	 * Option that stores the failures of the last run, for the admin notice.
	 */
	const FAILURES_OPTION = 'ep_labs_upgrade_failures';

	/**
	 * Option that stores the routines of the last run that need someone's action.
	 */
	const ACTIONS_OPTION = 'ep_labs_upgrade_actions';

	/**
	 * Transient that pauses automatic runs after a failure.
	 */
	const BACKOFF_TRANSIENT = 'ep_labs_upgrade_backoff';

	/**
	 * Transient held while an automatic run is in progress.
	 */
	const LOCK_TRANSIENT = 'ep_labs_upgrade_lock';

	/**
	 * Key of the notice listing the routines that need someone's action.
	 */
	const ACTIONS_NOTICE = 'ep_labs_upgrade_actions';

	/**
	 * Result statuses.
	 */
	const APPLIED      = 'applied';
	const PENDING      = 'pending';
	const SKIPPED      = 'skipped';
	const NEEDS_ACTION = 'needs_action';
	const FAILED       = 'failed';

	/**
	 * Hook the automatic run and the failure notice.
	 */
	public function setup() {
		add_action( 'admin_init', [ $this, 'maybe_run' ] );
		add_filter( 'ep_admin_notices', [ $this, 'add_failures_notice' ] );
		add_filter( 'ep_admin_notices', [ $this, 'add_actions_notice' ] );
		add_action( 'ep_after_put_mapping', [ $this, 'recheck_actions' ] );
	}

	/**
	 * The routines, grouped by the Labs version that introduced them.
	 *
	 * @return array[] `[ version => [ routine_id => [ 'label' => string, 'callback' => callable ] ] ]`
	 */
	public function get_routines(): array {
		$routines = [
			'2.6.0' => [
				'vector_embeddings_mapping' => [
					'label'    => __( 'Add missing Vector Embeddings fields to the post index mapping', 'elasticpress-labs' ),
					'callback' => [ $this, 'upgrade_vector_embeddings_mapping' ],
				],
			],
		];

		/**
		 * Filter the upgrade routines.
		 *
		 * @hook ep_labs_upgrade_routines
		 * @since 2.6.0
		 * @param {array} $routines `[ version => [ routine_id => [ 'label' => string, 'callback' => callable ] ] ]`
		 * @return {array} New routines
		 */
		$routines = (array) apply_filters( 'ep_labs_upgrade_routines', $routines );

		uksort( $routines, 'version_compare' );

		return $routines;
	}

	/**
	 * The last Labs version whose routines all ran, or false if none did.
	 *
	 * @return string|false
	 */
	public function get_stored_version() {
		return Utils\get_option( self::VERSION_OPTION, false );
	}

	/**
	 * Run the pending routines on admin requests, when there are any.
	 *
	 * Only for users who can manage ElasticPress, as routines send requests to Elasticsearch
	 * for every site, and only one request runs them at a time.
	 */
	public function maybe_run() {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ELASTICPRESS_LABS_VERSION === $this->get_stored_version() ) {
			return;
		}

		if ( ! current_user_can( Utils\get_capability() ) ) {
			return;
		}

		if ( Utils\get_transient( self::BACKOFF_TRANSIENT ) || Utils\get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		Utils\set_transient( self::LOCK_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );

		try {
			$this->run();
		} finally {
			Utils\delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Run routines.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type string $routine Only run the routines of this version, whatever the stored version.
	 *                           The stored version is not changed.
	 *     @type bool   $force   Run every routine up to the current version.
	 *     @type bool   $dry_run Report what would run. Nothing is changed.
	 * }
	 * @return array[] One row per routine and site: `version`, `routine`, `label`, `site_id`, `status`, `message`.
	 */
	public function run( array $args = [] ): array {
		$args = wp_parse_args(
			$args,
			[
				'routine' => '',
				'force'   => false,
				'dry_run' => false,
			]
		);

		$routines = $this->select_routines( (string) $args['routine'], (bool) $args['force'] );
		$results  = [];

		foreach ( $this->get_site_ids() as $site_id ) {
			$switched = $this->switch_to_site( $site_id );

			foreach ( $routines as $version => $version_routines ) {
				foreach ( $version_routines as $routine_id => $routine ) {
					$results[] = array_merge(
						[
							'version' => $version,
							'routine' => $routine_id,
							'label'   => $routine['label'] ?? $routine_id,
							'site_id' => get_current_blog_id(),
						],
						$this->run_routine( $routine, (bool) $args['dry_run'] )
					);
				}
			}

			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( ! $args['dry_run'] ) {
			$this->record( $results, '' === (string) $args['routine'] );
		}

		return $results;
	}

	/**
	 * Routines to run.
	 *
	 * @param string $version Only this version's routines, if set.
	 * @param bool   $force   Every routine up to the current version.
	 * @return array[]
	 */
	protected function select_routines( string $version, bool $force ): array {
		$routines = $this->get_routines();

		if ( '' !== $version ) {
			return isset( $routines[ $version ] ) ? [ $version => $routines[ $version ] ] : [];
		}

		$stored = $this->get_stored_version();

		return array_filter(
			$routines,
			function ( $routine_version ) use ( $stored, $force ) {
				if ( version_compare( $routine_version, ELASTICPRESS_LABS_VERSION, '>' ) ) {
					return false;
				}

				return $force || ! $stored || version_compare( $stored, $routine_version, '<' );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Sites the routines run for.
	 *
	 * With ElasticPress network-activated, the version is stored once for the network
	 * and each indexable site has its own indices, so routines run once per site.
	 * Otherwise they run for the current site.
	 *
	 * @return int[]
	 */
	protected function get_site_ids(): array {
		if ( is_multisite() && defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			return array_map( 'absint', wp_list_pluck( Utils\get_sites( 0, true ), 'blog_id' ) );
		}

		return [ get_current_blog_id() ];
	}

	/**
	 * Switch to a site if it is not the current one.
	 *
	 * @param int $site_id Site ID.
	 * @return bool Whether it switched.
	 */
	protected function switch_to_site( int $site_id ): bool {
		if ( ! is_multisite() || get_current_blog_id() === $site_id ) {
			return false;
		}

		switch_to_blog( $site_id );

		return true;
	}

	/**
	 * Run one routine and normalize its result.
	 *
	 * @param array $routine Routine.
	 * @param bool  $dry_run Whether this is a dry run.
	 * @return array `status` and `message`.
	 */
	protected function run_routine( array $routine, bool $dry_run ): array {
		if ( empty( $routine['callback'] ) || ! is_callable( $routine['callback'] ) ) {
			return [
				'status'  => self::FAILED,
				'message' => __( 'The routine callback is not callable.', 'elasticpress-labs' ),
			];
		}

		try {
			$result = call_user_func( $routine['callback'], $dry_run );
		} catch ( \Throwable $th ) {
			return [
				'status'  => self::FAILED,
				'message' => $th->getMessage(),
			];
		}

		$statuses = [ self::APPLIED, self::PENDING, self::SKIPPED, self::NEEDS_ACTION, self::FAILED ];

		if ( ! is_array( $result ) || ! in_array( $result['status'] ?? '', $statuses, true ) ) {
			return [
				'status'  => self::FAILED,
				'message' => __( 'The routine returned an invalid result.', 'elasticpress-labs' ),
			];
		}

		return [
			'status'  => $result['status'],
			'message' => (string) ( $result['message'] ?? '' ),
		];
	}

	/**
	 * Store the outcome of a run.
	 *
	 * Routines that need someone's action are stored for their notice. Without failures, a
	 * run of the pending (or all) routines stores the current version and clears old failures.
	 * With failures, the version is kept, the failures are stored for the notice, and automatic
	 * runs pause for an hour.
	 *
	 * @param array $results       Run results.
	 * @param bool  $store_version Whether the run covered the pending routines, not a single version.
	 */
	protected function record( array $results, bool $store_version ) {
		$this->record_actions( array_values( wp_list_filter( $results, [ 'status' => self::NEEDS_ACTION ] ) ) );

		$failures = array_values( wp_list_filter( $results, [ 'status' => self::FAILED ] ) );

		if ( $failures ) {
			Utils\update_option( self::FAILURES_OPTION, $failures );
			Utils\set_transient( self::BACKOFF_TRANSIENT, time(), HOUR_IN_SECONDS );
			return;
		}

		if ( $store_version ) {
			Utils\update_option( self::VERSION_OPTION, ELASTICPRESS_LABS_VERSION );
		}

		Utils\delete_option( self::FAILURES_OPTION );
		Utils\delete_transient( self::BACKOFF_TRANSIENT );
	}

	/**
	 * Store the routines that need someone's action. When they changed, a dismissed notice
	 * shows again.
	 *
	 * @since 2.6.0
	 * @param array $actions Results with the `needs_action` status.
	 */
	protected function record_actions( array $actions ) {
		if ( ! $actions ) {
			Utils\delete_option( self::ACTIONS_OPTION );
			return;
		}

		$messages = wp_list_pluck( $actions, 'message' );
		$previous = wp_list_pluck( (array) Utils\get_option( self::ACTIONS_OPTION, [] ), 'message' );

		if ( $messages !== $previous ) {
			Utils\delete_option( 'ep_hide_' . self::ACTIONS_NOTICE . '_notice' );
		}

		Utils\update_option( self::ACTIONS_OPTION, $actions );
	}

	/**
	 * After an index mapping is put (a sync with `--setup`, for example), run the routines
	 * again on the next admin request, to check whether the actions they asked for are done.
	 *
	 * @since 2.6.0
	 */
	public function recheck_actions() {
		if ( ! Utils\get_option( self::ACTIONS_OPTION, [] ) ) {
			return;
		}

		Utils\delete_option( self::ACTIONS_OPTION );
		Utils\delete_option( self::VERSION_OPTION );
	}

	/**
	 * Show what the routines of the last run need someone to do.
	 *
	 * @since 2.6.0
	 * @param array $notices Admin notices.
	 * @return array
	 */
	public function add_actions_notice( $notices ) {
		$actions = Utils\get_option( self::ACTIONS_OPTION, [] );

		if ( empty( $actions ) || ! is_array( $actions ) || Utils\get_option( 'ep_hide_' . self::ACTIONS_NOTICE . '_notice', false ) ) {
			return $notices;
		}

		$items = array_map(
			function ( $action ) {
				return sprintf( '<li>%s</li>', esc_html( $action['message'] ?? '' ) );
			},
			$actions
		);

		$notices[ self::ACTIONS_NOTICE ] = [
			'html'    => __( 'ElasticPress Labs needs your action to finish its update:', 'elasticpress-labs' ) . '<ul>' . implode( '', $items ) . '</ul>',
			'type'    => 'warning',
			'dismiss' => true,
		];

		return $notices;
	}

	/**
	 * Show the failures of the last run.
	 *
	 * @param array $notices Admin notices.
	 * @return array
	 */
	public function add_failures_notice( $notices ) {
		$failures = Utils\get_option( self::FAILURES_OPTION, [] );

		if ( empty( $failures ) || ! is_array( $failures ) ) {
			return $notices;
		}

		$items = array_map(
			function ( $failure ) {
				return sprintf(
					'<li><strong>%1$s</strong> (%2$s): %3$s</li>',
					esc_html( $failure['label'] ?? '' ),
					esc_html( $failure['version'] ?? '' ),
					esc_html( $failure['message'] ?? '' )
				);
			},
			$failures
		);

		$notices['ep_labs_upgrade_failures'] = [
			'html'    => sprintf(
				/* translators: 1: list of failed routines, 2: WP-CLI command */
				__( 'Some ElasticPress Labs upgrade routines failed. They run again automatically in an hour, or when you run %2$s. %1$s', 'elasticpress-labs' ),
				'<ul>' . implode( '', $items ) . '</ul>',
				'<code>wp elasticpress-labs run-upgrades</code>'
			),
			'type'    => 'error',
			'dismiss' => false,
		];

		return $notices;
	}

	/**
	 * Routine: add missing Vector Embeddings fields to the post index mapping.
	 *
	 * @param bool $dry_run Whether this is a dry run.
	 * @return array
	 */
	public function upgrade_vector_embeddings_mapping( bool $dry_run ): array {
		$feature = Features::factory()->get_registered_feature( 'vector_embeddings' );

		if ( ! $feature ) {
			return [
				'status'  => self::SKIPPED,
				'message' => __( 'Vector Embeddings is not registered.', 'elasticpress-labs' ),
			];
		}

		// Skips the indices while the feature is inactive.
		return $feature->ensure_mapping( $dry_run );
	}
}
