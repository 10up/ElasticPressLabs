<?php
/**
 * Test Chat retrieval with each search method, and the AI query's fallbacks
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use ElasticPress\SearchAlgorithms;
use ElasticPressLabs\Feature\Chat\AI_Query;
use ElasticPressLabs\Feature\Chat\Query_Writing_Algorithm;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Retriever;

/**
 * Search method tests.
 */
class TestChatRetrieverSearchMethod extends BaseTestCase {

	/**
	 * Reply the stubbed query model gives.
	 *
	 * @var string|\WP_Error
	 */
	protected $reply = '';

	/**
	 * Search algorithm slugs of the Elasticsearch searches, in order.
	 *
	 * @var string[]
	 */
	protected $algorithms = [];

	/**
	 * Setup each test: two indexed posts, the AI query registered, the model stubbed.
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

		$this->reply      = '';
		$this->algorithms = [];

		add_filter( 'ep_chat_ai_query_pre', [ $this, 'stub_model' ] );
		add_action( 'ep_remote_request', [ $this, 'track_algorithm' ], 10, 2 );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		SearchAlgorithms::factory()->unregister( AI_Query::SLUG );
		SearchAlgorithms::factory()->unregister( 'test_writer' );
		remove_filter( 'ep_chat_ai_query_pre', [ $this, 'stub_model' ] );
		remove_action( 'ep_remote_request', [ $this, 'track_algorithm' ], 10 );

		parent::tear_down();
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
	 * Record the algorithm each Chat search request asked for (`site` for the site search's).
	 *
	 * @param array  $query Remote request.
	 * @param string $type  Request type.
	 * @return void
	 */
	public function track_algorithm( $query, $type ) {
		$vars = $query['query_args'] ?? [];

		if ( 'query' === $type && ! empty( $vars[ Retriever::QUERY_FLAG ] ) ) {
			$this->algorithms[] = $vars[ Retriever::ALGORITHM_VAR ] ?? 'site';
		}
	}

	/**
	 * A bot with search settings.
	 *
	 * @param string $method   Search method.
	 * @param string $fallback Fallback search.
	 * @return array
	 */
	protected function bot( string $method, string $fallback = '3.5' ): array {
		return [
			'search_algorithm' => $method,
			'search_fallback'  => $fallback,
		];
	}

	/**
	 * The site search's algorithm is left to ElasticPress; a chosen one is used for the Chat
	 * query only. Each records the algorithm that ran.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_site_and_chosen_algorithms() {
		$retriever = new Retriever();
		$site      = Indexables::factory()->get( 'post' )->get_search_algorithm( 'cash', [], [] )->get_slug();

		$this->assertCount( 2, $retriever->search( 'cash', $this->bot( 'site' ) ) );
		$this->assertSame( [ 'algorithm' => $site ], $retriever->get_last_search() );

		$this->assertCount( 2, $retriever->search( 'cash', $this->bot( '3.5' ) ) );
		$this->assertSame( [ 'algorithm' => '3.5' ], $retriever->get_last_search() );

		// A search outside Chat keeps the site's algorithm.
		$this->assertSame( $site, Indexables::factory()->get( 'post' )->get_search_algorithm( 'cash', [], [] )->get_slug() );
	}

	/**
	 * A method that is not registered runs the site search, and says so.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_unregistered_method() {
		$method = static function () {
			return 'missing';
		};

		add_filter( 'ep_chat_search_algorithm', $method );

		$retriever = new Retriever();
		$posts     = $retriever->search( 'cash', $this->bot( 'site' ) );

		remove_filter( 'ep_chat_search_algorithm', $method );

		$this->assertCount( 2, $posts );
		$this->assertSame( 'missing', $retriever->get_last_search()['requested_algorithm'] );
		$this->assertSame( Retriever::FALLBACK_UNAVAILABLE, $retriever->get_last_search()['fallback'] );
	}

	/**
	 * The AI query is written first and run once; the record has its query.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_ai_query() {
		$this->reply = '{"query":{"match":{"post_title":"credit"}}}';

		$retriever = new Retriever();
		$posts     = $retriever->search( 'cash and credit', $this->bot( AI_Query::SLUG ), [], 'Cash and credit?' );

		$this->assertSame( [ 'Cash and credit cards in 2015' ], wp_list_pluck( $posts, 'post_title' ) );
		$this->assertSame( [ AI_Query::SLUG ], $this->algorithms );
		$this->assertSame(
			[
				'algorithm'       => AI_Query::SLUG,
				'model'           => '',
				'generated_query' => [ 'query' => [ 'match' => [ 'post_title' => 'credit' ] ] ],
			],
			$retriever->get_last_search()
		);
	}

	/**
	 * A query that cannot be written runs the fallback, with no search for the AI query.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_generation_failure_falls_back() {
		$this->reply = 'No idea.';

		$retriever = new Retriever();
		$posts     = $retriever->search( 'cash', $this->bot( AI_Query::SLUG ) );
		$search    = $retriever->get_last_search();

		$this->assertCount( 2, $posts );
		$this->assertSame( [ '3.5' ], $this->algorithms );
		$this->assertSame( '3.5', $search['algorithm'] );
		$this->assertSame( AI_Query::SLUG, $search['requested_algorithm'] );
		$this->assertSame( Retriever::FALLBACK_GENERATION, $search['fallback'] );
		$this->assertSame( 'ep_chat_ai_query_unreadable', $search['fallback_error'] );
		$this->assertArrayNotHasKey( 'generated_query', $search );
	}

	/**
	 * A query Elasticsearch refuses, or one that finds nothing, runs the fallback.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_es_error_and_zero_hits_fall_back() {
		$failed = [];
		$track  = static function ( $reason ) use ( &$failed ) {
			$failed[] = $reason;
		};

		add_action( 'ep_chat_ai_query_failed', $track );

		// An unknown date format passes the checks, but Elasticsearch refuses it.
		$this->reply = '{"query":{"range":{"post_date":{"gte":"2020","format":"not a format"}}}}';

		$retriever = new Retriever();
		$posts     = $retriever->search( 'cash', $this->bot( AI_Query::SLUG ) );
		$search    = $retriever->get_last_search();

		$this->assertCount( 2, $posts );
		$this->assertSame( Retriever::FALLBACK_ES_ERROR, $search['fallback'] );
		$this->assertSame( 'ep_chat_retrieve_failed', $search['fallback_error'] );
		$this->assertArrayHasKey( 'generated_query', $search );

		$this->reply = '{"query":{"match":{"post_title":"pineapple"}}}';

		$posts  = $retriever->search( 'cash', $this->bot( AI_Query::SLUG ) );
		$search = $retriever->get_last_search();

		remove_action( 'ep_chat_ai_query_failed', $track );

		$this->assertCount( 2, $posts );
		$this->assertSame( Retriever::FALLBACK_ZERO_HITS, $search['fallback'] );
		$this->assertArrayNotHasKey( 'fallback_error', $search );
		$this->assertSame( [ Retriever::FALLBACK_ES_ERROR, Retriever::FALLBACK_ZERO_HITS ], $failed );
		$this->assertSame( [ AI_Query::SLUG, '3.5', AI_Query::SLUG, '3.5' ], $this->algorithms );
	}

	/**
	 * With no fallback, a failed query is an error to retry, and no hits are no sources.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_no_fallback() {
		$retriever = new Retriever();

		$this->reply = 'No idea.';

		$result = $retriever->search( 'cash', $this->bot( AI_Query::SLUG, 'none' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_retrieve_failed', $result->get_error_code() );
		$this->assertSame( 'none', $retriever->get_last_search()['algorithm'] );

		$this->reply = '{"query":{"match":{"post_title":"pineapple"}}}';

		$this->assertSame( [], $retriever->search( 'cash', $this->bot( AI_Query::SLUG, 'none' ) ) );
		$this->assertSame( Retriever::FALLBACK_ZERO_HITS, $retriever->get_last_search()['fallback'] );
		$this->assertSame( [ AI_Query::SLUG ], $this->algorithms );
	}

	/**
	 * The answer payload records how the search ran, and which model wrote the query.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_payload_records_the_search() {
		$this->reply = '{"query":{"match":{"post_title":"pineapple"}}}';

		$generate = static function () {
			return 'Most adults used cash in 2015 [[1]].';
		};

		add_filter( 'ep_chat_generate_text', $generate );

		// No optional answer checks: the claim verifier needs a model.
		$outcome = ( new Pipeline() )->run( 'cash', [], $this->bot( AI_Query::SLUG ) + [ 'optional_gates' => [] ] );

		remove_filter( 'ep_chat_generate_text', $generate );

		$this->assertIsArray( $outcome );

		$search = $outcome['meta']['search'];

		$this->assertSame( '3.5', $search['algorithm'] );
		$this->assertSame( AI_Query::SLUG, $search['requested_algorithm'] );
		$this->assertSame( Retriever::FALLBACK_ZERO_HITS, $search['fallback'] );
		$this->assertSame( [ 'query' => [ 'match' => [ 'post_title' => 'pineapple' ] ] ], $search['generated_query'] );
		$this->assertArrayNotHasKey( 'model', $search );
		$this->assertArrayNotHasKey( 'query', $search, 'The search plan is only kept when it differs from the question.' );
		$this->assertSame( '', $outcome['meta']['models']['query'] );
	}

	/**
	 * Log entries of Chat queries lose their vectors.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_redact_log() {
		$log = Retriever::redact_log(
			[
				'wp_args' => [
					Retriever::QUERY_FLAG => true,
					'ep_vectors'          => [ 0.1, 0.2 ],
				],
				'body'    => wp_json_encode(
					[
						'query' => [ 'match_all' => new \stdClass() ],
						'knn'   => [
							'field'        => 'chunks.vector',
							'query_vector' => [ 0.1, 0.2 ],
						],
					]
				),
			]
		);

		$this->assertSame( '[vector]', $log['wp_args']['ep_vectors'] );
		$this->assertSame( '[vector]', json_decode( $log['body'], true )['knn']['query_vector'] );

		$log = Retriever::redact_log( [ 'body' => wp_json_encode( [ 'knn' => [ [ 'query_vector' => [ 1 ] ], [ 'query_vector' => [ 2 ] ] ] ] ) ] );

		$this->assertSame( [ [ 'query_vector' => '[vector]' ], [ 'query_vector' => '[vector]' ] ], json_decode( $log['body'], true )['knn'] );
	}

	/**
	 * The Automatic fallback runs the site search where the hybrid search is not registered.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_automatic_fallback() {
		$this->reply = 'No idea.';

		$retriever = new Retriever();
		$posts     = $retriever->search( 'cash', $this->bot( AI_Query::SLUG, 'auto' ) );

		$this->assertCount( 2, $posts );
		$this->assertSame( [ 'site' ], $this->algorithms );
		$this->assertSame( Indexables::factory()->get( 'post' )->get_search_algorithm( 'cash', [], [] )->get_slug(), $retriever->get_last_search()['algorithm'] );
	}

	/**
	 * Any search algorithm that writes its query for each question (Query_Writing_Algorithm) gets
	 * the same flow as the AI-generated query: written first, one search with it, recorded, and the
	 * fallback when writing fails (no search) or the search finds nothing.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_query_writing_algorithm() {
		$writer = new class() extends \ElasticPress\SearchAlgorithm implements Query_Writing_Algorithm {
			/**
			 * Calls a model.
			 *
			 * @var bool
			 */
			public $uses_model = true;

			/**
			 * What write_query() returns next.
			 *
			 * @var array|\WP_Error
			 */
			public $next = [];

			/**
			 * Searches it was asked to write for.
			 *
			 * @var string[]
			 */
			public $written = [];

			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function get_slug(): string {
				return 'test_writer';
			}

			/**
			 * Name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return 'Test writer';
			}

			/**
			 * Description.
			 *
			 * @return string
			 */
			public function get_description(): string {
				return 'Searches the title for the word it wrote.';
			}

			/**
			 * Write the query.
			 *
			 * @param string $search  Search.
			 * @param array  $context Context.
			 * @return array|\WP_Error
			 */
			public function write_query( string $search, array $context ) {
				unset( $context );

				$this->written[] = $search;

				return $this->next;
			}

			/**
			 * Search the title for the written word.
			 *
			 * @param string $indexable_slug Indexable slug.
			 * @param string $search_term    Search term.
			 * @param array  $search_fields  Search fields.
			 * @param array  $query_vars     Query vars.
			 * @return array
			 */
			protected function get_raw_query( string $indexable_slug, string $search_term, array $search_fields, array $query_vars ): array {
				return [ 'match' => [ 'post_title' => (string) ( $query_vars[ Query_Writing_Algorithm::QUERY_VAR ]['word'] ?? '' ) ] ];
			}
		};

		SearchAlgorithms::factory()->register( $writer );

		$retriever    = new Retriever();
		$writer->next = [
			'query' => [ 'word' => 'credit' ],
			'model' => 'fixture::writer',
		];

		$posts = $retriever->search( 'cash and credit', $this->bot( 'test_writer' ) );

		$this->assertSame( [ 'Cash and credit cards in 2015' ], wp_list_pluck( $posts, 'post_title' ) );
		$this->assertSame( [ 'cash and credit' ], $writer->written );
		$this->assertSame( [ 'test_writer' ], $this->algorithms );
		$this->assertSame(
			[
				'algorithm'       => 'test_writer',
				'model'           => 'fixture::writer',
				'generated_query' => [ 'word' => 'credit' ],
			],
			$retriever->get_last_search()
		);
		$this->assertContains( 'test_writer', Settings::get_ai_search_methods() );
		$this->assertNotContains( 'test_writer', Settings::get_choices( 'search_fallback' ) );

		// Writing fails: the fallback runs, with no search for the writer.
		$writer->next = new \WP_Error( 'test_failed', 'No query.' );

		$retriever->search( 'cash', $this->bot( 'test_writer' ) );

		$this->assertSame( [ 'test_writer', '3.5' ], $this->algorithms );
		$this->assertSame( 'test_writer', $retriever->get_last_search()['requested_algorithm'] );
		$this->assertSame( Retriever::FALLBACK_GENERATION, $retriever->get_last_search()['fallback'] );

		// The search finds nothing: the fallback runs.
		$writer->next = [
			'query' => [ 'word' => 'pineapple' ],
			'model' => '',
		];

		$this->assertCount( 2, $retriever->search( 'cash', $this->bot( 'test_writer' ) ) );
		$this->assertSame( Retriever::FALLBACK_ZERO_HITS, $retriever->get_last_search()['fallback'] );
	}
}
