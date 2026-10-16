<?php
/**
 * Test the call that writes the AI-generated query
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Index_Schema;
use ElasticPressLabs\Feature\Chat\Query_Generator;
use ElasticPressLabs\Feature\Chat\Steps;

/**
 * Query_Generator tests.
 */
class TestChatQueryGenerator extends \WP_UnitTestCase {

	/**
	 * Reply the stubbed model gives (string or WP_Error).
	 *
	 * @var string|\WP_Error
	 */
	protected $reply = '';

	/**
	 * Messages the stubbed model received.
	 *
	 * @var array[]
	 */
	protected $calls = [];

	/**
	 * Stub the model.
	 */
	public function set_up() {
		parent::set_up();

		$this->reply = '';
		$this->calls = [];

		add_filter( 'ep_chat_ai_query_pre', [ $this, 'stub_model' ], 10, 2 );
	}

	/**
	 * Remove the stub.
	 */
	public function tear_down() {
		remove_filter( 'ep_chat_ai_query_pre', [ $this, 'stub_model' ], 10 );

		parent::tear_down();
	}

	/**
	 * The stubbed model.
	 *
	 * @param mixed $pre      Short-circuit value.
	 * @param array $messages Messages.
	 * @return string|\WP_Error
	 */
	public function stub_model( $pre, $messages ) {
		unset( $pre );

		$this->calls[] = $messages;

		return $this->reply;
	}

	/**
	 * A schema with fixed fields and terms.
	 *
	 * @param array $fields Path => type.
	 * @return Index_Schema
	 */
	protected function get_schema( array $fields = [] ): Index_Schema {
		$fields = $fields ? $fields : [
			'post_title'         => 'text',
			'post_content'       => 'text',
			'post_date'          => 'date',
			'terms.formats.name' => 'text',
			'terms.formats.slug' => 'keyword',
			'chunks'             => 'nested',
			'chunks.vector'      => 'dense_vector',
		];

		$schema = new class() extends Index_Schema {
			/**
			 * Fields.
			 *
			 * @var array
			 */
			public $fields = [];

			/**
			 * Fields.
			 *
			 * @return array
			 */
			public function get_fields(): array {
				return $this->fields;
			}

			/**
			 * Terms.
			 *
			 * @return array
			 */
			public function get_terms(): array {
				return [
					'formats' => [
						'report'     => 'Report',
						'fact-sheet' => 'Fact Sheet',
					],
				];
			}
		};

		foreach ( $fields as $path => $type ) {
			$schema->fields[ $path ] = [
				'type' => $type,
				'note' => 'post_title' === $path ? 'Title.' : '',
			];
		}

		return $schema;
	}

	/**
	 * A reply with a query (in prose and a code fence) is read and checked; the step is reported.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_generate_returns_the_checked_query() {
		$steps = [];
		$track = static function ( $step ) use ( &$steps ) {
			$steps[] = $step;
		};

		add_action( 'ep_chat_step', $track );

		$this->reply = "Here is the query:\n```json\n" . wp_json_encode(
			[
				'query' => [
					'bool' => [
						'must'   => [ [ 'match' => [ 'post_title' => 'cash' ] ] ],
						'filter' => [ [ 'term' => [ 'terms.formats.slug' => 'report' ] ] ],
					],
				],
				'knn'   => [
					'field'        => 'chunks.vector',
					'k'            => 10,
					'query_vector' => '[PLACEHOLDER]',
				],
				'size'  => 500,
			]
		) . "\n```";

		$result = ( new Query_Generator( $this->get_schema() ) )->generate( 'cash', [ 'question' => 'Cash?' ] );

		remove_action( 'ep_chat_step', $track );

		$this->assertSame( [ Query_Generator::STEP ], $steps );
		$this->assertSame( '', $result['model'] );
		$this->assertSame(
			[
				'query' => [
					'bool' => [
						'must'   => [ [ 'match' => [ 'post_title' => 'cash' ] ] ],
						'filter' => [ [ 'term' => [ 'terms.formats.slug' => 'report' ] ] ],
					],
				],
				'knn'   => [
					'field'          => 'chunks.vector',
					'k'              => 10,
					'num_candidates' => 100,
				],
			],
			$result['query']
		);
	}

	/**
	 * A reply that nests the whole query in `query` is unwrapped.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_wrapped_reply_is_unwrapped() {
		$this->reply = wp_json_encode(
			[
				'query' => [
					'query' => [ 'match' => [ 'post_title' => 'cash' ] ],
					'knn'   => [ 'field' => 'chunks.vector' ],
				],
			]
		);

		$result = ( new Query_Generator( $this->get_schema() ) )->generate( 'cash' );

		$this->assertSame( [ 'match' => [ 'post_title' => 'cash' ] ], $result['query']['query'] );
		$this->assertSame( 'chunks.vector', $result['query']['knn']['field'] );
	}

	/**
	 * Every failure is a WP_Error: the model call, a reply without JSON, a query the checks
	 * reject, and an index that cannot be read (no call then).
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_failures_are_errors() {
		$generator = new Query_Generator( $this->get_schema() );

		$this->reply = new \WP_Error( 'ep_chat_ai_query_exception', 'Timed out.' );
		$this->assertSame( 'ep_chat_ai_query_exception', $generator->generate( 'cash' )->get_error_code() );

		$this->reply = 'I cannot help with that.';
		$this->assertSame( 'ep_chat_ai_query_unreadable', $generator->generate( 'cash' )->get_error_code() );

		$this->reply = '{"query":{"script":{"script":"true"}}}';
		$this->assertSame( 'ep_chat_ai_query_clause_not_allowed', $generator->generate( 'cash' )->get_error_code() );

		$empty = new class() extends Index_Schema {
			/**
			 * No fields: the index cannot be read.
			 *
			 * @return array
			 */
			public function get_fields(): array {
				return [];
			}
		};

		$calls = count( $this->calls );

		$this->assertSame( 'ep_chat_ai_query_no_schema', ( new Query_Generator( $empty ) )->generate( 'cash' )->get_error_code() );
		$this->assertCount( $calls, $this->calls, 'No model call without an index.' );
	}

	/**
	 * The prompt lists the fields with their notes, the listed terms, the rules, the plan's
	 * limits, and examples; the question and the search are tagged data on one line each.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_messages() {
		$schema   = $this->get_schema();
		$messages = ( new Query_Generator( $schema ) )->get_messages(
			'cash use',
			[
				'question' => "And <b>cash</b>\nuse?",
				'plan'     => [
					'published_after' => '2020-01-01',
					'recent'          => true,
				],
			],
			$schema->get_fields(),
			$schema->get_terms()
		);

		$this->assertStringContainsString( '- post_title: text', $messages['system'] );
		$this->assertStringContainsString( 'Title.', $messages['system'] );
		$this->assertStringContainsString( '- chunks.vector: vector (knn only).', $messages['system'] );
		$this->assertStringContainsString( 'terms.formats.slug: report (Report), fact-sheet (Fact Sheet).', $messages['system'] );
		$this->assertStringContainsString( '"knn" on chunks.vector', $messages['system'] );
		$this->assertStringContainsString( '2020-01-01', $messages['system'] );
		$this->assertStringContainsString( 'recent data', $messages['system'] );
		$this->assertStringContainsString( 'Examples:', $messages['system'] );
		$this->assertStringContainsString( wp_date( 'Y-m-d' ), $messages['system'] );
		$this->assertSame( "<question>And ‹b›cash‹/b› use?</question>\n<search>cash use</search>\nJSON:", $messages['user'] );

		// The question is not repeated when it is what is searched.
		$messages = ( new Query_Generator( $schema ) )->get_messages( 'cash', [ 'question' => 'cash' ], $schema->get_fields(), [] );

		$this->assertSame( "<search>cash</search>\nJSON:", $messages['user'] );
		$this->assertStringNotContainsString( 'Values of', $messages['system'] );
	}

	/**
	 * Without a vector field, the rules and examples do not mention kNN.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_messages_without_a_vector_field() {
		$schema   = $this->get_schema(
			[
				'post_title' => 'text',
				'post_date'  => 'date',
			]
		);
		$messages = ( new Query_Generator( $schema ) )->get_messages( 'cash', [], $schema->get_fields(), [] );

		$this->assertStringNotContainsString( '"knn" on', $messages['system'] );
		$this->assertStringNotContainsString( 'chunks.vector', $messages['system'] );
	}

	/**
	 * Sites add rules and examples through filters.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_rules_and_examples_filters() {
		$rules    = static function ( $rules ) {
			$rules[] = 'Reports about a country are in the regions taxonomy.';

			return $rules;
		};
		$examples = static function () {
			return [
				[
					'question' => 'Trust in China',
					'query'    => [ 'query' => [ 'match' => [ 'post_title' => 'China trust' ] ] ],
				],
				[ 'question' => 'Broken example' ],
			];
		};

		add_filter( 'ep_chat_ai_query_rules', $rules );
		add_filter( 'ep_chat_ai_query_examples', $examples );

		$schema   = $this->get_schema();
		$messages = ( new Query_Generator( $schema ) )->get_messages( 'cash', [], $schema->get_fields(), [] );

		remove_filter( 'ep_chat_ai_query_rules', $rules );
		remove_filter( 'ep_chat_ai_query_examples', $examples );

		$this->assertStringContainsString( '- Reports about a country are in the regions taxonomy.', $messages['system'] );
		$this->assertStringContainsString( "Question: Trust in China\n{\"query\":{\"match\":{\"post_title\":\"China trust\"}}}", $messages['system'] );
		$this->assertStringNotContainsString( 'Broken example', $messages['system'] );
		$this->assertStringNotContainsString( 'remote work', $messages['system'] );
	}

	/**
	 * The step has a label.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_step_label() {
		$generator = new Query_Generator( $this->get_schema() );
		$generator->setup();

		$labels = Steps::get_labels();

		remove_filter( 'ep_chat_step_labels', [ $generator, 'add_step_label' ] );

		$this->assertNotEmpty( $labels[ Query_Generator::STEP ] ?? '' );
	}

	/**
	 * Adjustments: date ranges keep their lower bound only (and go without one), and the kNN
	 * search is added when missing. Text clauses are kept as written.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_adjust() {
		$schema    = $this->get_schema();
		$generator = new Query_Generator( $schema );
		$adjusted  = $generator->adjust(
			[
				'query' => [
					'bool' => [
						'must'   => [
							[
								'multi_match' => [
									'query'    => 'August 2013 social media survey LinkedIn users',
									'fields'   => [ 'post_title' ],
									'operator' => 'and',
								],
							],
							[
								'match' => [
									'post_title' => [
										'query'    => 'LinkedIn users',
										'operator' => 'and',
									],
								],
							],
						],
						'filter' => [
							[
								'range' => [
									'post_date' => [
										'gte' => '2013-08-01',
										'lte' => '2013-08-31',
									],
								],
							],
							[ 'range' => [ 'post_date' => [ 'lt' => '2020-01-01' ] ] ],
						],
					],
				],
			],
			$schema->get_fields()
		);

		$this->assertSame(
			[
				'query' => [
					'bool' => [
						'must'   => [
							[
								'multi_match' => [
									'query'    => 'August 2013 social media survey LinkedIn users',
									'fields'   => [ 'post_title' ],
									'operator' => 'and',
								],
							],
							[
								'match' => [
									'post_title' => [
										'query'    => 'LinkedIn users',
										'operator' => 'and',
									],
								],
							],
						],
						'filter' => [
							[ 'range' => [ 'post_date' => [ 'gte' => '2013-08-01' ] ] ],
						],
					],
				],
				'knn'   => [
					'field'          => 'chunks.vector',
					'k'              => 10,
					'num_candidates' => 100,
				],
			],
			$adjusted
		);

		// A query that is only an upper date bound goes; the kNN search stays.
		$this->assertSame(
			[
				'knn' => [
					'field'          => 'chunks.vector',
					'k'              => 10,
					'num_candidates' => 100,
				],
			],
			$generator->adjust( [ 'query' => [ 'range' => [ 'post_date' => [ 'lte' => '2020-01-01' ] ] ] ], $schema->get_fields() )
		);

		// Without a vector field, no kNN search is added.
		$text = $this->get_schema( [ 'post_title' => 'text' ] );

		$this->assertSame(
			[ 'query' => [ 'match' => [ 'post_title' => 'cash' ] ] ],
			( new Query_Generator( $text ) )->adjust( [ 'query' => [ 'match' => [ 'post_title' => 'cash' ] ] ], $text->get_fields() )
		);
	}
}
