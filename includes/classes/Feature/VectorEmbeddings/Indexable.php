<?php
/**
 * Vector Embeddings - Indexable
 *
 * As each indexable type (posts, terms, comments, users) uses different hooks, this abstract class is used to
 * keep implementations independent.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

/**
 * Vector Embeddings Indexable abstract class
 */
abstract class Indexable {
	/**
	 * VectorEmbeddings instance
	 *
	 * @var VectorEmbeddings
	 */
	protected $feature;

	/**
	 * Class constructor
	 *
	 * @param VectorEmbeddings $feature The VectorEmbeddings feature instance
	 */
	public function __construct( VectorEmbeddings $feature ) {
		$this->feature = $feature;
	}
}
