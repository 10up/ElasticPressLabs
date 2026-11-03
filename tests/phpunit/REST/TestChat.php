<?php
/**
 * Test Chat REST controller
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest\REST;

use ElasticPress\Features;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Capabilities;
use ElasticPressLabs\Feature\Chat\Storage;
use ElasticPressLabs\REST\Chat as ChatController;

/**
 * Chat REST test class
 */
class TestChat extends \ElasticPressLabsTest\BaseTestCase {
	/**
	 * Controller instance
	 *
	 * @var ChatController
	 */
	protected $controller;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->controller = new ChatController();
	}

	/**
	 * Tear down each test: drop the REST server these tests built, so later tests build their own.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Create a chat bot for Ask requests.
	 *
	 * @param string $name Bot name.
	 * @param string $mode Execution mode.
	 * @return array
	 */
	protected function create_bot( $name = 'Default Bot', $mode = Bots::MODE_SYNC ) {
		$bots = new Bots();
		$bots->register_post_type();

		$bot_id = $bots->create( $name, $mode );

		return $bots->get( $bot_id );
	}

	/**
	 * Authenticate a user who can persist Chat conversations.
	 *
	 * @return int
	 */
	protected function authenticate_chat_user() {
		$user_id = $this->ep_factory->user->create( [ 'role' => 'administrator' ] );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Utils\get_capability( 'chat' ) );
		wp_set_current_user( $user_id );

		( new Storage() )->register_post_type();

		return $user_id;
	}

	/**
	 * Set the current user to a role.
	 *
	 * @param string $role Role slug.
	 * @return int
	 */
	protected function login_as_role( string $role ): int {
		$user_id = $this->ep_factory->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Short-circuit retrieve and synthesis for persist tests.
	 *
	 * @param string $reply Generated reply.
	 * @return void
	 */
	protected function stub_pipeline( string $reply = 'Stored reply.' ) {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'ElasticPress Guide',
				'post_content' => 'ElasticPress is a WordPress search plugin.',
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
			static function () use ( $reply ) {
				return $reply;
			}
		);
	}

	/**
	 * Register the Chat REST route on rest_api_init.
	 */
	protected function register_chat_routes() {
		$chat = Features::factory()->get_registered_feature( 'chat' );

		if ( $chat && method_exists( $chat, 'set_i18n_strings' ) ) {
			$chat->set_i18n_strings();
		}

		add_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Test that guests cannot post to Chat.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_requires_capability() {
		$this->register_chat_routes();

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', 1 );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test that an empty question is rejected.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_rejects_empty_question() {
		$this->authenticate_chat_user();

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', '   ' );

		$response = $this->controller->create_reply( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'ep_chat_empty_question', $response->get_error_code() );
	}

	/**
	 * Test that a stubbed client reply is returned.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_returns_generated_text() {
		$this->authenticate_chat_user();

		$bot = $this->create_bot();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'ElasticPress Guide',
				'post_content' => 'ElasticPress is a WordPress search plugin.',
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
			static function ( $pre, $question ) {
				return 'Reply to: ' . $question;
			},
			10,
			2
		);

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$response = $this->controller->create_reply( $request );

		$this->assertSame( 'Reply to: What is ElasticPress?', $response['content'] );
		$this->assertSame( $bot, $response['bot'] );
		$this->assertCount( 1, $response['hits'] );
		$this->assertSame( $post_id, $response['hits'][0]['id'] );
		$this->assertSame( Storage::POST_TYPE, get_post_type( $response['ep_chat_id'] ) );
		$this->assertSame( 'What is ElasticPress?', get_post( $response['ep_chat_id'] )->post_title );
		$this->assertSame( Storage::STATUS_DONE, ( new Storage() )->get_status( $response['question_id'] ) );
		$this->assertSame(
			[
				// Notes and the citation are added when the answer is shown, not stored.
				'hits'         => array_map(
					static function ( $hit ) {
						unset( $hit['notes'], $hit['citation'] );

						return $hit;
					},
					$response['hits']
				),
				'provenance'   => $response['provenance'],
				// The fake reply (ep_chat_generate_text) has no model.
				'models'       => [
					'answer'   => '',
					'verifier' => '',
					'query'    => '',
				],
				// No answer instructions are set.
				'instructions' => [
					'source' => 'none',
					'hash'   => '',
				],
			],
			get_comment_meta( $response['answer_id'], Storage::META_PAYLOAD, true )
		);
	}

	/**
	 * Test that client errors become HTTP 502.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_maps_client_errors() {
		$this->authenticate_chat_user();

		$bot = $this->create_bot();

		$post_id = $this->factory()->post->create(
			[
				'post_title'  => 'ElasticPress Guide',
				'post_status' => 'publish',
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
			static function () {
				return new \WP_Error( 'ep_chat_ai_failed', 'No model' );
			}
		);

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$response = $this->controller->create_reply( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'ep_chat_ai_failed', $response->get_error_code() );
		$this->assertSame( 502, $response->get_error_data()['status'] );
		$this->assertArrayHasKey( 'ep_chat_id', $response->get_error_data() );
		$this->assertArrayHasKey( 'answer_id', $response->get_error_data() );
		$this->assertSame(
			Storage::STATUS_ERROR,
			( new Storage() )->get_status( $response->get_error_data()['question_id'] )
		);

		$loaded = ( new Storage() )->get( (int) $response->get_error_data()['ep_chat_id'] );

		$this->assertCount( 2, $loaded['turns'] );
		$this->assertSame( Storage::STATUS_ERROR, $loaded['turns'][1]['status'] );
		$this->assertSame( 'No model', $loaded['turns'][1]['content'] );
	}

	/**
	 * Test that a missing or invalid bot is rejected.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_requires_bot() {
		$this->authenticate_chat_user();

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', 0 );

		$response = $this->controller->create_reply( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'ep_chat_invalid_bot', $response->get_error_code() );
	}

	/**
	 * Test async bots enqueue and return a queued handle.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_enqueues_async_bot() {
		$this->authenticate_chat_user();

		$bot = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$response = $this->controller->create_reply( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 202, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( Storage::STATUS_QUEUED, $data['status'] );
		$this->assertSame( 'wp_async', $data['runner'] );
		$this->assertArrayHasKey( 'ep_chat_id', $data );
		$this->assertArrayHasKey( 'question_id', $data );
		$this->assertSame(
			Storage::STATUS_QUEUED,
			( new Storage() )->get_status( $data['question_id'] )
		);
		$this->assertSame(
			$bot['id'],
			(int) get_comment_meta( $data['question_id'], Storage::META_BOT_ID, true )
		);
	}

	/**
	 * A declined answer is a first-class result: HTTP 200, status refused, exactly one reason,
	 * and the retrieved passages kept for "Related research".
	 *
	 * @group chat
	 * @group rest
	 */
	public function test_declined_answer_is_http_200_with_one_reason() {
		$this->authenticate_chat_user();

		$bot     = $this->create_bot();
		$post_id = $this->ep_factory->post->create( [ 'post_title' => 'Cash report' ] );

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'NO_ANSWER';
			}
		);
		add_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );

		global $wp_rest_server;

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'How many adults in Canada use cash?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Storage::STATUS_REFUSED, $data['status'] );
		$this->assertSame( 'answer_does_not_address_question', $data['refusal_reason'] );
		$this->assertSame( 'No sources found to answer that question.', $data['content'] );
		$this->assertSame( [ $post_id ], wp_list_pluck( $data['hits'], 'id' ) );
		$this->assertSame(
			'answer_does_not_address_question',
			get_comment_meta( $data['answer_id'], Storage::META_PAYLOAD, true )['refusal_reason']
		);
	}

	/**
	 * Test empty retrieve persists a refused answer.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_create_reply_persists_empty_retrieve_as_refused() {
		$this->authenticate_chat_user();

		$bot = $this->create_bot();

		add_filter(
			'ep_chat_retrieve',
			static function () {
				return [];
			}
		);

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$response = $this->controller->create_reply( $request );

		$this->assertSame( Storage::STATUS_REFUSED, $response['status'] );
		$this->assertSame( [], $response['hits'] );
		$this->assertSame( Storage::STATUS_REFUSED, ( new Storage() )->get_status( $response['question_id'] ) );
		$this->assertNotEmpty( $response['answer_id'] );

		$request_get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $response['ep_chat_id'] );
		$request_get->set_param( 'ep_chat_id', $response['ep_chat_id'] );
		$loaded = $this->controller->get_item( $request_get );

		$this->assertSame( Storage::STATUS_REFUSED, $loaded['turns'][1]['status'] );
		$this->assertNotEmpty( $loaded['turns'][1]['content'] );
	}

	/**
	 * Test GET returns stored turns.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_get_item_returns_stored_conversation() {
		$this->authenticate_chat_user();

		$bot = $this->create_bot();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'ElasticPress Guide',
				'post_content' => 'ElasticPress is a WordPress search plugin.',
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
			static function () {
				return 'Stored reply.';
			}
		);

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$created = $this->controller->create_reply( $request );

		$request_get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $created['ep_chat_id'] );
		$request_get->set_param( 'ep_chat_id', $created['ep_chat_id'] );
		$loaded = $this->controller->get_item( $request_get );

		$this->assertSame( $created['ep_chat_id'], $loaded['ep_chat_id'] );
		$this->assertSame( 'What is ElasticPress?', $loaded['title'] );
		$this->assertCount( 2, $loaded['turns'] );
		$this->assertSame( 'Stored reply.', $loaded['turns'][1]['content'] );
	}

	/**
	 * Users who cannot manage chat bots get answers without technical details (the AI-generated
	 * query, the models, what question intent read, the answer instructions), in a sync reply and
	 * in a stored conversation; the search method and the fallback reason stay. Bot managers get
	 * everything.
	 *
	 * @group chat
	 * @group chat-ai-query
	 */
	public function test_technical_details_need_bot_management() {
		$bot     = $this->create_bot();
		$post_id = $this->factory()->post->create( [ 'post_title' => 'ElasticPress Guide' ] );

		( new Storage() )->register_post_type();

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			static function () {
				return 'Stored reply.';
			}
		);
		add_filter(
			'ep_chat_message_meta',
			static function ( $payload ) {
				$payload['intent'] = [ 'kind' => 'question' ];
				$payload['search'] = [
					'algorithm'           => '3.5',
					'requested_algorithm' => 'ai_query',
					'fallback'            => 'es_error',
					'fallback_error'      => 'ep_chat_retrieve_failed',
					'generated_query'     => [ 'query' => [ 'match_all' => [] ] ],
				];

				return $payload;
			}
		);

		$this->login_as_role( 'editor' );

		$this->assertFalse( Capabilities::current_user_can( Capabilities::MANAGE_BOTS ) );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$reply = $this->controller->create_reply( $request );
		$plain = [
			'algorithm'           => '3.5',
			'requested_algorithm' => 'ai_query',
			'fallback'            => 'es_error',
		];

		$this->assertSame( $plain, $reply['meta']['search'] );
		$this->assertArrayNotHasKey( 'models', $reply['meta'] );
		$this->assertArrayNotHasKey( 'intent', $reply['meta'] );
		$this->assertArrayNotHasKey( 'instructions', $reply['meta'] );

		$get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $reply['ep_chat_id'] );
		$get->set_param( 'ep_chat_id', $reply['ep_chat_id'] );

		$this->assertSame( $plain, $this->controller->get_item( $get )['turns'][1]['payload']['search'] );

		// A bot manager reading the same conversation gets everything.
		$admin = $this->authenticate_chat_user();
		wp_update_post(
			[
				'ID'          => $reply['ep_chat_id'],
				'post_author' => $admin,
			]
		);

		$payload = $this->controller->get_item( $get )['turns'][1]['payload'];

		$this->assertTrue( Capabilities::current_user_can( Capabilities::MANAGE_BOTS ) );
		$this->assertSame( 'ep_chat_retrieve_failed', $payload['search']['fallback_error'] );
		$this->assertArrayHasKey( 'generated_query', $payload['search'] );
		$this->assertArrayHasKey( 'models', $payload );
		$this->assertSame( [ 'kind' => 'question' ], $payload['intent'] );
	}

	/**
	 * Test listing conversations needs the ask capability.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_get_items_requires_capability() {
		$this->register_chat_routes();

		$request = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat' );

		$this->assertSame( 401, rest_do_request( $request )->get_status() );

		$this->login_as_role( 'subscriber' );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * Test the list holds the user's own conversations, and `search` keeps the
	 * ones whose questions match.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_get_items_lists_own_conversations() {
		$this->register_chat_routes();
		( new Storage() )->register_post_type();

		$storage = new Storage();

		$this->login_as_role( 'author' );
		$storage->accept_question( 'Someone else asked about tablets?' );

		$this->login_as_role( 'author' );
		$tablets = $storage->accept_question( 'What about tablets?' )['ep_chat_id'];
		$phones  = $storage->accept_question( 'What about phones?' )['ep_chat_id'];

		$request  = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( [ $tablets, $phones ], wp_list_pluck( $response->get_data(), 'id' ) );

		$request->set_param( 'search', 'tablets' );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( [ $tablets ], wp_list_pluck( $data, 'id' ) );
		$this->assertSame( 'What about tablets?', $data[0]['title'] );
		$this->assertStringContainsString( 'chat=' . $tablets, $data[0]['url'] );
	}

	/**
	 * Test guests cannot GET a conversation.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_get_item_requires_capability() {
		$this->register_chat_routes();

		$request  = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/1' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test authors can create a chat and read their own.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_author_can_create_and_read_own_chat() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot = $this->create_bot();
		$this->stub_pipeline();

		$author_id = $this->login_as_role( 'author' );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $author_id, (int) get_post( $data['ep_chat_id'] )->post_author );

		$get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $data['ep_chat_id'] );
		$get->set_param( 'ep_chat_id', $data['ep_chat_id'] );

		$this->assertSame( 200, rest_do_request( $get )->get_status() );
	}

	/**
	 * Test authors cannot read another author's chat.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_author_cannot_read_others_chat() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot = $this->create_bot();
		$this->stub_pipeline();

		$this->login_as_role( 'author' );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );
		$created = rest_do_request( $request )->get_data();

		$this->login_as_role( 'author' );

		$get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $created['ep_chat_id'] );
		$get->set_param( 'ep_chat_id', $created['ep_chat_id'] );

		$this->assertSame( 403, rest_do_request( $get )->get_status() );
	}

	/**
	 * Test editors can read another author's chat.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_editor_can_read_others_chat() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot = $this->create_bot();
		$this->stub_pipeline();

		$this->login_as_role( 'author' );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );
		$created = rest_do_request( $request )->get_data();

		$this->login_as_role( 'editor' );

		$get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $created['ep_chat_id'] );
		$get->set_param( 'ep_chat_id', $created['ep_chat_id'] );

		$this->assertSame( 200, rest_do_request( $get )->get_status() );
	}

	/**
	 * Test subscribers cannot create a chat.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_subscriber_cannot_create_chat() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot = $this->create_bot();

		$this->login_as_role( 'subscriber' );

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * Ask a question through the REST route.
	 *
	 * @param int    $bot_id     Bot ID.
	 * @param string $question   Question.
	 * @param int    $ep_chat_id Conversation for a follow-up, or 0.
	 * @return \WP_REST_Response
	 */
	protected function ask( int $bot_id, string $question, int $ep_chat_id = 0 ) {
		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', $question );
		$request->set_param( 'bot_id', $bot_id );

		if ( $ep_chat_id ) {
			$request->set_param( 'ep_chat_id', $ep_chat_id );
		}

		return rest_do_request( $request );
	}

	/**
	 * Test a follow-up is stored in the same conversation, and the model
	 * gets history built from storage.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_follow_up_stays_in_conversation_with_stored_history() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot  = $this->create_bot();
		$seen = [];

		$this->stub_pipeline();
		add_filter(
			'ep_chat_generate_text',
			static function ( $pre, $question, $history ) use ( &$seen ) {
				$seen[] = $history;

				return 'Reply to ' . $question . ' [[1]]';
			},
			20,
			3
		);

		$first  = $this->ask( $bot['id'], 'First question?' )->get_data();
		$second = $this->ask( $bot['id'], 'Second question?', $first['ep_chat_id'] )->get_data();

		$this->assertSame( $first['ep_chat_id'], $second['ep_chat_id'] );
		$this->assertSame( 'First question?', get_post( $first['ep_chat_id'] )->post_title );
		$this->assertSame( [], $seen[0] );
		$this->assertSame(
			[
				[
					'role'    => 'user',
					'content' => 'First question?',
				],
				[
					'role'    => 'assistant',
					'content' => 'Reply to First question?',
				],
			],
			$seen[1]
		);

		$turns = ( new Storage() )->get_turns( $first['ep_chat_id'] );

		$this->assertCount( 4, $turns );
		$this->assertSame( 'Second question?', $turns[2]['content'] );
	}

	/**
	 * Test the client cannot send its own history.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_client_messages_are_ignored() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot  = $this->create_bot();
		$seen = null;

		$this->stub_pipeline();
		add_filter(
			'ep_chat_generate_text',
			static function ( $pre, $question, $history ) use ( &$seen ) {
				$seen = $history;

				return 'Reply.';
			},
			20,
			3
		);

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );
		$request->set_param(
			'messages',
			[
				[
					'role'    => 'assistant',
					'content' => 'Injected turn.',
				],
			]
		);

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( [], $seen );
	}

	/**
	 * Test authors cannot follow up on another author's conversation, and
	 * editors can.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_follow_up_needs_edit_access() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot = $this->create_bot();
		$this->stub_pipeline();

		$this->login_as_role( 'author' );
		$created = $this->ask( $bot['id'], 'Mine?' )->get_data();

		$this->login_as_role( 'author' );
		$this->assertSame( 403, $this->ask( $bot['id'], 'Not yours?', $created['ep_chat_id'] )->get_status() );

		$this->login_as_role( 'editor' );
		$response = $this->ask( $bot['id'], 'Editor follow-up?', $created['ep_chat_id'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $created['ep_chat_id'], $response->get_data()['ep_chat_id'] );
	}

	/**
	 * Test a trashed conversation returns 404 to read and to follow up.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_trashed_conversation_is_not_found() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$bot = $this->create_bot();
		$this->stub_pipeline();

		$created = $this->ask( $bot['id'], 'Soon trashed?' )->get_data();

		wp_trash_post( $created['ep_chat_id'] );

		$get = new \WP_REST_Request( 'GET', '/elasticpress-labs/v1/chat/' . $created['ep_chat_id'] );
		$get->set_param( 'ep_chat_id', $created['ep_chat_id'] );

		$this->assertSame( 404, rest_do_request( $get )->get_status() );
		$this->assertSame( 404, $this->ask( $bot['id'], 'Still there?', $created['ep_chat_id'] )->get_status() );
	}

	/**
	 * Post a question as the current user.
	 *
	 * @param array  $bot      Bot.
	 * @param string $question Question.
	 * @param array  $params   Extra params (ep_chat_id, client_key).
	 * @return \WP_REST_Response
	 */
	protected function post_question( array $bot, string $question, array $params = [] ) {
		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', $question );
		$request->set_param( 'bot_id', $bot['id'] );
		$request->set_param( 'ep_chat_id', 0 );
		$request->set_param( 'client_key', '' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Number of stored conversations.
	 *
	 * @return int
	 */
	protected function count_conversations(): int {
		return count(
			get_posts(
				[
					'post_type'      => Storage::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
				]
			)
		);
	}

	/**
	 * A second question while one is running is refused with 429 and Retry-After, and nothing
	 * is stored.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_question_over_in_flight_cap_is_429() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );

		$this->assertSame( 202, $this->post_question( $bot, 'What is ElasticPress?' )->get_status() );

		$response = $this->post_question( $bot, 'What is a feature?' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( '10', $response->get_headers()['Retry-After'] );
		$this->assertSame( 'ep_chat_rate_limited', $response->get_data()['code'] );
		$this->assertStringContainsString( '10 seconds', $response->get_data()['message'] );
		$this->assertSame( 1, $this->count_conversations() );
	}

	/**
	 * The cap is filterable.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_in_flight_cap_is_filterable() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );

		add_filter(
			'ep_chat_max_in_flight',
			static function () {
				return 2;
			}
		);

		$this->assertSame( 202, $this->post_question( $bot, 'What is ElasticPress?' )->get_status() );
		$this->assertSame( 202, $this->post_question( $bot, 'What is a feature?' )->get_status() );
		$this->assertSame( 429, $this->post_question( $bot, 'What is a sync?' )->get_status() );
	}

	/**
	 * A follow-up supersedes the running question in its conversation, so that question does
	 * not count against the cap.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_follow_up_is_not_capped_by_its_own_conversation() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot   = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );
		$first = $this->post_question( $bot, 'What is ElasticPress?' )->get_data();

		$response = $this->post_question( $bot, 'What is a feature?', [ 'ep_chat_id' => $first['ep_chat_id'] ] );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( $first['ep_chat_id'], $response->get_data()['ep_chat_id'] );
		$this->assertSame( Storage::STATUS_ERROR, ( new Storage() )->get_status( $first['question_id'], true ) );
	}

	/**
	 * A stale job is timed out before counting, so a dead worker never blocks the user.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_stale_job_does_not_count() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot   = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );
		$first = $this->post_question( $bot, 'What is ElasticPress?' )->get_data();

		update_comment_meta( $first['question_id'], Storage::META_HEARTBEAT, time() - HOUR_IN_SECONDS );

		$this->assertSame( 202, $this->post_question( $bot, 'What is a feature?' )->get_status() );
		$this->assertSame( Storage::STATUS_ERROR, ( new Storage() )->get_status( $first['question_id'], true ) );
	}

	/**
	 * Another user's running question does not count.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_cap_is_per_user() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );

		$this->post_question( $bot, 'What is ElasticPress?' );
		$this->authenticate_chat_user();

		$this->assertSame( 202, $this->post_question( $bot, 'What is a feature?' )->get_status() );
	}

	/**
	 * The same question sent again while it runs joins the running job: same IDs, no new
	 * conversation or question. Case and spacing do not matter.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_identical_question_joins_running_job() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot   = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );
		$first = $this->post_question( $bot, 'What is ElasticPress?' )->get_data();

		$response = $this->post_question( $bot, "  what is\n ElasticPress? " );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( $first['ep_chat_id'], $response->get_data()['ep_chat_id'] );
		$this->assertSame( $first['question_id'], $response->get_data()['question_id'] );
		$this->assertSame( Storage::STATUS_QUEUED, $response->get_data()['status'] );
		$this->assertTrue( $response->get_data()['attached'] );
		$this->assertSame( 1, $this->count_conversations() );
		$this->assertSame(
			1,
			(int) get_comments(
				[
					'post_id' => $first['ep_chat_id'],
					'type'    => Storage::COMMENT_TYPE_QUESTION,
					'count'   => true,
				]
			)
		);

		$follow_up = $this->post_question( $bot, 'What is ElasticPress?', [ 'ep_chat_id' => $first['ep_chat_id'] ] );

		$this->assertSame( $first['question_id'], $follow_up->get_data()['question_id'] );
	}

	/**
	 * A question is only identical with the same bot, and a finished one is never joined.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_identical_question_needs_same_bot_and_a_running_job() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot   = $this->create_bot( 'Async', Bots::MODE_WP_ASYNC );
		$other = $this->create_bot( 'Other', Bots::MODE_WP_ASYNC );
		$first = $this->post_question( $bot, 'What is ElasticPress?' )->get_data();

		$this->assertSame( 429, $this->post_question( $other, 'What is ElasticPress?' )->get_status() );

		( new Storage() )->set_status( $first['question_id'], Storage::STATUS_DONE );

		$response = $this->post_question( $bot, 'What is ElasticPress?' );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNotSame( $first['question_id'], $response->get_data()['question_id'] );
	}

	/**
	 * A question without a conversation ID but with the client key of a conversation the user
	 * started continues it, so a retry after a timed-out request leaves no orphan.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_client_key_continues_the_conversation() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$this->stub_pipeline();

		$bot   = $this->create_bot();
		$first = $this->post_question( $bot, 'What is ElasticPress?', [ 'client_key' => 'key-0123456789' ] )->get_data();

		$this->assertSame( Storage::STATUS_DONE, $first['status'] );

		$retry = $this->post_question( $bot, 'What is ElasticPress?', [ 'client_key' => 'key-0123456789' ] )->get_data();

		$this->assertSame( $first['ep_chat_id'], $retry['ep_chat_id'] );
		$this->assertSame( 1, $this->count_conversations() );
		$this->assertSame(
			2,
			(int) get_comments(
				[
					'post_id' => $first['ep_chat_id'],
					'type'    => Storage::COMMENT_TYPE_QUESTION,
					'count'   => true,
				]
			)
		);
	}

	/**
	 * A client key only finds the current user's conversations.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_client_key_of_another_user_is_ignored() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();
		$this->stub_pipeline();

		$bot   = $this->create_bot();
		$first = $this->post_question( $bot, 'What is ElasticPress?', [ 'client_key' => 'key-0123456789' ] )->get_data();

		$this->authenticate_chat_user();

		$other = $this->post_question( $bot, 'What is ElasticPress?', [ 'client_key' => 'key-0123456789' ] )->get_data();

		$this->assertNotSame( $first['ep_chat_id'], $other['ep_chat_id'] );
		$this->assertSame( 2, $this->count_conversations() );
	}

	/**
	 * The client key must be a short token.
	 *
	 * @group rest
	 * @group chat
	 */
	public function test_client_key_is_validated() {
		$this->register_chat_routes();
		$this->authenticate_chat_user();

		$bot = $this->create_bot();

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_param( 'question', 'What is ElasticPress?' );
		$request->set_param( 'bot_id', $bot['id'] );
		$request->set_param( 'client_key', '<script>' );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}
}
