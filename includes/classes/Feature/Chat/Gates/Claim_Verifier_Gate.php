<?php
/**
 * Optional gate: a second model checks ranking and place claims against the passages.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Models;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Settings;
use ElasticPressLabs\Feature\Chat\Steps;
use ElasticPressLabs\Feature\Chat\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Claim_Verifier_Gate (the only model-based gate).
 *
 * The semantic layer over Superlative_Gate and Scope_Gate, for the claims a pattern cannot judge.
 * It runs only when the answer ranks something (see Superlative_Gate::has_ranking_claim()) or
 * the question names a place (see Scope_Gate::find_places()), and asks a second model whether
 * those claims are stated in the passages. It is optional (it calls an external model) and on
 * by default. It fails closed: when the check cannot be made (an error, a timeout, an
 * unreadable reply), the answer is not shown and the reader gets an error to retry.
 */
class Claim_Verifier_Gate extends Base_Gate {
	/**
	 * The model that checked the last answer, as `provider::model`.
	 *
	 * @var string
	 */
	protected $model_used = '';

	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'claim_verifier';

	/**
	 * Progress step while the second model checks the answer.
	 *
	 * @var string
	 */
	public const STEP = 'claim_verifier';

	/**
	 * Error code when the check cannot be made.
	 *
	 * @var string
	 */
	public const ERROR = 'ep_chat_verifier_failed';

	/**
	 * Most passages the verifier sees.
	 *
	 * @var int
	 */
	public const PASSAGES = 8;

	/**
	 * Default total characters of passage text the verifier sees.
	 *
	 * @var int
	 */
	public const PASSAGE_BUDGET = 24000;

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
		return esc_html__( 'Claim verifier (second model)', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'A second model checks the answer’s rankings and place claims against the sources, and declines it when they are not stated there. Adds a second model call (about 1 second).', 'elasticpress-labs' );
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
	 * Ask the second model whether the answer's ranking and place claims are in the passages.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$answer   = (string) ( $context['content'] ?? '' );
		$question = (string) ( $context['question'] ?? '' );
		$ranking  = Gates::get_gate( Superlative_Gate::class )->has_ranking_claim( $answer );
		$place    = (bool) Gates::get_gate( Scope_Gate::class )->find_places( $question );

		// An answer that declines (Refusal_Gate's test, whether or not that gate runs) has nothing
		// to verify.
		$triggered = ( $ranking || $place ) && '' !== trim( $answer ) && ! Gates::get_gate( Refusal_Gate::class )->is_declined( $answer );

		/**
		 * Filters whether the claim verifier checks this answer.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_triggered
		 * @param {bool}  $triggered Default: the answer has a ranking claim or the question names
		 *                           a place, and the answer is not a refusal.
		 * @param {array} $context   Pipeline context.
		 * @return {bool} Filtered value.
		 */
		if ( ! apply_filters( 'ep_chat_claim_verifier_triggered', $triggered, $context ) ) {
			return Gate_Result::pass();
		}

		Steps::report( self::STEP );

		$this->model_used = '';

		$verdict = $this->get_verdict( $context );

		if ( null === $verdict ) {
			return Gate_Result::error(
				self::ERROR,
				__( 'The answer could not be checked. Please try again.', 'elasticpress-labs' )
			);
		}

		if ( $verdict ) {
			// Kept with the answer, so the stored payload says which model checked it.
			return $this->model_used ? Gate_Result::modify( [ 'verifier_model' => $this->model_used ] ) : Gate_Result::pass();
		}

		$code = $ranking || ! $place ? Superlative_Gate::REASON : Scope_Gate::REASON;

		/**
		 * Fires when the claim verifier finds a ranking or place claim the passages do not state.
		 *
		 * For logging and evaluation; the answer is not stored or returned.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_not_supported
		 * @param {string} $code    Refusal code.
		 * @param {array}  $context Pipeline context, with the withheld answer in `content`.
		 */
		do_action( 'ep_chat_claim_not_supported', $code, $context );

		return $this->refuse( $code, $this->model_used ? [ 'verifier_model' => $this->model_used ] : [] );
	}

	/**
	 * The verifier's verdict.
	 *
	 * @param array $context Pipeline context.
	 * @return bool|null True when supported, false when not, null when the check failed.
	 */
	protected function get_verdict( array $context ): ?bool {
		$time_left = Pipeline::get_time_left( $context['deadline'] ?? null );

		// Someone is waiting and the time is up: fail closed, as on a timeout.
		if ( null !== $time_left && $time_left < 1 ) {
			$this->report_failure( new \WP_Error( 'ep_chat_verifier_no_time', 'No time left to check the answer.' ), $context );

			return null;
		}

		$messages = $this->get_messages( $context );

		/**
		 * Short-circuit the claim verifier (tests, other providers). Return true (supported),
		 * false (not supported), or a WP_Error (the check failed).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_verdict
		 * @param {null|bool|\WP_Error} $pre      Short-circuit value.
		 * @param {array}               $messages `system` and `user` messages.
		 * @param {array}               $context  Pipeline context.
		 * @return {null|bool|\WP_Error} Filtered value.
		 */
		$pre = apply_filters( 'ep_chat_claim_verifier_verdict', null, $messages, $context );

		if ( is_bool( $pre ) ) {
			return $pre;
		}

		if ( is_wp_error( $pre ) ) {
			$this->report_failure( $pre, $context );

			return null;
		}

		$reply = Models::generate(
			$messages['user'],
			[
				'system'      => $messages['system'],
				'models'      => $this->get_models( $context ),
				'temperature' => 0.0,
				'max_tokens'  => $this->get_max_tokens(),
				'timeout'     => $this->get_timeout( $time_left ),
				'json_schema' => [
					'type'       => 'object',
					'properties' => [ 'supported' => [ 'type' => 'boolean' ] ],
					'required'   => [ 'supported' ],
				],
				'error_code'  => 'ep_chat_verifier_exception',
			]
		);

		if ( is_wp_error( $reply ) ) {
			$this->report_failure( $reply, $context );

			return null;
		}

		$this->model_used = $reply['model'];
		$reply            = $reply['text'];

		$verdict = $this->parse( (string) $reply );

		if ( null === $verdict ) {
			$this->report_failure( new \WP_Error( 'ep_chat_verifier_unreadable', (string) $reply ), $context );
		}

		return $verdict;
	}

	/**
	 * `supported` from a reply: a JSON object, possibly in a code fence or after other text, or
	 * a reply cut off after the value (`{"supported": false`).
	 *
	 * @param string $reply Model reply.
	 * @return bool|null Null when the reply has no boolean `supported`.
	 */
	public function parse( string $reply ): ?bool {
		// The last object wins.
		$data = Text::get_last_json_object( $reply );

		if ( is_bool( $data['supported'] ?? null ) ) {
			return $data['supported'];
		}

		// A reply cut off after the value.

		if ( preg_match_all( '/"supported"\s*:\s*(true|false)\b/iu', $reply, $values ) ) {
			return 'true' === strtolower( (string) end( $values[1] ) );
		}

		return null;
	}

	/**
	 * System and user messages for the verifier.
	 *
	 * @param array $context Pipeline context.
	 * @return array{system: string, user: string}
	 */
	public function get_messages( array $context ): array {
		$passages = [];
		$budget   = $this->get_passage_budget();

		// Whole passages, those with the answer's figures first: the support for a claim is
		// often past the start of a passage made of several chunks.
		foreach ( array_slice( $this->order_passages( $context ), 0, self::PASSAGES ) as $text ) {
			if ( $budget <= 0 ) {
				break;
			}

			$text       = mb_substr( $text, 0, $budget, 'UTF-8' );
			$budget    -= mb_strlen( $text, 'UTF-8' );
			$passages[] = '- ' . $text;
		}

		$messages = [
			'system' => 'You verify ONLY the COMPARATIVE/RANKING and PLACE/GROUP-SCOPED claims in an answer. '
				. "Ranking: 'the smallest/largest/highest/lowest share', 'the only country', 'more than any other', 'ranks first'. "
				. 'Scoped: a figure attributed to a specific US state, country, or demographic. '
				. 'Question: are THOSE claims explicitly supported by the passages? '
				. 'Ignore tone, completeness, and plain numbers (checked elsewhere). '
				. 'Set supported=false ONLY when a specific ranking or place-scoped claim is clearly NOT stated in ANY passage — '
				. "e.g. the answer says 'the smallest share' but no passage ranks it lowest, or attributes a percentage to Ohio but no passage gives an Ohio figure. "
				. 'If the passages could reasonably support the claim, or you are unsure, set supported=true. '
				. 'Reply ONLY JSON: {"supported": true|false}.',
			'user'   => 'QUESTION: ' . (string) ( $context['question'] ?? '' ) . "\n"
				. 'ANSWER: ' . (string) ( $context['content'] ?? '' ) . "\n"
				. "PASSAGES:\n" . implode( "\n", $passages ) . "\nJSON:",
		];

		/**
		 * Filters the claim verifier's messages.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_messages
		 * @param {array} $messages `system` and `user`.
		 * @param {array} $context  Pipeline context.
		 * @return {array} Filtered messages.
		 */
		$filtered = apply_filters( 'ep_chat_claim_verifier_messages', $messages, $context );

		return [
			'system' => (string) ( $filtered['system'] ?? $messages['system'] ),
			'user'   => (string) ( $filtered['user'] ?? $messages['user'] ),
		];
	}

	/**
	 * Passage texts, the ones that contain a statistic of the answer first (in their order),
	 * then the others.
	 *
	 * @param array $context Pipeline context.
	 * @return string[]
	 */
	protected function order_passages( array $context ): array {
		$figures    = new Figures();
		$statistics = [];

		foreach ( $figures->locate( (string) ( $context['content'] ?? '' ) ) as $figure ) {
			if ( Figures::is_statistic( $figure ) ) {
				$statistics[] = $figure['figure'];
			}
		}

		$first = [];
		$rest  = [];

		foreach ( Text::get_passages( $context ) as $passage ) {
			$text     = Text::get_passage_text( $passage );
			$grounded = $figures->grounded( [ $text ] );
			$has      = (bool) array_filter(
				$statistics,
				function ( $statistic ) use ( $grounded ) {
					return isset( $grounded[ $statistic ] );
				}
			);

			if ( $has ) {
				$first[] = $text;
			} else {
				$rest[] = $text;
			}
		}

		return array_merge( $first, $rest );
	}

	/**
	 * Total characters of passage text the verifier sees.
	 *
	 * @return int
	 */
	protected function get_passage_budget(): int {
		/**
		 * Filters how many characters of passage text the claim verifier sees in all (whole
		 * passages, the ones with the answer's figures first, at most eight).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_passage_budget
		 * @param {int} $budget Characters. Default 24000.
		 * @return {int} Filtered characters.
		 */
		return max( 500, (int) apply_filters( 'ep_chat_claim_verifier_passage_budget', self::PASSAGE_BUDGET ) );
	}

	/**
	 * Preferred models for the verifier, most preferred first.
	 *
	 * @param array $context Pipeline context (for the selected bot).
	 * @return array Model IDs (or `[ provider, model ]` pairs). Empty for the default model.
	 */
	protected function get_models( array $context = [] ): array {
		$bot     = is_array( $context['bot'] ?? null ) ? $context['bot'] : [];
		$setting = Models::parse( (string) Settings::get( 'verifier_model', $bot ) );

		/**
		 * Filters the models the claim verifier prefers, most preferred first (the AI client's
		 * model preference: model IDs, or `[ provider ID, model ID ]` pairs). Empty uses the
		 * AI client's default model.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_model
		 * @param {array} $models Preferred models. Default: the bot's verifier model, else the
		 *                        Chat feature's, else the answer model.
		 * @param {array} $bot    Selected chat bot.
		 * @return {array} Filtered models.
		 */
		$models = apply_filters( 'ep_chat_claim_verifier_model', $setting ? [ $setting ] : [], $bot );

		return array_values( array_filter( is_array( $models ) ? $models : [ $models ] ) );
	}

	/**
	 * Most tokens the verifier's model may use. The reply is a tiny JSON object, but thinking
	 * models spend tokens before it: with a long prompt, Gemini flash needs about 1,024 (at 32
	 * it returns nothing, at 256 the JSON is cut off). Other models stop after the object.
	 *
	 * @return int
	 */
	protected function get_max_tokens(): int {
		/**
		 * Filters the most tokens the claim verifier's model may use.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_max_tokens
		 * @param {int} $max_tokens Tokens. Default 1024.
		 * @return {int} Filtered tokens.
		 */
		return max( 16, (int) apply_filters( 'ep_chat_claim_verifier_max_tokens', 1024 ) );
	}

	/**
	 * Seconds the verifier call may take: its timeout, or the time left for the answer when
	 * that is shorter.
	 *
	 * @param float|null $time_left Seconds left for the answer, or null without a limit.
	 * @return float
	 */
	protected function get_timeout( ?float $time_left = null ): float {
		/**
		 * Filters how long the claim verifier waits for its model.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_timeout
		 * @param {float} $timeout Seconds. Default 10.
		 * @return {float} Filtered seconds.
		 */
		$timeout = max( 1.0, (float) apply_filters( 'ep_chat_claim_verifier_timeout', 10.0 ) );

		return null === $time_left ? $timeout : max( 1.0, min( $timeout, $time_left ) );
	}

	/**
	 * Report a failed check.
	 *
	 * @param \WP_Error $error   Failure.
	 * @param array     $context Pipeline context.
	 * @return void
	 */
	protected function report_failure( \WP_Error $error, array $context ) {
		/**
		 * Fires when the claim verifier cannot check an answer (the answer is not shown).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_claim_verifier_failed
		 * @param {\WP_Error} $error   What failed.
		 * @param {array}     $context Pipeline context.
		 */
		do_action( 'ep_chat_claim_verifier_failed', $error, $context );
	}
}
