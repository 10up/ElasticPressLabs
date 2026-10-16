<?php
/**
 * Fixture re-ranker used by TestChatReranker.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Rerank_Provider;

/**
 * Returns a fixed ranked list or error.
 */
class FixtureRerankProvider implements Rerank_Provider {
	/**
	 * Fixed result or error.
	 *
	 * @var array|\WP_Error
	 */
	private $ranked;

	/**
	 * Constructor.
	 *
	 * @param array|\WP_Error $ranked Fixed result.
	 */
	public function __construct( $ranked ) {
		$this->ranked = $ranked;
	}

	/**
	 * Score documents for a query.
	 *
	 * @param string $query     User question.
	 * @param array  $documents List of [ id => mixed, text => string ].
	 * @return array|\WP_Error
	 */
	public function rerank( string $query, array $documents ) {
		unset( $query, $documents );

		return $this->ranked;
	}
}
