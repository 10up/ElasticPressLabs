<?php
/**
 * Chat Bots REST API controller.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Capabilities;
use ElasticPressLabs\Feature\Chat\Client;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * CRUD routes for `ep_chat_bot`.
 */
class ChatBots {
	/**
	 * Bot storage.
	 *
	 * @var Bots
	 */
	protected $bots;

	/**
	 * Constructor.
	 *
	 * @param Bots|null $bots Bot storage.
	 */
	public function __construct( ?Bots $bots = null ) {
		$this->bots = $bots ? $bots : new Bots();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'elasticpress-labs/v1',
			'chat-bots',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'check_list_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_item_args(),
				],
			]
		);

		register_rest_route(
			'elasticpress-labs/v1',
			'chat-bots/order',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_order' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'ids' => [
							'description' => __( 'Chat bot IDs in display order.', 'elasticpress-labs' ),
							'type'        => 'array',
							'required'    => true,
							'items'       => [
								'type' => 'integer',
							],
						],
					],
				],
			]
		);

		register_rest_route(
			'elasticpress-labs/v1',
			'chat-bots/prompt-preview',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'preview_prompt' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'bot_id'              => [
							'description'       => __( 'Chat bot ID. 0 previews the Chat feature default with the gates that are on by default.', 'elasticpress-labs' ),
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
						'answer_instructions' => [
							'description' => __( 'Unsaved answer instructions to preview. For a bot, empty uses the Chat feature value. Omit to use the saved value.', 'elasticpress-labs' ),
							'type'        => 'string',
							'required'    => false,
						],
						'optional_gates'      => [
							'description' => __( 'Unsaved optional gate slugs to preview. Omit to use the saved ones.', 'elasticpress-labs' ),
							'type'        => 'array',
							'required'    => false,
							'items'       => [
								'type' => 'string',
							],
						],
					],
				],
			]
		);

		register_rest_route(
			'elasticpress-labs/v1',
			'chat-bots/(?P<id>[\d]+)/duplicate',
			[
				'args' => [
					'id' => [
						'description'       => __( 'Chat bot ID.', 'elasticpress-labs' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'duplicate_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			'elasticpress-labs/v1',
			'chat-bots/(?P<id>[\d]+)',
			[
				'args' => [
					'id' => [
						'description'       => __( 'Chat bot ID.', 'elasticpress-labs' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->get_item_args(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	/**
	 * Whether the current user can manage chat bots.
	 *
	 * Defaults to ElasticPress's `manage_elasticpress` capability. Sites can
	 * change that via `ep_capability`, or for bots only via
	 * `ep_chat_bots_capability`.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return Capabilities::current_user_can( Capabilities::MANAGE_BOTS );
	}

	/**
	 * Whether the current user can list chat bots: bot managers, and users
	 * who can ask (Ask needs the list to pick a bot).
	 *
	 * @return bool
	 */
	public function check_list_permission() {
		return $this->check_permission() || Capabilities::current_user_can( Capabilities::ASK );
	}

	/**
	 * List chat bots. Users who cannot manage bots only get what Ask shows:
	 * ID, name, and execution mode.
	 *
	 * @return array
	 */
	public function get_items() {
		$bots = $this->bots->get_all();

		if ( $this->check_permission() ) {
			return $bots;
		}

		return array_map(
			static function ( $bot ) {
				return array_intersect_key( $bot, array_flip( [ 'id', 'name', 'execution_mode' ] ) );
			},
			$bots
		);
	}

	/**
	 * Duplicate a chat bot with all its settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function duplicate_item( \WP_REST_Request $request ) {
		$bot_id = $this->bots->duplicate( (int) $request['id'] );

		if ( is_wp_error( $bot_id ) ) {
			return $this->error_or_item( $bot_id );
		}

		return new \WP_REST_Response( $this->bots->get( $bot_id ), 201 );
	}

	/**
	 * Get one chat bot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		return $this->error_or_item( $this->bots->get( (int) $request['id'] ) );
	}

	/**
	 * Create a chat bot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $request ) {
		$bot_id = $this->bots->create(
			(string) $request['name'],
			(string) ( $request['execution_mode'] ?? Bots::MODE_SYNC ),
			$this->get_settings( $request )
		);

		if ( is_wp_error( $bot_id ) ) {
			return $this->error_or_item( $bot_id );
		}

		$response = rest_ensure_response( $this->bots->get( $bot_id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a chat bot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function update_item( \WP_REST_Request $request ) {
		$updated = $this->bots->update(
			(int) $request['id'],
			(string) $request['name'],
			(string) ( $request['execution_mode'] ?? Bots::MODE_SYNC ),
			$this->get_settings( $request )
		);

		if ( is_wp_error( $updated ) ) {
			return $this->error_or_item( $updated );
		}

		return $this->bots->get( $updated );
	}

	/**
	 * Delete a chat bot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( \WP_REST_Request $request ) {
		$bot_id   = (int) $request['id'];
		$previous = $this->bots->get( $bot_id );

		if ( is_wp_error( $previous ) ) {
			return $this->error_or_item( $previous );
		}

		$deleted = $this->bots->delete( $bot_id );

		if ( is_wp_error( $deleted ) ) {
			return $this->error_or_item( $deleted );
		}

		$response = rest_ensure_response(
			[
				'deleted'  => true,
				'previous' => $previous,
			]
		);
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Persist chat bot display order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function update_order( \WP_REST_Request $request ) {
		$ids = $request['ids'];

		return $this->error_or_item(
			$this->bots->reorder( is_array( $ids ) ? $ids : [] )
		);
	}

	/**
	 * REST args for create/update.
	 *
	 * @return array
	 */
	protected function get_item_args(): array {
		return [
			'name'           => [
				'description'       => __( 'Chat bot name.', 'elasticpress-labs' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'execution_mode' => [
				'description'       => __( 'Chat bot runner.', 'elasticpress-labs' ),
				'type'              => 'string',
				'required'          => false,
				'enum'              => $this->bots->allowed_execution_modes(),
				'sanitize_callback' => function ( $mode ) {
					return $this->bots->sanitize_execution_mode( (string) $mode );
				},
			],
			'rerank_enabled' => [
				'description' => __( 'Whether this bot re-ranks retrieved passages.', 'elasticpress-labs' ),
				'type'        => 'boolean',
				'required'    => false,
			],
			'optional_gates' => [
				'description' => __( 'Optional gate slugs enabled on this chat bot.', 'elasticpress-labs' ),
				'type'        => 'array',
				'required'    => false,
				'items'       => [
					'type' => 'string',
				],
			],
		] + $this->get_override_args();
	}

	/**
	 * Args for the shared answering settings a bot can override. Empty or null inherits.
	 *
	 * @return array
	 */
	protected function get_override_args(): array {
		$args = [];

		foreach ( Settings::get_shared_keys() as $key ) {
			$args[ $key ] = [
				'description' => Settings::get_help( $key ),
				'required'    => false,
			];
		}

		return $args;
	}

	/**
	 * Bot settings from a REST request: re-ranker on/off, optional gates, and overrides.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	protected function get_settings( \WP_REST_Request $request ): array {
		$settings = [];

		if ( $request->has_param( 'rerank_enabled' ) ) {
			$settings['rerank_enabled'] = $request['rerank_enabled'];
		}

		if ( $request->has_param( 'optional_gates' ) ) {
			$settings['optional_gates'] = $request['optional_gates'];
		}

		foreach ( Settings::get_shared_keys() as $key ) {
			if ( $request->has_param( $key ) ) {
				$settings[ $key ] = $request[ $key ];
			}
		}

		return $settings;
	}

	/**
	 * The answer prompt as it would be sent, with the form's unsaved instructions and gates.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error `prompt`.
	 */
	public function preview_prompt( \WP_REST_Request $request ) {
		$bot_id       = (int) $request['bot_id'];
		$bot          = [];
		$instructions = null;

		if ( $bot_id ) {
			$bot = $this->bots->get( $bot_id );

			if ( is_wp_error( $bot ) ) {
				return $this->error_or_item( $bot );
			}
		}

		if ( $request->has_param( 'answer_instructions' ) ) {
			$value = Settings::sanitize( 'answer_instructions', $request['answer_instructions'], false );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			if ( $bot_id ) {
				$bot['answer_instructions'] = $value;
			} else {
				// The feature form's own text; empty means the code default.
				$instructions = null === $value ? (string) Settings::get_default( 'answer_instructions' ) : $value;
			}
		}

		if ( $request->has_param( 'optional_gates' ) ) {
			$bot['optional_gates'] = array_values( array_map( 'strval', (array) $request['optional_gates'] ) );
		} elseif ( ! $bot_id ) {
			$bot['optional_gates'] = Gates::get_default_optional();
		}

		// Without an ID, the gates come from `optional_gates` (see Gates::should_run()).
		unset( $bot['id'] );

		return [
			'prompt' => ( new Client() )->preview_instruction( $bot, $instructions ),
		];
	}

	/**
	 * Attach an HTTP status to a WP_Error when missing.
	 *
	 * @param array|\WP_Error $item Item or error.
	 * @return array|\WP_Error
	 */
	protected function error_or_item( $item ) {
		if ( ! is_wp_error( $item ) ) {
			return $item;
		}

		$data = $item->get_error_data();

		if ( empty( $data['status'] ) ) {
			$status = 'ep_chat_invalid_bot' === $item->get_error_code() ? 404 : 400;
			$item->add_data( [ 'status' => $status ], $item->get_error_code() );
		}

		return $item;
	}
}
