<?php
/**
 * Test the Claim_Verifier_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Gates\Claim_Verifier_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Steps;

/**
 * Claim_Verifier_Gate tests, with a fake verifier (`ep_chat_claim_verifier_verdict`).
 */
class TestChatClaimVerifier extends \WP_UnitTestCase {

	/**
	 * Answer the fake model returns.
	 *
	 * @var string
	 */
	protected $answer = '';

	/**
	 * Verdict the fake verifier returns (true, false, or a WP_Error).
	 *
	 * @var mixed
	 */
	protected $verdict = true;

	/**
	 * Verifier calls: the messages it got.
	 *
	 * @var array[]
	 */
	protected $calls = [];

	/**
	 * Setup each test: one retrieved post, a fake model, a fake verifier.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Views of the U.S.',
				'post_excerpt' => 'In Poland, 93% view the U.S. favorably, its highest rating. In Ohio, 61% of adults agree.',
				'post_status'  => 'publish',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			function () {
				return $this->answer;
			}
		);
		add_filter(
			'ep_chat_claim_verifier_verdict',
			function ( $pre, $messages ) {
				$this->calls[] = $messages;

				return $this->verdict;
			},
			10,
			2
		);
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the pipeline with the verifier on.
	 *
	 * @param string $answer   Answer from the fake model.
	 * @param string $question Question.
	 * @param array  $gates    Optional gates on for the bot.
	 * @param ?float $deadline When the answer must be ready, or null.
	 * @return array|\WP_Error
	 */
	protected function ask( string $answer, string $question = 'Which country rates the U.S. highest?', array $gates = [ 'claim_verifier' ], ?float $deadline = null ) {
		$this->answer = $answer;

		return ( new Pipeline() )->set_deadline( $deadline )->run(
			$question,
			[],
			[
				'execution_mode' => 'sync',
				// The answer checks, plus the gates under test.
				'optional_gates' => array_merge( [ 'figure_match', 'scope', 'superlative', 'refusal', 'citation_attributor' ], $gates ),
			]
		);
	}

	/**
	 * The verifier is optional and on by default: a bot that never saved its gates runs it; a
	 * bot that saved a choice without it does not.
	 *
	 * @group chat
	 */
	public function test_optional_and_on_by_default() {
		$gate = Gates::registry()->get( Claim_Verifier_Gate::SLUG );

		$this->assertFalse( $gate->is_required() );
		$this->assertTrue( $gate->is_enabled_by_default() );

		$bots = new Bots();
		$bots->register_post_type();
		$bot_id = $bots->create( 'Verifier bot', Bots::MODE_SYNC );

		$this->assertContains( 'claim_verifier', Gates::get_optional_enabled( $bot_id ) );

		Gates::update_optional_enabled( $bot_id, [] );

		$this->assertNotContains( 'claim_verifier', Gates::get_optional_enabled( $bot_id ) );
		$this->assertArrayHasKey( Claim_Verifier_Gate::STEP, Steps::get_labels() );
	}

	/**
	 * A ranking answer is verified: supported passes; not supported refuses with the ranking
	 * code, and the verifier sees the question, the answer, and the passages.
	 *
	 * @group chat
	 */
	public function test_ranking_claim_is_verified() {
		$steps = [];
		add_action(
			'ep_chat_step',
			static function ( $step ) use ( &$steps ) {
				$steps[] = $step;
			}
		);

		$answer = 'Poland gives the U.S. its highest rating: 93% view it favorably.';

		$this->assertSame( 'done', $this->ask( $answer )['status'] );
		$this->assertCount( 1, $this->calls );
		$this->assertStringContainsString( 'QUESTION: Which country rates the U.S. highest?', $this->calls[0]['user'] );
		$this->assertStringContainsString( 'ANSWER: ' . $answer, $this->calls[0]['user'] );
		$this->assertStringContainsString( '- Views of the U.S.', $this->calls[0]['user'] );
		$this->assertContains( Claim_Verifier_Gate::STEP, $steps );

		$this->verdict = false;
		$result        = $this->ask( $answer );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'comparison_not_in_sources', $result['refusal_reason'] );
	}

	/**
	 * A place in the question triggers the verifier; an unsupported place claim refuses with
	 * the place code.
	 *
	 * @group chat
	 */
	public function test_place_claim_is_verified() {
		$this->verdict = false;
		$result        = $this->ask( 'About 61% of Ohioans agree.', 'What share of Ohioans agree?' );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'unsupported_subgroup', $result['refusal_reason'] );
	}

	/**
	 * No call without a ranking or place claim, for a declined answer, or when the bot has the
	 * gate off.
	 *
	 * @group chat
	 */
	public function test_skipped_without_trigger_declined_or_off() {
		$this->assertSame( 'done', $this->ask( 'About 93% of Poles view the U.S. favorably.', 'How do Poles view the U.S.?' )['status'] );
		$this->assertSame( 'refused', $this->ask( 'NO_ANSWER' )['status'] );
		$this->assertSame( 'done', $this->ask( 'Poland gives the U.S. its highest rating: 93%.', 'Which country rates the U.S. highest?', [] )['status'] );

		$this->assertSame( [], $this->calls );
	}

	/**
	 * When the check fails, the answer is not shown and the reader gets an error to retry,
	 * not a refusal.
	 *
	 * @group chat
	 */
	public function test_failure_is_an_error_not_a_refusal() {
		$failures = [];
		add_action(
			'ep_chat_claim_verifier_failed',
			static function ( $error ) use ( &$failures ) {
				$failures[] = $error->get_error_code();
			}
		);

		$this->verdict = new \WP_Error( 'timeout', 'The model did not answer.' );
		$result        = $this->ask( 'Poland gives the U.S. its highest rating: 93%.' );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_verifier_failed', $result->get_error_code() );
		$this->assertSame( 'The answer could not be checked. Please try again.', $result->get_error_message() );
		$this->assertSame( [ 'timeout' ], $failures );
	}

	/**
	 * With a deadline, the check gets the time left; with no time left, the answer is not shown
	 * and the reader gets the same error to retry, without calling the model.
	 *
	 * @group chat
	 */
	public function test_deadline() {
		$failures = [];
		add_action(
			'ep_chat_claim_verifier_failed',
			static function ( $error ) use ( &$failures ) {
				$failures[] = $error->get_error_code();
			}
		);

		$answer = 'Poland gives the U.S. its highest rating: 93%.';

		$this->assertSame( 'done', $this->ask( $answer, 'Which country rates the U.S. highest?', [ 'claim_verifier' ], microtime( true ) + 30 )['status'] );
		$this->assertCount( 1, $this->calls );

		$result = $this->ask( $answer, 'Which country rates the U.S. highest?', [ 'claim_verifier' ], microtime( true ) - 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_verifier_failed', $result->get_error_code() );
		$this->assertSame( [ 'ep_chat_verifier_no_time' ], $failures );
		$this->assertCount( 1, $this->calls, 'The model was not called.' );

		$timeout = new \ReflectionMethod( Claim_Verifier_Gate::class, 'get_timeout' );
		$timeout->setAccessible( true );
		$gate = new Claim_Verifier_Gate();

		$this->assertSame( 10.0, $timeout->invoke( $gate, null ) );
		$this->assertSame( 10.0, $timeout->invoke( $gate, 30.0 ) );
		$this->assertSame( 3.5, $timeout->invoke( $gate, 3.5 ) );
	}

	/**
	 * The verdict is read from the last JSON object of the reply; anything else is unreadable.
	 *
	 * @group chat
	 */
	public function test_reply_parsing() {
		$gate = new Claim_Verifier_Gate();

		$this->assertTrue( $gate->parse( '{"supported": true}' ) );
		$this->assertFalse( $gate->parse( "```json\n{\"supported\": false}\n```" ) );
		$this->assertFalse( $gate->parse( 'Thinking {"supported": true} … final: {"supported": false}' ) );
		$this->assertFalse( $gate->parse( "{\n  \"supported\": false" ) );
		$this->assertNull( $gate->parse( 'supported: yes' ) );
		$this->assertNull( $gate->parse( '{"supported": "true"}' ) );
	}

	/**
	 * The verifier sees whole passages, those with the answer's figures first, at most 8 and
	 * within the character budget.
	 *
	 * @group chat
	 */
	public function test_messages_order_and_limit_passages() {
		$passages = array_map(
			static function ( $i ) {
				return [
					'title'   => "Report $i",
					'excerpt' => str_repeat( 'x', 3000 ) . ( 9 === $i ? ' Poland: 93% favorable.' : '' ),
				];
			},
			range( 1, 10 )
		);
		$gate     = new Claim_Verifier_Gate();
		$context  = [
			'question' => 'Q',
			'content'  => 'Poland gives the U.S. its highest rating, 93%.',
			'passages' => $passages,
		];
		$user     = $gate->get_messages( $context )['user'];

		// The passage with 93% leads, whole (its figure is past character 3,000).
		$this->assertStringStartsWith( "- Report 9\n", explode( "PASSAGES:\n", $user )[1] );
		$this->assertStringContainsString( 'Poland: 93% favorable.', $user );
		$this->assertSame( 8, substr_count( $user, "\n- Report" ) );

		add_filter(
			'ep_chat_claim_verifier_passage_budget',
			static function () {
				return 4000;
			}
		);

		$this->assertSame( 2, substr_count( $gate->get_messages( $context )['user'], "\n- Report" ) );
	}
}
