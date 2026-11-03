<?php
/**
 * Gate (optional, on by default): decline answers that say they cannot answer.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Refusals;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Refusal_Gate (deterministic).
 *
 * Makes the model's own "the sources do not answer this" authoritative: such a reply is a typed
 * refusal, not an answer. It declines when the model replies with the self-report token (see
 * get_instructions()), when the first sentence contains a refusal phrase, or when a short answer
 * contains one anywhere. Checking only the first sentence of longer answers keeps a real answer
 * that mentions "does not address" in passing, and an answer with statistics is a partial answer
 * ("the sources have no overall figure, but 62% of…"), not a refusal: the phrases only decline
 * answers without them. This gate does not check those statistics; Figure_Match_Gate does, when
 * it runs.
 */
class Refusal_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'refusal';

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	public const REASON = 'answer_does_not_address_question';

	/**
	 * Reply the model gives when the sources do not answer the question.
	 *
	 * @var string
	 */
	public const TOKEN = 'NO_ANSWER';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_label(): string {
		return esc_html__( 'Refusal', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Shows a clear “could not answer” when the model says the sources do not answer the question.', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_stage(): string {
		return self::STAGE_VERIFY_ANSWER;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool {
		return true;
	}

	/**
	 * Decline an answer that reports it cannot answer.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$answer = (string) ( $context['content'] ?? '' );

		if ( ! $this->is_declined( $answer ) ) {
			return Gate_Result::pass();
		}

		/**
		 * Fires when an answer is declined because it reports that the sources do not answer
		 * the question.
		 *
		 * For logging and evaluation; the answer is not stored or returned.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_answer_declined
		 * @param {array} $context Pipeline context, with the declined answer in `content`.
		 */
		do_action( 'ep_chat_answer_declined', $context );

		return $this->refuse( self::REASON );
	}

	/**
	 * Whether an answer reports that it cannot answer.
	 *
	 * @param string $answer Answer text.
	 * @return bool
	 */
	public function is_declined( string $answer ): bool {
		$text = $this->to_plain( $answer );

		if ( '' === $text ) {
			return false;
		}

		if ( 0 === strpos( $text, self::TOKEN ) ) {
			return true;
		}

		// Statistics make it a partial answer: it says what is missing, then answers the rest.
		if ( $this->has_statistics( $text ) ) {
			return false;
		}

		$lower   = mb_strtolower( $text, 'UTF-8' );
		$limits  = $this->get_limits();
		$phrases = $this->get_phrases();

		$first = preg_split( '/(?<=[.!?])\s+/u', $lower, 2 );
		$first = mb_substr( (string) ( $first[0] ?? '' ), 0, $limits['first_sentence'], 'UTF-8' );

		if ( $this->contains_phrase( $first, $phrases ) ) {
			return true;
		}

		return mb_strlen( $text, 'UTF-8' ) < $limits['short_answer'] && $this->contains_phrase( $lower, $phrases );
	}

	/**
	 * Refusal phrases, lowercase.
	 *
	 * @return string[]
	 */
	public function get_phrases(): array {
		$phrases = [
			'do not contain',
			'does not contain',
			"doesn't contain",
			'no information about',
			"hasn't published",
			'has not published',
			'have not published',
			'do not provide',
			'does not provide',
			'cannot answer',
			"can't answer",
			'no relevant information',
			'not directly address',
			'does not directly address',
			'does not address',
			'do not address',
			'does not specifically address',
			'not specifically address',
			'no specific information',
			'not enough information',
			'insufficient information',
			'is not covered',
		];

		/**
		 * Filters the phrases that mark an answer as a refusal (matched lowercase, with straight
		 * apostrophes) in its first sentence, or anywhere in a short answer.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_refusal_phrases
		 * @param {string[]} $phrases Phrases.
		 * @return {string[]} Filtered phrases.
		 */
		$phrases = apply_filters( 'ep_chat_refusal_phrases', $phrases );

		return array_values(
			array_filter(
				array_map(
					function ( $phrase ) {
						return mb_strtolower( trim( (string) $phrase ), 'UTF-8' );
					},
					is_array( $phrases ) ? $phrases : []
				),
				'strlen'
			)
		);
	}

	/**
	 * How much of the answer is checked for phrases.
	 *
	 * @return array{first_sentence: int, short_answer: int}
	 */
	public function get_limits(): array {
		$limits = [
			'first_sentence' => 220,
			'short_answer'   => 200,
		];

		/**
		 * Filters how much of an answer Refusal_Gate checks: the first sentence is cut to
		 * `first_sentence` characters, and an answer under `short_answer` characters is
		 * checked whole.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_refusal_limits
		 * @param {array} $limits `first_sentence` and `short_answer`, in characters.
		 * @return {array} Filtered limits.
		 */
		$filtered = apply_filters( 'ep_chat_refusal_limits', $limits );

		return [
			'first_sentence' => max( 0, (int) ( $filtered['first_sentence'] ?? $limits['first_sentence'] ) ),
			'short_answer'   => max( 0, (int) ( $filtered['short_answer'] ?? $limits['short_answer'] ) ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return [
			self::REASON => [
				'label'       => __( 'Not answered by the sources', 'elasticpress-labs' ),
				'message'     => __( 'No sources found to answer that question.', 'elasticpress-labs' ),
				'description' => __( 'An answer was written, but it said that the sources found do not answer this question, so it is not shown as an answer.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_NO_SOURCES,
			],
		];
	}

	/**
	 * Ask the model to say plainly when the sources do not answer, so the gate does not rely
	 * on phrasing alone.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [
			sprintf(
				/* translators: %s: reply token, NO_ANSWER */
				__( 'Answer the parts of the question the sources cover, and say plainly what they do not cover. If the sources cover none of it, reply with exactly %s and nothing else.', 'elasticpress-labs' ),
				self::TOKEN
			),
		];
	}

	/**
	 * Answer text without Markdown bold / code marks or leading list and heading marks, with
	 * straight apostrophes. Underscores stay: they are part of the token.
	 *
	 * @param string $answer Answer.
	 * @return string
	 */
	protected function to_plain( string $answer ): string {
		$text = str_replace( [ '’', '‘' ], "'", $answer );
		$text = (string) preg_replace( '/[*`]+/u', '', $text );
		$text = (string) preg_replace( '/^\s*(?:#{1,6}|[-+>]|\d+[.)])\s+/mu', '', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether a text contains a refusal phrase ("the sources do not contain…").
	 *
	 * @param string $text Text, such as one sentence of an answer.
	 * @return bool
	 */
	public function has_refusal_phrase( string $text ): bool {
		return $this->contains_phrase( mb_strtolower( $this->to_plain( $text ), 'UTF-8' ), $this->get_phrases() );
	}

	/**
	 * Whether a text states a statistic (a percentage, points, a magnitude, a count of 1,000 or
	 * more, or a decimal). Years and small counts do not count.
	 *
	 * @param string $text Answer text.
	 * @return bool
	 */
	protected function has_statistics( string $text ): bool {
		foreach ( ( new Figures() )->locate( $text ) as $figure ) {
			if ( Figures::is_statistic( $figure ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a text contains any phrase.
	 *
	 * @param string   $text    Lowercase text.
	 * @param string[] $phrases Phrases.
	 * @return bool
	 */
	protected function contains_phrase( string $text, array $phrases ): bool {
		foreach ( $phrases as $phrase ) {
			if ( false !== strpos( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}
}
