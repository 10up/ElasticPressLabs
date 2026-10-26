<?php
/**
 * Test Chat answering settings: feature defaults, bot overrides, and models
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\FeatureRequirementsStatus;
use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Client;
use ElasticPressLabs\Feature\Chat\Gates\Claim_Verifier_Gate;
use ElasticPressLabs\Feature\Chat\Jobs\Admission;
use ElasticPressLabs\Feature\Chat\Models;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Reranker;
use ElasticPressLabs\Feature\Chat\Retriever;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Storage;

require_once __DIR__ . '/Fixtures/FixtureAiProvider.php';

/**
 * Chat settings test class
 */
class TestChatSettings extends BaseTestCase {
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

		Models::reset();
		FixtureAiProvider::$reply   = '';
		FixtureAiProvider::$replies = [];

		$this->bots = new Bots();
		$this->bots->register_post_type();
		( new Storage() )->register_post_type();

		wp_set_current_user( $this->ep_factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Reset the model list.
	 */
	public function tear_down() {
		Models::reset();

		parent::tear_down();
	}

	/**
	 * Set Chat feature settings.
	 *
	 * @param array $values Settings.
	 */
	protected function set_feature( array $values ) {
		$settings         = Utils\get_option( 'ep_feature_settings', [] );
		$settings['chat'] = array_merge( is_array( $settings['chat'] ?? null ) ? $settings['chat'] : [], $values );

		Utils\update_option( 'ep_feature_settings', $settings );
	}

	/**
	 * Offer the fixture provider's models (and optionally a failed provider).
	 *
	 * @param array $errors Provider ID => message.
	 */
	protected function use_fixture_models( array $errors = [] ) {
		FixtureAiProvider::register();

		add_filter(
			'ep_chat_models',
			static function ( $models_list ) use ( $errors ) {
				$models_list['errors'] = array_merge( $models_list['errors'], $errors );

				return $models_list;
			}
		);
	}

	/**
	 * A bot with overrides.
	 *
	 * @param array $settings Overrides.
	 * @return array
	 */
	protected function bot( array $settings = [] ): array {
		return $this->bots->get( $this->bots->create( 'Bot', Bots::MODE_SYNC, $settings ) );
	}

	/**
	 * Call a protected method.
	 *
	 * @param object $instance Object.
	 * @param string $method   Method.
	 * @param array  $args     Args.
	 * @return mixed
	 */
	protected function call( $instance, string $method, array $args = [] ) {
		$reflection = new \ReflectionMethod( $instance, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $instance, $args );
	}

	/**
	 * A bot value wins over the feature value, which wins over the code default; an empty bot
	 * value inherits.
	 *
	 * @group chat
	 */
	public function test_bot_then_feature_then_default() {
		$this->assertSame( 10, Settings::get( 'retrieve_count' ) );
		$this->assertSame( 4, Settings::get( 'history_messages' ) );
		$this->assertNull( Settings::get( 'rerank_floor' ) );

		$this->set_feature(
			[
				'retrieve_count'   => '20',
				'history_messages' => '6',
				'rerank_floor'     => '0.3',
			]
		);

		$inherits = $this->bot();
		$own      = $this->bot(
			[
				'retrieve_count'   => 5,
				'history_messages' => '0',
				'rerank_floor'     => '0.5',
			]
		);

		$this->assertNull( $inherits['retrieve_count'] );
		$this->assertSame( 20, Settings::get( 'retrieve_count', $inherits ) );
		$this->assertSame( 6, Settings::get( 'history_messages', $inherits ) );
		$this->assertSame( 0.3, Settings::get( 'rerank_floor', $inherits ) );
		$this->assertSame( 5, Settings::get( 'retrieve_count', $own ) );
		$this->assertSame( 0, Settings::get( 'history_messages', $own ) );
		$this->assertSame( 0.5, Settings::get( 'rerank_floor', $own ) );

		// Feature-only settings ignore the bot.
		$this->assertSame( 3, Settings::get( 'passage_chunks', [ 'passage_chunks' => 9 ] ) );
	}

	/**
	 * Clearing a bot value makes it inherit again; a bad value is refused and nothing is saved.
	 *
	 * @group chat
	 */
	public function test_bot_overrides_save_clear_and_refuse() {
		$bot_id = $this->bots->create( 'Bot', Bots::MODE_SYNC, [ 'retrieve_count' => 7 ] );

		$this->assertSame( 7, $this->bots->get( $bot_id )['retrieve_count'] );

		$this->bots->update( $bot_id, 'Bot', Bots::MODE_SYNC, [ 'retrieve_count' => '' ] );
		$this->assertNull( $this->bots->get( $bot_id )['retrieve_count'] );
		$this->assertSame( '', get_post_meta( $bot_id, Bots::META_SETTING_PREFIX . 'retrieve_count', true ) );

		$error = $this->bots->update( $bot_id, 'Renamed', Bots::MODE_SYNC, [ 'retrieve_count' => 51 ] );

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_setting_out_of_range', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( 'Bot', $this->bots->get( $bot_id )['name'] );

		$this->assertWPError( $this->bots->create( 'Bad', Bots::MODE_SYNC, [ 'rerank_floor' => '1.5' ] ) );
	}

	/**
	 * Each setting's range; empty is "not set"; decimals are refused for whole numbers.
	 *
	 * @group chat
	 */
	public function test_ranges() {
		$ranges = [
			'retrieve_count'      => [ 1, 50 ],
			'history_messages'    => [ 0, 20 ],
			'rerank_floor'        => [ 0, 1 ],
			'passage_chunks'      => [ 1, 10 ],
			'passage_word_budget' => [ 500, 20000 ],
			'max_in_flight'       => [ 1, 10 ],
			'max_per_hour'        => [ 0, 1000 ],
			'retry_after'         => [ 1, 300 ],
		];

		foreach ( $ranges as $key => $range ) {
			$this->assertEquals( $range[0], Settings::sanitize( $key, (string) $range[0] ), $key );
			$this->assertEquals( $range[1], Settings::sanitize( $key, $range[1] ), $key );
			$this->assertWPError( Settings::sanitize( $key, $range[0] - 1 ), $key );
			$this->assertWPError( Settings::sanitize( $key, $range[1] + 1 ), $key );
			$this->assertNull( Settings::sanitize( $key, '' ), $key );
			$this->assertWPError( Settings::sanitize( $key, 'many' ), $key );
		}

		$this->assertWPError( Settings::sanitize( 'retrieve_count', '2.5' ) );
		$this->assertSame( 0.25, Settings::sanitize( 'rerank_floor', '0.25' ) );
	}

	/**
	 * Saving the feature refuses out-of-range values and keeps empty ones empty.
	 *
	 * @group chat
	 */
	public function test_feature_save_refuses_out_of_range() {
		$feature = Features::factory()->get_registered_feature( 'chat' );

		$this->assertWPError( $feature->sanitize_settings_callback( [ 'retrieve_count' => '0' ] ) );
		$this->assertWPError( $feature->sanitize_settings_callback( [ 'answer_model' => 'nowhere::model' ] ) );
		$this->assertSame(
			[
				'retrieve_count' => '12',
				'rerank_floor'   => '',
				'rerank_api_url' => 'http://example.test',
			],
			$feature->sanitize_settings_callback(
				[
					'retrieve_count' => ' 12 ',
					'rerank_floor'   => '',
					'rerank_api_url' => 'http://example.test',
				]
			)
		);
	}

	/**
	 * The feature card has four boxes (only the models open, the re-ranker's state on its
	 * header); every setting has one short line of help, details behind "More"; the model fields
	 * are searchable when ElasticPress supports it, else a plain select; declined questions is a
	 * radio with one line per choice.
	 *
	 * @group chat
	 */
	public function test_feature_card_boxes_and_help() {
		$this->use_fixture_models();

		$feature = Features::factory()->get_registered_feature( 'chat' );
		$feature->set_i18n_strings();
		$feature->reset_settings_schema();

		$by_key = [];

		foreach ( $feature->get_settings_schema() as $field ) {
			$by_key[ $field['key'] ] = $field;
		}

		$groups = $feature->get_field_group_map();

		$this->assertSame( [ 'chat_models', 'chat_rerank', 'chat_defaults', 'chat_advanced' ], array_keys( $groups ) );
		$this->assertSame( [ true, false, false, false ], array_values( wp_list_pluck( $groups, 'initialOpen' ) ) );
		$this->assertSame( 'Not set up', $groups['chat_rerank']['badge'] );

		$expected_groups = [
			'answer_model'        => 'chat_models',
			'intent_model'        => 'chat_models',
			'query_model'         => 'chat_models',
			'summary_model'       => 'chat_models',
			'verifier_model'      => 'chat_models',
			'rerank_api_url'      => 'chat_rerank',
			'rerank_api_key'      => 'chat_rerank',
			'rerank_model'        => 'chat_rerank',
			'rerank_floor'        => 'chat_rerank',
			'answer_instructions' => 'chat_defaults',
			'retrieve_count'      => 'chat_defaults',
			'history_messages'    => 'chat_defaults',
			'decline_display'     => 'chat_defaults',
			'intent_enabled'      => 'chat_defaults',
			'date_range_enabled'  => 'chat_defaults',
			'clarify_enabled'     => 'chat_defaults',
			'summary_enabled'     => 'chat_defaults',
			'search_algorithm'    => 'chat_defaults',
			'search_fallback'     => 'chat_defaults',
			'passage_chunks'      => 'chat_advanced',
			'passage_word_budget' => 'chat_advanced',
			'max_in_flight'       => 'chat_advanced',
			'max_per_hour'        => 'chat_advanced',
			'retry_after'         => 'chat_advanced',
		];

		foreach ( $expected_groups as $key => $group ) {
			$this->assertSame( $group, $by_key[ $key ]['field_group_slug'], $key );
		}

		foreach ( array_keys( Settings::FIELDS ) as $key ) {
			$this->assertArrayHasKey( $key, $by_key );
			$this->assertNotEmpty( $by_key[ $key ]['help'], $key );

			// One short line before "More".
			$short = explode( '<details', $by_key[ $key ]['help'] )[0];
			$this->assertLessThanOrEqual( 16, str_word_count( wp_strip_all_tags( $short ) ), $key );
		}

		$this->assertStringContainsString( '<details class="ep-chat-help-more"><summary>More</summary>', $by_key['intent_enabled']['help'] );
		$this->assertSame( 'number', $by_key['retrieve_count']['type'] );
		$this->assertSame( 'radio', $by_key['decline_display']['type'] );
		$this->assertSame( [ 'show', 'hide', 'uniform' ], wp_list_pluck( $by_key['decline_display']['options'], 'value' ) );
		$this->assertStringContainsString( 'ep-chat-choice-description', $by_key['decline_display']['options'][0]['label'] );

		// Field groups render in order: each box's fields are next to each other.
		$seen = [];

		foreach ( $feature->get_settings_schema() as $field ) {
			$group = $field['field_group_slug'] ?? '';

			if ( '' !== $group && end( $seen ) !== $group ) {
				$this->assertNotContains( $group, $seen, "{$group} is split" );
				$seen[] = $group;
			}
		}

		$this->assertSame( array_keys( $groups ), $seen );

		$this->set_feature(
			[
				'rerank_api_url' => 'https://rerank.test/v1/rerank',
				'rerank_model'   => 'rerank-model',
			]
		);

		$this->assertSame( 'Set up', $feature->get_field_group_map()['chat_rerank']['badge'] );

		$this->assertSame( 'combobox', $by_key['answer_model']['type'] );
		$this->assertContains(
			[
				'value' => 'fixture-ai::fixture-large',
				'label' => 'Fixture AI – fixture-large',
			],
			$by_key['answer_model']['options']
		);
		$this->assertSame( '', $by_key['answer_model']['options'][0]['value'] );
	}

	/**
	 * The model list: configured providers' text models, kept in a transient; a model of a
	 * provider whose list failed can still be saved, an unknown one cannot.
	 *
	 * @group chat
	 */
	public function test_model_list_and_validation() {
		$this->use_fixture_models( [ 'down-ai' => 'timeout' ] );

		$values = wp_list_pluck( Models::get_list()['models'], 'value' );

		$this->assertContains( 'fixture-ai::fixture-small', $values );
		$this->assertIsArray( get_transient( Models::TRANSIENT ) );
		$this->assertTrue( Models::is_valid( 'fixture-ai::fixture-small' ) );
		$this->assertTrue( Models::is_valid( 'down-ai::anything' ) );
		$this->assertFalse( Models::is_valid( 'fixture-ai::missing' ) );
		$this->assertFalse( Models::is_valid( 'no-separator' ) );
		$this->assertSame( [ 'openai-compatible-servers', 'amazon-nova-pro-v1' ], Models::parse( 'openai-compatible-servers::amazon-nova-pro-v1' ) );

		Models::reset();
		$this->assertFalse( get_transient( Models::TRANSIENT ) );
	}

	/**
	 * The answer model: bot, else feature, else `ep_chat_default_model`; the verifier, summary,
	 * and question models fall back to the answer model.
	 *
	 * @group chat
	 */
	public function test_model_fallbacks() {
		$this->assertSame( '', Settings::get( 'answer_model' ) );
		$this->assertSame( '', Settings::get( 'verifier_model' ) );

		add_filter(
			'ep_chat_default_model',
			static function () {
				return 'fixture-ai::fixture-small';
			}
		);

		$this->assertSame( 'fixture-ai::fixture-small', Settings::get( 'answer_model' ) );
		$this->assertSame( 'fixture-ai::fixture-small', Settings::get( 'verifier_model' ) );

		$this->set_feature( [ 'answer_model' => 'fixture-ai::fixture-large' ] );

		$this->assertSame( 'fixture-ai::fixture-large', Settings::get( 'answer_model' ) );
		$this->assertSame( 'fixture-ai::fixture-small', Settings::get( 'verifier_model', [ 'verifier_model' => 'fixture-ai::fixture-small' ] ) );
		$this->assertSame( 'fixture-ai::fixture-large', Settings::get( 'verifier_model', [ 'verifier_model' => null ] ) );
		$this->assertSame( 'fixture-ai::fixture-large', Settings::get( 'summary_model' ) );
		$this->assertSame( 'fixture-ai::fixture-large', Settings::get( 'intent_model' ) );
		$this->assertSame( 'fixture-ai::fixture-small', Settings::get( 'intent_model', [ 'answer_model' => 'fixture-ai::fixture-small' ] ), 'An empty question model is the bot\'s answer model.' );

		$this->set_feature(
			[
				'answer_model' => 'fixture-ai::fixture-large',
				'intent_model' => 'fixture-ai::fixture-small',
			]
		);

		$this->assertSame( 'fixture-ai::fixture-small', Settings::get( 'intent_model' ) );
	}

	/**
	 * The answer is written by the chosen model, and the client says which one; a model that is
	 * no longer offered falls back to the AI client's default.
	 *
	 * @group chat
	 */
	public function test_answer_uses_the_chosen_model() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'The WordPress AI client is not available.' );
		}

		$this->use_fixture_models();

		$client = new Client();
		$reply  = $client->generate_text( 'Hi?', [], [], [ 'answer_model' => 'fixture-ai::fixture-large' ] );

		$this->assertSame( 'Reply from fixture-large', $reply );
		$this->assertSame( 'fixture-ai::fixture-large', $client->get_model_used() );

		$fallback = new Client();
		$fallback->generate_text( 'Hi?', [], [], [ 'answer_model' => 'fixture-ai::gone' ] );

		$this->assertNotSame( '', $fallback->get_model_used() );
		$this->assertNotSame( 'fixture-ai::gone', $fallback->get_model_used() );
	}

	/**
	 * The claim verifier uses its model and records it with the answer.
	 *
	 * @group chat
	 */
	public function test_verifier_uses_and_records_its_model() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'The WordPress AI client is not available.' );
		}

		$this->use_fixture_models();
		FixtureAiProvider::$reply = '{"supported": true}';

		$result = ( new Claim_Verifier_Gate() )->run(
			[
				'question' => 'Where is the U.S. viewed most favorably?',
				'content'  => 'Poland gives the U.S. its highest rating, at 93%.',
				'passages' => [
					[
						'id'      => 1,
						'title'   => 'Views of the U.S.',
						'content' => 'In Poland, 93% view the U.S. favorably, its highest rating.',
					],
				],
				'bot'      => [
					'answer_model'   => 'fixture-ai::fixture-large',
					'verifier_model' => 'fixture-ai::fixture-small',
				],
			]
		);

		$this->assertTrue( $result->is_modify() );
		$this->assertSame( [ 'verifier_model' => 'fixture-ai::fixture-small' ], $result->get_context() );
	}

	/**
	 * Retrieval, history, passages, re-rank floor, and limits read the settings, and their
	 * filters still have the last word.
	 *
	 * @group chat
	 */
	public function test_pipeline_reads_the_settings() {
		$this->set_feature(
			[
				'retrieve_count'      => '15',
				'history_messages'    => '2',
				'rerank_floor'        => '0.4',
				'passage_chunks'      => '5',
				'passage_word_budget' => '800',
				'max_in_flight'       => '3',
				'retry_after'         => '30',
			]
		);

		$bot = $this->bot( [ 'retrieve_count' => 8 ] );

		$this->assertSame( 8, ( new Retriever() )->get_query_args( 'Hi?', $bot )['posts_per_page'] );
		$this->assertSame( 15, ( new Retriever() )->get_query_args( 'Hi?' )['posts_per_page'] );
		$this->assertSame( 0.4, $this->call( new Reranker(), 'get_floor', [ $bot ] ) );
		$this->assertSame( 800, $this->call( new Pipeline(), 'get_word_budget' ) );
		$this->assertSame( 3, ( new Admission() )->get_max_in_flight( 1 ) );
		$this->assertSame( 30, ( new Admission() )->get_retry_after( 1 ) );

		$seen = [];

		add_filter(
			'ep_chat_history_messages',
			static function ( $count, $ep_chat_id, $question_id, $filtered_bot ) use ( &$seen ) {
				$seen = [ $count, $filtered_bot['id'] ?? 0 ];

				return $count;
			},
			10,
			4
		);

		( new Storage() )->get_history( 0, 0, $bot );
		$this->assertSame( [ 2, $bot['id'] ], $seen );

		add_filter(
			'ep_chat_retrieve_query_args',
			static function ( $args ) {
				$args['posts_per_page'] = 42;

				return $args;
			}
		);

		$this->assertSame( 42, ( new Retriever() )->get_query_args( 'Hi?', $bot )['posts_per_page'] );
	}

	/**
	 * REST saves bot overrides and refuses bad ones with 400.
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_rest_bot_overrides() {
		foreach ( Features::factory()->registered_features as $feature ) {
			if ( method_exists( $feature, 'set_i18n_strings' ) ) {
				$feature->set_i18n_strings();
			}
		}

		add_action( 'rest_api_init', [ new \ElasticPressLabs\REST\ChatBots( $this->bots ), 'register_routes' ] );

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$bot_id  = $this->bots->create( 'Bot', Bots::MODE_SYNC );
		$request = new \WP_REST_Request( 'PUT', '/elasticpress-labs/v1/chat-bots/' . $bot_id );
		$request->set_param( 'name', 'Bot' );
		$request->set_param( 'history_messages', '9' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 9, $response->get_data()['history_messages'] );

		$request->set_param( 'history_messages', '21' );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
		$this->assertSame( 9, $this->bots->get( $bot_id )['history_messages'] );
	}

	/**
	 * The stored payload says which models answered and checked, also when the checked answer
	 * is declined.
	 *
	 * @group chat
	 */
	public function test_payload_records_models() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'The WordPress AI client is not available.' );
		}

		$this->use_fixture_models();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Views of the U.S.',
				'post_excerpt' => 'In Poland, 93% view the U.S. favorably, its highest rating.',
				'post_status'  => 'publish',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);

		$bot = $this->bot(
			[
				'answer_model'   => 'fixture-ai::fixture-large',
				'verifier_model' => 'fixture-ai::fixture-small',
			]
		);

		FixtureAiProvider::$replies = [
			'fixture-large' => 'Poland gives the U.S. its highest rating, at 93%.',
			'fixture-small' => '{"supported": true}',
		];

		$done = ( new Pipeline() )->run( 'Where is the U.S. viewed most favorably?', [], $bot );

		$this->assertSame( 'done', $done['status'] );
		$this->assertSame(
			[
				'answer'   => 'fixture-ai::fixture-large',
				'verifier' => 'fixture-ai::fixture-small',
				'query'    => '',
			],
			$done['meta']['models']
		);

		FixtureAiProvider::$replies['fixture-small'] = '{"supported": false}';

		$declined = ( new Pipeline() )->run( 'Where is the U.S. viewed most favorably?', [], $bot );

		$this->assertSame( 'refused', $declined['status'] );
		$this->assertSame( 'comparison_not_in_sources', $declined['refusal_reason'] );
		$this->assertSame(
			[
				'answer'   => 'fixture-ai::fixture-large',
				'verifier' => 'fixture-ai::fixture-small',
				'query'    => '',
			],
			$declined['meta']['models']
		);

		// A pipeline that is reused does not carry the models over to a question declined
		// before any answer is written.
		$pipeline = new Pipeline();
		$pipeline->run( 'Where is the U.S. viewed most favorably?', [], $bot );

		add_filter( 'ep_chat_retrieve', '__return_empty_array', 20 );

		$empty = $pipeline->run( 'Where is the U.S. viewed most favorably?', [], $bot );

		$this->assertSame( 'refused', $empty['status'] );
		$this->assertArrayNotHasKey( 'models', $empty['meta'] );
	}

	/**
	 * Duplicating a bot copies every setting, including gates and overrides, and places the
	 * copy last; REST needs the bots capability.
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_duplicate_bot() {
		$first  = $this->bots->create( 'First', Bots::MODE_SYNC );
		$bot_id = $this->bots->create(
			'Researcher',
			Bots::MODE_WP_ASYNC,
			[
				'rerank_enabled'   => true,
				'rerank_floor'     => '0.4',
				'optional_gates'   => [ 'injection_defense' ],
				'retrieve_count'   => 7,
				'history_messages' => 0,
			]
		);

		$copy_id = $this->bots->duplicate( $bot_id );
		$bot     = $this->bots->get( $bot_id );
		$copy    = $this->bots->get( $copy_id );

		$this->assertSame( 'Researcher (copy)', $copy['name'] );

		foreach ( [ 'execution_mode', 'rerank_enabled', 'rerank_floor', 'optional_gates', 'retrieve_count', 'history_messages', 'answer_model' ] as $key ) {
			$this->assertSame( $bot[ $key ], $copy[ $key ], $key );
		}

		$this->assertSame( [ $first, $bot_id, $copy_id ], wp_list_pluck( $this->bots->get_all(), 'id' ) );
		$this->assertWPError( $this->bots->duplicate( $first + 999 ) );

		foreach ( Features::factory()->registered_features as $feature ) {
			if ( method_exists( $feature, 'set_i18n_strings' ) ) {
				$feature->set_i18n_strings();
			}
		}

		add_action( 'rest_api_init', [ new \ElasticPressLabs\REST\ChatBots( $this->bots ), 'register_routes' ] );

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$response = rest_do_request( new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat-bots/' . $bot_id . '/duplicate' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Researcher (copy)', $response->get_data()['name'] );

		wp_set_current_user( $this->ep_factory->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat-bots/' . $bot_id . '/duplicate' ) )->get_status() );
	}

	/**
	 * Before ElasticPress 5.4.0, Chat is unavailable: it says so, comes back once ElasticPress is
	 * updated, and has no settings fields the screen cannot render.
	 *
	 * @group chat
	 */
	public function test_chat_needs_elasticpress_5_4() {
		add_filter( 'ep_chat_ai_available', '__return_true' );

		$old = new class() extends \ElasticPressLabs\Feature\Chat\Chat {
			/**
			 * As ElasticPress before 5.4.0.
			 *
			 * @return bool
			 */
			public static function is_elasticpress_supported(): bool {
				return false;
			}
		};

		$status = $old->requirements_status();

		// Not force disabled: ElasticPress would not turn Chat back on after an update.
		$this->assertSame( FeatureRequirementsStatus::TEMPORARILY_DISABLED, $status->code );
		$this->assertStringContainsString( 'ElasticPress 5.4.0', $status->message );
		$this->assertSame( [ 'active' ], wp_list_pluck( $old->get_settings_schema(), 'key' ) );

		$current = new \ElasticPressLabs\Feature\Chat\Chat();

		$this->assertTrue( \ElasticPressLabs\Feature\Chat\Chat::is_elasticpress_supported() );
		$this->assertStringNotContainsString( 'ElasticPress 5.4.0', (string) $current->requirements_status()->message );
		$this->assertNotEmpty( $current->get_settings_schema() );
	}
}
