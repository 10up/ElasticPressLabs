<?php
/**
 * How a declined question is shown.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Injection_Defense_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Injection_Echo_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Each refusal code has a kind, and the kind decides the next step the card suggests and whether
 * it lists the reports that were found:
 *
 * - `outside`: the question is outside what the site covers (nothing was searched).
 * - `no_sources`: the search ran, and no passage answers the question.
 * - `withheld`: an answer was written, and a check withheld it.
 * - `as_asked`: the question cannot be answered as asked (planted instructions, advocacy).
 *
 * What the reader sees is decided when the answer is read, by the bot's "Declined questions"
 * setting: show the reason; hide it (no reason label, no "Why?", a generic message, no refusal
 * code in the reply); or show the same card for every decline.
 */
class Refusals {
	/**
	 * Outside what the site covers.
	 *
	 * @var string
	 */
	public const KIND_OUTSIDE = 'outside';

	/**
	 * Nothing to answer from.
	 *
	 * @var string
	 */
	public const KIND_NO_SOURCES = 'no_sources';

	/**
	 * Answer withheld by a check.
	 *
	 * @var string
	 */
	public const KIND_WITHHELD = 'withheld';

	/**
	 * Question not answerable as asked.
	 *
	 * @var string
	 */
	public const KIND_AS_ASKED = 'as_asked';

	/**
	 * Codes whose own message would tell the reader what was caught, so it is never shown.
	 *
	 * @var string[]
	 */
	protected const GENERIC_ONLY = [
		Injection_Echo_Gate::REASON,
		Injection_Defense_Gate::REASON,
	];

	/**
	 * Everything the card needs for a code: label, message, description, kind, next step, and
	 * whether it lists the reports found.
	 *
	 * @param string $code Refusal code.
	 * @return array{label: string, message: string, description: string, kind: string, next_step: string, related: bool}
	 */
	public static function describe( string $code ): array {
		$reasons = Gates::refusal_reasons();
		$row     = is_array( $reasons[ $code ] ?? null ) ? $reasons[ $code ] : [];
		$kind    = (string) ( $row['kind'] ?? '' );

		// A code that declares no kind (or an unknown one) is "nothing to answer from".
		if ( ! in_array( $kind, self::get_kinds(), true ) ) {
			$kind = self::KIND_NO_SOURCES;
		}

		$next_step = trim( (string) ( $row['next_step'] ?? '' ) );

		return [
			'label'       => trim( (string) ( $row['label'] ?? '' ) ),
			'message'     => trim( (string) ( $row['message'] ?? '' ) ),
			'description' => trim( (string) ( $row['description'] ?? '' ) ),
			'kind'        => $kind,
			'next_step'   => '' !== $next_step ? $next_step : self::get_next_step( $kind ),
			'related'     => in_array( $kind, [ self::KIND_NO_SOURCES, self::KIND_WITHHELD ], true ),
		];
	}

	/**
	 * The kinds.
	 *
	 * @return string[]
	 */
	public static function get_kinds(): array {
		return [ self::KIND_OUTSIDE, self::KIND_NO_SOURCES, self::KIND_WITHHELD, self::KIND_AS_ASKED ];
	}

	/**
	 * The next step a kind suggests, when its code sets none.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function get_next_step( string $kind ): string {
		$steps = [
			self::KIND_OUTSIDE    => __( 'You can ask about the topics this site publishes on.', 'elasticpress-labs' ),
			self::KIND_NO_SOURCES => __( 'Try different words or a broader question.', 'elasticpress-labs' ),
			self::KIND_WITHHELD   => __( 'Try a narrower question, or open one of the reports below.', 'elasticpress-labs' ),
			self::KIND_AS_ASKED   => __( 'Try asking what the research shows.', 'elasticpress-labs' ),
		];

		return $steps[ $kind ] ?? $steps[ self::KIND_NO_SOURCES ];
	}

	/**
	 * The message shown when the reason is not shown.
	 *
	 * @return string
	 */
	public static function get_generic_message(): string {
		return __( 'The assistant could not answer that question from the sources.', 'elasticpress-labs' );
	}

	/**
	 * The next step of the same card for every decline.
	 *
	 * @return string
	 */
	public static function get_uniform_next_step(): string {
		/**
		 * Filters the next step shown on every decline when a bot shows the same card for every
		 * decline.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_uniform_next_step
		 * @param {string} $next_step Next step.
		 * @return {string} Filtered next step.
		 */
		$next_step = apply_filters( 'ep_chat_uniform_next_step', __( 'Try rephrasing the question, or ask about another topic.', 'elasticpress-labs' ) );

		return is_string( $next_step ) ? $next_step : '';
	}

	/**
	 * How a bot shows declined questions: `show` (the reason), `hide` (no reason), or `uniform`
	 * (the same card for every decline).
	 *
	 * @param array $bot Chat bot.
	 * @return string
	 */
	public static function get_display( array $bot ): string {
		return (string) Settings::get( 'decline_display', $bot );
	}

	/**
	 * A declined (or clarify) answer as the reader sees it. Its payload gets `refusal`:
	 *
	 * - `show`: `kind`, `next_step`, `related`, `label`, `description`;
	 * - `hide`: `kind`, `next_step`, `related`; the code is removed and the message is generic;
	 * - `uniform`: the same `next_step` for every decline and `related` false; no code, no kind,
	 *   no reports, and the generic message.
	 *
	 * Reports are removed when the card does not list them. Other answers are returned as they
	 * are.
	 *
	 * @param array  $payload Answer payload.
	 * @param string $content Answer text.
	 * @param string $status  Question status.
	 * @param array  $bot     Chat bot the question was asked with.
	 * @return array{0: array, 1: string} Payload and text.
	 */
	public static function present( array $payload, string $content, string $status, array $bot ): array {
		if ( ! in_array( $status, [ Storage::STATUS_REFUSED, Storage::STATUS_CLARIFY ], true ) ) {
			return [ $payload, $content ];
		}

		$code    = (string) ( $payload['refusal_reason'] ?? '' );
		$info    = self::describe( $code );
		$display = self::get_display( $bot );
		$refusal = [
			'kind'      => $info['kind'],
			'next_step' => $info['next_step'],
			'related'   => $info['related'],
		];

		if ( 'uniform' === $display ) {
			$refusal = [
				'next_step' => self::get_uniform_next_step(),
				'related'   => false,
			];
		}

		if ( 'show' === $display ) {
			$refusal['label']       = $info['label'];
			$refusal['description'] = $info['description'];
		} else {
			unset( $payload['refusal_reason'] );
		}

		if ( Storage::STATUS_REFUSED === $status && ( 'show' !== $display || in_array( $code, self::GENERIC_ONLY, true ) ) ) {
			$content = self::get_generic_message();
		}

		if ( ! $refusal['related'] ) {
			$payload['hits'] = [];
		}

		$payload['refusal'] = $refusal;

		return [ $payload, $content ];
	}

	/**
	 * A pipeline result (a sync reply) as the reader sees it; see present().
	 *
	 * @param array $result Pipeline result: `status`, `content`, `meta`, `hits`, `bot`.
	 * @return array
	 */
	public static function present_result( array $result ): array {
		$status = (string) ( $result['status'] ?? '' );

		if ( ! in_array( $status, [ Storage::STATUS_REFUSED, Storage::STATUS_CLARIFY ], true ) ) {
			return $result;
		}

		$payload = is_array( $result['meta'] ?? null ) ? $result['meta'] : [];

		if ( ! isset( $payload['refusal_reason'] ) && isset( $result['refusal_reason'] ) ) {
			$payload['refusal_reason'] = (string) $result['refusal_reason'];
		}

		[ $payload, $content ] = self::present( $payload, (string) ( $result['content'] ?? '' ), $status, is_array( $result['bot'] ?? null ) ? $result['bot'] : [] );

		$result['meta']    = $payload;
		$result['content'] = $content;
		$result['refusal'] = $payload['refusal'];
		$result['hits']    = is_array( $payload['hits'] ?? null ) ? $payload['hits'] : [];

		if ( isset( $payload['refusal_reason'] ) ) {
			$result['refusal_reason'] = $payload['refusal_reason'];
		} else {
			unset( $result['refusal_reason'] );
		}

		return $result;
	}
}
