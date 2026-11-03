<?php
/**
 * Chat REST API controller.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPressLabs\Feature\Chat\Admin;
use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Capabilities;
use ElasticPressLabs\Feature\Chat\Jobs\Admission;
use ElasticPressLabs\Feature\Chat\Jobs\Dispatcher;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Handle;
use ElasticPressLabs\Feature\Chat\Jobs\Reaper;
use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Conversational Chat REST routes.
 */
class Chat {
	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'elasticpress-labs/v1',
			'chat',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'check_list_permission' ],
					'args'                => [
						'search' => [
							'description'       => __( 'Only conversations with a question or a summary that matches this text.', 'elasticpress-labs' ),
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_reply' ],
					'permission_callback' => [ $this, 'check_create_permission' ],
					'args'                => [
						'question'   => [
							'description'       => __( 'The user question.', 'elasticpress-labs' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'ep_chat_id' => [
							'description'       => __( 'Conversation to add a follow-up question to. Omit to start a new conversation.', 'elasticpress-labs' ),
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
						'bot_id'     => [
							'description'       => __( 'Chat bot ID.', 'elasticpress-labs' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'client_key' => [
							'description' => __( 'Random key the client sends with every question of a new conversation until it knows the conversation ID. A retry with the same key continues the conversation the first request created.', 'elasticpress-labs' ),
							'type'        => 'string',
							'required'    => false,
							'default'     => '',
							'pattern'     => '^([A-Za-z0-9_-]{8,64})?$',
						],
					],
				],
			]
		);

		register_rest_route(
			'elasticpress-labs/v1',
			'chat/(?P<ep_chat_id>[\d]+)',
			[
				'args' => [
					'ep_chat_id' => [
						'description'       => __( 'Chat conversation post ID.', 'elasticpress-labs' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'check_read_permission' ],
				],
			]
		);
	}

	/**
	 * Whether the current user can ask a question.
	 *
	 * Starting a conversation needs the `ask` capability. A follow-up also
	 * needs edit access to that conversation. A missing or trashed
	 * conversation passes here, so the handler returns 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_create_permission( \WP_REST_Request $request ) {
		if ( ! Capabilities::current_user_can( Capabilities::ASK ) ) {
			return false;
		}

		$ep_chat_id = (int) $request['ep_chat_id'];

		if ( ! $ep_chat_id || ! ( new Storage() )->is_open_conversation( $ep_chat_id ) ) {
			return true;
		}

		return current_user_can( 'edit_post', $ep_chat_id );
	}

	/**
	 * Whether the current user can read a stored conversation.
	 *
	 * Reading a conversation uses the same check as editing it: users see
	 * their own, and users with the `others` capability see everyone's. A
	 * missing or trashed conversation passes here, so the handler returns 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_read_permission( \WP_REST_Request $request ) {
		if ( ! Capabilities::current_user_can( Capabilities::ASK ) ) {
			return false;
		}

		$ep_chat_id = (int) $request['ep_chat_id'];

		if ( ! ( new Storage() )->is_open_conversation( $ep_chat_id ) ) {
			return true;
		}

		return current_user_can( 'read_post', $ep_chat_id );
	}

	/**
	 * Whether the current user can list their conversations.
	 *
	 * @return bool
	 */
	public function check_list_permission() {
		return Capabilities::current_user_can( Capabilities::ASK );
	}

	/**
	 * The current user's latest conversations, as the Ask sidebar lists them.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array[] Each with `id`, `title`, `date`, and `url`.
	 */
	public function get_items( \WP_REST_Request $request ) {
		return ( new Admin() )->get_recent_conversations( (string) $request['search'] );
	}

	/**
	 * Load a stored conversation. Stale jobs are timed out first, so a
	 * poll or a restored thread never waits on a dead worker.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$storage    = new Storage();
		$ep_chat_id = (int) $request['ep_chat_id'];

		( new Reaper( $storage ) )->reap_conversation( $ep_chat_id );

		$conversation = $storage->get( $ep_chat_id );

		if ( is_array( $conversation ) && is_array( $conversation['turns'] ?? null ) ) {
			foreach ( $conversation['turns'] as $index => $turn ) {
				if ( is_array( $turn['payload'] ?? null ) ) {
					$conversation['turns'][ $index ]['payload'] = self::without_technical_details( $turn['payload'] );
				}
			}
		}

		return $conversation;
	}

	/**
	 * An answer payload without its technical details (the AI-generated query and its error, the
	 * models, what question intent read, the answer instructions) for users who cannot manage chat
	 * bots: they tell what the server runs. What was searched for, the search method, and why a
	 * fallback ran stay, for "How this answer was made" and the fallback note.
	 *
	 * @param array $payload Answer payload.
	 * @return array
	 */
	public static function without_technical_details( array $payload ): array {
		if ( Capabilities::current_user_can( Capabilities::MANAGE_BOTS ) ) {
			return $payload;
		}

		unset( $payload['models'], $payload['intent'], $payload['instructions'] );

		if ( is_array( $payload['search'] ?? null ) ) {
			unset( $payload['search']['generated_query'], $payload['search']['fallback_error'] );
		}

		return $payload;
	}

	/**
	 * Generate a conversational reply.
	 *
	 * Persist happens when the question is accepted (queued), before the
	 * pipeline runs, so async can later poll the same question comment.
	 *
	 * A question without a conversation ID but with a known client key
	 * continues that conversation. Before it is accepted, a question
	 * identical to one the user has running joins that job (202 with its
	 * IDs), and a user over the in-flight cap gets a 429 with Retry-After.
	 * See Admission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_REST_Response|\WP_Error
	 */
	public function create_reply( \WP_REST_Request $request ) {
		$question = trim( (string) $request['question'] );

		if ( '' === $question ) {
			return new \WP_Error(
				'ep_chat_empty_question',
				esc_html__( 'Enter a question.', 'elasticpress-labs' ),
				[ 'status' => 400 ]
			);
		}

		$bots = new Bots();
		$bot  = $bots->get( (int) ( $request['bot_id'] ?? 0 ) );

		if ( is_wp_error( $bot ) ) {
			return $bot;
		}

		$storage    = new Storage();
		$admission  = new Admission( $storage );
		$user_id    = get_current_user_id();
		$bot_id     = (int) ( $bot['id'] ?? 0 );
		$ep_chat_id = (int) $request['ep_chat_id'];
		$client_key = $ep_chat_id ? '' : (string) $request['client_key'];

		if ( ! $ep_chat_id ) {
			$ep_chat_id = $storage->find_by_client_key( $client_key, $user_id );
		}

		$accepted = $admission->with_lock(
			$user_id,
			static function () use ( $storage, $admission, $user_id, $bot_id, $question, $ep_chat_id, $client_key ) {
				$duplicate = $admission->find_duplicate( $user_id, $bot_id, $question, $ep_chat_id );

				if ( $duplicate ) {
					return [ 'duplicate' => $duplicate ];
				}

				$allowed = $admission->check_cap( $user_id, $ep_chat_id );

				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}

				$accepted = $storage->accept_question( $question, $ep_chat_id, $client_key );

				if ( ! is_wp_error( $accepted ) ) {
					// The bot is stored here, inside the lock, so an identical question that arrives next can match it.
					$storage->set_job_context( (int) $accepted['question_id'], $bot_id, [] );
				}

				return $accepted;
			}
		);

		if ( is_wp_error( $accepted ) ) {
			return $this->to_response( $accepted );
		}

		if ( isset( $accepted['duplicate'] ) ) {
			$context = $storage->get_job_context( (int) $accepted['duplicate'] );

			return new \WP_REST_Response(
				[
					'ep_chat_id'  => (int) $context['ep_chat_id'],
					'question_id' => (int) $accepted['duplicate'],
					'status'      => $storage->get_status( (int) $accepted['duplicate'], true ),
					'attached'    => true,
				],
				202
			);
		}

		$ids = [
			'ep_chat_id'  => (int) $accepted['ep_chat_id'],
			'question_id' => (int) $accepted['question_id'],
		];

		$handle = ( new Dispatcher() )->dispatch(
			$question,
			$storage->get_history( $ids['ep_chat_id'], $ids['question_id'], $bot ),
			$bot,
			$ids
		);

		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		if ( ! $handle instanceof Job_Handle ) {
			return new \WP_Error(
				'ep_chat_dispatch_failed',
				esc_html__( 'The assistant could not generate a reply.', 'elasticpress-labs' ),
				array_merge( $ids, [ 'status' => 500 ] )
			);
		}

		if ( $handle->is_complete() ) {
			$reply = $handle->to_rest();

			if ( is_array( $reply['meta'] ?? null ) ) {
				$reply['meta'] = self::without_technical_details( $reply['meta'] );
			}

			return $reply;
		}

		return new \WP_REST_Response( $handle->to_rest(), 202 );
	}

	/**
	 * REST response for an error, with Retry-After when it carries one.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_Error|\WP_REST_Response
	 */
	protected function to_response( \WP_Error $error ) {
		$data = $error->get_error_data();

		if ( empty( $data['retry_after'] ) ) {
			return $error;
		}

		$response = rest_convert_error_to_response( $error );
		$response->header( 'Retry-After', (string) (int) $data['retry_after'] );

		return $response;
	}
}
