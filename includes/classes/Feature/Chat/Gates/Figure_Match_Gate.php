<?php
/**
 * Gate (optional, on by default): every figure in the answer must be in a passage.
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
 * Figure_Match_Gate (deterministic).
 *
 * Refuses the whole answer when any figure in it (see Figures) is not written in one of the
 * passages the model saw for this question: their titles and text. Earlier answers in the
 * conversation do not count, and neither do the question's statistics — only its years, so
 * an answer may say "the sources have no estimate for 2026" when asked about 2026. No
 * figures in the answer → pass.
 */
class Figure_Match_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'figure_match';

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	public const REASON = 'numeric_mismatch';

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
		return esc_html__( 'Figure match', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Declines an answer with a number that is not in the sources.', 'elasticpress-labs' );
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
	 * Refuse when the answer has a figure that no passage contains.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$answer = (string) ( $context['content'] ?? '' );

		if ( '' === trim( $answer ) ) {
			return Gate_Result::pass();
		}

		$figures   = new Figures();
		$unmatched = array_values(
			array_diff(
				$figures->unmatched( $answer, Text::get_passage_texts( $context['passages'] ?? [] ) ),
				$this->get_question_years( $figures, (string) ( $context['question'] ?? '' ) ),
				$this->get_source_years( $context['passages'] ?? [] )
			)
		);

		if ( ! $unmatched ) {
			return Gate_Result::pass();
		}

		/**
		 * Fires when an answer is withheld because of figures no passage contains.
		 *
		 * For logging and evaluation; the answer is not stored or returned.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ungrounded_figures
		 * @param {string[]} $unmatched Normalized figures not found in the passages.
		 * @param {array}    $context   Pipeline context, with the withheld answer in `content`.
		 */
		do_action( 'ep_chat_ungrounded_figures', $unmatched, $context );

		return $this->refuse( self::REASON );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return [
			self::REASON => [
				'label'       => __( 'Unsupported figure', 'elasticpress-labs' ),
				'message'     => self::get_default_message(),
				'description' => __( 'An answer was written, but it had a figure that does not appear in any of the sources, so it was not shown. Every figure in an answer must come from a source.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_WITHHELD,
			],
		];
	}

	/**
	 * Ask the model to copy figures, so answers that are right are not refused.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [
			__( 'Copy every number exactly as it is written in the sources. Do not round, calculate, or recall figures that the sources do not contain.', 'elasticpress-labs' ),
		];
	}

	/**
	 * Default reader-safe copy for the refusal.
	 *
	 * @return string
	 */
	public static function get_default_message(): string {
		return __( 'The answer was withheld because it included a figure that could not be found in the sources.', 'elasticpress-labs' );
	}

	/**
	 * Years written in the question (normalized figures of kind year).
	 *
	 * @param Figures $figures  Figures helper.
	 * @param string  $question Question.
	 * @return string[]
	 */
	protected function get_question_years( Figures $figures, string $question ): array {
		$years = [];

		foreach ( $figures->locate( $question ) as $figure ) {
			if ( Figures::KIND_YEAR === $figure['kind'] ) {
				$years[] = $figure['figure'];
			}
		}

		return $years;
	}

	/**
	 * Years that date a source: in its URL (such as `/2026/02/`) or its publication date. Models
	 * name a source by its year ("the 2023 report"), which is not a statistic. Only years count:
	 * other numbers in a URL, such as post IDs, do not ground anything.
	 *
	 * @param mixed $passages Passages.
	 * @return string[]
	 */
	protected function get_source_years( $passages ): array {
		$years = [];

		foreach ( is_array( $passages ) ? $passages : [] as $passage ) {
			if ( ! is_array( $passage ) ) {
				continue;
			}

			$path = (string) wp_parse_url( (string) ( $passage['url'] ?? '' ), PHP_URL_PATH );

			if ( preg_match_all( '#(?<=[/-])((?:19|20)\d{2})(?=[/-]|$)#', $path, $matches ) ) {
				$years = array_merge( $years, $matches[1] );
			}

			if ( preg_match_all( '/(?<!\d)((?:19|20)\d{2})(?!\d)/', (string) ( $passage['date'] ?? '' ), $matches ) ) {
				$years = array_merge( $years, $matches[1] );
			}
		}

		return array_values( array_unique( $years ) );
	}
}
