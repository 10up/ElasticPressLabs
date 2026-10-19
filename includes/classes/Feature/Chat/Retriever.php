<?php
/**
 * Retrieve indexed posts for a Chat question.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Runs an ElasticPress search for Chat, with the bot's search method (see Settings): the site
 * search's algorithm (which other plugins may still switch per query through
 * `ep_post_search_algorithm`), a registered algorithm, or one that writes its query for each
 * question first (Query_Writing_Algorithm, such as the AI-generated query). When that cannot be
 * written, fails in Elasticsearch, or finds nothing, the bot's fallback search runs. What ran is
 * kept for the answer (see get_last_search()).
 */
class Retriever {
	/**
	 * WP_Query flag that marks a Chat retrieve.
	 *
	 * @var string
	 */
	public const QUERY_FLAG = 'ep_labs_chat';

	/**
	 * WP_Query arg for a question that asks for recent data: how many posts to keep. The search
	 * asks for more, and newer ones among them rank higher (see favor_recent()).
	 *
	 * @var string
	 */
	public const RECENT_FLAG = 'ep_labs_chat_recent';

	/**
	 * WP_Query arg with the search algorithm slug a Chat retrieve uses (absent: the site search's).
	 *
	 * @var string
	 */
	public const ALGORITHM_VAR = 'ep_labs_chat_algorithm';

	/**
	 * Fallback reason: the AI query could not be written or was rejected.
	 *
	 * @var string
	 */
	public const FALLBACK_GENERATION = 'generation_failed';

	/**
	 * Fallback reason: Elasticsearch did not run the AI query.
	 *
	 * @var string
	 */
	public const FALLBACK_ES_ERROR = 'es_error';

	/**
	 * Fallback reason: the AI query found nothing.
	 *
	 * @var string
	 */
	public const FALLBACK_ZERO_HITS = 'zero_hits';

	/**
	 * Fallback reason: the chosen search algorithm is not registered.
	 *
	 * @var string
	 */
	public const FALLBACK_UNAVAILABLE = 'unavailable';

	/**
	 * What the last search ran (see get_last_search()).
	 *
	 * @var array
	 */
	protected $last_search = [];

	/**
	 * Post field that carries the Elasticsearch score of a Chat retrieve hit.
	 *
	 * @var string
	 */
	public const SCORE_FIELD = 'ep_chat_score';

	/**
	 * How many more posts a recent question asks for, before the newer ones are kept.
	 *
	 * @var int
	 */
	protected const RECENT_POOL = 3;

	/**
	 * Search for passages matching a question.
	 *
	 * @param string $question Search query: the user question, or its rewrite.
	 * @param array  $bot      Selected chat bot (for how many posts to retrieve, and how to search).
	 * @param array  $plan     Search plan (see Pipeline::plan_search()): `published_after`
	 *                         (`Y-m-d` or null) and `recent` (bool). Empty for no limits.
	 * @param string $asked    The question as asked, when the search query is its rewrite (the
	 *                         AI-generated query reads both).
	 * @return array<\WP_Post>|\WP_Error Posts, or an error when Elasticsearch did not answer (a
	 *                                    failed search is not "no sources").
	 */
	public function search( string $question, array $bot = [], array $plan = [], string $asked = '' ) {
		$this->last_search = [];

		/**
		 * Short-circuit Chat retrieve. Return an array of posts (or empty).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retrieve
		 * @param {null|array} $pre      Short-circuit value.
		 * @param {string}     $question Search query (the question, or its rewrite).
		 * @param {array}      $plan     Search plan: `query`, `published_after`, `recent`.
		 * @return {null|array} Filtered value.
		 */
		$pre = apply_filters( 'ep_chat_retrieve', null, $question, $plan );

		if ( is_array( $pre ) ) {
			return $pre;
		}

		$method = $this->get_search_method( $bot );
		$writer = $this->get_query_writer( $method['algorithm'] );

		if ( null === $writer ) {
			$run               = $this->run( $question, $bot, $plan, $method['algorithm'] );
			$this->last_search = array_merge( [ 'algorithm' => $run['algorithm'] ], $method['note'] );

			return $run['posts'];
		}

		$written = $writer->write_query(
			$question,
			[
				'question' => '' !== $asked ? $asked : $question,
				'bot'      => $bot,
				'plan'     => $plan,
			]
		);

		Steps::report( Steps::RETRIEVE );

		$record = [
			'algorithm' => (string) $method['algorithm'],
			'model'     => is_wp_error( $written ) ? (string) ( $written->get_error_data()['model'] ?? '' ) : $written['model'],
		];

		if ( is_wp_error( $written ) ) {
			return $this->fall_back( $question, $bot, $plan, $record, self::FALLBACK_GENERATION, $written );
		}

		$record['generated_query'] = $written['query'];

		$run = $this->run( $question, $bot, $plan, (string) $method['algorithm'], $written['query'] );

		// What the search applied (a sort it left out is not recorded).
		if ( is_array( $run['query']->{Query_Writing_Algorithm::RESULT_PROPERTY}['query'] ?? null ) ) {
			$record['generated_query'] = $run['query']->{Query_Writing_Algorithm::RESULT_PROPERTY}['query'];
		}

		if ( is_wp_error( $run['posts'] ) ) {
			return $this->fall_back( $question, $bot, $plan, $record, self::FALLBACK_ES_ERROR, $run['posts'] );
		}

		$error = $run['query']->{Query_Writing_Algorithm::RESULT_PROPERTY}['error'] ?? null;

		if ( $error instanceof \WP_Error ) {
			return $this->fall_back( $question, $bot, $plan, $record, self::FALLBACK_GENERATION, $error );
		}

		if ( ! $run['posts'] ) {
			return $this->fall_back( $question, $bot, $plan, $record, self::FALLBACK_ZERO_HITS, null );
		}

		$this->last_search = $record;

		return $run['posts'];
	}

	/**
	 * What the last search ran, for the answer: the `algorithm` that ran; for the AI query, the
	 * `generated_query` and the `model` that wrote it; and, when another search ran instead,
	 * `requested_algorithm`, the `fallback` reason (`generation_failed`, `es_error`, `zero_hits`,
	 * `unavailable`), and the error code as `fallback_error`. Empty when the search was
	 * short-circuited.
	 *
	 * @return array
	 */
	public function get_last_search(): array {
		return $this->last_search;
	}

	/**
	 * The bot's search method: the algorithm slug (null for the site search's) and, when the
	 * chosen algorithm is not registered, a note that the site search ran instead.
	 *
	 * @param array $bot Selected chat bot.
	 * @return array{algorithm: string|null, note: array}
	 */
	protected function get_search_method( array $bot ): array {
		$setting = (string) Settings::get( 'search_algorithm', $bot );

		/**
		 * Filters the search method of a Chat retrieve: `site` (the site search's algorithm) or a
		 * search algorithm slug, such as `ai_query`.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_search_algorithm
		 * @param {string} $algorithm Search method. Default: the bot's Search method setting.
		 * @param {array}  $bot       Selected chat bot.
		 * @return {string} Filtered search method.
		 */
		$setting = (string) apply_filters( 'ep_chat_search_algorithm', $setting, $bot );

		if ( Settings::SEARCH_SITE === $setting || '' === $setting ) {
			return [
				'algorithm' => null,
				'note'      => [],
			];
		}

		if ( ! in_array( $setting, \ElasticPress\SearchAlgorithms::factory()->get_all( true ), true ) ) {
			return [
				'algorithm' => null,
				'note'      => [
					'requested_algorithm' => $setting,
					'fallback'            => self::FALLBACK_UNAVAILABLE,
				],
			];
		}

		return [
			'algorithm' => $setting,
			'note'      => [],
		];
	}

	/**
	 * The search algorithm when it writes its query for each question (see
	 * Query_Writing_Algorithm, such as the AI-generated query); null for the others.
	 *
	 * @param string|null $slug Search algorithm slug, or null for the site search's.
	 * @return Query_Writing_Algorithm|null
	 */
	protected function get_query_writer( $slug ) {
		if ( null === $slug || ! in_array( $slug, \ElasticPress\SearchAlgorithms::factory()->get_all( true ), true ) ) {
			return null;
		}

		$algorithm = \ElasticPress\SearchAlgorithms::factory()->get( $slug );

		return $algorithm instanceof Query_Writing_Algorithm ? $algorithm : null;
	}

	/**
	 * Run the bot's fallback search after an AI search method failed. With no fallback (`none`), a
	 * failed query is the error the reader can retry, and a query that found nothing is no
	 * sources.
	 *
	 * @param string         $question Search query.
	 * @param array          $bot      Selected chat bot.
	 * @param array          $plan     Search plan.
	 * @param array          $record   What the AI search did (`algorithm`, `model`, `generated_query`).
	 * @param string         $reason   Why it fell back.
	 * @param \WP_Error|null $error    What failed.
	 * @return array<\WP_Post>|\WP_Error
	 */
	protected function fall_back( string $question, array $bot, array $plan, array $record, string $reason, $error ) {
		/**
		 * Fires when Chat's AI-generated query could not be used and the fallback search runs.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ai_query_failed
		 * @param {string}         $reason   `generation_failed`, `es_error`, or `zero_hits`.
		 * @param {\WP_Error|null} $error    What failed.
		 * @param {string}         $question Search query.
		 * @param {array}          $bot      Selected chat bot.
		 */
		do_action( 'ep_chat_ai_query_failed', $reason, $error, $question, $bot );

		$record = array_merge(
			$record,
			[
				'requested_algorithm' => (string) ( $record['algorithm'] ?? '' ),
				'fallback'            => $reason,
			],
			$error instanceof \WP_Error ? [ 'fallback_error' => (string) $error->get_error_code() ] : []
		);

		$fallback = Settings::resolve_fallback( (string) Settings::get( 'search_fallback', $bot ) );

		if ( Settings::SEARCH_NONE === $fallback ) {
			$this->last_search = array_merge( $record, [ 'algorithm' => Settings::SEARCH_NONE ] );

			if ( self::FALLBACK_ZERO_HITS === $reason ) {
				return [];
			}

			return new \WP_Error(
				'ep_chat_retrieve_failed',
				__( 'The search did not respond. Please try again.', 'elasticpress-labs' ),
				[ 'status' => 503 ]
			);
		}

		$algorithm = Settings::SEARCH_SITE === $fallback || ! in_array( $fallback, \ElasticPress\SearchAlgorithms::factory()->get_all( true ), true )
			? null
			: $fallback;
		$run       = $this->run( $question, $bot, $plan, $algorithm );

		$this->last_search = array_merge( $record, [ 'algorithm' => $run['algorithm'] ] );

		return $run['posts'];
	}

	/**
	 * Run one Chat retrieve query.
	 *
	 * @param string      $question  Search query.
	 * @param array       $bot       Selected chat bot.
	 * @param array       $plan      Search plan.
	 * @param string|null $algorithm Search algorithm slug, or null for the site search's.
	 * @param array|null  $written   For the AI query: the checked query to run.
	 * @return array{posts: array|\WP_Error, query: \WP_Query, algorithm: string} The algorithm that
	 *                                                                           ran.
	 */
	protected function run( string $question, array $bot, array $plan, $algorithm, $written = null ): array {
		/*
		 * Chat can run in any request: REST (sync), admin-post.php (WP Async), admin-ajax
		 * (Action Scheduler), WP-Cron, WP-CLI. ElasticPress only integrates search queries in
		 * public and REST requests by default, and would leave this one to WordPress' MySQL
		 * search elsewhere. Integrate it wherever it runs, for this query only.
		 */
		$integrate = static function ( $integrated, $context ) {
			return 'search' === $context ? true : $integrated;
		};

		/*
		 * A semantic search over every chunk can take several seconds, longer than the default
		 * HTTP timeout ElasticPress leaves its search requests with. Give this query its own.
		 */
		$timeout = $this->get_timeout();
		$wait    = static function ( $request_args, $path = '', $index = '', $type = '', $query = [], $query_args = [] ) use ( $timeout ) {
			if ( ! empty( $query_args[ self::QUERY_FLAG ] ) ) {
				$request_args['timeout'] = max( (float) ( $request_args['timeout'] ?? 0 ), $timeout );
			}

			return $request_args;
		};

		/*
		 * The fuzzy clause of ElasticPress's weighted text query is most of the search time on a
		 * large index (fuzzy matching over every post's content), while the vector half of the
		 * query already tolerates typos. Drop fuzziness from this query only.
		 */
		$fuzziness = $this->keep_fuzziness();
		$sharpen   = function ( $formatted_args, $args ) use ( $fuzziness ) {
			if ( empty( $args[ self::QUERY_FLAG ] ) || ! is_array( $formatted_args ) ) {
				return $formatted_args;
			}

			if ( ! $fuzziness && isset( $formatted_args['query'] ) && is_array( $formatted_args['query'] ) ) {
				$formatted_args['query'] = self::without_fuzziness( $formatted_args['query'] );
			}

			return $this->apply_plan( $formatted_args, $args );
		};

		$args = $this->get_query_args( $question, $bot, $plan );
		$args = $this->add_query_vector( $args, $question );

		if ( null !== $algorithm ) {
			$args[ self::ALGORITHM_VAR ] = $algorithm;
		}

		if ( null !== $written ) {
			$args[ Query_Writing_Algorithm::QUERY_VAR ] = $written;
		}

		// The algorithm the query asks for, for this query only and over any other choice.
		$choose = static function ( $slug, $search_text = '', $search_fields = [], $query_vars = [] ) {
			return ! empty( $query_vars[ self::QUERY_FLAG ] ) && ! empty( $query_vars[ self::ALGORITHM_VAR ] )
				? (string) $query_vars[ self::ALGORITHM_VAR ]
				: $slug;
		};

		// A failed query's log entry (Status Report) keeps its query, not the question's vector.
		$redact = static function ( $log ) {
			return is_array( $log ) && ! empty( $log['wp_args'][ self::QUERY_FLAG ] ) ? self::redact_log( $log ) : $log;
		};

		// Each hit keeps its score, so a recent question can weigh it against the publish date.
		$score  = static function ( $post, $hit ) {
			if ( is_array( $post ) && isset( $hit['_score'] ) ) {
				$post[ self::SCORE_FIELD ] = (float) $hit['_score'];
			}

			return $post;
		};
		$fields = static function ( $properties ) {
			$properties   = (array) $properties;
			$properties[] = self::SCORE_FIELD;

			return $properties;
		};

		add_filter( 'ep_is_integrated_request', $integrate, 10, 2 );
		add_filter( 'ep_query_request_args', $wait, 10, 6 );
		add_filter( 'ep_post_formatted_args', $sharpen, PHP_INT_MAX, 2 );
		add_filter( 'ep_retrieve_the_post', $score, 10, 2 );
		add_filter( 'ep_search_post_return_args', $fields );
		add_filter( 'ep_post_search_algorithm', $choose, PHP_INT_MAX, 4 );
		add_filter( 'ep_query_logger_formatted_query', $redact );

		try {
			$query = new \WP_Query( $args );
			$ran   = \ElasticPress\Indexables::factory()->get( 'post' )->get_search_algorithm( $question, [], $query->query_vars )->get_slug();
		} finally {
			remove_filter( 'ep_post_search_algorithm', $choose, PHP_INT_MAX );
			remove_filter( 'ep_query_logger_formatted_query', $redact );
			remove_filter( 'ep_is_integrated_request', $integrate, 10 );
			remove_filter( 'ep_query_request_args', $wait, 10 );
			remove_filter( 'ep_post_formatted_args', $sharpen, PHP_INT_MAX );
			remove_filter( 'ep_retrieve_the_post', $score, 10 );
			remove_filter( 'ep_search_post_return_args', $fields );
		}

		// Never answer from WordPress' own search, and never report an outage as "no sources".
		if ( true !== ( $query->elasticsearch_success ?? null ) ) {
			/**
			 * Fires when Chat retrieval did not run through Elasticsearch (not integrated, or
			 * the query failed). The question gets no passages.
			 *
			 * @since 2.6.0
			 * @hook ep_chat_retrieve_failed
			 * @param {string}    $question User question.
			 * @param {\WP_Query} $query    The query that was not answered by Elasticsearch.
			 */
			do_action( 'ep_chat_retrieve_failed', $question, $query );

			$posts = new \WP_Error(
				'ep_chat_retrieve_failed',
				__( 'The search did not respond. Please try again.', 'elasticpress-labs' ),
				[ 'status' => 503 ]
			);
		} elseif ( ! empty( $args[ self::RECENT_FLAG ] ) ) {
			$posts = $this->favor_recent( $this->without_password_protected( $query->posts ), (int) $args[ self::RECENT_FLAG ] );
		} else {
			$posts = $this->without_password_protected( $query->posts );
		}

		return [
			'posts'     => $posts,
			'query'     => $query,
			'algorithm' => $ran,
		];
	}

	/**
	 * Leave out password-protected posts: their text is behind a password on the site, so it
	 * must not become an answer. The password is read from the database, so this works whether
	 * or not ElasticPress indexes it (Protected Content).
	 *
	 * @param \WP_Post[] $posts Retrieved posts.
	 * @return \WP_Post[]
	 */
	protected function without_password_protected( array $posts ): array {
		/**
		 * Filters whether Chat may answer from password-protected posts.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retrieve_password_protected_posts
		 * @param {bool} $allowed Whether password-protected posts are kept. Default false.
		 * @return {bool} New value.
		 */
		if ( apply_filters( 'ep_chat_retrieve_password_protected_posts', false ) ) {
			return $posts;
		}

		return array_values(
			array_filter(
				$posts,
				static function ( $post ) {
					return '' === (string) get_post_field( 'post_password', $post->ID ?? 0 );
				}
			)
		);
	}

	/**
	 * A Query Log / Failed Queries entry without vectors: the kNN query vector in the request
	 * body and the question's vector in the WP_Query args.
	 *
	 * @param array $log Log entry (see ElasticPress's QueryLogger).
	 * @return array
	 */
	public static function redact_log( array $log ): array {
		if ( isset( $log['wp_args']['ep_vectors'] ) ) {
			$log['wp_args']['ep_vectors'] = '[vector]';
		}

		$body = is_string( $log['body'] ?? null ) ? json_decode( $log['body'], true ) : null;

		if ( is_array( $body ) && isset( $body['knn'] ) && is_array( $body['knn'] ) ) {
			$knns = isset( $body['knn']['field'] ) ? [ &$body['knn'] ] : [];

			if ( ! $knns ) {
				foreach ( $body['knn'] as $key => $knn ) {
					if ( is_array( $knn ) ) {
						$knns[] = &$body['knn'][ $key ];
					}
				}
			}

			foreach ( $knns as &$knn ) {
				if ( isset( $knn['query_vector'] ) ) {
					$knn['query_vector'] = '[vector]';
				}
			}

			unset( $knn );

			$log['body'] = (string) wp_json_encode( $body );
		}

		return $log;
	}

	/**
	 * For a question that asks for recent data: order the posts by their score times a decay on
	 * the publish date, and keep the first ones. An older post stays when it scores well enough;
	 * it only gives way to a newer post that also matched the question.
	 *
	 * A score is used as returned by the search, for any search algorithm (kNN scores cannot be
	 * boosted inside Elasticsearch). A post without a score counts by its position.
	 *
	 * @param array $posts Posts, best first.
	 * @param int   $keep  How many to keep.
	 * @param int   $now   Current timestamp (tests).
	 * @return array
	 */
	public function favor_recent( array $posts, int $keep, int $now = 0 ): array {
		$now   = $now ? $now : time();
		$decay = $this->get_recency_decay();
		$total = count( $posts );
		$rows  = [];

		foreach ( array_values( $posts ) as $index => $post ) {
			$score = isset( $post->{self::SCORE_FIELD} ) ? (float) $post->{self::SCORE_FIELD} : (float) ( $total - $index );
			$time  = $post instanceof \WP_Post ? (int) get_post_time( 'U', true, $post ) : 0;
			$days  = $time ? max( 0.0, ( $now - $time ) / DAY_IN_SECONDS - $decay['offset'] ) : 0.0;

			// A Gaussian decay, as Elasticsearch's: `decay` at `scale` days past the offset.
			$weight = exp( log( $decay['decay'] ) * ( $days / $decay['scale'] ) ** 2 );
			$rows[] = [ $post, $score * $weight, $index ];
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$order = $b[1] <=> $a[1];

				return 0 !== $order ? $order : $a[2] <=> $b[2];
			}
		);

		return array_slice( array_column( $rows, 0 ), 0, max( 1, $keep ) );
	}

	/**
	 * How strongly a recent question favors newer posts.
	 *
	 * @return array{scale: float, offset: float, decay: float} Days, days, and the weight at
	 *                                                          `offset` + `scale`.
	 */
	protected function get_recency_decay(): array {
		/**
		 * Filters how strongly a question that asks for recent data ("latest", "these days")
		 * favors newer posts. The search asks for three times as many posts, orders them by their
		 * score times a Gaussian decay on the publish date, and keeps the usual number: a post
		 * `offset` days old keeps its full score, and one `offset` + `scale` days old keeps
		 * `decay` of it.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_recency_decay
		 * @param {array} $decay `scale` (days), `offset` (days), `decay` (0 to 1). Default 730, 90, 0.5.
		 * @return {array} Filtered decay.
		 */
		$decay = apply_filters(
			'ep_chat_recency_decay',
			[
				'scale'  => 730,
				'offset' => 90,
				'decay'  => 0.5,
			]
		);
		$decay = is_array( $decay ) ? $decay : [];

		return [
			'scale'  => max( 1.0, (float) ( $decay['scale'] ?? 730 ) ),
			'offset' => max( 0.0, (float) ( $decay['offset'] ?? 90 ) ),
			'decay'  => min( 1.0, max( 0.01, (float) ( $decay['decay'] ?? 0.5 ) ) ),
		];
	}

	/**
	 * Apply the date limit of the search plan to the Elasticsearch query.
	 *
	 * The "published on or after" limit is a WP_Query `date_query`, which ElasticPress turns into
	 * a post filter. A kNN search picks its nearest posts before the post filter, so it gets the
	 * same limit as its own filter, or old posts would take its places and then be dropped.
	 *
	 * @param array $formatted_args Elasticsearch query.
	 * @param array $args           WP_Query args.
	 * @return array
	 */
	protected function apply_plan( array $formatted_args, array $args ): array {
		$after = (string) ( $args['date_query'][0]['after'] ?? '' );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) && ! empty( $formatted_args['knn'] ) && is_array( $formatted_args['knn'] ) ) {
			$range = [ 'range' => [ 'post_date' => [ 'gte' => $after . ' 00:00:00' ] ] ];

			// One kNN search, or a list of them.
			if ( isset( $formatted_args['knn']['field'] ) ) {
				$formatted_args['knn'] = self::with_knn_filter( $formatted_args['knn'], $range );
			} else {
				foreach ( $formatted_args['knn'] as $key => $knn ) {
					$formatted_args['knn'][ $key ] = is_array( $knn ) ? self::with_knn_filter( $knn, $range ) : $knn;
				}
			}
		}

		return $formatted_args;
	}

	/**
	 * A kNN search with one more filter query.
	 *
	 * @param array $knn    kNN search.
	 * @param array $filter Filter query.
	 * @return array
	 */
	protected static function with_knn_filter( array $knn, array $filter ): array {
		$filters = $knn['filter'] ?? [];
		// One filter query, or a list of them.
		$filters       = is_array( $filters ) && ( ! $filters || isset( $filters[0] ) ) ? $filters : [ $filters ];
		$filters[]     = $filter;
		$knn['filter'] = $filters;

		return $knn;
	}

	/**
	 * A query without `fuzziness` anywhere in it.
	 *
	 * @param array $query Elasticsearch query clauses.
	 * @return array
	 */
	public static function without_fuzziness( array $query ): array {
		unset( $query['fuzziness'] );

		foreach ( $query as $key => $value ) {
			if ( is_array( $value ) ) {
				$query[ $key ] = self::without_fuzziness( $value );
			}
		}

		return $query;
	}

	/**
	 * Whether Chat's search keeps ElasticPress's fuzzy matching.
	 *
	 * @return bool
	 */
	protected function keep_fuzziness(): bool {
		/**
		 * Filters whether Chat's search keeps the fuzzy matching of ElasticPress's weighted text
		 * query. Off by default: it is most of the search time on a large index, and the vector
		 * half of the query already tolerates typos. Site search is not affected.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retrieve_fuzziness
		 * @param {bool} $keep Default false.
		 * @return {bool} Filtered value.
		 */
		return (bool) apply_filters( 'ep_chat_retrieve_fuzziness', false );
	}

	/**
	 * Add the question's vector to the query args, from a short cache when the same question
	 * was just asked (a Retry, a repeat), so the embedding call is not made again.
	 *
	 * Only for generators Labs calls itself (not ElasticPress.io, which embeds the search term
	 * on its side); on any failure the search algorithm gets the vector as usual.
	 *
	 * @param array  $args     WP_Query args.
	 * @param string $question Question.
	 * @return array
	 */
	protected function add_query_vector( array $args, string $question ): array {
		if ( isset( $args['ep_vectors'] ) || '' === trim( $question ) ) {
			return $args;
		}

		$feature = \ElasticPress\Features::factory()->get_registered_feature( 'vector_embeddings' );

		if ( ! $feature || ! $feature->is_active() || 'epio' === $feature->get_setting( 'ep_embeddings_generator' ) ) {
			return $args;
		}

		/**
		 * Filters how long Chat keeps a question's vector (seconds; 0 for no cache).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_query_vector_ttl
		 * @param {int} $ttl Seconds. Default 15 minutes.
		 * @return {int} Filtered seconds.
		 */
		$ttl = max( 0, (int) apply_filters( 'ep_chat_query_vector_ttl', 15 * MINUTE_IN_SECONDS ) );
		$key = 'ep_chat_qv_' . $feature->get_chunk_hash( $question );

		if ( $ttl ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) && $cached ) {
				$args['ep_vectors'] = $cached;

				return $args;
			}
		}

		try {
			$vector = $feature->generate_embedding( $question );
		} catch ( \Throwable $exception ) {
			return $args;
		}

		if ( ! is_array( $vector ) || ! $vector ) {
			return $args;
		}

		// A chunked text returns one vector per chunk; a question is one chunk.
		$vector = is_array( reset( $vector ) ) ? reset( $vector ) : $vector;
		$vector = array_map( 'floatval', $vector );

		if ( $ttl ) {
			set_transient( $key, $vector, $ttl );
		}

		$args['ep_vectors'] = $vector;

		return $args;
	}

	/**
	 * Seconds Chat waits for its Elasticsearch search.
	 *
	 * @return float
	 */
	public function get_timeout(): float {
		/**
		 * Filters how long Chat waits for its Elasticsearch search (the HTTP timeout of that
		 * request only; other searches keep theirs).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retrieve_timeout
		 * @param {float} $timeout Seconds. Default 15.
		 * @return {float} Filtered seconds.
		 */
		return max( 1.0, (float) apply_filters( 'ep_chat_retrieve_timeout', 15.0 ) );
	}

	/**
	 * Post types Chat retrieves from: every post type marked embeddable on the
	 * Vector Embeddings screen, so every embedded type can reach an answer.
	 *
	 * @return string[]
	 */
	public function get_post_types(): array {
		$post_types = [];
		$settings   = new \ElasticPressLabs\Feature\VectorEmbeddings\Settings();

		foreach ( (array) ( $settings->get_settings()['postTypeConfig'] ?? [] ) as $config ) {
			$key = (string) ( $config['key'] ?? '' );

			if ( '' !== $key && ! empty( $config['embeddable'] ) && post_type_exists( $key ) ) {
				$post_types[] = $key;
			}
		}

		if ( empty( $post_types ) ) {
			$post_types = [ 'post' ];
		}

		/**
		 * Filters the post types Chat retrieves from.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retrieve_post_types
		 * @param {string[]} $post_types Post types. Default: the embeddable post types.
		 * @return {string[]} Filtered post types.
		 */
		$post_types = (array) apply_filters( 'ep_chat_retrieve_post_types', $post_types );

		return array_values( array_unique( array_filter( array_map( 'strval', $post_types ) ) ) );
	}

	/**
	 * WP_Query args for a Chat retrieve.
	 *
	 * @param string $question Search query (the question, or its rewrite).
	 * @param array  $bot      Selected chat bot.
	 * @param array  $plan     Search plan: `published_after` (`Y-m-d` or null), `recent` (bool).
	 * @return array
	 */
	public function get_query_args( string $question, array $bot = [], array $plan = [] ): array {
		$range = Date_Range::clean( $plan );
		$args  = [
			's'              => $question,
			'ep_integrate'   => true,
			self::QUERY_FLAG => true,
			'post_type'      => $this->get_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => (int) Settings::get( 'retrieve_count', $bot ),
		];

		if ( null !== $range['published_after'] ) {
			$args['date_query'] = [
				[
					'column'    => 'post_date',
					'after'     => $range['published_after'],
					'inclusive' => true,
				],
			];
		}

		// A recent question asks for more posts, and keeps the usual number (see favor_recent()).
		if ( $range['recent'] ) {
			$args[ self::RECENT_FLAG ] = $args['posts_per_page'];
			$args['posts_per_page']    = min( 100, $args['posts_per_page'] * self::RECENT_POOL );
		}

		/**
		 * Filters Chat retrieve WP_Query args.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_retrieve_query_args
		 * @param {array}  $args     Query args. `posts_per_page` is the bot's Reports retrieved
		 *                           (else the Chat feature's, else 10).
		 * @param {string} $question Search query (the question, or its rewrite).
		 * @param {array}  $bot      Selected chat bot.
		 * @param {array}  $plan     Search plan: `query`, `published_after`, `recent`.
		 * @return {array} Filtered args.
		 */
		return apply_filters( 'ep_chat_retrieve_query_args', $args, $question, $bot, $plan );
	}
}
