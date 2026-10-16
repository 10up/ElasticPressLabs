<?php
/**
 * Test the AI-generated query search algorithm
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use ElasticPress\SearchAlgorithms;
use ElasticPressLabs\Feature\Chat\AI_Query;
use ElasticPressLabs\Feature\SearchAlgorithm;

/**
 * AI_Query tests.
 */
class TestChatAIQuery extends BaseTestCase {

	/**
	 * Reply the stubbed query model gives.
	 *
	 * @var string|\WP_Error
	 */
	protected $reply = '';

	/**
	 * Setup each test: two indexed posts, the algorithm registered, the model stubbed.
	 */
	public function set_up() {
		parent::set_up();

		Elasticsearch::factory()->delete_index( Indexables::factory()->get( 'post' )->get_index_name() );
		Indexables::factory()->get( 'post' )->put_mapping();

		$this->ep_factory->post->create(
			[
				'post_title'   => 'Fewer Americans use cash',
				'post_content' => 'Today, 42% of U.S. adults say they do not use cash in a typical week.',
				'post_date'    => '2022-03-01 10:00:00',
			]
		);
		$this->ep_factory->post->create(
			[
				'post_title'   => 'Cash and credit cards in 2015',
				'post_content' => 'Most adults used cash in 2015.',
				'post_date'    => '2015-03-01 10:00:00',
			]
		);

		Elasticsearch::factory()->refresh_indices();

		SearchAlgorithms::factory()->register( new AI_Query() );

		$this->reply = '';

		add_filter( 'ep_post_search_algorithm', [ $this, 'use_ai_query' ], 99, 4 );
		add_filter( 'ep_chat_ai_query_pre', [ $this, 'stub_model' ] );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		SearchAlgorithms::factory()->unregister( AI_Query::SLUG );
		remove_filter( 'ep_post_search_algorithm', [ $this, 'use_ai_query' ], 99 );
		remove_filter( 'ep_chat_ai_query_pre', [ $this, 'stub_model' ] );

		parent::tear_down();
	}

	/**
	 * Use the algorithm for test queries.
	 *
	 * @param string $algorithm   Algorithm.
	 * @param string $search_text Search text.
	 * @param array  $fields      Search fields.
	 * @param array  $query_vars  Query vars.
	 * @return string
	 */
	public function use_ai_query( $algorithm, $search_text, $fields, $query_vars ) {
		unset( $search_text, $fields );

		return empty( $query_vars['ai_query_test'] ) ? $algorithm : AI_Query::SLUG;
	}

	/**
	 * The stubbed query model.
	 *
	 * @return string|\WP_Error
	 */
	public function stub_model() {
		return $this->reply;
	}

	/**
	 * A search with the algorithm.
	 *
	 * @param array $args More query vars.
	 * @return \WP_Query
	 */
	protected function search( array $args = [] ): \WP_Query {
		return new \WP_Query(
			array_merge(
				[
					's'             => 'cash',
					'ep_integrate'  => true,
					'ai_query_test' => true,
					'post_type'     => 'post',
					'post_status'   => 'publish',
				],
				$args
			)
		);
	}

	/**
	 * Registered, but hidden from the Search Algorithm feature and never a Chat fallback.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_hidden_and_uses_a_model() {
		$algorithm = SearchAlgorithms::factory()->get( AI_Query::SLUG );

		$this->assertInstanceOf( AI_Query::class, $algorithm );
		$this->assertTrue( SearchAlgorithm::is_hidden_search_algorithm( $algorithm ) );
		$this->assertArrayNotHasKey( AI_Query::SLUG, SearchAlgorithm::get_public_search_algorithms() );
		$this->assertTrue( $algorithm->uses_model );
		$this->assertInstanceOf( \ElasticPressLabs\Feature\Chat\Query_Writing_Algorithm::class, $algorithm );
	}

	/**
	 * A query written before the search runs as is, inside ElasticPress's search (post filter,
	 * size): the 2015 post does not match the written range. The hook is gone afterwards.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_written_query_runs() {
		$query = $this->search(
			[
				AI_Query::QUERY_VAR => [
					'query' => [
						'bool' => [
							'must'   => [ [ 'match' => [ 'post_content' => 'cash' ] ] ],
							'filter' => [
								[
									'range' => [
										'post_date' => [
											'gte'    => '2020-01-01',
											'format' => 'yyyy-MM-dd',
										],
									],
								],
							],
						],
					],
				],
			]
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertCount( 1, $query->posts );
		$this->assertSame( 'Fewer Americans use cash', $query->posts[0]->post_title );
		$this->assertArrayHasKey( 'query', $query->{AI_Query::RESULT_PROPERTY} );
		$this->assertFalse( has_filter( 'ep_post_formatted_args', [ SearchAlgorithms::factory()->get( AI_Query::SLUG ), 'get_es_query' ] ) );
	}

	/**
	 * Without a written query, the algorithm asks the model during the search; the model's sort
	 * applies when the query has a filter.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_query_is_written_during_the_search() {
		$this->reply = wp_json_encode(
			[
				'query' => [
					'bool' => [
						'must'   => [ [ 'match' => [ 'post_content' => 'cash' ] ] ],
						'filter' => [
							[ 'range' => [ 'post_date' => [ 'gte' => '2010-01-01' ] ] ],
							[ 'term' => [ 'post_type.raw' => 'post' ] ],
						],
					],
				],
				'sort'  => [ [ 'post_date' => 'asc' ] ],
			]
		);

		$query = $this->search();

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertSame( [ 'Cash and credit cards in 2015', 'Fewer Americans use cash' ], wp_list_pluck( $query->posts, 'post_title' ) );
		$this->assertSame( 'asc', $query->{AI_Query::RESULT_PROPERTY}['query']['sort'][0]['post_date']['order'] );
	}

	/**
	 * When the model reply cannot be used, the error is on the WP_Query and ElasticPress's own
	 * search runs, so Chat can tell and fall back.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_failed_writing_is_reported() {
		$this->reply = 'No idea.';

		$query = $this->search();

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertWPError( $query->{AI_Query::RESULT_PROPERTY}['error'] );
		$this->assertSame( 'ep_chat_ai_query_unreadable', $query->{AI_Query::RESULT_PROPERTY}['error']->get_error_code() );
		$this->assertCount( 2, $query->posts );
	}

	/**
	 * A search that is not the algorithm's leaves it alone, even after it ran once.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_other_searches_are_not_changed() {
		$this->search( [ AI_Query::QUERY_VAR => [ 'query' => [ 'match' => [ 'post_title' => 'credit' ] ] ] ] );

		$query = new \WP_Query(
			[
				's'            => 'cash',
				'ep_integrate' => true,
			]
		);

		$this->assertCount( 2, $query->posts );
		$this->assertObjectNotHasProperty( AI_Query::RESULT_PROPERTY, $query );
	}

	/**
	 * Wrapping: the written query and sort replace ElasticPress's; a sort needs a filter; the kNN
	 * search gets the vector, a k of at least the size, the inner hits, the written filters, and
	 * the post filter; a kNN search alone gets the minimum score.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_wrap() {
		$algorithm = new AI_Query();
		$formatted = [
			'from'        => 0,
			'size'        => 20,
			'post_filter' => [ 'bool' => [ 'filter' => [ [ 'term' => [ 'post_status' => 'publish' ] ] ] ] ],
			'query'       => [ 'match_all' => new \stdClass() ],
			'sort'        => [ [ '_score' => [ 'order' => 'desc' ] ] ],
			'_source'     => [ 'post_title' ],
		];
		$args      = [ 'ep_vectors' => [ 0.1, 0.2 ] ];
		$range     = [ 'range' => [ 'post_date' => [ 'gte' => '2020-01-01' ] ] ];
		$not       = [ 'term' => [ 'post_type.raw' => 'page' ] ];
		$type      = [ 'term' => [ 'post_type.raw' => 'post' ] ];

		// Text and sort, without a filter: the sort is dropped.
		$body = $algorithm->wrap(
			$formatted,
			[
				'query' => [ 'match' => [ 'post_title' => 'cash' ] ],
				'sort'  => [ [ 'post_date' => [ 'order' => 'desc' ] ] ],
			],
			$args,
			'cash'
		);

		$this->assertSame( [ 'match' => [ 'post_title' => 'cash' ] ], $body['query'] );
		$this->assertArrayNotHasKey( 'sort', $body );
		$this->assertArrayNotHasKey( 'knn', $body );
		$this->assertArrayNotHasKey( 'min_score', $body );
		$this->assertSame( [ 'post_title' ], $body['_source'] );
		$this->assertSame( $formatted['post_filter'], $body['post_filter'] );

		// Filter, sort, and kNN.
		$body = $algorithm->wrap(
			$formatted,
			[
				'query' => [
					'bool' => [
						'must'     => [ [ 'match' => [ 'post_title' => 'cash' ] ] ],
						'filter'   => [ $range, $type ],
						'must_not' => [ $not ],
					],
				],
				'knn'   => [
					'field'          => 'chunks.vector',
					'k'              => 5,
					'num_candidates' => 10,
				],
				'sort'  => [ [ 'post_date' => [ 'order' => 'desc' ] ] ],
			],
			$args,
			'cash'
		);

		$this->assertSame(
			[
				[ 'post_date' => [ 'order' => 'desc' ] ],
				[ '_score' => [ 'order' => 'desc' ] ],
			],
			$body['sort']
		);
		$this->assertSame( [ 0.1, 0.2 ], $body['knn']['query_vector'] );
		$this->assertSame( 20, $body['knn']['k'] );
		$this->assertSame( 20, $body['knn']['num_candidates'] );
		$this->assertSame(
			[
				$range,
				$type,
				[ 'bool' => [ 'must_not' => [ $not ] ] ],
				$formatted['post_filter'],
			],
			$body['knn']['filter']
		);
		$this->assertArrayNotHasKey( 'min_score', $body );

		if ( version_compare( (string) Elasticsearch::factory()->get_elasticsearch_version(), '8.11', '>=' ) ) {
			$this->assertSame( [ 'chunks.text', 'chunks.type' ], $body['knn']['inner_hits']['_source'] );
		}

		// A date range alone does not keep the results on topic: no sort.
		$body = $algorithm->wrap(
			$formatted,
			[
				'query' => [
					'bool' => [
						'must'   => [ [ 'match' => [ 'post_title' => 'cash' ] ] ],
						'filter' => [ $range ],
					],
				],
				'sort'  => [ [ 'post_date' => [ 'order' => 'desc' ] ] ],
			],
			$args,
			'cash'
		);

		$this->assertArrayNotHasKey( 'sort', $body );

		// kNN alone.
		$body = $algorithm->wrap( $formatted, [ 'knn' => [ 'field' => 'chunks.vector' ] ], $args, 'cash' );

		$this->assertArrayNotHasKey( 'query', $body );
		$this->assertArrayNotHasKey( 'sort', $body );
		$this->assertArrayHasKey( 'min_score', $body );

		// kNN alone, without a vector.
		$result = $algorithm->wrap( $formatted, [ 'knn' => [ 'field' => 'chunks.vector' ] ], [ 'ep_vectors' => [] ], '' );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_ai_query_no_vector', $result->get_error_code() );
	}
}
