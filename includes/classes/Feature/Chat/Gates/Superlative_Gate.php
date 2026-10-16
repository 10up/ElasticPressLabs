<?php
/**
 * Gate (optional, on by default): a ranking or superlative must be stated by the sources.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Refusals;
use ElasticPressLabs\Feature\Chat\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Superlative_Gate (deterministic).
 *
 * Each rule pairs a ranking an answer can make ("the largest share", "the only country",
 * "more than any other") with the comparative wording a passage must carry to support it. The
 * support must be in the passage that grounds a statistic of the same answer sentence, or, for
 * a sentence without statistics, in a passage sentence that shares at least MIN_SHARED_WORDS
 * content words with it. The first rule that matches without support refuses the answer.
 * "Most" and "least" alone are ordinary wording ("most Americans") and are not checked here;
 * has_ranking_claim() also flags them, for the claim verifier.
 */
class Superlative_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'superlative';

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	public const REASON = 'comparison_not_in_sources';

	/**
	 * Content words a passage sentence shares with an answer sentence without statistics to
	 * count as its source.
	 *
	 * @var int
	 */
	public const MIN_SHARED_WORDS = 3;

	/**
	 * Any ranking claim, for the claim verifier (the reference's RANKING_RE and THAN_ANY_RE).
	 *
	 * @var string[]
	 */
	protected const RANKING_CLAIMS = [
		'/\b(?:only|smallest|largest|highest|lowest|fewest|most|least|greatest|biggest|the first|ranks? (?:first|last|highest|lowest))\b/iu',
		'/\b(?:more|fewer|less)\b[^.]{0,40}\bthan any(?: other)?\b/iu',
	];

	/**
	 * Figures helper.
	 *
	 * @var Figures
	 */
	protected $figures;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->figures = new Figures();
	}

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
		return esc_html__( 'Superlative', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Declines an answer that ranks things (“the highest”, “the only”) when the sources do not state that ranking.', 'elasticpress-labs' );
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
	 * Refuse a ranking or superlative the sources do not state.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$answer = Text::to_plain( (string) ( $context['content'] ?? '' ) );

		if ( '' === trim( $answer ) ) {
			return Gate_Result::pass();
		}

		$sources = $this->get_sources( $context['passages'] ?? [] );
		$rules   = $this->get_rules();

		foreach ( Text::split_sentences( $answer ) as $sentence ) {
			foreach ( $rules as $rule ) {
				if ( ! preg_match( $rule['answer'], $sentence ) ) {
					continue;
				}

				if ( $this->is_supported( $sentence, $rule['support'], $sources ) ) {
					continue;
				}

				/**
				 * Fires when an answer is withheld because of a ranking or superlative the
				 * sources do not state.
				 *
				 * For logging and evaluation; the answer is not stored or returned.
				 *
				 * @since 2.6.0
				 * @hook ep_chat_unsupported_comparison
				 * @param {string} $sentence Answer sentence with the claim.
				 * @param {array}  $rule     The rule: `answer` and `support` patterns.
				 * @param {array}  $context  Pipeline context, with the withheld answer in `content`.
				 */
				do_action( 'ep_chat_unsupported_comparison', $sentence, $rule, $context );

				return $this->refuse( self::REASON );
			}
		}

		return Gate_Result::pass();
	}

	/**
	 * Whether an answer makes a ranking or superlative claim of any kind, "most" and "least"
	 * included (the claim verifier checks those).
	 *
	 * @param string $answer Answer.
	 * @return bool
	 */
	public function has_ranking_claim( string $answer ): bool {
		$answer = Text::to_plain( $answer );

		foreach ( self::RANKING_CLAIMS as $pattern ) {
			if ( preg_match( $pattern, $answer ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The rules: an answer pattern and the comparative wording a passage needs to support it.
	 * Order matters: the first rule that matches without support refuses.
	 *
	 * @return array[] `[ 'answer' => regex, 'support' => regex ]`.
	 */
	public function get_rules(): array {
		$measure = '(?=[^.]{0,45}\b(?:share|percent|percentage|proportion|number|amount)\b)';
		$rules   = [
			[
				'answer'  => '/\b(?:smallest|lowest|fewest)\b' . $measure . '/iu',
				'support' => '/\b(?:smallest|lowest|fewest)\b|\bless\s+likely\s+than\b|\bfewer\s+than\s+any\b|\bless\s+than\s+any\b|\bfell\s+behind\b|\btrail(?:s|ed)?\b|\boutlier\b|\bbottom\b/iu',
			],
			[
				'answer'  => '/\b(?:largest|highest|greatest|biggest)\b' . $measure . '/iu',
				'support' => '/\b(?:largest|highest|greatest|biggest)\b|\bmore\s+likely\s+than\b|\bmore\s+than\s+any\b|\bof\s+any\b|\btop(?:s|ped)?\b|\bleads?\b|\bled\b|\boutlier\b/iu',
			],
			[
				'answer'  => '/\bthe (?:only|sole) (?:country|nation|group|state|place|one)\b/iu',
				'support' => '/\b(?:only|sole)\b|\bno\s+other\b|\bnone\s+other\b/iu',
			],
			[
				'answer'  => '/\b(?:more|fewer|less)\b[^.]{0,40}\bthan any(?: other)?\b/iu',
				'support' => '/\bthan\s+any(?:\s+other)?\b|\bmore\s+than\s+any\b|\bfewer\s+than\s+any\b|\bless\s+than\s+any\b|\boutlier\b/iu',
			],
		];

		/**
		 * Filters Superlative_Gate's rules. Each pairs a pattern for a ranking an answer can make
		 * with the pattern for the comparative wording a passage needs to support it. The first
		 * rule that matches an answer sentence without support refuses the answer.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_superlative_rules
		 * @param {array} $rules `[ 'answer' => regex, 'support' => regex ]` rows. Default: the
		 *                       smallest / largest / only / "than any" rules (English).
		 * @return {array} Filtered rules.
		 */
		$rules = apply_filters( 'ep_chat_superlative_rules', $rules );

		return array_values(
			array_filter(
				is_array( $rules ) ? $rules : [],
				function ( $rule ) {
					return is_array( $rule ) && ! empty( $rule['answer'] ) && ! empty( $rule['support'] );
				}
			)
		);
	}

	/**
	 * Whether a passage supports an answer sentence's claim.
	 *
	 * @param string  $sentence Answer sentence.
	 * @param string  $support  Support pattern.
	 * @param array[] $sources  Passages: `text`, `grounded`, `sentences`.
	 * @return bool
	 */
	protected function is_supported( string $sentence, string $support, array $sources ): bool {
		$statistics = [];

		foreach ( $this->figures->locate( $sentence ) as $figure ) {
			if ( Figures::is_statistic( $figure ) ) {
				$statistics[] = $figure['figure'];
			}
		}

		// The passages that ground the sentence's statistics are its sources.
		$grounding = array_filter(
			$sources,
			function ( $source ) use ( $statistics ) {
				foreach ( $statistics as $statistic ) {
					if ( isset( $source['grounded'][ $statistic ] ) ) {
						return true;
					}
				}

				return false;
			}
		);

		if ( $grounding ) {
			foreach ( $grounding as $source ) {
				if ( preg_match( $support, $source['text'] ) ) {
					return true;
				}
			}

			return false;
		}

		// No statistic: a passage sentence about the same thing must carry the wording.
		$words = Text::get_content_words( $sentence );

		foreach ( $sources as $source ) {
			foreach ( $source['sentences'] as $candidate ) {
				if ( count( array_intersect_key( $words, Text::get_content_words( $candidate ) ) ) >= self::MIN_SHARED_WORDS && preg_match( $support, $candidate ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return [
			self::REASON => [
				'label'       => __( 'Comparison not in the sources', 'elasticpress-labs' ),
				'message'     => __( 'The answer was withheld because it made a ranking or comparison that the sources do not state.', 'elasticpress-labs' ),
				'description' => __( 'An answer was written, but it ranked or compared things (such as “the largest” or “the only”) in a way the sources do not state, so it was not shown.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_WITHHELD,
			],
		];
	}

	/**
	 * Ask the model not to rank on its own.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [
			__( 'Do not write a ranking or superlative (largest, smallest, only, more than any) unless a source states that comparison.', 'elasticpress-labs' ),
		];
	}

	/**
	 * Passages with what the check needs.
	 *
	 * @param mixed $passages Passages.
	 * @return array[] `text`, `grounded` (normalized figures), `sentences`.
	 */
	protected function get_sources( $passages ): array {
		$sources = [];

		foreach ( is_array( $passages ) ? $passages : [] as $passage ) {
			if ( ! is_array( $passage ) ) {
				continue;
			}

			$text = Text::get_passage_text( $passage );

			$sources[] = [
				'text'      => $text,
				'grounded'  => $this->figures->grounded( [ $text ] ),
				'sentences' => Text::split_sentences( $text ),
			];
		}

		return $sources;
	}
}
