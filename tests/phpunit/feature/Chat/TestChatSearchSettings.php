<?php
/**
 * Test the Chat search method settings
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPress\SearchAlgorithms;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Admin;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Settings;

/**
 * Search method, fallback search, and query model settings.
 */
class TestChatSearchSettings extends BaseTestCase {
	/**
	 * Slug of the stand-in AI search method.
	 *
	 * @var string
	 */
	const AI_METHOD = 'fixture_ai';


	/**
	 * Bots.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Setup each test: a stand-in AI search method registered.
	 */
	public function set_up() {
		parent::set_up();

		$this->bots = new Bots();
		$this->bots->register_post_type();

		$this->register_ai_method();

		wp_set_current_user( $this->ep_factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		SearchAlgorithms::factory()->unregister( self::AI_METHOD );
		SearchAlgorithms::factory()->unregister( 'hybrid_knn' );

		parent::tear_down();
	}

	/**
	 * Register a stand-in AI search method: hidden from the site search, and calls a model.
	 *
	 * @return void
	 */
	protected function register_ai_method() {
		$ai_method = new class() extends \ElasticPress\SearchAlgorithm {
			/**
			 * Not listed on the Search Algorithm screen.
			 *
			 * @var bool
			 */
			public $hidden = true;

			/**
			 * Calls a model.
			 *
			 * @var bool
			 */
			public $uses_model = true;

			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function get_slug(): string {
				return TestChatSearchSettings::AI_METHOD;
			}

			/**
			 * Name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return 'Fixture AI method';
			}

			/**
			 * Description.
			 *
			 * @return string
			 */
			public function get_description(): string {
				return 'A model writes the query.';
			}

			/**
			 * Query.
			 *
			 * @param string $indexable_slug Indexable slug.
			 * @param string $search_term    Search term.
			 * @param array  $search_fields  Search fields.
			 * @param array  $query_vars     Query vars.
			 * @return array
			 */
			protected function get_raw_query( string $indexable_slug, string $search_term, array $search_fields, array $query_vars ): array {
				return [];
			}
		};

		SearchAlgorithms::factory()->register( $ai_method );
	}

	/**
	 * Register a stand-in for the hybrid algorithm (Semantic Search is off in tests).
	 *
	 * @return void
	 */
	protected function register_hybrid() {
		$hybrid = new class() extends \ElasticPress\SearchAlgorithm {
			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function get_slug(): string {
				return 'hybrid_knn';
			}

			/**
			 * Name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return 'Hybrid (kNN + Regular ES)';
			}

			/**
			 * Description.
			 *
			 * @return string
			 */
			public function get_description(): string {
				return 'Search using a mix of Elasticsearch kNN and a regular query.';
			}

			/**
			 * Query.
			 *
			 * @param string $indexable_slug Indexable slug.
			 * @param string $search_term    Search term.
			 * @param array  $search_fields  Search fields.
			 * @param array  $query_vars     Query vars.
			 * @return array
			 */
			protected function get_raw_query( string $indexable_slug, string $search_term, array $search_fields, array $query_vars ): array {
				return [];
			}
		};

		SearchAlgorithms::factory()->register( $hybrid );
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
	 * The search method lists the site search, then every registered algorithm (the hidden AI
	 * query too). The fallback adds None and leaves out algorithms that call a model.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_choices() {
		$methods   = Settings::get_choices( 'search_algorithm' );
		$fallbacks = Settings::get_choices( 'search_fallback' );

		$this->assertSame( 'site', $methods[0] );
		$this->assertContains( '4.0', $methods );
		$this->assertContains( self::AI_METHOD, $methods );
		$this->assertNotContains( 'none', $methods );

		$this->assertSame( [ 'auto', 'site', 'none' ], array_slice( $fallbacks, 0, 3 ) );
		$this->assertContains( '4.0', $fallbacks );
		$this->assertNotContains( self::AI_METHOD, $fallbacks );

		$labels = Settings::get_choice_labels( 'search_algorithm' );

		$this->assertSame( 'Same as site search', $labels['site'] );
		$this->assertSame( 'Fixture AI method', $labels[ self::AI_METHOD ] );
		$this->assertNotEmpty( Settings::get_choice_descriptions( 'search_fallback' )['none'] );
	}

	/**
	 * Saved values must be registered algorithms; a value whose algorithm is gone is ignored, so
	 * the next level applies.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_values_must_be_registered() {
		$this->assertSame( self::AI_METHOD, Settings::sanitize( 'search_algorithm', self::AI_METHOD ) );
		$this->assertWPError( Settings::sanitize( 'search_algorithm', 'unknown' ) );
		$this->assertWPError( Settings::sanitize( 'search_fallback', self::AI_METHOD ) );
		$this->assertSame( 'none', Settings::sanitize( 'search_fallback', 'none' ) );

		$bot = $this->bots->get( $this->bots->create( 'Bot', Bots::MODE_SYNC, [ 'search_algorithm' => self::AI_METHOD ] ) );

		$this->assertSame( self::AI_METHOD, Settings::get( 'search_algorithm', $bot ) );

		SearchAlgorithms::factory()->unregister( self::AI_METHOD );

		$this->assertSame( 'site', Settings::get( 'search_algorithm', $bot ) );
	}

	/**
	 * The search method defaults to the site search; the fallback to Automatic: the hybrid
	 * search where it is registered, else the site search.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_defaults() {
		$this->assertSame( 'site', Settings::get( 'search_algorithm' ) );
		$this->assertSame( 'auto', Settings::get( 'search_fallback' ) );
		$this->assertSame( 'site', Settings::resolve_fallback( 'auto' ) );
		$this->assertSame( 'none', Settings::resolve_fallback( 'none' ) );
		$this->assertSame( '4.0', Settings::resolve_fallback( '4.0' ) );

		$this->register_hybrid();

		$this->assertSame( 'hybrid_knn', Settings::resolve_fallback( 'auto' ) );
	}

	/**
	 * A bot overrides the feature, which overrides the default; the query model falls back to
	 * the question model.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_bot_then_feature() {
		$this->set_feature(
			[
				'search_algorithm' => self::AI_METHOD,
				'search_fallback'  => 'none',
				'intent_model'     => 'fixture-ai::fixture-small',
			]
		);

		$bot = $this->bots->get( $this->bots->create( 'Bot', Bots::MODE_SYNC, [ 'search_fallback' => '4.0' ] ) );

		$this->assertSame( self::AI_METHOD, Settings::get( 'search_algorithm', $bot ) );
		$this->assertSame( '4.0', Settings::get( 'search_fallback', $bot ) );
		$this->assertSame( 'none', Settings::get( 'search_fallback' ) );
		$this->assertSame( 'fixture-ai::fixture-small', Settings::get( 'query_model', $bot ) );
	}

	/**
	 * On the feature screen the search method and fallback are radios in the chat bot defaults;
	 * the fallback and the query model are always shown, their help says they are for the AI
	 * search methods.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_feature_screen_fields() {
		$feature = Features::factory()->get_registered_feature( 'chat' );
		$feature->reset_settings_schema();

		$by_key = [];

		foreach ( $feature->get_settings_schema() as $field ) {
			$by_key[ $field['key'] ] = $field;
		}

		$this->assertSame( 'radio', $by_key['search_algorithm']['type'] );
		$this->assertSame( 'site', $by_key['search_algorithm']['default'] );
		$this->assertContains( self::AI_METHOD, wp_list_pluck( $by_key['search_algorithm']['options'], 'value' ) );
		// ElasticPress before 5.4.0 reads a condition as one exact value: the fields are always shown.
		if ( version_compare( EP_VERSION, '5.4.0', '<' ) ) {
			foreach ( [ 'search_algorithm', 'search_fallback', 'query_model' ] as $key ) {
				$this->assertArrayNotHasKey( 'requires_fields', $by_key[ $key ], $key );
			}
		}

		$this->assertStringContainsString( 'AI search methods', $by_key['query_model']['help'] );
		$this->assertStringContainsString( 'ep-chat-choice-description', $by_key['search_fallback']['options'][1]['label'] );
	}

	/**
	 * The bot screen gets each setting's inherited value (to show the fallback and the query
	 * model only for the AI query) and says what an empty query model uses.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_bot_screen_config() {
		$method = new \ReflectionMethod( Admin::class, 'describe_answering_settings' );
		$method->setAccessible( true );

		$config = $method->invoke( new Admin() );

		$this->assertSame( 'site', $config['fields']['search_algorithm']['inherited'] );
		$this->assertSame( [ self::AI_METHOD ], $config['aiMethods'] );
		$this->assertSame( 'choice', $config['fields']['search_fallback']['type'] );
		$this->assertContains( 'none', wp_list_pluck( $config['fields']['search_fallback']['choices'], 'value' ) );
		$this->assertSame( 'same as the question model', $config['fields']['query_model']['default'] );
		$this->assertSame( 'same as the answer model', $config['fields']['verifier_model']['default'] );
	}

	/**
	 * Saving the feature with an algorithm that is gone keeps the other settings and sends that
	 * one back to its default; a bot refuses it.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_feature_save_resets_algorithms_that_are_gone() {
		$feature = Features::factory()->get_registered_feature( 'chat' );
		$saved   = $feature->sanitize_settings_callback(
			[
				'search_algorithm' => 'missing',
				'search_fallback'  => 'hybrid_knn',
				'retrieve_count'   => '12',
			]
		);

		$this->assertSame( '', $saved['search_algorithm'] );
		$this->assertSame( '', $saved['search_fallback'] );
		$this->assertSame( '12', $saved['retrieve_count'] );
		$this->assertWPError( $feature->sanitize_settings_callback( [ 'retrieve_count' => '999' ] ) );
		$this->assertWPError( Settings::sanitize( 'search_algorithm', 'missing' ) );

		// The defaults can always be saved.
		$defaults = $feature->sanitize_settings_callback(
			[
				'search_algorithm' => $feature->default_settings['search_algorithm'],
				'search_fallback'  => $feature->default_settings['search_fallback'],
			]
		);

		$this->assertSame( 'site', $defaults['search_algorithm'] );
		$this->assertSame( 'auto', $defaults['search_fallback'] );
	}

	/**
	 * Where ElasticPress reads a list of values as "any of these" (5.4.0 or later), the fallback and
	 * the query model show only for the AI search methods, and not at all when none is registered.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_fields_shown_only_for_ai_methods() {
		$schema = static function () {
			$feature = new class() extends \ElasticPressLabs\Feature\Chat\Chat {
				/**
				 * As ElasticPress 5.4.0.
				 *
				 * @return bool
				 */
				protected function supports_any_of_conditions(): bool {
					return true;
				}
			};

			$by_key = [];

			foreach ( $feature->get_settings_schema() as $field ) {
				$by_key[ $field['key'] ] = $field;
			}

			return $by_key;
		};

		$by_key = $schema();

		$this->assertArrayNotHasKey( 'requires_fields', $by_key['search_algorithm'] );

		foreach ( [ 'search_fallback', 'query_model' ] as $key ) {
			$this->assertSame( [ 'conditions' => [ 'search_algorithm' => [ self::AI_METHOD ] ] ], $by_key[ $key ]['requires_fields'], $key );
		}

		SearchAlgorithms::factory()->unregister( self::AI_METHOD );

		$this->assertSame( [ 'conditions' => [ 'search_algorithm' => [] ] ], $schema()['search_fallback']['requires_fields'] );
	}
}
