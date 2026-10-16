<?php
/**
 * Test the checks on a query a model wrote
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Query_Validator;

/**
 * Query_Validator tests.
 */
class TestChatQueryValidator extends \WP_UnitTestCase {

	/**
	 * A validator with a small index: content fields, two taxonomies (one listed), and chunks.
	 *
	 * @return Query_Validator
	 */
	protected function get_validator(): Query_Validator {
		$fields = [];

		foreach (
			[
				'post_title'          => 'text',
				'post_content'        => 'text',
				'post_date'           => 'date',
				'post_type.raw'       => 'keyword',
				'terms.formats.name'  => 'text',
				'terms.formats.slug'  => 'keyword',
				'terms.category.name' => 'text',
				'terms.category.slug' => 'keyword',
				'terms.post_tag.slug' => 'keyword',
				'chunks'              => 'nested',
				'chunks.text'         => 'text',
				'chunks.vector'       => 'dense_vector',
			] as $path => $type
		) {
			$fields[ $path ] = [
				'type' => $type,
				'note' => '',
			];
		}

		return new Query_Validator(
			$fields,
			[
				'formats' => [
					'report'     => 'Report',
					'fact-sheet' => 'Fact Sheet',
				],
			]
		);
	}

	/**
	 * A typical query passes unchanged, but for the date formats a range on a date field reads.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_valid_query_passes_unchanged() {
		$query = [
			'query' => [
				'bool' => [
					'must'                 => [
						[
							'multi_match' => [
								'query'  => 'trust in government',
								'fields' => [ 'post_title^3', 'post_content' ],
								'type'   => 'best_fields',
							],
						],
					],
					'filter'               => [
						[ 'range' => [ 'post_date' => [ 'gte' => '2020-01-01' ] ] ],
						[ 'term' => [ 'terms.formats.slug' => 'report' ] ],
					],
					'should'               => [
						[ 'match_phrase' => [ 'post_title' => 'trust' ] ],
					],
					'minimum_should_match' => 0,
				],
			],
			'sort'  => [
				[ 'post_date' => [ 'order' => 'desc' ] ],
			],
		];

		$expected = $query;

		$expected['query']['bool']['filter'][0]['range']['post_date']['format'] = Query_Validator::DATE_FORMATS;

		$this->assertSame( $expected, $this->get_validator()->validate( $query ) );
	}

	/**
	 * Only `query`, `knn`, and `sort` are kept: code sets size, source, and filters.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_other_top_level_keys_are_dropped() {
		$result = $this->get_validator()->validate(
			[
				'query'            => [ 'match' => [ 'post_title' => 'cash' ] ],
				'size'             => 10000,
				'from'             => 50,
				'_source'          => [ 'post_password' ],
				'aggs'             => [ 'x' => [ 'terms' => [ 'field' => 'post_type.raw' ] ] ],
				'post_filter'      => [ 'match_all' => new \stdClass() ],
				'runtime_mappings' => [ 'x' => [ 'type' => 'keyword' ] ],
				'script_fields'    => [ 'x' => [ 'script' => 'params._source' ] ],
				'stored_fields'    => [ '*' ],
				'profile'          => true,
			]
		);

		$this->assertSame( [ 'query' => [ 'match' => [ 'post_title' => 'cash' ] ] ], $result );
	}

	/**
	 * A clause type outside the allowed list rejects the whole query.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_disallowed_clause_types_reject_the_query() {
		$disallowed = [
			[ 'script' => [ 'script' => [ 'source' => 'true' ] ] ],
			[
				'script_score' => [
					'query'  => [ 'match_all' => new \stdClass() ],
					'script' => [ 'source' => '1' ],
				],
			],
			[ 'function_score' => [ 'query' => [ 'match_all' => new \stdClass() ] ] ],
			[ 'query_string' => [ 'query' => 'post_password:*' ] ],
			[ 'wildcard' => [ 'post_title' => 'a*' ] ],
			[ 'regexp' => [ 'post_title' => '.*' ] ],
			[ 'more_like_this' => [ 'like' => 'x' ] ],
		];

		foreach ( $disallowed as $clause ) {
			$result = $this->get_validator()->validate( [ 'query' => [ 'bool' => [ 'filter' => [ $clause ] ] ] ] );

			$this->assertWPError( $result, (string) key( $clause ) );
			$this->assertSame( 'ep_chat_ai_query_clause_not_allowed', $result->get_error_code() );
		}
	}

	/**
	 * A clause on a field outside the field list is dropped (the search gets wider, and the
	 * field is never read); a multi_match keeps its allowed fields only.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_clauses_on_other_fields_are_dropped() {
		$result = $this->get_validator()->validate(
			[
				'query' => [
					'bool' => [
						'must'   => [
							[ 'match' => [ 'post_content' => 'cash' ] ],
							[ 'match' => [ 'post_password' => 'secret' ] ],
							[
								'multi_match' => [
									'query'  => 'cash',
									'fields' => [ 'post_title^2', 'post_author.login', 'meta.*' ],
								],
							],
						],
						'filter' => [
							[ 'exists' => [ 'field' => 'post_password' ] ],
							[ 'term' => [ 'post_status' => 'draft' ] ],
						],
					],
				],
			]
		);

		$this->assertSame(
			[
				'query' => [
					'bool' => [
						'must' => [
							[ 'match' => [ 'post_content' => 'cash' ] ],
							[
								'multi_match' => [
									'query'  => 'cash',
									'fields' => [ 'post_title^2' ],
								],
							],
						],
					],
				],
			],
			$result
		);
	}

	/**
	 * A multi_match without fields would search every field: it gets the allowed text fields
	 * outside nested paths.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_multi_match_without_fields_gets_the_allowed_text_fields() {
		$result = $this->get_validator()->validate( [ 'query' => [ 'multi_match' => [ 'query' => 'cash' ] ] ] );

		$this->assertSame(
			[ 'post_title', 'post_content', 'terms.formats.name', 'terms.category.name' ],
			$result['query']['multi_match']['fields']
		);
	}

	/**
	 * A slug of a listed taxonomy that exists is kept. An unknown slug, or any slug of a
	 * taxonomy that is not listed, is matched by name instead; without a name field the clause
	 * is dropped.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_taxonomy_slugs() {
		$validator = $this->get_validator();

		$this->assertSame(
			[ 'query' => [ 'term' => [ 'terms.formats.slug' => 'report' ] ] ],
			$validator->validate( [ 'query' => [ 'term' => [ 'terms.formats.slug' => 'report' ] ] ] )
		);

		$this->assertSame(
			[
				'query' => [
					'match' => [
						'terms.formats.name' => [
							'query'    => 'data essay',
							'operator' => 'and',
						],
					],
				],
			],
			$validator->validate( [ 'query' => [ 'term' => [ 'terms.formats.slug' => [ 'value' => 'data-essay' ] ] ] ] )
		);

		$this->assertSame(
			[
				'query' => [
					'match' => [
						'terms.category.name' => [
							'query'    => 'religion and politics',
							'operator' => 'and',
						],
					],
				],
			],
			$validator->validate( [ 'query' => [ 'term' => [ 'terms.category.slug' => 'religion-and-politics' ] ] ] ),
			'A taxonomy that is not listed is matched by name.'
		);

		$this->assertSame(
			[
				'query' => [
					'bool' => [
						'should'               => [
							[ 'terms' => [ 'terms.formats.slug' => [ 'report', 'fact-sheet' ] ] ],
							[
								'match' => [
									'terms.formats.name' => [
										'query'    => 'short read',
										'operator' => 'and',
									],
								],
							],
						],
						'minimum_should_match' => 1,
					],
				],
			],
			$validator->validate( [ 'query' => [ 'terms' => [ 'terms.formats.slug' => [ 'report', 'short-read', 'fact-sheet' ] ] ] ] )
		);

		// No `terms.post_tag.name` field: the clause is dropped, and the query with it.
		$result = $validator->validate( [ 'query' => [ 'term' => [ 'terms.post_tag.slug' => 'cash' ] ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_ai_query_empty', $result->get_error_code() );
	}

	/**
	 * A nested query must use an allowed nested path; its inner hits are code's to set.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_nested() {
		$validator = $this->get_validator();

		$this->assertSame(
			[
				'query' => [
					'nested' => [
						'path'       => 'chunks',
						'query'      => [ 'match' => [ 'chunks.text' => 'cash' ] ],
						'score_mode' => 'max',
					],
				],
			],
			$validator->validate(
				[
					'query' => [
						'nested' => [
							'path'       => 'chunks',
							'query'      => [ 'match' => [ 'chunks.text' => 'cash' ] ],
							'score_mode' => 'max',
							'inner_hits' => [ '_source' => [ 'chunks.vector' ] ],
						],
					],
				]
			)
		);

		$this->assertWPError(
			$validator->validate(
				[
					'query' => [
						'nested' => [
							'path'  => 'post_title',
							'query' => [ 'match' => [ 'post_title' => 'cash' ] ],
						],
					],
				]
			)
		);
	}

	/**
	 * knn: only an allowed vector field; the vector, its builder, and inner hits are removed (code
	 * sets them); k and candidates are capped; the filter is checked like a query.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_knn() {
		$validator = $this->get_validator();
		$result    = $validator->validate(
			[
				'knn' => [
					'field'                => 'chunks.vector',
					'query_vector'         => '[PLACEHOLDER]',
					'query_vector_builder' => [ 'text_embedding' => [ 'model_id' => 'x' ] ],
					'k'                    => 5000,
					'num_candidates'       => 99999,
					'inner_hits'           => [ 'size' => 100 ],
					'similarity'           => 0.1,
					'filter'               => [
						[ 'term' => [ 'terms.formats.slug' => 'report' ] ],
						[ 'match' => [ 'post_password' => 'x' ] ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'knn' => [
					'field'          => 'chunks.vector',
					'k'              => 100,
					'num_candidates' => 1000,
					'filter'         => [
						[ 'term' => [ 'terms.formats.slug' => 'report' ] ],
					],
				],
			],
			$result
		);

		// A list of kNN searches keeps the first.
		$result = $validator->validate(
			[
				'knn' => [
					[
						'field' => 'chunks.vector',
						'k'     => 10,
					],
					[
						'field' => 'chunks.vector',
						'k'     => 20,
					],
				],
			]
		);

		$this->assertSame( 10, $result['knn']['k'] );
		$this->assertSame( 100, $result['knn']['num_candidates'] );

		// Not a vector field.
		$this->assertWPError( $validator->validate( [ 'knn' => [ 'field' => 'post_title' ] ] ) );
	}

	/**
	 * Sort is normalized; only `_score` and allowed sortable fields stay.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_sort() {
		$result = $this->get_validator()->validate(
			[
				'query' => [ 'match' => [ 'post_title' => 'cash' ] ],
				'sort'  => [
					'_score',
					[ 'post_date' => 'asc' ],
					[ 'post_type.raw' => [ 'order' => 'sideways' ] ],
					[ 'post_title' => 'asc' ],
					[ 'chunks.text' => 'asc' ],
					[ 'post_password' => 'asc' ],
					[ '_script' => [ 'script' => '1' ] ],
				],
			]
		);

		$this->assertSame(
			[
				[ '_score' => [ 'order' => 'desc' ] ],
				[ 'post_date' => [ 'order' => 'asc' ] ],
				[ 'post_type.raw' => [ 'order' => 'desc' ] ],
			],
			$result['sort']
		);

		$result = $this->get_validator()->validate(
			[
				'query' => [ 'match' => [ 'post_title' => 'cash' ] ],
				'sort'  => [ 'post_date' => 'desc' ],
			]
		);

		$this->assertSame( [ [ 'post_date' => [ 'order' => 'desc' ] ] ], $result['sort'] );
	}

	/**
	 * Range needs a date or number field and plain values.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_range() {
		$validator = $this->get_validator();

		$this->assertSame(
			[
				'query' => [
					'range' => [
						'post_date' => [
							'gte'    => 'now-2y/y',
							'lt'     => '2025-01-01',
							'format' => 'yyyy-MM-dd',
						],
					],
				],
			],
			$validator->validate(
				[
					'query' => [
						'range' => [
							'post_date' => [
								'gte'      => 'now-2y/y',
								'lt'       => '2025-01-01',
								'format'   => 'yyyy-MM-dd',
								'relation' => 'within',
							],
						],
					],
				]
			)
		);

		// Without a format, a date range reads the dates a model writes as well as ElasticPress's.
		$this->assertSame(
			[
				'query' => [
					'range' => [
						'post_date' => [
							'gte'    => '2020-01-01',
							'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||yyyy-MM||yyyy',
						],
					],
				],
			],
			$validator->validate( [ 'query' => [ 'range' => [ 'post_date' => [ 'gte' => '2020-01-01' ] ] ] ] )
		);

		// A text field cannot be a range: dropped, so the query is empty.
		$this->assertWPError( $validator->validate( [ 'query' => [ 'range' => [ 'post_title' => [ 'gte' => 'a' ] ] ] ] ) );
		// Values must be plain.
		$this->assertWPError( $validator->validate( [ 'query' => [ 'range' => [ 'post_date' => [ 'gte' => [ 'x' ] ] ] ] ] ) );
	}

	/**
	 * Limits: depth, number of clauses, string length, and terms per list.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_limits() {
		$validator = $this->get_validator();
		$deep      = [ 'match' => [ 'post_title' => 'cash' ] ];

		for ( $i = 0; $i < 8; $i++ ) {
			$deep = [ 'bool' => [ 'must' => [ $deep ] ] ];
		}

		$result = $validator->validate( [ 'query' => $deep ] );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_ai_query_too_deep', $result->get_error_code() );

		$result = $validator->validate( [ 'query' => [ 'bool' => [ 'should' => array_fill( 0, 41, [ 'match' => [ 'post_title' => 'cash' ] ] ) ] ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_ai_query_too_many_clauses', $result->get_error_code() );

		$result = $validator->validate( [ 'query' => [ 'match' => [ 'post_title' => str_repeat( 'a', 600 ) ] ] ] );

		$this->assertSame( 500, strlen( $result['query']['match']['post_title'] ) );

		$result = $validator->validate( [ 'query' => [ 'terms' => [ 'post_type.raw' => array_fill( 0, 60, 'post' ) ] ] ] );

		$this->assertCount( 50, $result['query']['terms']['post_type.raw'] );
	}

	/**
	 * Malformed input, or nothing left to search, rejects the query.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_malformed_or_empty_queries_are_rejected() {
		$validator = $this->get_validator();

		$this->assertSame( 'ep_chat_ai_query_not_object', $validator->validate( 'match_all' )->get_error_code() );
		$this->assertSame( 'ep_chat_ai_query_empty', $validator->validate( [] )->get_error_code() );
		$this->assertSame( 'ep_chat_ai_query_empty', $validator->validate( [ 'sort' => [ '_score' ] ] )->get_error_code() );
		$this->assertSame(
			'ep_chat_ai_query_malformed',
			$validator->validate(
				[
					'query' => [
						'match' => [ 'post_title' => 'a' ],
						'term'  => [ 'post_type.raw' => 'post' ],
					],
				]
			)->get_error_code()
		);
		$this->assertSame( 'ep_chat_ai_query_malformed', $validator->validate( [ 'query' => [ 'match' => [ 'post_title' => [ 'query' => [ 'nested' ] ] ] ] ] )->get_error_code() );
		$this->assertSame( 'ep_chat_ai_query_malformed', $validator->validate( [ 'query' => 'cash' ] )->get_error_code() );

		// A terms lookup reads another index.
		$lookup = [
			'index' => 'other-index',
			'id'    => '1',
			'path'  => 'secret',
		];

		$this->assertSame( 'ep_chat_ai_query_malformed', $validator->validate( [ 'query' => [ 'terms' => [ 'post_type.raw' => $lookup ] ] ] )->get_error_code() );
	}

	/**
	 * match_all is kept as a JSON object.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_match_all() {
		$result = $this->get_validator()->validate(
			[
				'query' => [ 'match_all' => [] ],
				'sort'  => [ [ 'post_date' => 'desc' ] ],
			]
		);

		$this->assertSame( '{"query":{"match_all":{}},"sort":[{"post_date":{"order":"desc"}}]}', wp_json_encode( $result ) );
	}
}
