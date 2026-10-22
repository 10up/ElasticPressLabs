<?php
/**
 * Test the upgrade routines runner
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Utils;
use ElasticPressLabs\Upgrades;

/**
 * Upgrades test class
 */
class TestUpgrades extends \WP_UnitTestCase {

	/**
	 * Calls made to the fake routines: `[ version, dry_run ]`.
	 *
	 * @var array
	 */
	protected $calls = [];

	/**
	 * Results the fake routines return, keyed by version.
	 *
	 * @var array
	 */
	protected $returns = [];

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->calls   = [];
		$this->returns = [];

		Utils\delete_option( Upgrades::VERSION_OPTION );
		Utils\delete_option( Upgrades::FAILURES_OPTION );
		Utils\delete_option( Upgrades::ACTIONS_OPTION );
		Utils\delete_option( 'ep_hide_' . Upgrades::ACTIONS_NOTICE . '_notice' );
		Utils\delete_transient( Upgrades::BACKOFF_TRANSIENT );
		Utils\delete_transient( Upgrades::LOCK_TRANSIENT );

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_filter( 'ep_labs_upgrade_routines', [ $this, 'fake_routines' ] );
	}

	/**
	 * Replace the routines with fakes: an old one, one for the current version, and a future one.
	 *
	 * @return array
	 */
	public function fake_routines() {
		$routines = [];

		foreach ( [ '999.0.0', ELASTICPRESS_LABS_VERSION, '0.0.1' ] as $version ) {
			$routines[ $version ] = [
				"routine_{$version}" => [
					'label'    => "Routine {$version}",
					'callback' => function ( $dry_run ) use ( $version ) {
						$this->calls[] = [ $version, $dry_run ];

						if ( isset( $this->returns[ $version ] ) ) {
							$value = $this->returns[ $version ];
							if ( $value instanceof \Throwable ) {
								throw $value;
							}
							return $value;
						}

						return [
							'status'  => $dry_run ? Upgrades::PENDING : Upgrades::APPLIED,
							'message' => "Ran {$version}",
						];
					},
				],
			];
		}

		return $routines;
	}

	/**
	 * Versions of the fake routines that ran, in order.
	 *
	 * @return array
	 */
	protected function ran_versions(): array {
		return array_column( $this->calls, 0 );
	}

	/**
	 * On a site with no stored version, every routine up to the current version runs
	 * (in version order), and the current version is stored.
	 *
	 * @group upgrades
	 */
	public function test_runs_routines_up_to_current_version_and_stores_it() {
		$results = ( new Upgrades() )->run();

		$this->assertSame( [ '0.0.1', ELASTICPRESS_LABS_VERSION ], $this->ran_versions() );
		$this->assertSame( [ Upgrades::APPLIED, Upgrades::APPLIED ], array_column( $results, 'status' ) );
		$this->assertSame( "routine_{$this->current()}", $results[1]['routine'] );
		$this->assertSame( get_current_blog_id(), $results[1]['site_id'] );
		$this->assertSame( ELASTICPRESS_LABS_VERSION, Utils\get_option( Upgrades::VERSION_OPTION ) );
	}

	/**
	 * Routines of versions at or below the stored version do not run again.
	 *
	 * @group upgrades
	 */
	public function test_skips_routines_that_already_ran() {
		Utils\update_option( Upgrades::VERSION_OPTION, '0.0.1' );

		( new Upgrades() )->run();

		$this->assertSame( [ ELASTICPRESS_LABS_VERSION ], $this->ran_versions() );
	}

	/**
	 * A failed routine keeps the stored version, is listed in the notice, and pauses
	 * automatic runs.
	 *
	 * @group upgrades
	 */
	public function test_failure_keeps_version_and_backs_off() {
		$this->returns[ ELASTICPRESS_LABS_VERSION ] = [
			'status'  => Upgrades::FAILED,
			'message' => 'Elasticsearch <down>',
		];

		$upgrades = new Upgrades();
		$upgrades->run();

		$this->assertFalse( Utils\get_option( Upgrades::VERSION_OPTION ) );
		$this->assertNotEmpty( Utils\get_transient( Upgrades::BACKOFF_TRANSIENT ) );

		$failures = Utils\get_option( Upgrades::FAILURES_OPTION );
		$this->assertCount( 1, $failures );
		$this->assertSame( "routine_{$this->current()}", $failures[0]['routine'] );

		$notices = $upgrades->add_failures_notice( [] );
		$this->assertArrayHasKey( 'ep_labs_upgrade_failures', $notices );
		$this->assertStringContainsString( 'Elasticsearch &lt;down&gt;', $notices['ep_labs_upgrade_failures']['html'] );
		$this->assertFalse( $notices['ep_labs_upgrade_failures']['dismiss'] );

		// Automatic runs wait for the backoff.
		$this->calls = [];
		$upgrades->maybe_run();
		$this->assertSame( [], $this->calls );
	}

	/**
	 * A later successful run stores the version and clears the failures and the backoff.
	 *
	 * @group upgrades
	 */
	public function test_success_clears_previous_failures() {
		$this->returns[ ELASTICPRESS_LABS_VERSION ] = [
			'status'  => Upgrades::FAILED,
			'message' => 'down',
		];

		$upgrades = new Upgrades();
		$upgrades->run();

		unset( $this->returns[ ELASTICPRESS_LABS_VERSION ] );
		$upgrades->run();

		$this->assertSame( ELASTICPRESS_LABS_VERSION, Utils\get_option( Upgrades::VERSION_OPTION ) );
		$this->assertFalse( Utils\get_option( Upgrades::FAILURES_OPTION ) );
		$this->assertFalse( Utils\get_transient( Upgrades::BACKOFF_TRANSIENT ) );
		$this->assertSame( [], $upgrades->add_failures_notice( [] ) );
	}

	/**
	 * Exceptions and invalid results count as failures.
	 *
	 * @group upgrades
	 */
	public function test_exceptions_and_invalid_results_fail() {
		$this->returns['0.0.1']                     = new \Exception( 'Boom' );
		$this->returns[ ELASTICPRESS_LABS_VERSION ] = 'not an array';

		$results = ( new Upgrades() )->run();

		$this->assertSame( [ Upgrades::FAILED, Upgrades::FAILED ], array_column( $results, 'status' ) );
		$this->assertSame( 'Boom', $results[0]['message'] );
		$this->assertFalse( Utils\get_option( Upgrades::VERSION_OPTION ) );
	}

	/**
	 * `routine` runs one version's routines whatever the stored version, and does not
	 * change the stored version.
	 *
	 * @group upgrades
	 */
	public function test_routine_arg_runs_one_version() {
		Utils\update_option( Upgrades::VERSION_OPTION, ELASTICPRESS_LABS_VERSION );

		$results = ( new Upgrades() )->run( [ 'routine' => '0.0.1' ] );

		$this->assertSame( [ '0.0.1' ], $this->ran_versions() );
		$this->assertCount( 1, $results );

		Utils\update_option( Upgrades::VERSION_OPTION, '0.0.1' );
		( new Upgrades() )->run( [ 'routine' => ELASTICPRESS_LABS_VERSION ] );

		$this->assertSame( '0.0.1', Utils\get_option( Upgrades::VERSION_OPTION ) );

		$this->assertSame( [], ( new Upgrades() )->run( [ 'routine' => '5.5.5' ] ) );
	}

	/**
	 * `force` runs every routine up to the current version, never future ones.
	 *
	 * @group upgrades
	 */
	public function test_force_runs_all_routines_up_to_current_version() {
		Utils\update_option( Upgrades::VERSION_OPTION, ELASTICPRESS_LABS_VERSION );

		( new Upgrades() )->run( [ 'force' => true ] );

		$this->assertSame( [ '0.0.1', ELASTICPRESS_LABS_VERSION ], $this->ran_versions() );
	}

	/**
	 * A dry run passes the flag to the routines and stores nothing.
	 *
	 * @group upgrades
	 */
	public function test_dry_run_changes_nothing() {
		$this->returns[ ELASTICPRESS_LABS_VERSION ] = [
			'status'  => Upgrades::FAILED,
			'message' => 'would fail',
		];

		$results = ( new Upgrades() )->run( [ 'dry_run' => true ] );

		$this->assertSame( [ [ '0.0.1', true ], [ ELASTICPRESS_LABS_VERSION, true ] ], $this->calls );
		$this->assertSame( Upgrades::PENDING, $results[0]['status'] );
		$this->assertFalse( Utils\get_option( Upgrades::VERSION_OPTION ) );
		$this->assertFalse( Utils\get_option( Upgrades::FAILURES_OPTION ) );
		$this->assertFalse( Utils\get_transient( Upgrades::BACKOFF_TRANSIENT ) );
	}

	/**
	 * Automatic runs only happen when the stored version is not the current one.
	 *
	 * @group upgrades
	 */
	public function test_maybe_run_only_when_version_changed() {
		Utils\update_option( Upgrades::VERSION_OPTION, ELASTICPRESS_LABS_VERSION );

		( new Upgrades() )->maybe_run();
		$this->assertSame( [], $this->calls );

		Utils\update_option( Upgrades::VERSION_OPTION, '0.0.1' );

		( new Upgrades() )->maybe_run();
		$this->assertSame( [ ELASTICPRESS_LABS_VERSION ], $this->ran_versions() );
	}

	/**
	 * Automatic runs only happen for users who can manage ElasticPress.
	 *
	 * @group upgrades
	 */
	public function test_maybe_run_needs_capability() {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'subscriber' ] ) );

		( new Upgrades() )->maybe_run();
		$this->assertSame( [], $this->calls );
		$this->assertFalse( Utils\get_option( Upgrades::VERSION_OPTION ) );
	}

	/**
	 * Only one automatic run at a time: a run in progress makes others skip, and its lock
	 * is released when it ends.
	 *
	 * @group upgrades
	 */
	public function test_maybe_run_skips_while_another_run_is_in_progress() {
		Utils\set_transient( Upgrades::LOCK_TRANSIENT, time(), MINUTE_IN_SECONDS );

		( new Upgrades() )->maybe_run();
		$this->assertSame( [], $this->calls );

		Utils\delete_transient( Upgrades::LOCK_TRANSIENT );

		( new Upgrades() )->maybe_run();
		$this->assertSame( [ '0.0.1', ELASTICPRESS_LABS_VERSION ], $this->ran_versions() );
		$this->assertFalse( Utils\get_transient( Upgrades::LOCK_TRANSIENT ) );
	}

	/**
	 * A routine that needs someone's action does not block the version or back off, and is
	 * listed in a dismissible notice, which shows again when what is needed changes.
	 *
	 * @group upgrades
	 */
	public function test_needs_action_stores_version_and_shows_dismissible_notice() {
		$this->returns[ ELASTICPRESS_LABS_VERSION ] = [
			'status'  => Upgrades::NEEDS_ACTION,
			'message' => 'Run a <sync>',
		];

		$upgrades = new Upgrades();
		$upgrades->run();

		$this->assertSame( ELASTICPRESS_LABS_VERSION, Utils\get_option( Upgrades::VERSION_OPTION ) );
		$this->assertFalse( Utils\get_transient( Upgrades::BACKOFF_TRANSIENT ) );
		$this->assertSame( [], $upgrades->add_failures_notice( [] ) );

		$notices = $upgrades->add_actions_notice( [] );
		$this->assertStringContainsString( 'Run a &lt;sync&gt;', $notices[ Upgrades::ACTIONS_NOTICE ]['html'] );
		$this->assertSame( 'warning', $notices[ Upgrades::ACTIONS_NOTICE ]['type'] );
		$this->assertTrue( $notices[ Upgrades::ACTIONS_NOTICE ]['dismiss'] );

		// Dismissed, it stays hidden while the same action is needed.
		Utils\update_option( 'ep_hide_' . Upgrades::ACTIONS_NOTICE . '_notice', true );
		$upgrades->run( [ 'force' => true ] );
		$this->assertSame( [], $upgrades->add_actions_notice( [] ) );

		$this->returns[ ELASTICPRESS_LABS_VERSION ]['message'] = 'Run another sync';
		$upgrades->run( [ 'force' => true ] );
		$this->assertArrayHasKey( Upgrades::ACTIONS_NOTICE, $upgrades->add_actions_notice( [] ) );

		// Once nothing is needed, the notice goes away.
		unset( $this->returns[ ELASTICPRESS_LABS_VERSION ] );
		$upgrades->run( [ 'force' => true ] );
		$this->assertSame( [], $upgrades->add_actions_notice( [] ) );
	}

	/**
	 * After a mapping is put, routines that needed someone's action run again on the next
	 * admin request. Without such routines, nothing changes.
	 *
	 * @group upgrades
	 */
	public function test_put_mapping_rechecks_needed_actions() {
		$upgrades = new Upgrades();
		$upgrades->setup();

		Utils\update_option( Upgrades::VERSION_OPTION, ELASTICPRESS_LABS_VERSION );

		do_action( 'ep_after_put_mapping', 'index', [] );
		$this->assertSame( ELASTICPRESS_LABS_VERSION, Utils\get_option( Upgrades::VERSION_OPTION ) );

		Utils\update_option( Upgrades::ACTIONS_OPTION, [ [ 'message' => 'Run a sync' ] ] );

		do_action( 'ep_after_put_mapping', 'index', [] );
		$this->assertFalse( Utils\get_option( Upgrades::VERSION_OPTION ) );
		$this->assertSame( [], $upgrades->add_actions_notice( [] ) );

		$upgrades->maybe_run();
		$this->assertSame( [ '0.0.1', ELASTICPRESS_LABS_VERSION ], $this->ran_versions() );
	}

	/**
	 * Current Labs version.
	 *
	 * @return string
	 */
	protected function current(): string {
		return ELASTICPRESS_LABS_VERSION;
	}
}
