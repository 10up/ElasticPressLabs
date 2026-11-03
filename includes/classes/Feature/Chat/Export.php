<?php
/**
 * Chat export.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Conversations as JSON for an audit: an envelope with who exported what and when, then every
 * conversation the History filters match, with its questions and answers.
 *
 * Like core's `export_wp()`, the query loads IDs only, and conversations are read and written
 * in chunks, so memory stays flat however many there are. Each object is written (and flushed)
 * as soon as it is encoded.
 */
class Export {
	/**
	 * Version of the JSON shape. Bump it when a field changes meaning or is removed.
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Conversations read per query.
	 *
	 * @var int
	 */
	public const CHUNK = 50;

	/**
	 * Where the JSON goes: `echo` and flush by default.
	 *
	 * @var callable
	 */
	protected $write;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param callable|null $write Called with each piece of JSON. Default: echo and flush.
	 */
	public function __construct( ?callable $write = null ) {
		$this->write   = $write ? $write : [ $this, 'echo_and_flush' ];
		$this->storage = new Storage();
	}

	/**
	 * Write the export.
	 *
	 * @param array $filters    History filters (`view`, `author`, `m`, `s`).
	 * @param bool  $see_all    Whether the exporting user sees everyone's conversations.
	 * @param int   $user_id    Exporting user (0 for WP-CLI without a user).
	 * @return int Conversations written.
	 */
	public function stream( array $filters, bool $see_all, int $user_id ): int {
		$query = new \WP_Query(
			History_List_Table::build_query_args(
				$filters,
				$see_all,
				$user_id,
				[
					'fields'                 => 'ids',
					'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPageNoUnlimited.posts_per_page_posts_per_page -- IDs only; posts are read in chunks.
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			)
		);

		$user     = $user_id ? get_userdata( $user_id ) : false;
		$envelope = [
			'schema_version' => self::SCHEMA_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'exported_by'    => $user ? [
				'id'    => (int) $user->ID,
				'login' => $user->user_login,
			] : null,
			'site'           => home_url( '/' ),
			'plugin_version' => defined( 'ELASTICPRESS_LABS_VERSION' ) ? ELASTICPRESS_LABS_VERSION : '',
			'filters'        => $this->describe_filters( $filters ),
		];

		// The envelope without its closing brace, then the conversations, then the count.
		$this->write( substr( $this->encode( $envelope ), 0, -1 ) . ',"conversations":[' );

		$count = 0;

		foreach ( array_chunk( array_map( 'intval', $query->posts ), self::CHUNK ) as $ids ) {
			foreach ( $this->describe_chunk( $ids ) as $conversation ) {
				$this->write( ( $count ? ',' : '' ) . $this->encode( $conversation ) );
				++$count;
			}
		}

		$this->write( '],"count":' . $count . '}' );

		return $count;
	}

	/**
	 * The file name of a download.
	 *
	 * @return string
	 */
	public static function get_filename(): string {
		$site = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return sprintf( 'ai-chat-export-%s-%s.json', $site ? $site : 'site', gmdate( 'Y-m-d' ) );
	}

	/**
	 * Conversations of one chunk, with their turns (one comment query for the chunk).
	 *
	 * @param int[] $ids Conversation post IDs, in export order.
	 * @return array[]
	 */
	protected function describe_chunk( array $ids ): array {
		_prime_post_caches( $ids, false, false );

		$comments = get_comments(
			[
				'post__in' => $ids,
				'type__in' => [ Storage::COMMENT_TYPE_QUESTION, Storage::COMMENT_TYPE_ANSWER ],
				// Turns of a trashed conversation are `post-trashed`.
				'status'   => [ 'approve', 'post-trashed' ],
				'orderby'  => 'comment_ID',
				'order'    => 'ASC',
			]
		);

		$by_post = [];

		foreach ( $comments as $comment ) {
			$by_post[ (int) $comment->comment_post_ID ][] = $comment;
		}

		$conversations = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post ) {
				$conversations[] = $this->describe_conversation( $post, $by_post[ $id ] ?? [] );
			}
		}

		// Keep memory flat between chunks.
		wp_cache_flush_runtime();

		return $conversations;
	}

	/**
	 * One conversation.
	 *
	 * @param \WP_Post      $post     Conversation.
	 * @param \WP_Comment[] $comments Its turns, in order.
	 * @return array
	 */
	protected function describe_conversation( \WP_Post $post, array $comments ): array {
		$summary = Summarizer::read( (string) $post->post_content );

		$conversation = [
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'summary'  => '' === $summary['text'] ? null : $summary,
			'author'   => $this->describe_user( (int) $post->post_author ),
			'created'  => $this->to_iso( $post->post_date_gmt ),
			'modified' => $this->to_iso( $post->post_modified_gmt ),
			'status'   => 'trash' === $post->post_status ? 'trashed' : 'open',
			'turns'    => array_map( [ $this, 'describe_turn' ], $comments ),
		];

		/**
		 * Filters one conversation in the Chat export.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_export_conversation
		 * @param {array}    $conversation Conversation.
		 * @param {\WP_Post} $post         Conversation post.
		 * @return {array} Filtered conversation.
		 */
		return apply_filters( 'ep_chat_export_conversation', $conversation, $post );
	}

	/**
	 * One question or answer.
	 *
	 * @param \WP_Comment $comment Turn.
	 * @return array
	 */
	protected function describe_turn( \WP_Comment $comment ): array {
		$turn = [
			'id'   => (int) $comment->comment_ID,
			'role' => Storage::COMMENT_TYPE_QUESTION === $comment->comment_type ? 'user' : 'assistant',
			'date' => $this->to_iso( $comment->comment_date_gmt ),
		];

		if ( 'user' === $turn['role'] ) {
			$bot_id = (int) get_comment_meta( $comment->comment_ID, Storage::META_BOT_ID, true );
			$bot    = $bot_id ? get_post( $bot_id ) : null;

			return $turn + [
				'author' => $this->describe_user( (int) $comment->user_id ),
				'text'   => $comment->comment_content,
				'bot'    => $bot && Bots::POST_TYPE === $bot->post_type ? [
					'id'   => $bot_id,
					'name' => $bot->post_title,
				] : null,
				'status' => $this->describe_status( (string) get_comment_meta( $comment->comment_ID, Storage::META_STATUS, true ), (int) $comment->comment_ID ),
			];
		}

		$payload = get_comment_meta( $comment->comment_ID, Storage::META_PAYLOAD, true );
		$payload = is_array( $payload ) ? $payload : [];
		$status  = (string) get_comment_meta( (int) $comment->comment_parent, Storage::META_STATUS, true );
		$outcome = [
			Storage::STATUS_DONE    => 'answered',
			Storage::STATUS_REFUSED => 'refused',
			Storage::STATUS_CLARIFY => 'clarify',
		];

		return $turn + [
			'in_reply_to'       => (int) $comment->comment_parent ? (int) $comment->comment_parent : null,
			'text'              => $comment->comment_content,
			'outcome'           => $outcome[ $status ] ?? 'error',
			'refusal_reason'    => isset( $payload['refusal_reason'] ) ? (string) $payload['refusal_reason'] : null,
			'error_code'        => Storage::STATUS_ERROR === $status ? $this->get_error_code( (int) $comment->comment_ID ) : null,
			'models'            => is_array( $payload['models'] ?? null ) ? array_map( static fn( $model ) => '' === $model ? null : $model, $payload['models'] ) : null,
			'instructions'      => $payload['instructions'] ?? null,
			'intent'            => is_array( $payload['intent'] ?? null ) ? $payload['intent'] : null,
			'search'            => is_array( $payload['search'] ?? null ) ? $payload['search'] : null,
			'clarify'           => is_array( $payload['clarify'] ?? null ) ? [
				'prompt'  => (string) ( $payload['clarify']['prompt'] ?? '' ),
				'options' => array_values( array_map( 'strval', (array) ( $payload['clarify']['options'] ?? [] ) ) ),
			] : null,
			'citations'         => $this->describe_citations( (array) ( $payload['provenance'] ?? [] ), (array) ( $payload['hits'] ?? [] ) ),
			'sources_retrieved' => $this->describe_sources( (array) ( $payload['hits'] ?? [] ) ),
		];
	}

	/**
	 * Cited sources, one per marker: what the answer quotes and where it comes from.
	 *
	 * @param array $provenance Payload provenance (`n` is the source's number among the hits).
	 * @param array $hits       Payload hits.
	 * @return array[]
	 */
	protected function describe_citations( array $provenance, array $hits ): array {
		$post_ids = [];

		foreach ( $hits as $index => $hit ) {
			if ( is_array( $hit ) && isset( $hit['id'] ) ) {
				$post_ids[ (int) ( $hit['number'] ?? $index + 1 ) ] = (int) $hit['id'];
			}
		}

		$citations = [];

		foreach ( $provenance as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$citations[] = [
				'k'       => isset( $item['k'] ) ? (int) $item['k'] : null,
				'post_id' => $post_ids[ (int) ( $item['n'] ?? 0 ) ] ?? null,
				'title'   => isset( $item['title'] ) ? (string) $item['title'] : null,
				'url'     => isset( $item['url'] ) ? (string) $item['url'] : null,
				'date'    => isset( $item['date'] ) ? (string) $item['date'] : null,
				'figure'  => isset( $item['figure'] ) ? (string) $item['figure'] : null,
				'quote'   => isset( $item['quote'] ) ? (string) $item['quote'] : null,
				'notes'   => Sources::get_notes( (int) ( $post_ids[ (int) ( $item['n'] ?? 0 ) ] ?? 0 ) ),
			];
		}

		return $citations;
	}

	/**
	 * Retrieved sources: IDs and URLs only.
	 *
	 * @param array $hits Payload hits.
	 * @return array[]
	 */
	protected function describe_sources( array $hits ): array {
		$sources = [];

		foreach ( $hits as $hit ) {
			if ( is_array( $hit ) && isset( $hit['id'] ) ) {
				$sources[] = [
					'post_id' => (int) $hit['id'],
					'url'     => isset( $hit['url'] ) ? (string) $hit['url'] : null,
				];
			}
		}

		return $sources;
	}

	/**
	 * A user, without the email address.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	protected function describe_user( int $user_id ): ?array {
		if ( ! $user_id ) {
			return null;
		}

		$user = get_userdata( $user_id );

		return [
			'id'           => $user_id,
			'login'        => $user ? $user->user_login : null,
			'display_name' => $user ? $user->display_name : null,
		];
	}

	/**
	 * A question status for the export: `done`, `refused`, `error`, `timed_out`, or (still
	 * running) `queued` / `running`.
	 *
	 * @param string $status      Stored status.
	 * @param int    $question_id Question comment ID.
	 * @return string
	 */
	protected function describe_status( string $status, int $question_id ): string {
		if ( Storage::STATUS_ERROR === $status && $this->storage->is_timed_out( $question_id ) ) {
			return 'timed_out';
		}

		return '' === $status ? Storage::STATUS_DONE : $status;
	}

	/**
	 * An answer's error code, if any (the reaper stores it on the timeout answer).
	 *
	 * @param int $answer_id Answer comment ID.
	 * @return string|null
	 */
	protected function get_error_code( int $answer_id ): ?string {
		$code = (string) get_comment_meta( $answer_id, Storage::META_ERROR_CODE, true );

		return '' === $code ? null : $code;
	}

	/**
	 * The filters, as written in the envelope.
	 *
	 * @param array $filters History filters.
	 * @return array
	 */
	protected function describe_filters( array $filters ): array {
		return [
			'view'   => 'trash' === ( $filters['view'] ?? '' ) ? 'trash' : 'all',
			'author' => absint( $filters['author'] ?? 0 ) ? absint( $filters['author'] ) : null,
			'month'  => preg_match( '/^\d{6}$/', (string) ( $filters['m'] ?? '' ) ) ? (string) $filters['m'] : null,
			'search' => '' !== trim( (string) ( $filters['s'] ?? '' ) ) ? trim( (string) $filters['s'] ) : null,
		];
	}

	/**
	 * A GMT MySQL date as ISO 8601 (UTC), or null.
	 *
	 * @param string $gmt GMT date.
	 * @return string|null
	 */
	protected function to_iso( string $gmt ): ?string {
		return $gmt && '0000-00-00 00:00:00' !== $gmt ? gmdate( 'c', (int) strtotime( $gmt . ' UTC' ) ) : null;
	}

	/**
	 * JSON for one piece.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	protected function encode( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Write a piece of JSON.
	 *
	 * @param string $json JSON.
	 * @return void
	 */
	protected function write( string $json ) {
		call_user_func( $this->write, $json );
	}

	/**
	 * Default writer.
	 *
	 * @param string $json JSON.
	 * @return void
	 */
	public function echo_and_flush( string $json ) {
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		flush();
	}
}
