<?php
/**
 * Test the Refusal_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Gates\Claim_Verifier_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Figure_Match_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Injection_Echo_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Population_Match_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Trend_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Refusal_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Scope_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Superlative_Gate;
use ElasticPressLabs\Feature\Chat\Pipeline;

/**
 * Refusal_Gate and pipeline tests.
 */
class TestChatRefusal extends \WP_UnitTestCase {

	/**
	 * Answer the fake model returns.
	 *
	 * @var string
	 */
	protected $answer = '';

	/**
	 * Setup each test: one retrieved post, a fake model.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Fewer Americans use cash',
				'post_excerpt' => 'Today, 42% of U.S. adults say they do not use cash in a typical week.',
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
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the pipeline for a question.
	 *
	 * @param string $answer Answer from the fake model.
	 * @return array
	 */
	protected function ask( string $answer ): array {
		$this->answer = $answer;

		// The answer checks, without the optional model-based ones.
		return ( new Pipeline() )->run(
			'How many adults in Canada use cash?',
			[],
			[
				'execution_mode' => 'sync',
				'optional_gates' => [ 'figure_match', 'scope', 'superlative', 'refusal', 'citation_attributor' ],
			]
		);
	}

	/**
	 * Assert the pipeline declined the answer as not answered.
	 *
	 * @param array  $result Pipeline result.
	 * @param string $draft  The model's answer, which must not be returned.
	 * @return void
	 */
	protected function assertDeclined( array $result, string $draft ) {
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( Refusal_Gate::REASON, $result['refusal_reason'] );
		$this->assertSame( 'No sources found to answer that question.', $result['content'] );
		$this->assertStringNotContainsString( $draft, wp_json_encode( $result ) );
		$this->assertArrayNotHasKey( 'provenance', $result['meta'] );
	}

	/**
	 * Labs registers the gate as optional and on by default, last in verify_answer, with its
	 * reason and its self-report instruction.
	 *
	 * @group chat
	 */
	public function test_gate_is_optional_and_last_answer_check() {
		$verify = array_values(
			array_map(
				static function ( Gate $gate ) {
					return get_class( $gate );
				},
				Gates::registry()->get_for_stage( Gate::STAGE_VERIFY_ANSWER )
			)
		);

		$this->assertSame( [ Injection_Echo_Gate::class, Figure_Match_Gate::class, Scope_Gate::class, Population_Match_Gate::class, Trend_Gate::class, Superlative_Gate::class, Claim_Verifier_Gate::class, Refusal_Gate::class ], $verify );
		$this->assertFalse( Gates::registry()->get( Refusal_Gate::SLUG )->is_required() );
		$this->assertTrue( Gates::registry()->get( Refusal_Gate::SLUG )->is_enabled_by_default() );
		$this->assertArrayHasKey( Refusal_Gate::REASON, Gates::refusal_reasons() );
		$this->assertContains(
			'Answer the parts of the question the sources cover, and say plainly what they do not cover. If the sources cover none of it, reply with exactly NO_ANSWER and nothing else.',
			Gates::instructions( [ 'optional_gates' => [ Refusal_Gate::SLUG ] ] )
		);
	}

	/**
	 * The self-report token declines the answer, keeps the passages as related research, and
	 * sends the draft to the action only.
	 *
	 * @group chat
	 */
	public function test_self_report_token_declines() {
		$declined = [];

		add_action(
			'ep_chat_answer_declined',
			static function ( $context ) use ( &$declined ) {
				$declined[] = $context['content'];
			}
		);

		$result = $this->ask( 'NO_ANSWER' );

		$this->assertDeclined( $result, 'NO_ANSWER' );
		$this->assertSame( [ 'NO_ANSWER' ], $declined );
		$this->assertCount( 1, $result['hits'] );
		$this->assertSame( 'Fewer Americans use cash', $result['hits'][0]['title'] );

		$this->assertDeclined( $this->ask( '**NO_ANSWER**' ), '**NO_ANSWER**' );
	}

	/**
	 * A refusal phrase in the first sentence declines the answer, whatever its length, emphasis,
	 * or apostrophes.
	 *
	 * @group chat
	 */
	public function test_refusal_phrase_in_first_sentence_declines() {
		$long = 'The sources do not contain information about cash use in Canada. ' . str_repeat( 'They describe U.S. adults and their weekly purchases. ', 6 );

		$this->assertDeclined( $this->ask( $long ), 'cash use in Canada' );
		$this->assertDeclined( $this->ask( '**Sorry**, I can’t answer that from these sources.' ), 'from these sources' );
		$this->assertDeclined( $this->ask( '- The provided sources do not provide Canadian data.' ), 'Canadian data' );
	}

	/**
	 * A short answer is declined when a refusal phrase is anywhere in it.
	 *
	 * @group chat
	 */
	public function test_short_hedge_declines() {
		$this->assertDeclined( $this->ask( 'Cash use is common. However, the sources do not provide a Canadian figure.' ), 'Canadian figure' );
	}

	/**
	 * A real answer that mentions a refusal phrase after its first sentence is kept.
	 *
	 * @group chat
	 */
	public function test_real_answer_mentioning_a_phrase_mid_text_passes() {
		$answer = 'About 42% of U.S. adults say they do not use cash in a typical week. '
			. 'That share has grown quickly among younger adults and in higher-income households across the country. '
			. 'The report does not address Canada, so this figure is for the U.S. only.';

		$result = $this->ask( $answer );

		$this->assertGreaterThan( 200, strlen( $answer ) );
		$this->assertSame( 'done', $result['status'] );
		$this->assertStringContainsString( 'does not address Canada', $result['content'] );
	}

	/**
	 * A partial answer — it opens with what the sources lack, then gives grounded statistics —
	 * is kept. Years and small counts alone do not make it an answer.
	 *
	 * @group chat
	 */
	public function test_partial_answer_with_statistics_passes() {
		$partial = 'The sources do not contain a figure for Canada. However, 42% of U.S. adults do not use cash in a typical week.';

		$this->assertSame( 'done', $this->ask( $partial )['status'] );

		$gate = new Refusal_Gate();

		$this->assertFalse( $gate->is_declined( 'The sources do not provide a Canadian share, but the U.S. share is 42%.' ) );
		$this->assertTrue( $gate->is_declined( 'The sources do not contain estimates for 2026.' ) );
		$this->assertTrue( $gate->is_declined( 'The sources do not contain figures for 12 states.' ) );
		$this->assertTrue( $gate->is_declined( 'NO_ANSWER' ) );
	}

	/**
	 * Phrases and limits are filterable.
	 *
	 * @group chat
	 */
	public function test_phrases_and_limits_are_filterable() {
		add_filter(
			'ep_chat_refusal_phrases',
			static function ( $phrases ) {
				$phrases[] = 'No Data For';

				return $phrases;
			}
		);

		$this->assertDeclined( $this->ask( 'There is no data for Canada in these reports.' ), 'Canada in these reports' );

		add_filter(
			'ep_chat_refusal_limits',
			static function () {
				return [ 'short_answer' => 0 ];
			}
		);

		$gate = new Refusal_Gate();

		$this->assertSame( 220, $gate->get_limits()['first_sentence'] );
		$this->assertFalse( $gate->is_declined( 'Cash use is common. The sources do not provide a Canadian figure.' ) );
		$this->assertTrue( $gate->is_declined( 'The sources do not provide a Canadian figure.' ) );
	}
}
