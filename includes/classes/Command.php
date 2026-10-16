<?php
/**
 * WP-CLI commands for ElasticPress Labs
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs;

use ElasticPress\Utils;
use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	// @codeCoverageIgnoreStart
	exit; // Exit if accessed directly.
	// @codeCoverageIgnoreEnd
}

/**
 * CLI commands for ElasticPress Labs
 */
class Command extends WP_CLI_Command {
	/**
	 * Run the ElasticPress Labs upgrade routines.
	 *
	 * By default, runs the routines added since the Labs version stored for the site, then
	 * stores the current version. The same routines run automatically on the next admin page
	 * load; this command runs them now (useful in deploy scripts). Every routine is safe to run
	 * again.
	 *
	 * ## OPTIONS
	 *
	 * [--routine=<version>]
	 * : Run only the routines of this Labs version, even if they already ran. The stored version is not changed.
	 *
	 * [--force]
	 * : Run every routine up to the current version, even if they already ran.
	 *
	 * [--network-wide]
	 * : Run for every indexable site of the network. With ElasticPress network-activated, every indexable site is always included.
	 *
	 * [--dry-run]
	 * : List what would run and what it would change. Nothing is changed.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what would run
	 *     $ wp elasticpress-labs run-upgrades --dry-run
	 *
	 *     # Run the pending routines
	 *     $ wp elasticpress-labs run-upgrades
	 *
	 *     # Run the 2.6.0 routines again on every site
	 *     $ wp elasticpress-labs run-upgrades --routine=2.6.0 --network-wide
	 *
	 * @subcommand run-upgrades
	 * @param array $args       Positional CLI args.
	 * @param array $assoc_args Associative CLI args.
	 */
	public function run_upgrades( $args, $assoc_args ) {
		$upgrades = new Upgrades();
		$run_args = [
			'routine' => (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'routine', '' ),
			'force'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ),
			'dry_run' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
		];

		if ( '' !== $run_args['routine'] && ! isset( $upgrades->get_routines()[ $run_args['routine'] ] ) ) {
			/* translators: %s: version */
			WP_CLI::error( sprintf( esc_html__( 'There are no routines for version %s.', 'elasticpress-labs' ), $run_args['routine'] ) );
		}

		$results = [];

		if ( $this->should_run_per_site( $assoc_args ) ) {
			foreach ( Utils\get_sites( 0, true ) as $site ) {
				switch_to_blog( (int) $site->blog_id );
				$results = array_merge( $results, $upgrades->run( $run_args ) );
				restore_current_blog();
			}
		} else {
			$results = $upgrades->run( $run_args );
		}

		if ( ! $results ) {
			/* translators: %s: ElasticPress Labs version */
			WP_CLI::success( sprintf( esc_html__( 'Nothing to run: no pending routines up to version %s.', 'elasticpress-labs' ), ELASTICPRESS_LABS_VERSION ) );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $results, [ 'site_id', 'version', 'routine', 'status', 'message' ] );

		$failed = count( wp_list_filter( $results, [ 'status' => Upgrades::FAILED ] ) );

		if ( $failed ) {
			/* translators: %d: number of failed routines */
			WP_CLI::error( sprintf( esc_html( _n( '%d routine failed.', '%d routines failed.', $failed, 'elasticpress-labs' ) ), $failed ) );
		}

		if ( $run_args['dry_run'] ) {
			WP_CLI::success( esc_html__( 'Dry run: nothing was changed.', 'elasticpress-labs' ) );
			return;
		}

		WP_CLI::success( esc_html__( 'Upgrade routines finished.', 'elasticpress-labs' ) );
	}

	/**
	 * Whether the command loops over the sites itself.
	 *
	 * With ElasticPress network-activated, the Upgrades class already runs for every site.
	 *
	 * @param array $assoc_args Associative CLI args.
	 * @return bool
	 */
	protected function should_run_per_site( array $assoc_args ): bool {
		if ( ! is_multisite() || ! WP_CLI\Utils\get_flag_value( $assoc_args, 'network-wide', false ) ) {
			return false;
		}

		return ! ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK );
	}
}
