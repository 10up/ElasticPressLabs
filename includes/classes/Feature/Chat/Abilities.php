<?php
/**
 * Chat abilities: ask and search, for the Abilities API and MCP.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers Chat's `ask` and `search` as WordPress abilities, marked for MCP, so an MCP server
 * (such as the WordPress MCP Adapter's default server) offers them to external clients.
 *
 * An ask goes through the same path as the Ask screen: Chat's REST route (History, conversation
 * memory, the in-flight limit), and the answer is read back from storage the way Ask reads it, so a decline is shown as Ask shows it for that bot (see Refusals). Nothing
 * the reader would not see leaves: no passage text on a decline, no withheld draft, and for an
 * answer only what the Sources rail shows for cited sources.
 *
 * Access is WordPress's: an MCP client signs in as a user (for example with an Application
 * Password), and Chat's capabilities apply.
 */
class Abilities {
	/**
	 * Ability category.
	 *
	 * @var string
	 */
	public const CATEGORY = 'elasticpress-labs-chat';

	/**
	 * Ask ability.
	 *
	 * @var string
	 */
	public const ASK = 'elasticpress-labs/chat-ask';

	/**
	 * Search ability.
	 *
	 * @var string
	 */
	public const SEARCH = 'elasticpress-labs/chat-search';

	/**
	 * List-bots ability.
	 *
	 * @var string
	 */
	public const LIST_BOTS = 'elasticpress-labs/chat-list-bots';

	/**
	 * Most search results.
	 *
	 * @var int
	 */
	public const MAX_RESULTS = 20;

	/**
	 * WordPress hooks. Nothing happens on WordPress without the Abilities API (before 6.9).
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the Chat category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Chat', 'elasticpress-labs' ),
				'description' => __( 'Grounded answers and search over the site’s published content.', 'elasticpress-labs' ),
			]
		);
	}

	/**
	 * Register the ask and search abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::ASK,
			[
				'label'               => __( 'Ask the site', 'elasticpress-labs' ),
				'description'         => __( 'Answers a question from the site’s published content, with a source for each fact, as the Chat Ask screen does. Every grounding check runs: an answer that cannot be supported is declined instead, with the reason the site chooses to show. Stored in the user’s Chat history.', 'elasticpress-labs' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_ask_input_schema(),
				'output_schema'       => $this->get_ask_output_schema(),
				'execute_callback'    => [ $this, 'ask' ],
				'permission_callback' => [ $this, 'can_ask' ],
				'meta'                => [
					'annotations'  => [
						'instructions' => __( 'Pass one question in plain words. To choose a bot, call elasticpress-labs/chat-list-bots first. For a follow-up, pass the conversation_id of the earlier answer. Figures in the answer are copied from the sources; each [[k]] marker points to an entry in citations. A declined question is a normal result (status "refused"), not an error: do not try to work around it.', 'elasticpress-labs' ),
						'readonly'     => false,
						'destructive'  => false,
						'idempotent'   => false,
					],
					'show_in_rest' => true,
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		$this->register_list_bots();

		wp_register_ability(
			self::SEARCH,
			[
				'label'               => __( 'Search the site', 'elasticpress-labs' ),
				'description'         => __( 'Finds the site’s published content that matches a query, with Chat’s search. Returns titles, links, and dates.', 'elasticpress-labs' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_search_input_schema(),
				'output_schema'       => $this->get_search_output_schema(),
				'execute_callback'    => [ $this, 'search' ],
				'permission_callback' => [ $this, 'can_search' ],
				'meta'                => [
					'annotations'  => [
						'instructions' => __( 'Pass a search query. Use elasticpress-labs/chat-ask for an answer with sources.', 'elasticpress-labs' ),
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => true,
					],
					'show_in_rest' => true,
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	/**
	 * Register the list-bots ability.
	 *
	 * @return void
	 */
	protected function register_list_bots() {
		wp_register_ability(
			self::LIST_BOTS,
			[
				'label'               => __( 'List the chat bots', 'elasticpress-labs' ),
				'description'         => __( 'Lists the Chat bots a question can be asked with: ID, name, and which one ask and search use when no bot is given.', 'elasticpress-labs' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'bots' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'      => [ 'type' => 'integer' ],
									'name'    => [ 'type' => 'string' ],
									'default' => [
										'type'        => 'boolean',
										'description' => __( 'The bot ask and search use when no bot_id is given.', 'elasticpress-labs' ),
									],
								],
							],
						],
					],
					'required'   => [ 'bots' ],
				],
				'execute_callback'    => [ $this, 'list_bots' ],
				'permission_callback' => [ $this, 'can_search' ],
				'meta'                => [
					'annotations'  => [
						'instructions' => __( 'Call it to choose a bot_id for elasticpress-labs/chat-ask or elasticpress-labs/chat-search. Bots can differ in their models and checks.', 'elasticpress-labs' ),
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => true,
					],
					'show_in_rest' => true,
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	/**
	 * The bots, in the AI Chat Bots order, with the one ask and search use by default.
	 *
	 * @return array
	 */
	public function list_bots(): array {
		$default = $this->get_default_bot();
		$bots    = [];

		foreach ( ( new Bots() )->get_all() as $bot ) {
			$bots[] = [
				'id'      => (int) $bot['id'],
				'name'    => (string) $bot['name'],
				'default' => ! is_wp_error( $default ) && (int) $default['id'] === (int) $bot['id'],
			];
		}

		return [ 'bots' => $bots ];
	}

	/**
	 * Whether the current user can ask (and, for a follow-up, add to that conversation).
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_ask( $input = [] ) {
		if ( ! Capabilities::current_user_can( Capabilities::ASK ) ) {
			return false;
		}

		$conversation_id = absint( is_array( $input ) ? ( $input['conversation_id'] ?? 0 ) : 0 );

		if ( ! $conversation_id ) {
			return true;
		}

		return ( new Storage() )->is_open_conversation( $conversation_id ) && current_user_can( 'edit_post', $conversation_id );
	}

	/**
	 * Whether the current user can search.
	 *
	 * @return bool
	 */
	public function can_search(): bool {
		return Capabilities::current_user_can( Capabilities::ASK );
	}

	/**
	 * Ask a question through Chat's REST route and return the answer as Ask shows it.
	 *
	 * The question runs in this request whatever the bot's runner: the MCP client waits for the
	 * answer anyway, and an async runner would only start once this request ends. A question that
	 * joins the same question already running (started from Ask) is waited for.
	 *
	 * @param array $input Ability input: `question`, `bot_id`, `conversation_id`.
	 * @return array|\WP_Error
	 */
	public function ask( $input ) {
		$input    = is_array( $input ) ? $input : [];
		$question = trim( (string) ( $input['question'] ?? '' ) );
		$bot      = $this->get_bot( absint( $input['bot_id'] ?? 0 ) );

		if ( is_wp_error( $bot ) ) {
			return $bot;
		}

		$request = new \WP_REST_Request( 'POST', '/elasticpress-labs/v1/chat' );
		$request->set_body_params(
			[
				'question'   => $question,
				'bot_id'     => (int) $bot['id'],
				'ep_chat_id' => absint( $input['conversation_id'] ?? 0 ),
			]
		);

		$sync = static function () {
			return Jobs\Job_Handle::MODE_SYNC;
		};

		add_filter( 'ep_chat_execution_mode', $sync );

		try {
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'ep_chat_execution_mode', $sync );
		}

		$data = $response->get_data();

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$conversation_id = (int) ( $data['ep_chat_id'] ?? 0 );
		$question_id     = (int) ( $data['question_id'] ?? 0 );

		if ( ! $conversation_id || ! $question_id ) {
			return new \WP_Error( 'ep_chat_ability_no_question', __( 'The question was not accepted.', 'elasticpress-labs' ) );
		}

		$storage = new Storage();

		if ( in_array( $storage->get_status( $question_id, true ), $storage->get_active_statuses(), true ) ) {
			$this->wait( $storage, $question_id );
		}

		return $this->to_output( $storage, $conversation_id, $question_id );
	}

	/**
	 * Search with Chat's retrieval.
	 *
	 * @param array $input Ability input: `query`, `bot_id`, `limit`.
	 * @return array|\WP_Error
	 */
	public function search( $input ) {
		$input = is_array( $input ) ? $input : [];
		$query = trim( (string) ( $input['query'] ?? '' ) );
		$limit = max( 1, min( self::MAX_RESULTS, absint( $input['limit'] ?? 10 ) ) );
		$bot   = $this->get_bot( absint( $input['bot_id'] ?? 0 ) );

		if ( '' === $query ) {
			return new \WP_Error( 'ep_chat_empty_question', __( 'Enter a search query.', 'elasticpress-labs' ), [ 'status' => 400 ] );
		}

		if ( is_wp_error( $bot ) ) {
			return $bot;
		}

		$posts = ( new Retriever() )->search( $query, $bot );

		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$results = [];

		foreach ( array_slice( $posts, 0, $limit ) as $post ) {
			if ( $post instanceof \WP_Post ) {
				$results[] = [
					'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => (string) get_permalink( $post ),
					'date'  => (string) get_the_date( 'Y-m-d', $post ),
				];
			}
		}

		return [ 'results' => $results ];
	}

	/**
	 * The bot to use: the one asked for, or the default (see get_default_bot()).
	 *
	 * @param int $bot_id Bot ID, or 0 for the default.
	 * @return array|\WP_Error
	 */
	protected function get_bot( int $bot_id ) {
		return $bot_id ? ( new Bots() )->get( $bot_id ) : $this->get_default_bot();
	}

	/**
	 * The bot ask and search use when none is given: the first sync bot (in the AI Chat Bots
	 * order), else the first bot. An ask runs in the request either way (see ask()), so a sync
	 * bot is only preferred because it is the one set up for answers that wait.
	 *
	 * @return array|\WP_Error
	 */
	protected function get_default_bot() {
		$all = ( new Bots() )->get_all();

		foreach ( $all as $bot ) {
			if ( Bots::MODE_SYNC === ( $bot['execution_mode'] ?? '' ) ) {
				return $bot;
			}
		}

		return $all ? $all[0] : new \WP_Error( 'ep_chat_no_bot', __( 'No chat bot is set up.', 'elasticpress-labs' ), [ 'status' => 400 ] );
	}

	/**
	 * Wait for an async answer, up to the timeout.
	 *
	 * @param Storage $storage     Storage.
	 * @param int     $question_id Question comment ID.
	 * @return void
	 */
	protected function wait( Storage $storage, int $question_id ) {
		/**
		 * Filters how long an ask through the Chat ability waits when it joins the same question
		 * already running elsewhere (for example started from Ask). Past it, the ability returns
		 * `queued` with the IDs, and the answer is in Chat history.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ability_wait
		 * @param {int} $seconds Seconds. Default 90.
		 * @return {int} Filtered seconds.
		 */
		$deadline = time() + max( 0, (int) apply_filters( 'ep_chat_ability_wait', 90 ) );

		while ( time() < $deadline && in_array( $storage->get_status( $question_id, true ), $storage->get_active_statuses(), true ) ) {
			sleep( 1 );
		}
	}

	/**
	 * The stored question and its answer, as Ask shows them.
	 *
	 * @param Storage $storage         Storage.
	 * @param int     $conversation_id Conversation post ID.
	 * @param int     $question_id     Question comment ID.
	 * @return array
	 */
	protected function to_output( Storage $storage, int $conversation_id, int $question_id ): array {
		$output = [
			'status'          => $storage->get_status( $question_id, true ),
			'conversation_id' => $conversation_id,
			'question_id'     => $question_id,
			'answer'          => '',
		];

		if ( in_array( $output['status'], $storage->get_active_statuses(), true ) ) {
			$output['status'] = 'queued';

			return $output;
		}

		$answer = null;
		$turns  = $storage->get_turns( $conversation_id );

		foreach ( $turns as $index => $turn ) {
			if ( 'user' === $turn['role'] && $question_id === (int) $turn['id'] ) {
				$next   = $turns[ $index + 1 ] ?? null;
				$answer = is_array( $next ) && 'assistant' === $next['role'] ? $next : null;
				break;
			}
		}

		if ( ! $answer ) {
			return $output;
		}

		$payload          = is_array( $answer['payload'] ?? null ) ? $answer['payload'] : [];
		$output['answer'] = (string) $answer['content'];

		if ( Storage::STATUS_DONE === $output['status'] ) {
			return $output + $this->describe_sources( $payload ) + $this->describe_search( $payload );
		}

		if ( in_array( $output['status'], [ Storage::STATUS_REFUSED, Storage::STATUS_CLARIFY ], true ) ) {
			$refusal = is_array( $payload['refusal'] ?? null ) ? $payload['refusal'] : [];

			$output['refusal'] = array_filter(
				[
					'reason'      => isset( $payload['refusal_reason'] ) ? (string) $payload['refusal_reason'] : null,
					'label'       => $refusal['label'] ?? null,
					'description' => $refusal['description'] ?? null,
					'kind'        => $refusal['kind'] ?? null,
					'next_step'   => $refusal['next_step'] ?? null,
				],
				static fn( $value ) => null !== $value && '' !== $value
			);
		}

		if ( Storage::STATUS_REFUSED === $output['status'] ) {
			// Related reports as the card lists them: titles and links only.
			$output['related'] = ! empty( $payload['refusal']['related'] ) ? $this->describe_reports( is_array( $payload['hits'] ?? null ) ? $payload['hits'] : [] ) : [];
		}

		if ( Storage::STATUS_CLARIFY === $output['status'] ) {
			$output['options'] = array_values( array_map( 'strval', (array) ( $payload['clarify']['options'] ?? [] ) ) );
		}

		return $output;
	}

	/**
	 * The citations and cited sources of an answer: what the Sources rail shows for them.
	 *
	 * @param array $payload Answer payload (read for the reader).
	 * @return array{citations: array, sources: array}
	 */
	protected function describe_sources( array $payload ): array {
		$hits = [];

		foreach ( (array) ( $payload['hits'] ?? [] ) as $hit ) {
			if ( is_array( $hit ) && isset( $hit['number'] ) ) {
				$hits[ (int) $hit['number'] ] = $hit;
			}
		}

		$citations = [];
		$cited     = [];

		foreach ( (array) ( $payload['provenance'] ?? [] ) as $entry ) {
			$hit = $hits[ (int) ( $entry['n'] ?? 0 ) ] ?? null;

			if ( ! is_array( $entry ) || ! $hit ) {
				continue;
			}

			$citations[] = [
				'k'      => (int) $entry['k'],
				'source' => (int) $entry['n'],
				'title'  => (string) ( $hit['title'] ?? '' ),
				'url'    => (string) ( $hit['url'] ?? '' ),
				'date'   => (string) ( $hit['date'] ?? '' ),
				'figure' => (string) ( $entry['figure'] ?? '' ),
				'quote'  => (string) ( $entry['quote'] ?? '' ),
			];

			$cited[ (int) $entry['n'] ] = $hit;
		}

		ksort( $cited );

		$sources = [];

		foreach ( $cited as $number => $hit ) {
			$sources[] = [
				'source'   => $number,
				'title'    => (string) ( $hit['title'] ?? '' ),
				'url'      => (string) ( $hit['url'] ?? '' ),
				'date'     => (string) ( $hit['date'] ?? '' ),
				'citation' => (string) ( $hit['citation'] ?? '' ),
				'notes'    => array_values(
					array_map(
						static fn( $note ) => [
							'label' => (string) ( $note['label'] ?? '' ),
							'text'  => (string) ( $note['text'] ?? '' ),
						],
						array_filter( (array) ( $hit['notes'] ?? [] ), 'is_array' )
					)
				),
			];
		}

		return compact( 'citations', 'sources' );
	}

	/**
	 * What the answer searched for, when question intent rewrote the question.
	 *
	 * @param array $payload Answer payload.
	 * @return array
	 */
	protected function describe_search( array $payload ): array {
		$query = is_string( $payload['search']['query'] ?? null ) ? $payload['search']['query'] : '';

		return '' !== $query ? [ 'searched_for' => $query ] : [];
	}

	/**
	 * Reports as titles, links, and dates.
	 *
	 * @param array $hits Hits.
	 * @return array
	 */
	protected function describe_reports( array $hits ): array {
		$reports = [];

		foreach ( $hits as $hit ) {
			if ( is_array( $hit ) && ! empty( $hit['url'] ) ) {
				$reports[] = [
					'title' => (string) ( $hit['title'] ?? '' ),
					'url'   => (string) $hit['url'],
					'date'  => (string) ( $hit['date'] ?? '' ),
				];
			}
		}

		return $reports;
	}

	/**
	 * Input schema of the ask ability.
	 *
	 * @return array
	 */
	protected function get_ask_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'question'        => [
					'type'        => 'string',
					'description' => __( 'The question, in plain words.', 'elasticpress-labs' ),
					'minLength'   => 1,
					'maxLength'   => 2000,
				],
				'bot_id'          => [
					'type'        => 'integer',
					'description' => __( 'Chat bot ID (see elasticpress-labs/chat-list-bots). Omit to use the first sync bot, or the first bot when none is sync.', 'elasticpress-labs' ),
					'minimum'     => 0,
				],
				'conversation_id' => [
					'type'        => 'integer',
					'description' => __( 'Conversation of an earlier answer, to ask a follow-up. Omit to start a new conversation.', 'elasticpress-labs' ),
					'minimum'     => 0,
				],
			],
			'required'             => [ 'question' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema of the ask ability.
	 *
	 * @return array
	 */
	protected function get_ask_output_schema(): array {
		$report = [
			'type'       => 'object',
			'properties' => [
				'title' => [ 'type' => 'string' ],
				'url'   => [ 'type' => 'string' ],
				'date'  => [ 'type' => 'string' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'status'          => [
					'type'        => 'string',
					'description' => __( 'done (answered), refused (declined), clarify (options to choose from), error, or queued (still running; the answer will be in Chat history).', 'elasticpress-labs' ),
					'enum'        => [ 'done', 'refused', 'clarify', 'error', 'queued' ],
				],
				'conversation_id' => [ 'type' => 'integer' ],
				'question_id'     => [ 'type' => 'integer' ],
				'answer'          => [
					'type'        => 'string',
					'description' => __( 'The answer (Markdown with [[k]] citation markers), the decline message, or the clarifying question.', 'elasticpress-labs' ),
				],
				'searched_for'    => [ 'type' => 'string' ],
				'citations'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'k'      => [ 'type' => 'integer' ],
							'source' => [ 'type' => 'integer' ],
							'title'  => [ 'type' => 'string' ],
							'url'    => [ 'type' => 'string' ],
							'date'   => [ 'type' => 'string' ],
							'figure' => [ 'type' => 'string' ],
							'quote'  => [ 'type' => 'string' ],
						],
					],
				],
				'sources'         => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'refusal'         => [
					'type'       => 'object',
					'properties' => [
						'reason'      => [ 'type' => 'string' ],
						'label'       => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
						'kind'        => [ 'type' => 'string' ],
						'next_step'   => [ 'type' => 'string' ],
					],
				],
				'related'         => [
					'type'  => 'array',
					'items' => $report,
				],
				'options'         => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'status', 'conversation_id', 'question_id', 'answer' ],
		];
	}

	/**
	 * Input schema of the search ability.
	 *
	 * @return array
	 */
	protected function get_search_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'query'  => [
					'type'        => 'string',
					'description' => __( 'What to search for.', 'elasticpress-labs' ),
					'minLength'   => 1,
					'maxLength'   => 500,
				],
				'bot_id' => [
					'type'        => 'integer',
					'description' => __( 'Chat bot whose search settings to use (see elasticpress-labs/chat-list-bots). Omit to use the first sync bot, or the first bot when none is sync.', 'elasticpress-labs' ),
					'minimum'     => 0,
				],
				'limit'  => [
					'type'        => 'integer',
					'description' => __( 'Most results (1 to 20). Default 10.', 'elasticpress-labs' ),
					'minimum'     => 1,
					'maximum'     => self::MAX_RESULTS,
				],
			],
			'required'             => [ 'query' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema of the search ability.
	 *
	 * @return array
	 */
	protected function get_search_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'results' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'title' => [ 'type' => 'string' ],
							'url'   => [ 'type' => 'string' ],
							'date'  => [ 'type' => 'string' ],
						],
					],
				],
			],
			'required'   => [ 'results' ],
		];
	}
}
