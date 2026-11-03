<?php
/**
 * Test how declines are shown, and clarifying questions
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Clarifier;
use ElasticPressLabs\Feature\Chat\Export;
use ElasticPressLabs\Feature\Chat\Intent;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Refusals;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * The refusal card (kinds, next steps, decline details) and the Clarify outcome.
 */
class TestChatRefusals extends BaseTestCase {
	/**
	 * Clarify replies for the next runs (string or WP_Error).
	 *
	 * @var mixed
	 */
	protected $clarify_reply = null;

	/**
	 * Clarify calls: messages sent.
	 *
	 * @var array[]
	 */
	protected $clarify_calls = [];

	/**
	 * Retrieved post IDs.
	 *
	 * @var int[]
	 */
	protected $post_ids = [];

	/**
	 * Stub the models and the search.
	 */
	public function set_up() {
		parent::set_up();

		( new Bots() )->register_post_type();

		$storage = new Storage();
		$storage->register_post_type();

		// Chat is not turned on in tests, so its hooks are added here.
		( new Intent() )->setup();
		( new Clarifier() )->setup();

		foreach ( [ 'Trust in the news media', 'Trust in government', 'How Americans trust each other' ] as $title ) {
			$this->post_ids[] = $this->factory()->post->create(
				[
					'post_title'   => $title,
					'post_content' => 'Most Americans say they trust each other.',
					'post_excerpt' => '',
					'post_status'  => 'publish',
				]
			);
		}

		add_filter(
			'ep_chat_retrieve',
			function () {
				return array_map( 'get_post', $this->post_ids );
			}
		);

		// A figure that no passage has: the answer is withheld (numeric_mismatch).
		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'About 73% of Americans trust each other.';
			}
		);

		add_filter(
			'ep_chat_clarify_pre',
			function ( $pre, $messages ) {
				$this->clarify_calls[] = $messages;

				return $this->clarify_reply;
			},
			10,
			2
		);
	}

	/**
	 * Chat feature settings.
	 *
	 * @param array $values Values.
	 */
	protected function set_feature( array $values ) {
		$settings         = Utils\get_option( 'ep_feature_settings', [] );
		$settings['chat'] = array_merge( [ 'active' => false ], is_array( $settings['chat'] ?? null ) ? $settings['chat'] : [], $values );

		Utils\update_option( 'ep_feature_settings', $settings );
	}

	/**
	 * Run the pipeline.
	 *
	 * @param string $question Question.
	 * @param array  $bot      Bot settings.
	 * @return array
	 */
	protected function ask( string $question, array $bot = [] ): array {
		$result = ( new Pipeline() )->run(
			$question,
			[],
			$bot + [
				'name'           => 'Test',
				'execution_mode' => 'sync',
				// The answer checks, without the optional model-based ones.
				'optional_gates' => [ 'figure_match', 'scope', 'superlative', 'refusal', 'citation_attributor' ],
			]
		);

		$this->assertIsArray( $result );

		return $result;
	}

	/**
	 * A clarify reply.
	 *
	 * @param array $options Options.
	 * @return string
	 */
	protected function clarify_reply( array $options ): string {
		return wp_json_encode(
			[
				'clarify' => true,
				'prompt'  => 'Trust in what — the news media, the government, or each other?',
				'options' => $options,
			]
		);
	}

	/**
	 * Every Labs code has its kind; the kind gives the next step and whether reports are
	 * listed; unknown codes and kinds are "nothing to answer from"; the vocabulary can change both.
	 *
	 * @group chat
	 */
	public function test_kinds() {
		$kinds = [
			'off_topic'                                  => Refusals::KIND_OUTSIDE,
			'unclear_question'                           => Refusals::KIND_OUTSIDE,
			'no_supporting_passage'                      => Refusals::KIND_NO_SOURCES,
			'answer_does_not_address_question'           => Refusals::KIND_NO_SOURCES,
			'answer_source_carries_injected_instruction' => Refusals::KIND_NO_SOURCES,
			'numeric_mismatch'                           => Refusals::KIND_WITHHELD,
			'unsupported_subgroup'                       => Refusals::KIND_WITHHELD,
			'comparison_not_in_sources'                  => Refusals::KIND_WITHHELD,
			'wave_not_in_corpus'                         => Refusals::KIND_WITHHELD,
			'question_carries_injected_instruction'      => Refusals::KIND_AS_ASKED,
			'made_up_code'                               => Refusals::KIND_NO_SOURCES,
		];

		foreach ( $kinds as $code => $kind ) {
			$this->assertSame( $kind, Refusals::describe( $code )['kind'], $code );
		}

		$this->assertFalse( Refusals::describe( 'off_topic' )['related'] );
		$this->assertTrue( Refusals::describe( 'no_supporting_passage' )['related'] );
		$this->assertTrue( Refusals::describe( 'numeric_mismatch' )['related'] );
		$this->assertFalse( Refusals::describe( 'question_carries_injected_instruction' )['related'] );
		$this->assertSame( 'Try a narrower question, or open one of the reports below.', Refusals::describe( 'numeric_mismatch' )['next_step'] );
		$this->assertSame( 'Try rephrasing it as a question.', Refusals::describe( 'unclear_question' )['next_step'] );
		$this->assertSame( 'Unsupported figure', Refusals::describe( 'numeric_mismatch' )['label'] );
		$this->assertNotSame( '', Refusals::describe( 'numeric_mismatch' )['description'] );

		add_filter(
			'ep_chat_refusal_reasons',
			static function ( $reasons ) {
				$reasons['numeric_mismatch']['kind']      = Refusals::KIND_AS_ASKED;
				$reasons['numeric_mismatch']['next_step'] = 'Ask again.';
				$reasons['off_topic']['kind']             = 'not_a_kind';

				return $reasons;
			},
			20
		);

		$this->assertSame( Refusals::KIND_AS_ASKED, Refusals::describe( 'numeric_mismatch' )['kind'] );
		$this->assertSame( 'Ask again.', Refusals::describe( 'numeric_mismatch' )['next_step'] );
		$this->assertSame( Refusals::KIND_NO_SOURCES, Refusals::describe( 'off_topic' )['kind'] );
	}

	/**
	 * The setting: three values, "show" by default; a bot overrides it; other values refused.
	 *
	 * @group chat
	 */
	public function test_display_setting() {
		$this->assertSame( 'show', Settings::get( 'decline_display' ) );
		$this->assertSame( 'uniform', Settings::get( 'decline_display', [ 'decline_display' => 'uniform' ] ) );
		$this->assertWPError( Settings::sanitize( 'decline_display', 'loud' ) );
		$this->assertSame( [ 'show', 'hide', 'uniform' ], array_keys( Settings::get_choice_labels( 'decline_display' ) ) );

		$this->set_feature( [ 'decline_display' => 'hide' ] );

		$this->assertSame( 'hide', Settings::get( 'decline_display' ) );
		$this->assertSame( 'show', Settings::get( 'decline_display', [ 'decline_display' => 'show' ] ) );
	}

	/**
	 * Decline details on: label, description, the code's message. Off: none of them, a generic
	 * message, no code. The next step and the reports stay. The injection codes are always
	 * generic; kinds that do not list reports lose them.
	 *
	 * @group chat
	 */
	public function test_present() {
		$payload = [
			'refusal_reason' => 'numeric_mismatch',
			'hits'           => [ [ 'title' => 'A report' ] ],
		];
		$message = 'The answer was withheld because it included a figure that could not be found in the sources.';

		[ $on, $content ] = Refusals::present( $payload, $message, Storage::STATUS_REFUSED, [] );

		$this->assertSame( $message, $content );
		$this->assertSame( 'numeric_mismatch', $on['refusal_reason'] );
		$this->assertSame( 'Unsupported figure', $on['refusal']['label'] );
		$this->assertSame( Refusals::KIND_WITHHELD, $on['refusal']['kind'] );
		$this->assertTrue( $on['refusal']['related'] );
		$this->assertCount( 1, $on['hits'] );

		[ $off, $content ] = Refusals::present( $payload, $message, Storage::STATUS_REFUSED, [ 'decline_display' => 'hide' ] );

		$this->assertSame( Refusals::get_generic_message(), $content );
		$this->assertArrayNotHasKey( 'refusal_reason', $off );
		$this->assertSame( [ 'kind', 'next_step', 'related' ], array_keys( $off['refusal'] ) );
		$this->assertCount( 1, $off['hits'] );

		// The same card for every decline: one message, one next step, no reports, no kind.
		foreach ( [ 'numeric_mismatch', 'off_topic', 'no_supporting_passage' ] as $code ) {
			[ $uniform, $content ] = Refusals::present(
				[
					'refusal_reason' => $code,
					'hits'           => [ [ 'title' => 'A report' ] ],
				],
				'A message.',
				Storage::STATUS_REFUSED,
				[ 'decline_display' => 'uniform' ]
			);

			$this->assertSame( Refusals::get_generic_message(), $content, $code );
			$this->assertSame(
				[
					'next_step' => 'Try rephrasing the question, or ask about another topic.',
					'related'   => false,
				],
				$uniform['refusal'],
				$code
			);
			$this->assertSame( [], $uniform['hits'], $code );
			$this->assertArrayNotHasKey( 'refusal_reason', $uniform, $code );
		}

		// Always generic, and no reports for a question that carried instructions.
		[ $injected, $content ] = Refusals::present(
			[
				'refusal_reason' => 'question_carries_injected_instruction',
				'hits'           => [ [ 'title' => 'A report' ] ],
			],
			'The question included instructions the assistant does not follow.',
			Storage::STATUS_REFUSED,
			[]
		);

		$this->assertSame( Refusals::get_generic_message(), $content );
		$this->assertSame( [], $injected['hits'] );
		$this->assertSame( 'Question carried instructions', $injected['refusal']['label'] );

		// Answered questions are left alone; a clarify keeps its prompt.
		$this->assertSame( [ [ 'hits' => [] ], 'An answer.' ], Refusals::present( [ 'hits' => [] ], 'An answer.', Storage::STATUS_DONE, [] ) );
		$this->assertSame( 'Trust in what?', Refusals::present( $payload, 'Trust in what?', Storage::STATUS_CLARIFY, [ 'decline_display' => 'uniform' ] )[1] );
	}

	/**
	 * Stored answers are shown with the bot's current setting; History and the export keep the
	 * code and the message.
	 *
	 * @group chat
	 */
	public function test_stored_answers_follow_the_bot() {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$bots    = new Bots();
		$bot_id  = $bots->create( 'Public', Bots::MODE_SYNC, [ 'decline_display' => 'hide' ] );
		$storage = new Storage();
		$message = 'The answer was withheld because it included a figure that could not be found in the sources.';

		$accepted = $storage->accept_question( 'How many trust each other?' );
		update_comment_meta( $accepted['question_id'], Storage::META_BOT_ID, $bot_id );
		$storage->complete_question(
			$accepted['ep_chat_id'],
			$accepted['question_id'],
			$message,
			[
				'refusal_reason' => 'numeric_mismatch',
				'hits'           => [],
			],
			Storage::STATUS_REFUSED
		);

		$answer = $storage->get_turns( $accepted['ep_chat_id'] )[1];

		$this->assertSame( Refusals::get_generic_message(), $answer['content'] );
		$this->assertArrayNotHasKey( 'refusal_reason', $answer['payload'] );
		$this->assertArrayNotHasKey( 'label', $answer['payload']['refusal'] );

		// Details on again: the stored answer shows them.
		$bots->update( $bot_id, 'Public', Bots::MODE_SYNC, [ 'decline_display' => 'show' ] );

		$answer = $storage->get_turns( $accepted['ep_chat_id'] )[1];

		$this->assertSame( $message, $answer['content'] );
		$this->assertSame( 'Unsupported figure', $answer['payload']['refusal']['label'] );

		$json = '';
		( new Export(
			static function ( $piece ) use ( &$json ) {
				$json .= $piece;
			}
		) )->stream( [ 'view' => 'all' ], true, get_current_user_id() );

		$exported = json_decode( $json, true )['conversations'][0]['turns'][1];

		$this->assertSame( 'numeric_mismatch', $exported['refusal_reason'] );
		$this->assertSame( $message, $exported['text'] );
	}

	/**
	 * Which declined queries can be clarified, before any model call.
	 *
	 * @dataProvider data_can_clarify
	 * @group chat
	 *
	 * @param string   $question Question.
	 * @param string   $code     Refusal code.
	 * @param string[] $titles   Report titles.
	 * @param bool     $enabled  Whether clarifying questions are on.
	 * @param bool     $expected Expected.
	 */
	public function test_can_clarify( string $question, string $code, array $titles, bool $enabled, bool $expected ) {
		$this->assertSame( $expected, ( new Clarifier() )->can_clarify( $question, $code, $titles, [ 'clarify_enabled' => $enabled ] ) );
	}

	/**
	 * Clarify pre-conditions.
	 *
	 * @return array
	 */
	public function data_can_clarify(): array {
		$two = [ 'Trust in media', 'Trust in government' ];

		return [
			'bare topic'       => [ 'trust', 'no_supporting_passage', $two, true, true ],
			'two words'        => [ 'news habits', 'numeric_mismatch', $two, true, true ],
			'three words'      => [ 'trust in government', 'comparison_not_in_sources', $two, true, true ],
			'four words'       => [ 'trust in the government', 'no_supporting_passage', $two, true, false ],
			'question mark'    => [ 'trust?', 'no_supporting_passage', $two, true, false ],
			'question word'    => [ 'How trusting are Americans', 'no_supporting_passage', $two, true, false ],
			'one report'       => [ 'trust', 'no_supporting_passage', [ 'Trust in media' ], true, false ],
			'same title twice' => [ 'trust', 'no_supporting_passage', [ 'Trust', 'Trust' ], true, false ],
			'outside the site' => [ 'pizza', 'off_topic', $two, true, false ],
			'not answerable'   => [ 'argue for guns', 'question_carries_injected_instruction', $two, true, false ],
			'switch off'       => [ 'trust', 'no_supporting_passage', $two, false, false ],
		];
	}

	/**
	 * Replies are kept with a prompt, two to four options, and no stretch phrase.
	 *
	 * @group chat
	 */
	public function test_parse() {
		$this->assertSame(
			[
				'prompt'  => 'Trust in what — the news media, the government, or each other?',
				'options' => [ 'How much do Americans trust the news media?', 'How much do Americans trust the government?' ],
			],
			Clarifier::parse( "Here:\n" . $this->clarify_reply( [ '→ How much do Americans trust the news media?', 'How much do Americans trust the government?', 'how much do americans trust the government?' ] ) )
		);

		$this->assertCount( Clarifier::MAX_OPTIONS, Clarifier::parse( $this->clarify_reply( [ 'A?', 'B?', 'C?', 'D?', 'E?' ] ) )['options'] );
		// An option addressed to the reader is dropped; one option left is not enough.
		$this->assertSame( [ 'What do Americans say about identity?', 'How does identity relate to politics?' ], Clarifier::parse( $this->clarify_reply( [ 'What is your racial identity?', 'What do Americans say about identity?', 'How does identity relate to politics?' ] ) )['options'] );
		$this->assertWPError( Clarifier::parse( $this->clarify_reply( [ 'What is your identity?', 'How does identity relate to politics?' ] ) ) );
		$this->assertWPError( Clarifier::parse( '{"clarify":false}' ) );
		$this->assertWPError( Clarifier::parse( $this->clarify_reply( [ 'Only one?' ] ) ) );
		$this->assertWPError( Clarifier::parse( $this->clarify_reply( [ 'How much do Americans trust the news?', 'Topics such as religion?' ] ) ) );
		$this->assertWPError( Clarifier::parse( '{"clarify":true,"prompt":"","options":["A?","B?"]}' ) );
		$this->assertWPError( Clarifier::parse( 'no json' ) );
	}

	/**
	 * The prompt: the site's scope, the rules (filterable), the query and the titles as data.
	 *
	 * @group chat
	 */
	public function test_messages() {
		add_filter(
			'ep_chat_clarify_rules',
			static function ( $rules ) {
				$rules['should'][] = '"faith" (affiliation? practice?)';

				return $rules;
			}
		);

		$messages = ( new Clarifier() )->get_messages( 'trust <b>', [ 'Trust in media', 'Trust in government' ] );

		$this->assertStringContainsString( 'the content published on', $messages['system'] );
		$this->assertStringContainsString( '"faith" (affiliation? practice?)', $messages['system'] );
		$this->assertStringContainsString( 'when unsure, return false', $messages['system'] );
		$this->assertSame( "<query>trust ‹b›</query>\n<related_reports>Trust in media | Trust in government</related_reports>\nJSON:", $messages['user'] );
	}

	/**
	 * A short query about to be declined gets the Clarify outcome: the prompt, the options, the
	 * reports found, and the code it replaced. A failed call, or a phrased question, is declined
	 * as usual.
	 *
	 * @group chat
	 */
	public function test_pipeline() {
		$options             = [ 'How much do Americans trust the news media?', 'How much do Americans trust the government?' ];
		$this->clarify_reply = $this->clarify_reply( $options );

		$result = $this->ask( 'trust', [ 'clarify_enabled' => true ] );

		$this->assertSame( Storage::STATUS_CLARIFY, $result['status'] );
		$this->assertSame( 'Trust in what — the news media, the government, or each other?', $result['content'] );
		$this->assertSame( $options, $result['meta']['clarify']['options'] );
		$this->assertSame( 'numeric_mismatch', $result['meta']['refusal_reason'] );
		$this->assertCount( 3, $result['meta']['hits'] );
		$this->assertStringContainsString( 'Trust in the news media | Trust in government | How Americans trust each other', $this->clarify_calls[0]['user'] );

		// A phrased question never reaches the model.
		$result = $this->ask( 'How much do Americans trust each other?', [ 'clarify_enabled' => true ] );

		$this->assertSame( Storage::STATUS_REFUSED, $result['status'] );
		$this->assertCount( 1, $this->clarify_calls );

		// A failed call declines as usual.
		$this->clarify_reply = new \WP_Error( 'timeout', 'Timed out.' );

		$this->assertSame( Storage::STATUS_REFUSED, $this->ask( 'trust', [ 'clarify_enabled' => true ] )['status'] );

		// Off by default.
		$this->assertSame( Storage::STATUS_REFUSED, $this->ask( 'trust' )['status'] );
		$this->assertCount( 2, $this->clarify_calls );
	}

	/**
	 * The Clarify outcome is stored and exported as its own outcome.
	 *
	 * @group chat
	 */
	public function test_clarify_is_stored_and_exported() {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$storage  = new Storage();
		$accepted = $storage->accept_question( 'trust' );

		$this->assertIsInt(
			$storage->complete_question(
				$accepted['ep_chat_id'],
				$accepted['question_id'],
				'Trust in what?',
				[
					'refusal_reason' => 'no_supporting_passage',
					'hits'           => [],
					'clarify'        => [
						'prompt'  => 'Trust in what?',
						'options' => [ 'A?', 'B?' ],
						'model'   => '',
					],
				],
				Storage::STATUS_CLARIFY
			)
		);
		$this->assertSame( Storage::STATUS_CLARIFY, $storage->get_status( $accepted['question_id'] ) );

		$turns = $storage->get_turns( $accepted['ep_chat_id'] );

		$this->assertSame( [ 'A?', 'B?' ], $turns[1]['payload']['clarify']['options'] );
		$this->assertSame( Refusals::KIND_NO_SOURCES, $turns[1]['payload']['refusal']['kind'] );

		$json = '';
		( new Export(
			static function ( $piece ) use ( &$json ) {
				$json .= $piece;
			}
		) )->stream( [ 'view' => 'all' ], true, get_current_user_id() );

		$answer = json_decode( $json, true )['conversations'][0]['turns'][1];

		$this->assertSame( 'clarify', $answer['outcome'] );
		$this->assertSame(
			[
				'prompt'  => 'Trust in what?',
				'options' => [ 'A?', 'B?' ],
			],
			$answer['clarify']
		);
		$this->assertSame( 'no_supporting_passage', $answer['refusal_reason'] );
	}
}
