<?php
/**
 * Run Chat gates for one stage.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Skips optional gates the selected bot has off.
 */
class Gate_Runner {
	/**
	 * Run every enabled gate on a stage.
	 *
	 * @param string $stage   Stage slug.
	 * @param array  $context Pipeline context.
	 * @param array  $bot     Selected bot.
	 * @return array{context: array, result: Gate_Result}
	 */
	public function run_stage( string $stage, array $context, array $bot ): array {
		foreach ( Gates::registry()->get_for_stage( $stage ) as $gate ) {
			if ( ! Gates::should_run( $gate, $bot ) ) {
				continue;
			}

			$result = $gate->run( $context );

			if ( ! $result instanceof Gate_Result ) {
				continue;
			}

			if ( $result->is_refuse() || $result->is_error() ) {
				return [
					'context' => array_merge( $context, $result->get_context() ),
					'result'  => $result,
				];
			}

			if ( $result->is_modify() ) {
				$context = array_merge( $context, $result->get_context() );
			}
		}

		return [
			'context' => $context,
			'result'  => Gate_Result::pass(),
		];
	}
}
