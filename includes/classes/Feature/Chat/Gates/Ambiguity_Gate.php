<?php
/**
 * Optional gate: ask which survey wave the reader means when the sources span several.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Refusals;
use ElasticPressLabs\Feature\Chat\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ambiguity_Gate, read from the passages before any answer is written (no model call).
 *
 * A question that names no year ("How many Americans own a smartphone?") has no single right
 * figure when the passages found come from two or more waves of the same report ("Phone
 * Ownership 2013", "Phone Ownership 2015"). Today the answer model picks a wave, and a
 * later check either lets a stale figure through or withholds it. This gate asks instead: it
 * stops the question with a clarifying prompt that names the waves found, and one option per
 * wave the reader can click.
 *
 * Deterministic on purpose: the trigger is read from the question and the passage titles and
 * dates only, with no model call. It fires when BOTH hold:
 *
 * 1. The question carries no disambiguator: no year, and no wave word (latest, most recent,
 *    recent, current, now, today, this year, last year, first time, since …). A reader who asked
 *    for "the latest" wave has already chosen one. A follow-up turn (non-empty history) passes
 *    too: the earlier turns fixed the wave.
 * 2. Two or more passages share one report title once years are removed, and carry two or more
 *    distinct survey years (the year in the title, else the publication year). Generic page
 *    titles (Methodology, Appendix, Acknowledgments…) never form a wave family, and a year in a
 *    title that is later than the publication year is a projection, not a wave.
 *
 * Group ambiguity (parents or teens? adults or online adults?) is not read here: it cannot be
 * told from titles and dates without a model, so it is left to Population_Match_Gate. Known
 * limit: when several reports qualify, the one with the most passages is offered, which is not
 * always the report the question is about, and waves of one series whose titles differ (Library
 * Use Across Age Groups 2018 / Library Visits by Age in 2021) are not joined.
 *
 * The gate refuses with `ambiguous_wave` and carries `clarify` (`prompt`, `options`) in its
 * result context; Pipeline::refused() turns that into a `clarify` outcome without a model call
 * (see Clarifier::from_gate()). The bot setting "Offer clarifying choices" (`clarify_enabled`)
 * governs only the model-written offers for short queries; this gate has its own bot toggle,
 * off by default (see is_enabled_by_default() for the trade it makes).
 */
class Ambiguity_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'ambiguity';

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	public const REASON = 'ambiguous_wave';

	/**
	 * Fewest distinct survey years of one report that make the question ambiguous.
	 *
	 * @var int
	 */
	public const MIN_WAVES = 2;

	/**
	 * Most waves named in the prompt.
	 *
	 * @var int
	 */
	public const MAX_NAMED = 6;

	/**
	 * Most options offered (the newest waves first).
	 *
	 * @var int
	 */
	public const MAX_OPTIONS = 4;

	/**
	 * Most characters of one option; Clarifier::from_gate() cuts options at this length, so a
	 * longer one would lose its year and collapse into the other options.
	 *
	 * @var int
	 */
	public const MAX_OPTION_LENGTH = 300;

	/**
	 * A four-digit year that is not part of a larger number ("2,000", "2016.5", "20160"). A year
	 * followed by a comma or full stop at a clause end ("In the 2016 update, what …")
	 * still counts.
	 *
	 * @var string
	 */
	protected const YEAR_PATTERN = '/(?<![\d,.])((?:19|20)\d{2})(?!\d|,\d|\.\d)/u';

	/**
	 * Words that pick a wave without naming a year.
	 *
	 * @var string
	 */
	protected const WAVE_WORD_PATTERN = '/\b(?:latest|most\s+recent|recent(?:ly)?|newest|current(?:ly)?|now|today|nowadays|these\s+days|right\s+now|this\s+year|last\s+year|a\s+year\s+ago|first\s+time|since\s+(?:19|20)\d{2}|(?:last|past|previous)\s+(?:\w+\s+)?(?:years?|decade|wave|survey|time))\b/iu';

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
		return esc_html__( 'Survey wave check', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'When a question names no year and the sources found come from several waves of the same report, asks which wave the reader means instead of picking one.', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_stage(): string {
		return self::STAGE_FILTER_PASSAGES;
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
	 * Off by default. The gate is cheap (no model call) but it trades answers for ask-backs: on a
	 * yearless question it also stops the newest-wave figure the answer model would have given,
	 * which readers often want. Tick it on the bot screen to turn it on; a bot that has saved its
	 * gate list once does not pick up a new default anyway.
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool {
		return false;
	}

	/**
	 * Ask which wave when the question names none and the passages span several.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$question = trim( (string) ( $context['question'] ?? '' ) );
		$passages = Text::get_passages( $context );

		if ( '' === $question || ! $passages || $this->is_disambiguated( $question ) ) {
			return Gate_Result::pass();
		}

		// A follow-up turn ("and how does that split by gender?") inherits the wave the earlier
		// turns fixed; asking for a year again would undo the conversation.
		if ( ! empty( $context['history'] ) && is_array( $context['history'] ) ) {
			return Gate_Result::pass();
		}

		$family = $this->find_ambiguous_family( $passages );

		if ( null === $family ) {
			return Gate_Result::pass();
		}

		$offer = $this->get_offer( $question, $family );

		/**
		 * Fires when a question is stopped because the sources span several waves of one report
		 * and the question names none. For logging and evaluation.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ambiguous_wave
		 * @param {array} $family  `title`, `waves` (year => title of that wave's passage).
		 * @param {array} $context Pipeline context.
		 */
		do_action( 'ep_chat_ambiguous_wave', $family, $context );

		return $this->refuse(
			self::REASON,
			[
				'clarify'   => $offer,
				'ambiguity' => $family,
			]
		);
	}

	/**
	 * Whether the question already picks a wave: it names a year, or uses a wave word.
	 *
	 * @param string $question User question.
	 * @return bool
	 */
	public function is_disambiguated( string $question ): bool {
		return (bool) preg_match( self::YEAR_PATTERN, $question ) || (bool) preg_match( self::WAVE_WORD_PATTERN, $question );
	}

	/**
	 * The report family with the most passages whose passages carry two or more survey years,
	 * or null.
	 *
	 * @param array[] $passages Passages (`title`, `date`).
	 * @return array{title: string, waves: array<int, string>, count: int}|null `waves` is
	 *         year => title of the passage for that wave, newest first.
	 */
	public function find_ambiguous_family( array $passages ): ?array {
		$families = [];

		foreach ( $passages as $passage ) {
			$title = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $passage['title'] ?? '' ) ) );
			$year  = $this->read_wave_year( $title, (string) ( $passage['date'] ?? '' ) );
			$key   = $this->get_family_key( $title );

			if ( null === $year || '' === $key ) {
				continue;
			}

			if ( ! isset( $families[ $key ] ) ) {
				$families[ $key ] = [
					'title' => $title,
					'waves' => [],
					'count' => 0,
				];
			}

			++$families[ $key ]['count'];

			if ( ! isset( $families[ $key ]['waves'][ $year ] ) ) {
				$families[ $key ]['waves'][ $year ] = $title;
			}
		}

		$best = null;

		foreach ( $families as $family ) {
			if ( count( $family['waves'] ) < self::MIN_WAVES ) {
				continue;
			}

			if ( null === $best || $family['count'] > $best['count'] ) {
				$best = $family;
			}
		}

		if ( null === $best ) {
			return null;
		}

		krsort( $best['waves'], SORT_NUMERIC );

		$best['title'] = trim( (string) preg_replace( '/[\s,:\-–—(]*(?<![\d,.])(?:19|20)\d{2}(?!\d|,\d|\.\d)[\s)]*/u', ' ', $best['title'] ) );
		$best['title'] = (string) preg_replace( '/^[\s,:\-–—]+|[\s,:\-–—]+$/u', '', (string) preg_replace( '/\s+/u', ' ', $best['title'] ) );

		return $best;
	}

	/**
	 * The survey year of one passage: the year in its title (a projection year, later than the
	 * publication year, does not count), else its publication year.
	 *
	 * @param string $title Passage title.
	 * @param string $date  Publication date, as the site prints it.
	 * @return int|null
	 */
	public function read_wave_year( string $title, string $date ): ?int {
		// Any four-digit year in the date, so dotted formats ("12.05.2016", "2016.05.12") count too.
		$published = preg_match( '/(?<!\d)((?:19|20)\d{2})(?!\d)/u', $date, $match ) ? (int) $match[1] : null;

		if ( preg_match_all( self::YEAR_PATTERN, $title, $matches ) ) {
			$years = array_map( 'intval', $matches[1] );

			if ( null !== $published ) {
				$years = array_filter(
					$years,
					static function ( $year ) use ( $published ) {
						return $year <= $published + 1;
					}
				);
			}

			if ( $years ) {
				return max( $years );
			}
		}

		return $published;
	}

	/**
	 * The report title without years, case or punctuation: passages with the same key are waves
	 * of one report. Empty for a generic page title (Methodology, Appendix…) or a title of fewer
	 * than two words.
	 *
	 * @param string $title Passage title.
	 * @return string
	 */
	public function get_family_key( string $title ): string {
		$key = mb_strtolower( $title );
		$key = str_replace( '&', ' and ', $key );
		$key = (string) preg_replace( self::YEAR_PATTERN, ' ', $key );
		$key = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $key );
		$key = trim( (string) preg_replace( '/\s+/u', ' ', $key ) );

		if ( '' === $key || count( explode( ' ', $key ) ) < 2 ) {
			return '';
		}

		foreach ( $this->get_generic_titles() as $generic ) {
			if ( $key === $generic || 0 === strpos( $key, $generic . ' ' ) ) {
				return '';
			}
		}

		return $key;
	}

	/**
	 * Page titles that every report has, so they never make a wave family.
	 *
	 * @return string[] Lowercase, no punctuation.
	 */
	public function get_generic_titles(): array {
		/**
		 * Filters the page titles that are never read as waves of one report (compared in
		 * lowercase without punctuation; a title that starts with one is skipped too).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ambiguity_generic_titles
		 * @param {string[]} $titles Titles.
		 * @return {string[]} Filtered titles.
		 */
		$titles = apply_filters(
			'ep_chat_ambiguity_generic_titles',
			[ 'methodology', 'methods', 'survey methodology', 'acknowledgments', 'acknowledgements', 'appendix', 'topline', 'about this report', 'about the survey', 'main findings', 'summary of findings', 'overview', 'introduction', 'references', 'glossary', 'detailed tables', 'additional findings' ]
		);

		return array_values( array_filter( array_map( 'strval', is_array( $titles ) ? $titles : [] ) ) );
	}

	/**
	 * The question, shortened at a word so that ", in the YYYY survey?" still fits in one option.
	 *
	 * @param string $stem Question without its closing punctuation.
	 * @return string
	 */
	protected function fit_stem( string $stem ): string {
		$room = self::MAX_OPTION_LENGTH - mb_strlen( ', in the 2024 survey?' ) - 1;

		if ( mb_strlen( $stem ) <= $room ) {
			return $stem;
		}

		$cut   = mb_substr( $stem, 0, $room );
		$space = mb_strrpos( $cut, ' ' );

		return (string) preg_replace( '/[\s,;:\-–—]+$/u', '', false === $space ? $cut : mb_substr( $cut, 0, $space ) ) . '…';
	}

	/**
	 * The clarifying prompt and one option per wave (the newest first).
	 *
	 * @param string $question User question.
	 * @param array  $family   See find_ambiguous_family().
	 * @return array{prompt: string, options: string[]}
	 */
	public function get_offer( string $question, array $family ): array {
		$named   = [];
		$options = [];
		$stem    = $this->fit_stem( rtrim( trim( $question ), ' ?!.' ) );

		foreach ( $family['waves'] as $year => $title ) {
			if ( count( $named ) < self::MAX_NAMED ) {
				$named[] = sprintf( '%d (%s)', $year, $title );
			}

			if ( count( $options ) < self::MAX_OPTIONS ) {
				$options[] = sprintf(
					/* translators: 1: the question without its question mark, 2: survey year */
					__( '%1$s, in the %2$d survey?', 'elasticpress-labs' ),
					$stem,
					$year
				);
			}
		}

		$prompt = sprintf(
			/* translators: %s: survey years, each with its report title */
			__( 'Your question doesn’t name a year, and the sources found span more than one survey wave of the same report: %s. Which one did you mean?', 'elasticpress-labs' ),
			implode( ', ', $named )
		);

		return [
			'prompt'  => $prompt,
			'options' => $options,
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
				'label'       => __( 'Several survey waves', 'elasticpress-labs' ),
				'message'     => __( 'Your question doesn’t name a year, and the sources found span more than one survey wave of the same report. Which year did you mean?', 'elasticpress-labs' ),
				'description' => __( 'The question named no year, and the reports found are several waves of the same report, which can give different figures. Rather than pick one, the assistant asks which wave you meant.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_NO_SOURCES,
				'next_step'   => __( 'Add the year, or pick one of the waves listed.', 'elasticpress-labs' ),
			],
		];
	}
}
