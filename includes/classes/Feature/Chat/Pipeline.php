<?php
/**
 * Same-request Chat execution path.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Result;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Runner;
use ElasticPressLabs\Feature\Chat\Gates\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * One question for a selected chat bot: the gates before the search, question intent, retrieve,
 * re-rank, the gates on the passages and the prompt, the answer, the gates on the answer, and
 * citations. Every outcome (an answer, a decline, clarifying options, or the reply about the
 * assistant) is shaped by finish().
 */
class Pipeline {
	/**
	 * No model has answered or checked yet.
	 *
	 * @var array{answer: string, verifier: string}
	 */
	protected const NO_MODELS = [
		'answer'   => '',
		'verifier' => '',
		'query'    => '',
	];

	/**
	 * Which models answered and checked this question and wrote its search query, as
	 * `provider::model` (empty when not known or not run).
	 *
	 * @var array{answer: string, verifier: string, query: string}
	 */
	protected $models = self::NO_MODELS;

	/**
	 * Which answer instructions wrote this answer: `source` (bot, feature, default, or none) and
	 * a short `hash` of the text. Null until an answer is written.
	 *
	 * @var array{source: string, hash: string}|null
	 */
	protected $instructions = null;

	/**
	 * What question intent read (`kind`, `model`, `fallback`), or null when it did not run.
	 *
	 * @var array|null
	 */
	protected $intent = null;

	/**
	 * The search plan, when it differs from searching the question as asked; else null.
	 *
	 * @var array|null
	 */
	protected $search = null;

	/**
	 * What the search ran (Retriever::get_last_search()): the algorithm, and for the AI query
	 * its query and any fallback.
	 *
	 * @var array
	 */
	protected $retrieval = [];

	/**
	 * The question as asked.
	 *
	 * @var string
	 */
	protected $question = '';

	/**
	 * The pipeline context so far, for `ep_chat_message_meta`.
	 *
	 * @var array
	 */
	protected $context = [];

	/**
	 * Every passage the search returned, before re-ranking and checks drop any: the reports a
	 * declined query can be clarified with.
	 *
	 * @var array
	 */
	protected $retrieved = [];

	/**
	 * Run the pipeline for one question (in the request, or in a background job).
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @return array|\WP_Error
	 */
	public function run( string $question, array $history, array $bot ) {
		$this->models       = self::NO_MODELS;
		$this->instructions = null;
		$this->intent       = null;
		$this->search       = null;
		$this->retrieval    = [];
		$this->question     = $question;
		$this->retrieved    = [];
		$this->context      = [];

		$runner  = new Gate_Runner();
		$context = [
			'question' => $question,
			'history'  => $history,
			'bot'      => $bot,
			'passages' => [],
			'prompt'   => '',
			'content'  => '',
		];

		/**
		 * Filters Chat context before retrieve.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_before_ask
		 * @param {array}  $context  Pipeline context.
		 * @param {string} $question User question.
		 * @param {array}  $bot      Selected chat bot.
		 * @return {array} Filtered context.
		 */
		$filtered = apply_filters( 'ep_chat_before_ask', $context, $question, $bot );
		$context  = is_array( $filtered ) ? $filtered : $context;

		Steps::report( Gate::STAGE_BEFORE_ASK );

		$this->context = $context;
		$stage         = $runner->run_stage( Gate::STAGE_BEFORE_ASK, $context, $bot );
		$stop          = $this->stop( $stage['result'], $bot, [] );

		if ( null !== $stop ) {
			return $stop;
		}

		$context       = $stage['context'];
		$this->context = $context;
		$question      = (string) $context['question'];
		$verdict       = $this->read_intent( $question, is_array( $context['history'] ?? null ) ? $context['history'] : $history, $bot );

		if ( $verdict && Intent::KIND_META === $verdict['kind'] ) {
			return $this->meta_reply( $bot );
		}

		if ( $verdict && Intent::KIND_GIBBERISH === $verdict['kind'] ) {
			return $this->refused( Gate_Result::refuse( Intent::REASON_UNCLEAR, '' ), $bot, [] );
		}

		if ( $verdict && ( Intent::KIND_OFFTOPIC === $verdict['kind'] || ! $verdict['on_topic'] ) ) {
			return $this->refused( Gate_Result::refuse( Intent::REASON_OFF_TOPIC, '' ), $bot, [] );
		}

		$plan              = $this->plan_search( $question, $verdict ? $verdict['query'] : $question, is_array( $context['history'] ?? null ) ? $context['history'] : $history, $bot );
		$context['search'] = $plan;
		$retriever         = new Retriever();

		Steps::report( Steps::RETRIEVE );

		$posts = $retriever->search( $plan['query'], $bot, $plan, $this->question );

		$this->retrieval       = $retriever->get_last_search();
		$this->models['query'] = (string) ( $this->retrieval['model'] ?? '' );

		// A failed search is an error the reader can retry, not a refusal.
		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$passages        = $this->to_passages( $posts );
		$this->retrieved = $passages;

		Steps::report( Steps::RERANK );

		// The re-ranker scores passages against what was searched for.
		$passages = ( new Reranker() )->apply( $passages, $plan['query'], $bot );

		/**
		 * Filters retrieved Chat passages before synthesis. Return the same
		 * shape to re-order, or an empty array to skip the LLM.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_rerank
		 * @param {array}  $passages Retrieved passages.
		 * @param {string} $question User question.
		 * @param {array}  $bot      Selected chat bot.
		 * @return {array} Filtered passages.
		 */
		$passages = apply_filters( 'ep_chat_rerank', $passages, $context['question'], $bot );

		/**
		 * Filters passages after re-rank.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_filter_passages
		 * @param {array}  $passages Retrieved passages.
		 * @param {string} $question User question.
		 * @param {array}  $bot      Selected chat bot.
		 * @return {array} Filtered passages.
		 */
		$passages = apply_filters( 'ep_chat_filter_passages', $passages, $context['question'], $bot );

		if ( ! is_array( $passages ) ) {
			$passages = [];
		}

		$context['passages'] = $passages;
		$this->context       = $context;

		Steps::report( Gate::STAGE_FILTER_PASSAGES );

		$stage = $runner->run_stage( Gate::STAGE_FILTER_PASSAGES, $context, $bot );
		$stop  = $this->stop( $stage['result'], $bot, is_array( $stage['context']['passages'] ?? null ) ? $stage['context']['passages'] : [] );

		if ( null !== $stop ) {
			return $stop;
		}

		$context  = $stage['context'];
		$passages = $this->number_passages( is_array( $context['passages'] ?? null ) ? $context['passages'] : [] );
		$client   = new Client();
		$history  = is_array( $context['history'] ?? null ) ? $context['history'] : $history;

		$context['passages'] = $passages;
		$context['prompt']   = $client->get_system_instruction( (string) $context['question'], $passages, $bot );
		$this->context       = $context;

		$stage = $runner->run_stage( Gate::STAGE_SYNTHESIS_PROMPT, $context, $bot );
		$stop  = $this->stop( $stage['result'], $bot, $passages );

		if ( null !== $stop ) {
			return $stop;
		}

		$context = $stage['context'];

		Steps::report( Steps::SYNTHESIZE );

		$result = $client->generate_text( (string) $context['question'], $history, $passages, $bot, (string) $context['prompt'] );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 502 ]
			);
		}

		$context['content']     = $result;
		$this->models['answer'] = $client->get_model_used();
		$this->instructions     = $this->describe_instructions( $bot );

		Steps::report( Gate::STAGE_VERIFY_ANSWER );

		/**
		 * Filters the Chat context before the gates that check the answer.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_verify_answer
		 * @param {array} $context Pipeline context, with the answer as `content`.
		 * @param {array} $bot     Selected chat bot.
		 * @return {array} Filtered context.
		 */
		$verified      = apply_filters( 'ep_chat_verify_answer', $context, $bot );
		$context       = is_array( $verified ) ? $verified : $context;
		$this->context = $context;
		$stage         = $runner->run_stage( Gate::STAGE_VERIFY_ANSWER, $context, $bot );

		$this->models['verifier'] = (string) ( $stage['context']['verifier_model'] ?? '' );

		$stop = $this->stop( $stage['result'], $bot, $passages );

		if ( null !== $stop ) {
			return $stop;
		}

		$context = $stage['context'];

		// Citations go on last, so every check above saw the answer the model wrote.
		Steps::report( Gate::STAGE_ATTRIBUTE_ANSWER );

		$stage = $runner->run_stage( Gate::STAGE_ATTRIBUTE_ANSWER, $context, $bot );
		$stop  = $this->stop( $stage['result'], $bot, $passages );

		if ( null !== $stop ) {
			return $stop;
		}

		$context = $stage['context'];

		// Notes a gate added for the reader (no figures), after the cited answer.
		$caveats = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $context['caveats'] ?? [] ) ) ) ) );

		if ( $caveats ) {
			$context['content'] = rtrim( (string) $context['content'] ) . "\n\n" . implode( "\n\n", array_map( static fn( $caveat ) => '_' . $caveat . '_', $caveats ) );
		}

		$payload = [
			'hits'         => is_array( $context['passages'] ?? null ) ? $context['passages'] : $passages,
			'provenance'   => is_array( $context['provenance'] ?? null ) ? array_values( $context['provenance'] ) : [],
			'models'       => $this->models,
			'instructions' => $this->instructions,
		];

		if ( $caveats ) {
			$payload['caveats'] = $caveats;
		}

		$this->context = $context;

		return $this->finish( Storage::STATUS_DONE, (string) ( $context['content'] ?? $result ), $payload, $bot );
	}

	/**
	 * Whether a gate stage stops the question: its error, or its decline (see refused()).
	 *
	 * @param Gate_Result $result Stage result.
	 * @param array       $bot    Selected chat bot.
	 * @param array       $hits   Passages a decline keeps.
	 * @return array|\WP_Error|null The outcome, or null to go on.
	 */
	protected function stop( Gate_Result $result, array $bot, array $hits ) {
		if ( $result->is_error() ) {
			return $result->to_wp_error();
		}

		return $result->is_refuse() ? $this->refused( $result, $bot, $hits ) : null;
	}

	/**
	 * An outcome as REST and storage take it, after `ep_chat_message_meta`.
	 *
	 * The payload gets the models and answer instructions when an answer was written (unless it
	 * has its own), and the question intent and search plan.
	 *
	 * @param string $status  Storage status: done, refused, or clarify.
	 * @param string $content Reply text.
	 * @param array  $payload Stored payload (`hits` and what the outcome adds).
	 * @param array  $bot     Selected chat bot.
	 * @return array `content`, `hits`, `bot`, `status`, `meta`, and `provenance` (an answer) or
	 *               `refusal_reason` (a decline or clarifying options).
	 */
	protected function finish( string $status, string $content, array $payload, array $bot ): array {
		// An answer that was written and then declined keeps which models wrote and checked it.
		if ( ! isset( $payload['models'] ) && array_filter( $this->models ) ) {
			$payload['models'] = $this->models;
		}

		if ( ! array_key_exists( 'instructions', $payload ) && null !== $this->instructions ) {
			$payload['instructions'] = $this->instructions;
		}

		$payload = $this->with_search( $payload );
		$reason  = isset( $payload['refusal_reason'] ) ? (string) $payload['refusal_reason'] : null;

		/**
		 * Filters stored answer payload / message meta.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_message_meta
		 * @param {array} $payload Stored payload.
		 * @param {array} $context Pipeline context so far, with `bot`, `status`, `content`, and
		 *                         for a decline `refusal_reason`.
		 * @return {array} Filtered payload.
		 */
		$filtered = apply_filters(
			'ep_chat_message_meta',
			$payload,
			array_merge(
				$this->context,
				[
					'bot'     => $bot,
					'status'  => $status,
					'content' => $content,
				],
				null === $reason ? [] : [ 'refusal_reason' => $reason ]
			)
		);
		$payload  = is_array( $filtered ) ? $filtered : $payload;
		$outcome  = [
			'content' => $content,
			'hits'    => is_array( $payload['hits'] ?? null ) ? $payload['hits'] : [],
			'bot'     => $bot,
			'status'  => $status,
			'meta'    => $payload,
		];

		if ( Storage::STATUS_DONE === $status ) {
			$outcome['provenance'] = is_array( $payload['provenance'] ?? null ) ? $payload['provenance'] : [];
		} else {
			$outcome['refusal_reason'] = (string) $reason;
		}

		return $outcome;
	}

	/**
	 * Shape a refusal for REST/storage.
	 *
	 * @param Gate_Result $result Gate refusal.
	 * @param array       $bot    Selected bot.
	 * @param array       $hits   Passages to keep, if any.
	 * @return array
	 */
	protected function refused( Gate_Result $result, array $bot, array $hits ): array {
		$reasons = Gates::refusal_reasons();
		$code    = $result->get_reason();
		$clarify = ( new Clarifier() )->clarify( $this->question, $code, $this->retrieved, $bot );

		if ( null !== $clarify ) {
			return $this->clarified( $clarify, $code, $bot );
		}

		if ( isset( $reasons[ $code ] ) ) {
			$message = '' !== $result->get_message() ? $result->get_message() : (string) ( $reasons[ $code ]['message'] ?? '' );
		} else {
			// Still refuse, but never with copy the vocabulary does not vouch for.
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: refusal code */
					esc_html__( 'The Chat refusal code "%s" is not declared. Declare it in the gate\'s get_refusal_reasons() or through the ep_chat_refusal_reasons filter.', 'elasticpress-labs' ),
					esc_html( $code )
				),
				'2.6.0'
			);
			$message = '';
		}

		if ( '' === $message ) {
			$message = Refusals::get_generic_message();
		}

		return $this->finish(
			Storage::STATUS_REFUSED,
			$message,
			[
				'hits'           => $hits,
				'refusal_reason' => $code,
			],
			$bot
		);
	}

	/**
	 * Read the intent of a question, when the bot has question intent on.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @return array|null The verdict (see Intent::classify()), or null when intent is off.
	 */
	protected function read_intent( string $question, array $history, array $bot ): ?array {
		if ( ! Settings::get( 'intent_enabled', $bot ) ) {
			return null;
		}

		Steps::report( Intent::STEP );

		$verdict      = ( new Intent() )->classify( $question, $history, $bot );
		$this->intent = [
			'kind'     => $verdict['kind'],
			'model'    => $verdict['model'],
			'fallback' => $verdict['fallback'],
		];

		return $verdict;
	}

	/**
	 * The search plan: what to search for (the question, or its rewrite) and the date limits read
	 * from the question, when the bot has Date range on.
	 *
	 * @param string $question User question.
	 * @param string $query    Search query (the question, or its rewrite).
	 * @param array  $history  Prior turns (the user's earlier questions vouch for years in the
	 *                         rewrite).
	 * @param array  $bot      Selected chat bot.
	 * @return array{query: string, published_after: string|null, recent: bool}
	 */
	protected function plan_search( string $question, string $query, array $history, array $bot ): array {
		$plan = [
			'query'           => $query,
			'published_after' => null,
			'recent'          => false,
		];

		if ( Settings::get( 'date_range_enabled', $bot ) ) {
			$earlier = [];

			foreach ( $history as $turn ) {
				if ( is_array( $turn ) && 'user' === ( $turn['role'] ?? '' ) ) {
					$earlier[] = (string) ( $turn['content'] ?? '' );
				}
			}

			$plan = array_merge( $plan, ( new Date_Range() )->read( $question, $query, $earlier ) );
		}

		/**
		 * Filters the Chat search plan: what is searched for and the date limits.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_search_plan
		 * @param {array}  $plan     `query` (the question, or its rewrite), `published_after`
		 *                           (`Y-m-d` or null: content published on or after it), and
		 *                           `recent` (bool: rank newer content higher).
		 * @param {string} $question User question.
		 * @param {array}  $bot      Selected chat bot.
		 * @return {array} Filtered plan.
		 */
		$filtered = apply_filters( 'ep_chat_search_plan', $plan, $question, $bot );
		$filtered = is_array( $filtered ) ? $filtered : $plan;
		$query    = trim( (string) ( $filtered['query'] ?? '' ) );
		$plan     = [ 'query' => '' !== $query ? $query : $question ] + Date_Range::clean( $filtered );

		$this->search = ( $plan['query'] !== $question || null !== $plan['published_after'] || $plan['recent'] ) ? $plan : null;

		return $plan;
	}

	/**
	 * A payload with what question intent read and how the search ran: the search plan, when it
	 * differs from searching the question as asked, and what the search ran (the algorithm, the
	 * AI-generated query, any fallback).
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	protected function with_search( array $payload ): array {
		if ( null !== $this->intent ) {
			$payload['intent'] = $this->intent;
		}

		$search = array_merge( (array) $this->search, array_diff_key( $this->retrieval, [ 'model' => true ] ) );

		if ( $search ) {
			$payload['search'] = $search;
		}

		return $payload;
	}

	/**
	 * The fixed reply to a question about the assistant itself: no search, no sources.
	 *
	 * @param array $bot Selected chat bot.
	 * @return array
	 */
	protected function meta_reply( array $bot ): array {
		return $this->finish(
			Storage::STATUS_DONE,
			( new Intent() )->get_meta_reply( $bot ),
			[
				'hits'       => [],
				'provenance' => [],
			],
			$bot
		);
	}

	/**
	 * A question about to be declined that gets clarifying options instead.
	 *
	 * @param array  $clarify `prompt`, `options`, `model`.
	 * @param string $code    Refusal code it replaces.
	 * @param array  $bot     Selected chat bot.
	 * @return array
	 */
	protected function clarified( array $clarify, string $code, array $bot ): array {
		return $this->finish(
			Storage::STATUS_CLARIFY,
			$clarify['prompt'],
			[
				'hits'           => $this->retrieved,
				'refusal_reason' => $code,
				'clarify'        => [
					'prompt'  => $clarify['prompt'],
					'options' => $clarify['options'],
					'model'   => $clarify['model'],
				],
			],
			$bot
		);
	}

	/**
	 * Where the answer instructions came from, and a short hash of the text, so an answer can be
	 * traced to the instructions that wrote it.
	 *
	 * @param array $bot Selected chat bot.
	 * @return array{source: string, hash: string}
	 */
	protected function describe_instructions( array $bot ): array {
		$text = (string) Settings::get( 'answer_instructions', $bot );

		return [
			'source' => Settings::get_source( 'answer_instructions', $bot ),
			'hash'   => '' === $text ? '' : substr( md5( $text ), 0, 8 ),
		];
	}

	/**
	 * Shape posts as prompt/REST passages.
	 *
	 * A post found by semantic search becomes its best matching chunks (see
	 * get_matched_chunks()), joined in reading order; other posts become their excerpt. The
	 * words sent across all passages are capped (see get_word_budget()): over the cap, the
	 * lowest-ranked posts lose their extra chunks first, and every post keeps its best one.
	 *
	 * @param array $posts Retrieved posts, best first.
	 * @return array
	 */
	public function to_passages( array $posts ): array {
		$passages = [];
		$chunks   = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$matched = $this->get_matched_chunks( $post );
			$excerpt = '';

			if ( ! $matched ) {
				$text = trim( (string) $post->post_excerpt );

				if ( '' === $text ) {
					$text = (string) $post->post_content;
				}

				$excerpt = $this->to_plain_text( wp_trim_words( wp_strip_all_tags( $text ), 80, '…' ) );
			}

			// Plain text, not HTML: the model reads it, and Ask renders it as text.
			$passages[] = [
				'id'      => (int) $post->ID,
				'title'   => $this->to_plain_text( get_the_title( $post ) ),
				'url'     => get_permalink( $post ),
				'date'    => (string) get_the_date( '', $post ),
				'excerpt' => $excerpt,
			];
			$chunks[]   = $matched;
		}

		$chunks = $this->fit_word_budget( $chunks, $passages );

		foreach ( $passages as $index => $passage ) {
			if ( ! $chunks[ $index ] ) {
				continue;
			}

			// Reading order: the position of each chunk in the post.
			$kept = $chunks[ $index ];
			usort(
				$kept,
				function ( $a, $b ) {
					return $a['offset'] <=> $b['offset'];
				}
			);

			// Chunks are plain text already capped at the chunk size; their lines (context line,
			// headings, Markdown tables, `## key: value` meta lines) are kept.
			$passages[ $index ]['excerpt'] = implode( "\n\n", wp_list_pluck( $kept, 'text' ) );
		}

		return $passages;
	}

	/**
	 * The chunks of a post that matched the question, best first, of the passage chunk types.
	 *
	 * @since 2.6.0
	 * @param \WP_Post $post Retrieved post.
	 * @return array[] `[ 'text' => string, 'offset' => int, 'words' => int ]`. Empty when the
	 *                 search returned none (not a semantic search, or no chunk of those types).
	 */
	protected function get_matched_chunks( \WP_Post $post ): array {
		$matched = isset( $post->matched_chunks ) && is_array( $post->matched_chunks ) ? $post->matched_chunks : [];
		$types   = array_flip( $this->get_passage_chunk_types() );
		$chunks  = [];

		foreach ( $matched as $chunk ) {
			$text = is_array( $chunk ) ? trim( (string) ( $chunk['text'] ?? '' ) ) : '';

			if ( '' === $text || ! isset( $types[ (string) ( $chunk['type'] ?? '' ) ] ) ) {
				continue;
			}

			$chunks[] = [
				'text'   => $text,
				'offset' => (int) ( $chunk['offset'] ?? count( $chunks ) ),
				'words'  => $this->count_words( $text ),
			];
		}

		/**
		 * Filters how many matching chunks of one post make its passage.
		 *
		 * Semantic search returns up to `ep_semantic_search_matched_chunks` (default 3) per post.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_passage_chunks
		 * @param {int}      $count Chunks per passage. Default: the Chat feature's Chunks per report (3).
		 * @param {\WP_Post} $post  Retrieved post.
		 * @return {int} Filtered count (at least 1).
		 */
		$count = max( 1, (int) apply_filters( 'ep_chat_passage_chunks', (int) Settings::get( 'passage_chunks' ), $post ) );

		return array_slice( $chunks, 0, $count );
	}

	/**
	 * Chunk types that can be passage text.
	 *
	 * @return string[]
	 */
	protected function get_passage_chunk_types(): array {
		/**
		 * Filters which chunk types can be passage text. `content` is the title, summary, and
		 * content; `meta` is the embedded meta fields an admin chose (text such as a summary or
		 * a dek); `taxonomy` lists term labels, left out by default.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_passage_chunk_types
		 * @param {string[]} $types Chunk types. Default `content` and `meta`.
		 * @return {string[]} Filtered types.
		 */
		$types = apply_filters( 'ep_chat_passage_chunk_types', [ 'content', 'meta' ] );

		return array_values( array_map( 'strval', is_array( $types ) ? $types : [] ) );
	}

	/**
	 * Drop extra chunks until all passages fit the word budget: from the lowest-ranked post
	 * up, its worst matching chunk first. Every post keeps its best chunk.
	 *
	 * @param array[] $chunks   Matched chunks per passage, best first.
	 * @param array[] $passages Passages (for the words of excerpt-only passages).
	 * @return array[] Chunks per passage.
	 */
	protected function fit_word_budget( array $chunks, array $passages ): array {
		$budget = $this->get_word_budget();

		if ( $budget <= 0 ) {
			return $chunks;
		}

		$total = 0;

		foreach ( $passages as $index => $passage ) {
			$total += $chunks[ $index ]
				? array_sum( wp_list_pluck( $chunks[ $index ], 'words' ) )
				: $this->count_words( (string) $passage['excerpt'] );
		}

		for ( $index = count( $chunks ) - 1; $index >= 0 && $total > $budget; $index-- ) {
			// Keep the first (best) chunk.
			while ( $total > $budget && isset( $chunks[ $index ][1] ) ) {
				$dropped = array_pop( $chunks[ $index ] );
				$total  -= $dropped['words'];
			}
		}

		return $chunks;
	}

	/**
	 * Most words sent across all passages of one question.
	 *
	 * @return int 0 for no cap.
	 */
	protected function get_word_budget(): int {
		/**
		 * Filters the most words sent to the model across all passages of one question. Over the
		 * budget, the lowest-ranked posts lose their extra chunks first; each post keeps its best
		 * chunk, so the total can stay above a small budget.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_passage_word_budget
		 * @param {int} $budget Words. Default: the Chat feature's Word budget (6000). 0 for no cap.
		 * @return {int} Filtered budget.
		 */
		return max( 0, (int) apply_filters( 'ep_chat_passage_word_budget', (int) Settings::get( 'passage_word_budget' ) ) );
	}

	/**
	 * Words in a text.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	protected function count_words( string $text ): int {
		return (int) preg_match_all( '/\S+/u', $text );
	}

	/**
	 * Number the passages the model sees, from 1. The number is stored with
	 * each hit, so an answer's `[[N]]` marker and the Sources rail agree even
	 * if later steps drop or reorder hits.
	 *
	 * @param array $passages Passages.
	 * @return array Passages with `number`.
	 */
	protected function number_passages( array $passages ): array {
		$numbered = [];

		foreach ( $passages as $passage ) {
			if ( is_array( $passage ) ) {
				$passage['number'] = count( $numbered ) + 1;
				$numbered[]        = $passage;
			}
		}

		return $numbered;
	}

	/**
	 * Decode HTML entities such as `&amp;` and `&#8217;` into plain text.
	 *
	 * @param string $text Text that may contain entities.
	 * @return string
	 */
	protected function to_plain_text( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
