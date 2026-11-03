<?php
/**
 * Test Chat conversation summaries
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\History;
use ElasticPressLabs\Feature\Chat\History_List_Table;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Processor;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Storage;
use ElasticPressLabs\Feature\Chat\Summarizer;

/**
 * Chat summary test class
 */
class TestChatSummary extends BaseTestCase {
	/**
	 * A valid model reply.
	 *
	 * @var string
	 */
	protected const REPLY = "The user asked about TikTok use among U.S. adults and teens ages 13 to 17, with trends from 2021 to 2025.\n\nKeywords: TikTok, social media, teens, U.S. adults, 2025";

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Bots.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->storage = new Storage();
		$this->storage->register_post_type();
		$this->storage->setup();

		$this->bots = new Bots();
		$this->bots->register_post_type();

		// Chat is not turned on in tests, so its hooks are added here.
		( new Summarizer() )->setup();

		wp_set_current_user( $this->ep_factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		$_REQUEST = [];
		$_GET     = [];

		// Nothing queued leaks into the next test.
		( new Summarizer() )->run_pending();

		parent::tear_down();
	}

	/**
	 * Set Chat feature settings.
	 *
	 * @param array $values Settings.
	 */
	protected function set_feature( array $values ) {
		$settings         = Utils\get_option( 'ep_feature_settings', [] );
		$settings['chat'] = array_merge( [ 'active' => false ], is_array( $settings['chat'] ?? null ) ? $settings['chat'] : [], $values );

		Utils\update_option( 'ep_feature_settings', $settings );
	}

	/**
	 * An answered question, stored with a bot.
	 *
	 * @param string $question Question.
	 * @param string $answer   Answer.
	 * @param int    $bot_id   Bot ID.
	 * @param string $status   Status.
	 * @param int    $chat_id  Conversation to follow up.
	 * @return array{ep_chat_id: int, question_id: int, answer_id: int}
	 */
	protected function exchange( string $question, string $answer, int $bot_id = 0, string $status = Storage::STATUS_DONE, int $chat_id = 0 ): array {
		$accepted = $this->storage->accept_question( $question, $chat_id );

		if ( $bot_id ) {
			update_comment_meta( $accepted['question_id'], Storage::META_BOT_ID, $bot_id );
		}

		$answer_id = $this->storage->complete_question( $accepted['ep_chat_id'], $accepted['question_id'], $answer, [], $status );

		return $accepted + [ 'answer_id' => (int) $answer_id ];
	}

	/**
	 * Replies the summary model gives, in turn; records the messages it got.
	 *
	 * @param array $replies Replies (string or WP_Error).
	 * @return \ArrayObject Messages of each call.
	 */
	protected function reply_with( array $replies ): \ArrayObject {
		$calls = new \ArrayObject();

		add_filter(
			'ep_chat_summary_pre',
			static function ( $pre, $messages ) use ( &$replies, $calls ) {
				$calls[] = $messages;

				return array_shift( $replies ) ?? new \WP_Error( 'no_reply', 'No reply left.' );
			},
			10,
			2
		);

		return $calls;
	}

	/**
	 * The setting: off by default, the feature turns it on, a bot overrides, bad values refused.
	 *
	 * @group chat
	 */
	public function test_setting() {
		$this->assertFalse( Settings::get( 'summary_enabled' ) );

		$this->set_feature( [ 'summary_enabled' => '1' ] );

		$inherits = $this->bots->get( $this->bots->create( 'Inherits', Bots::MODE_SYNC ) );
		$off      = $this->bots->get( $this->bots->create( 'Off', Bots::MODE_SYNC, [ 'summary_enabled' => '0' ] ) );

		$this->assertNull( $inherits['summary_enabled'] );
		$this->assertTrue( Settings::get( 'summary_enabled', $inherits ) );
		$this->assertFalse( $off['summary_enabled'] );
		$this->assertFalse( Settings::get( 'summary_enabled', $off ) );
		$this->assertSame( '0', get_post_meta( $off['id'], Bots::META_SETTING_PREFIX . 'summary_enabled', true ) );
		$this->assertWPError( Settings::sanitize( 'summary_enabled', 'maybe' ) );

		// The summary model is the Chat feature's only.
		$this->assertNotContains( 'summary_model', Settings::get_shared_keys() );
		$this->assertSame( '', Settings::get( 'summary_model', [ 'summary_model' => 'x::y' ] ) );

		$feature = Features::factory()->get_registered_feature( 'chat' );

		$this->assertSame( [ 'summary_enabled' => '0' ], $feature->sanitize_settings_callback( [ 'summary_enabled' => false ] ) );

		$feature->set_i18n_strings();
		$feature->reset_settings_schema();

		$groups = [];

		foreach ( $feature->get_settings_schema() as $field ) {
			$groups[ $field['key'] ] = $field['field_group_slug'] ?? '';
		}

		$this->assertSame( 'chat_defaults', $groups['summary_enabled'] );
		$this->assertSame( 'chat_models', $groups['summary_model'] );
	}

	/**
	 * Figures are percentages and numbers other than years and age groups.
	 *
	 * @group chat
	 */
	public function test_figures() {
		foreach ( [ '37% of adults', '21 percent', 'about 1,200 respondents', 'Wave 132', 'a [figure] rise', 'about four-in-ten adults', 'roughly half of teens', 'two-thirds of voters' ] as $text ) {
			$this->assertTrue( Summarizer::has_figure( $text ), $text );
		}

		foreach ( [ 'teens ages 13 to 17', 'teens aged 13-17', 'adults under 30', 'adults 65 and older', '18-to-29-year-olds', 'the spring 2023 survey', 'the percentage of adults' ] as $text ) {
			$this->assertFalse( Summarizer::has_figure( $text ), $text );
		}

		$this->assertSame(
			'In 2025, [figure] of adults under 30 and [figure] of teens ages 13 to 17.',
			Summarizer::mask_figures( 'In 2025, 63% of adults under 30 and 68% of teens ages 13 to 17.' )
		);
	}

	/**
	 * A reply splits into text and keywords (deduplicated, at most 50); list numbers are
	 * dropped; too long, empty, or with a figure is refused.
	 *
	 * @group chat
	 */
	public function test_parse() {
		$summary = Summarizer::parse( "1. The user asked about TikTok.\n2. Keywords: TikTok, social media, tiktok, teens." );

		$this->assertSame( 'The user asked about TikTok.', $summary['text'] );
		$this->assertSame( [ 'TikTok', 'social media', 'teens' ], $summary['keywords'] );
		$this->assertSame( "The user asked about TikTok.\n\nKeywords: TikTok, social media, teens", Summarizer::format( $summary ) );

		$many = Summarizer::parse( "Text.\nKeywords: " . implode( ', ', array_map( static fn( $i ) => 'term ' . str_repeat( 'x', $i ), range( 1, 60 ) ) ) );

		$this->assertCount( Summarizer::MAX_KEYWORDS, $many['keywords'] );
		$this->assertWPError( Summarizer::parse( str_repeat( 'word ', Summarizer::MAX_WORDS + 1 ) ) );
		$this->assertWPError( Summarizer::parse( 'Keywords: only' ) );
		$this->assertSame( 'ep_chat_summary_has_figure', Summarizer::parse( 'About 37% use it.' )->get_error_code() );
	}

	/**
	 * Answering a question with summaries on writes the summary at shutdown, from the previous
	 * summary and the latest exchange with its figures masked.
	 *
	 * @group chat
	 */
	public function test_summary_after_an_answer() {
		$this->set_feature( [ 'summary_enabled' => '1' ] );

		$calls    = $this->reply_with( [ self::REPLY, "Updated record about TikTok and Instagram.\n\nKeywords: TikTok, Instagram" ] );
		$first    = $this->exchange( 'What share of U.S. adults use TikTok?', 'About 37%[[1]] of U.S. adults use TikTok.' );
		$pipeline = new Job_Processor();

		$pipeline->persist(
			$first['ep_chat_id'],
			$this->storage->accept_question( 'And Instagram?', $first['ep_chat_id'] )['question_id'],
			[
				'content' => 'Half of adults, 50%[[1]], use Instagram.',
				'status'  => Storage::STATUS_DONE,
			],
			'sync',
			'sync'
		);

		// Nothing runs before shutdown.
		$this->assertCount( 0, $calls );

		( new Summarizer() )->run_pending();

		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( '<answer>', $calls[0]['user'] );
		$this->assertStringContainsString( '[figure]', $calls[0]['user'] );
		$this->assertStringNotContainsString( '50%', $calls[0]['user'] );
		$this->assertStringNotContainsString( '[[1]]', $calls[0]['user'] );
		$this->assertSame( self::REPLY, get_post( $first['ep_chat_id'] )->post_content );

		// The next summary starts from this one.
		$question = $this->storage->accept_question( 'And teens?', $first['ep_chat_id'] )['question_id'];
		( new Job_Processor() )->persist(
			$first['ep_chat_id'],
			$question,
			[
				'content' => 'Teens use it a lot.',
				'status'  => Storage::STATUS_DONE,
			],
			'sync',
			'sync'
		);
		( new Summarizer() )->run_pending();

		$this->assertStringContainsString( 'teens ages 13 to 17', $calls[1]['user'] );
		$this->assertStringStartsWith( 'Updated record', get_post( $first['ep_chat_id'] )->post_content );
	}

	/**
	 * No summary when summaries are off (feature or bot), or after an error.
	 *
	 * @group chat
	 */
	public function test_no_summary_when_off_or_error() {
		$calls = $this->reply_with( [ self::REPLY, self::REPLY, self::REPLY ] );

		$off = $this->exchange( 'Q?', 'A.' );
		do_action( 'ep_chat_question_answered', $off['ep_chat_id'], $off['question_id'], $off['answer_id'], Storage::STATUS_DONE );

		$this->set_feature( [ 'summary_enabled' => '1' ] );

		$bot_off = $this->bots->create( 'Off', Bots::MODE_SYNC, [ 'summary_enabled' => '0' ] );
		$by_bot  = $this->exchange( 'Q?', 'A.', $bot_off );
		do_action( 'ep_chat_question_answered', $by_bot['ep_chat_id'], $by_bot['question_id'], $by_bot['answer_id'], Storage::STATUS_DONE );

		$error = $this->exchange( 'Q?', 'Failed.', 0, Storage::STATUS_ERROR );
		do_action( 'ep_chat_question_answered', $error['ep_chat_id'], $error['question_id'], $error['answer_id'], Storage::STATUS_ERROR );

		( new Summarizer() )->run_pending();

		$this->assertCount( 0, $calls );

		// A declined question is summarized.
		$declined = $this->exchange( 'Q?', 'The sources do not cover this.', 0, Storage::STATUS_REFUSED );
		do_action( 'ep_chat_question_answered', $declined['ep_chat_id'], $declined['question_id'], $declined['answer_id'], Storage::STATUS_REFUSED );
		( new Summarizer() )->run_pending();

		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( '<answer declined="true">', $calls[0]['user'] );
	}

	/**
	 * A reply with a figure is retried once; a failure keeps the previous summary.
	 *
	 * @group chat
	 */
	public function test_retry_and_failure_keep_the_previous_summary() {
		$exchange = $this->exchange( 'Q?', 'A.' );
		$failures = [];

		add_action(
			'ep_chat_summary_failed',
			static function ( $error ) use ( &$failures ) {
				$failures[] = $error->get_error_code();
			}
		);

		$this->reply_with( [ 'About 37% use it.', self::REPLY, 'About 37% use it.', 'Still 37%.', new \WP_Error( 'down', 'Down.' ) ] );

		$summarizer = new Summarizer();

		$this->assertIsArray( $summarizer->summarize( $exchange['ep_chat_id'], $exchange['question_id'], $exchange['answer_id'] ) );
		$this->assertSame( self::REPLY, get_post( $exchange['ep_chat_id'] )->post_content );

		$this->assertWPError( $summarizer->summarize( $exchange['ep_chat_id'], $exchange['question_id'], $exchange['answer_id'] ) );
		$this->assertWPError( $summarizer->summarize( $exchange['ep_chat_id'], $exchange['question_id'], $exchange['answer_id'] ) );
		$this->assertSame( self::REPLY, get_post( $exchange['ep_chat_id'] )->post_content );
		$this->assertSame( [ 'ep_chat_summary_has_figure', 'down' ], $failures );
	}

	/**
	 * The History search matches the summary.
	 *
	 * @group chat
	 */
	public function test_history_search_matches_the_summary() {
		$exchange = $this->exchange( 'What share of U.S. adults use TikTok?', 'A.' );
		$other    = $this->exchange( 'Something else?', 'B.' );

		$this->reply_with( [ self::REPLY ] );
		( new Summarizer() )->summarize( $exchange['ep_chat_id'], $exchange['question_id'], $exchange['answer_id'] );

		set_current_screen( 'ai-chat_page_' . History::PAGE_SLUG );

		$_REQUEST = [ 's' => 'social media' ];
		$table    = new History_List_Table();
		$table->prepare_items();

		$this->assertSame( [ $exchange['ep_chat_id'] ], array_map( 'intval', wp_list_pluck( $table->items, 'ID' ) ) );
		$this->assertNotContains( $other['ep_chat_id'], History_List_Table::search_conversation_ids( 'social media', 0 ) );
	}
}
