<?php
/**
 * Chat re-ranker provider contract.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Normalizes a re-ranker response into scores keyed by passage ID.
 */
interface Rerank_Provider {
	/**
	 * Score documents for a query.
	 *
	 * @param string $query     User question.
	 * @param array  $documents List of [ id => mixed, text => string ].
	 * @return array|\WP_Error List of [ id => mixed, score => float ].
	 */
	public function rerank( string $query, array $documents );
}
