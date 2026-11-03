<?php
/**
 * Optional gate: a year the answer ties to a figure must be the source's, and a trend across a
 * change of method gets a caveat.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Figure_Context;
use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Refusals;
use ElasticPressLabs\Feature\Chat\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Trend_Gate, read from the text (no per-figure metadata).
 *
 * - Years: when the answer ties a year to a figure ("68% in 2024", "In 1994, 20%"), a passage
 *   must give that figure next to that year, or be from that year or the year after. Otherwise
 *   the answer is declined: a figure moved to another year is a wrong trend point.
 * - Method changes: when the answer gives figures for two or more years and a source it draws on
 *   says its survey method or question changed, or that figures are not directly comparable, the
 *   answer gets a caveat after it instead of a decline (research publishers report such trends
 *   themselves, with that note).
 */
class Trend_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'trend_guard';

	/**
	 * Refusal code: the survey wave is not in the sources.
	 *
	 * @var string
	 */
	public const REASON = 'wave_not_in_corpus';

	/**
	 * What a source says when its trend crosses a change of method or wording.
	 *
	 * @var string
	 */
	public const COMPARABILITY = '/(?:not (?:directly )?comparable|mode (?:change|shift|switch)|switched from (?:phone|telephone)|from (?:phone|telephone) (?:surveys? )?to (?:online|web)|methods? (?:varied|changed|differ)|caution (?:when|in) comparing|break in (?:the )?trend|differences in (?:survey )?(?:mode|method)|change in how (?:this|the) question was asked)/i';

	/**
	 * Figure context helper.
	 *
	 * @var Figure_Context
	 */
	protected $context;

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
		$this->context = new Figure_Context();
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
		return esc_html__( 'Trend guard', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Declines an answer that gives a figure for a year the sources do not give it for, and adds a note when an answer compares years across a change in how a survey was done.', 'elasticpress-labs' );
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
	 * Check the years, then look for a change of method across them.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$passages = Text::get_passages( $context );

		if ( ! $passages ) {
			return Gate_Result::pass();
		}

		$claims  = $this->get_claims( (string) ( $context['content'] ?? '' ) );
		$unbound = [];

		foreach ( $claims as $claim ) {
			if ( ! $this->context->passages_bind_year( $claim['figure'], $claim['year'], $passages ) ) {
				$unbound[] = $claim;
			}
		}

		if ( $unbound ) {
			/**
			 * Fires when an answer is withheld because it gives a figure for a year the passages
			 * do not give it for.
			 *
			 * For logging and evaluation; the answer is not stored or returned.
			 *
			 * @since 2.6.0
			 * @hook ep_chat_figure_year_mismatch
			 * @param {array[]} $unbound Claims: `figure`, `year`, `sentence`.
			 * @param {array}   $context Pipeline context, with the withheld answer in `content`.
			 */
			do_action( 'ep_chat_figure_year_mismatch', $unbound, $context );

			return $this->refuse( self::REASON );
		}

		if ( count( array_unique( wp_list_pluck( $claims, 'year' ) ) ) < 2 || ! $this->has_comparability_note( $claims, $passages ) ) {
			return Gate_Result::pass();
		}

		$caveats   = is_array( $context['caveats'] ?? null ) ? $context['caveats'] : [];
		$caveats[] = $this->get_caveat();

		return Gate_Result::modify( [ 'caveats' => array_values( array_unique( $caveats ) ) ] );
	}

	/**
	 * The figures an answer ties to a year.
	 *
	 * @param string $content Answer.
	 * @return array[] `figure`, `year`, `sentence`.
	 */
	public function get_claims( string $content ): array {
		$claims = [];

		foreach ( $this->figures->locate_answer_statistics( $content ) as $found ) {
			$year = $this->context->read_answer_year( $found['sentence'], $found['figure'] );

			if ( null !== $year ) {
				$claims[] = [
					'figure'   => $found['figure']['figure'],
					'year'     => $year,
					'sentence' => $found['sentence'],
				];
			}
		}

		return $claims;
	}

	/**
	 * Whether a source the trend draws on says its method or question changed, or that figures
	 * are not directly comparable: in the passage, or anywhere in the source post.
	 *
	 * @param array[] $claims   Figures tied to years.
	 * @param array[] $passages Passages.
	 * @return bool
	 */
	protected function has_comparability_note( array $claims, array $passages ): bool {
		$figures = array_unique( wp_list_pluck( $claims, 'figure' ) );

		foreach ( $passages as $passage ) {
			$text = Text::get_passage_text( $passage );

			if ( ! array_filter( $figures, fn( $figure ) => (bool) $this->context->find_occurrences( $figure, [ $text ] ) ) ) {
				continue;
			}

			$post = (int) ( $passage['id'] ?? 0 ) ? (string) get_post_field( 'post_content', (int) $passage['id'] ) : '';

			if ( preg_match( self::COMPARABILITY, $text . "\n" . wp_strip_all_tags( $post ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The caveat added after a trend across a change of method (no figures).
	 *
	 * @return string
	 */
	public function get_caveat(): string {
		/**
		 * Filters the note added after an answer that compares years across a change in how a
		 * survey was done. It must not contain figures.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_trend_caveat
		 * @param {string} $caveat Note.
		 * @return {string} Filtered note.
		 */
		$caveat = apply_filters( 'ep_chat_trend_caveat', __( 'Note: a source used here says the survey method or the question changed over this period, so part of the change between years may come from that change rather than from a change in views or behavior.', 'elasticpress-labs' ) );
		$caveat = is_string( $caveat ) ? trim( $caveat ) : '';

		// A caveat never brings a figure the checks did not see.
		return '' !== $caveat && ! $this->figures->locate( $caveat ) ? $caveat : __( 'Note: a source used here says the survey method or the question changed over this period.', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return [
			self::REASON => [
				'label'       => __( 'Figure not given for that year', 'elasticpress-labs' ),
				'message'     => __( 'The answer was withheld because it gave a figure for a year the sources do not give it for.', 'elasticpress-labs' ),
				'description' => __( 'An answer was written, but it gave a figure for a year that does not match the source: the source gives that figure for another year, or is from another time. A figure moved to the wrong year makes a wrong trend, so it was not shown.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_WITHHELD,
			],
		];
	}

	/**
	 * Ask the model to keep figures with their own year.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [
			__( 'Give each figure with the year the source gives it for; never move a figure to another year.', 'elasticpress-labs' ),
		];
	}
}
