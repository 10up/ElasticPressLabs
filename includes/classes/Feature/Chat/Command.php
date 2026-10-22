<?php
/**
 * WP-CLI commands for Chat.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	// @codeCoverageIgnoreStart
	exit; // Exit if accessed directly.
	// @codeCoverageIgnoreEnd
}

/**
 * Chat commands.
 */
class Command extends WP_CLI_Command {
	/**
	 * Export conversations as JSON, the same file the History screen's Export button downloads.
	 *
	 * Writes to stdout, so large exports do not depend on a browser request. Every conversation
	 * is included (like a user who sees everyone's questions). The export records the user set
	 * with `--user` as `exported_by`.
	 *
	 * ## OPTIONS
	 *
	 * [--author=<user>]
	 * : Only this author's conversations (user ID or login).
	 *
	 * [--month=<yyyymm>]
	 * : Only conversations started in this month, for example 202609.
	 *
	 * [--search=<text>]
	 * : Only conversations with a question or summary that contains this text.
	 *
	 * [--trash]
	 * : Export the trashed conversations instead.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp elasticpress-labs chat export --user=admin > ai-chat-export.json
	 *     $ wp elasticpress-labs chat export --month=202609 --search=TikTok > tiktok.json
	 *
	 * @param array $args       Positional CLI args.
	 * @param array $assoc_args Associative CLI args.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		unset( $args );

		$author = 0;

		if ( ! empty( $assoc_args['author'] ) ) {
			$user = is_numeric( $assoc_args['author'] ) ? get_userdata( (int) $assoc_args['author'] ) : get_user_by( 'login', (string) $assoc_args['author'] );

			if ( ! $user ) {
				WP_CLI::error( 'No such author.' );
			}

			$author = (int) $user->ID;
		}

		$month = (string) ( $assoc_args['month'] ?? '' );

		if ( '' !== $month && ! preg_match( '/^\d{6}$/', $month ) ) {
			WP_CLI::error( 'The month must be YYYYMM, for example 202609.' );
		}

		$count = ( new Export() )->stream(
			[
				'view'   => empty( $assoc_args['trash'] ) ? 'all' : 'trash',
				'author' => $author,
				'm'      => $month,
				's'      => (string) ( $assoc_args['search'] ?? '' ),
			],
			true,
			get_current_user_id()
		);

		WP_CLI::log( '' );
		fwrite( STDERR, sprintf( "Exported %d conversations.\n", $count ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
}
