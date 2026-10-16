<?php
/**
 * Fixture provider that returns a fixed ID order.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Rerank_Provider;

/**
 * Fixture re-ranker used by TestChatPipeline.
 */
class ReverseRerankProvider implements Rerank_Provider {
	/**
	 * First passage ID.
	 *
	 * @var int
	 */
	private $first;

	/**
	 * Second passage ID.
	 *
	 * @var int
	 */
	private $second;

	/**
	 * Constructor.
	 *
	 * @param int $first  First ID.
	 * @param int $second Second ID.
	 */
	public function __construct( $first, $second ) {
		$this->first  = $first;
		$this->second = $second;
	}

	/**
	 * Score documents for a query.
	 *
	 * @param string $query     User question.
	 * @param array  $documents List of [ id => mixed, text => string ].
	 * @return array
	 */
	public function rerank( string $query, array $documents ) {
		unset( $query, $documents );

		return [
			[
				'id'    => $this->second,
				'score' => 0.9,
			],
			[
				'id'    => $this->first,
				'score' => 0.2,
			],
		];
	}
}
