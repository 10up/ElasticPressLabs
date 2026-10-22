<?php
/**
 * Test Chat Date range: time words as search limits
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Date_Range;
use ElasticPressLabs\Feature\Chat\Retriever;

/**
 * Date range: the rules table, the Retriever args, and the Elasticsearch query.
 */
class TestChatDateRange extends BaseTestCase {
	/**
	 * "Now" for the rules: Sept. 29, 2026.
	 *
	 * @var int
	 */
	protected const NOW = 1790683200;

	/**
	 * Each rule: a year the question asks about is a lower limit (never after "before" and
	 * similar, never a future year), relative years count back from this year, and recency words
	 * rank newer content higher.
	 *
	 * @dataProvider data_rules
	 * @group chat
	 *
	 * @param string      $text   Question.
	 * @param string|null $after  Expected `published_after`.
	 * @param bool        $recent Expected `recent`.
	 */
	public function test_rules( string $text, ?string $after, bool $recent ) {
		$this->assertSame(
			[
				'published_after' => $after,
				'recent'          => $recent,
			],
			( new Date_Range() )->read( $text, '', [], self::NOW )
		);
	}

	/**
	 * The rules table.
	 *
	 * @return array
	 */
	public function data_rules(): array {
		return [
			'in a year'           => [ 'How did Americans view immigration in 2016?', '2016-01-01', false ],
			'bare year'           => [ 'Views of the 2016 election', '2016-01-01', false ],
			'since'               => [ 'How has trust changed since 2020?', '2020-01-01', false ],
			'range'               => [ 'Trust in government between 2010 and 2020', '2010-01-01', false ],
			'from through'        => [ 'Church attendance from 2008 through 2019', '2008-01-01', false ],
			'decade'              => [ 'Religion in the 1990s', '1990-01-01', false ],
			'before'              => [ 'What did Americans think of AI before 2020?', null, false ],
			'until'               => [ 'Gun views up to 2019', null, false ],
			'prior to'            => [ 'Voting prior to 2016', null, false ],
			'pre-'                => [ 'Pre-2020 views of China', null, false ],
			'by a future year'    => [ 'What will the U.S. look like by 2050?', null, false ],
			'future year'         => [ 'Predictions for 2030', null, false ],
			'count, not a year'   => [ 'A survey of 2,024 adults', null, false ],
			'last N years'        => [ 'Social media use in the last 5 years', '2021-01-01', false ],
			'past N years, words' => [ 'Views of AI over the past three years', '2023-01-01', false ],
			'last year'           => [ 'What happened last year?', '2025-01-01', false ],
			'past 12 months'      => [ 'News habits in the past 12 months', '2025-01-01', false ],
			'this year'           => [ 'Views of the economy this year', '2026-01-01', false ],
			'latest'              => [ 'What are the latest views on AI?', null, true ],
			'these days'          => [ 'How do teens use TikTok these days?', null, true ],
			'right now'           => [ 'How do Americans feel about the economy right now?', null, true ],
			'current is a topic'  => [ 'Views of the current president', null, false ],
			'currently'           => [ 'Who is currently religious?', null, false ],
			'age group'           => [ 'How do 18-to-29-year-olds use Reddit?', null, false ],
			'year and recency'    => [ 'Latest data on religion since 2014', '2014-01-01', true ],
			'no cue'              => [ 'What do Americans think about guns?', null, false ],
		];
	}

	/**
	 * The rewrite adds only years the user wrote (in this question or an earlier one); its
	 * other cues do not count. A filter changes the result.
	 *
	 * @group chat
	 */
	public function test_rewrite_and_filter() {
		$range = new Date_Range();

		// A follow-up keeps the year of the question it follows.
		$this->assertSame(
			[
				'published_after' => '2020-01-01',
				'recent'          => false,
			],
			$range->read( 'And teens?', 'How do teens view AI since 2020?', [ 'How do adults view AI since 2020?' ], self::NOW )
		);

		// A year (or a recency word) the model added is ignored.
		$this->assertSame(
			[
				'published_after' => null,
				'recent'          => false,
			],
			$range->read( 'And now?', 'What are the latest views of AI in 2024?', [ 'How did Americans view AI in 2023?' ], self::NOW )
		);

		// The earliest limit wins.
		$this->assertSame(
			[
				'published_after' => '2014-01-01',
				'recent'          => true,
			],
			$range->read( 'Latest data on religion since 2020?', 'Religion since 2014 and 2020', [ 'Religion in 2014' ], self::NOW )
		);

		add_filter(
			'ep_chat_date_range',
			static function ( $filtered ) {
				$filtered['published_after'] = 'not a date';
				$filtered['recent']          = true;

				return $filtered;
			}
		);

		$this->assertSame(
			[
				'published_after' => null,
				'recent'          => true,
			],
			$range->read( 'Religion in 2020', '', [], self::NOW )
		);
	}

	/**
	 * The plan becomes a `date_query` (on or after); a recent question asks for three times as
	 * many posts and keeps the usual number.
	 *
	 * @group chat
	 */
	public function test_query_args() {
		$retriever = new Retriever();
		$args      = $retriever->get_query_args(
			'How do teens view AI?',
			[],
			[
				'published_after' => '2020-01-01',
				'recent'          => true,
			]
		);

		$this->assertSame(
			[
				[
					'column'    => 'post_date',
					'after'     => '2020-01-01',
					'inclusive' => true,
				],
			],
			$args['date_query']
		);
		$this->assertSame( 10, $args[ Retriever::RECENT_FLAG ] );
		$this->assertSame( 30, $args['posts_per_page'] );

		$plain = $retriever->get_query_args( 'How do teens view AI?' );

		$this->assertArrayNotHasKey( 'date_query', $plain );
		$this->assertArrayNotHasKey( Retriever::RECENT_FLAG, $plain );
		$this->assertSame( 10, $plain['posts_per_page'] );
	}

	/**
	 * The Elasticsearch query: kNN searches get the lower limit as their own filter (one kNN,
	 * a list, an existing filter).
	 *
	 * @group chat
	 */
	public function test_elasticsearch_query() {
		$apply = new \ReflectionMethod( Retriever::class, 'apply_plan' );
		$apply->setAccessible( true );

		$retriever = new Retriever();
		$args      = $retriever->get_query_args( 'q', [], [ 'published_after' => '2020-01-01' ] );
		$range     = [ 'range' => [ 'post_date' => [ 'gte' => '2020-01-01 00:00:00' ] ] ];
		$term      = [ 'term' => [ 'post_type.raw' => 'post' ] ];

		$one = $apply->invoke( $retriever, [ 'knn' => [ 'field' => 'chunks.vector' ] ], $args );
		$this->assertSame( [ $range ], $one['knn']['filter'] );

		$existing = $apply->invoke(
			$retriever,
			[
				'knn' => [
					'field'  => 'chunks.vector',
					'filter' => $term,
				],
			],
			$args
		);
		$this->assertSame( [ $term, $range ], $existing['knn']['filter'] );

		$list = $apply->invoke( $retriever, [ 'knn' => [ [ 'field' => 'a' ], [ 'field' => 'b' ] ] ], $args );
		$this->assertSame( [ $range ], $list['knn'][1]['filter'] );

		// No date limit: kNN unchanged.
		$this->assertSame(
			[ 'knn' => [ 'field' => 'chunks.vector' ] ],
			$apply->invoke( $retriever, [ 'knn' => [ 'field' => 'chunks.vector' ] ], $retriever->get_query_args( 'q' ) )
		);
	}

	/**
	 * A recent question: posts ordered by score times a decay on the publish date, the usual
	 * number kept. A strong older post stays; a weak one gives way to a newer match.
	 *
	 * @group chat
	 */
	public function test_favor_recent() {
		$post = function ( string $title, string $date, ?float $score ) {
			$item = get_post(
				$this->factory()->post->create(
					[
						'post_title'    => $title,
						'post_date'     => $date,
						'post_date_gmt' => $date,
						'post_status'   => 'publish',
					]
				)
			);

			if ( null !== $score ) {
				$item->{Retriever::SCORE_FIELD} = $score;
			}

			return $item;
		};

		$posts = [
			$post( 'Strong, old', '2015-06-01 00:00:00', 10.0 ),
			$post( 'Weak, old', '2016-06-01 00:00:00', 6.0 ),
			$post( 'Weaker, new', '2026-06-01 00:00:00', 5.0 ),
			$post( 'Weakest, new', '2026-09-01 00:00:00', 1.0 ),
		];

		$kept = ( new Retriever() )->favor_recent( $posts, 2, self::NOW );

		// 2015 is about 11 years old: 10 × a small weight is below 5 × about 1.
		$this->assertSame( [ 'Weaker, new', 'Weakest, new' ], wp_list_pluck( $kept, 'post_title' ) );

		// Without scores, the position counts.
		$unscored = [
			$post( 'First, old', '2016-01-01 00:00:00', null ),
			$post( 'Second, new', '2026-09-01 00:00:00', null ),
		];

		$this->assertSame( [ 'Second, new' ], wp_list_pluck( ( new Retriever() )->favor_recent( $unscored, 1, self::NOW ), 'post_title' ) );

		// A gentler decay keeps the strong old post.
		add_filter(
			'ep_chat_recency_decay',
			static function () {
				return [
					'scale'  => 7300,
					'offset' => 0,
					'decay'  => 0.5,
				];
			}
		);

		$kept = ( new Retriever() )->favor_recent( $posts, 2, self::NOW );

		$this->assertSame( [ 'Strong, old', 'Weaker, new' ], wp_list_pluck( $kept, 'post_title' ) );
	}
}
