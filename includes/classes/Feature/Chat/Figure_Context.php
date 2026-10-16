<?php
/**
 * The population and the year a text ties to a figure.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads what a figure is for, from the words around it: the population ("21% of teens",
 * "among Republicans, 60%") and the year ("68% in 2024", "In 1994, 20%"). The answer side reads
 * only what sits right next to the figure, with no other figure between; the passage side also
 * reads the sentence before, and for a Markdown table the row, the header, and the caption above.
 *
 * Population_Match_Gate, Trend_Gate, and Citations share it, so "the answer ties the figure to X"
 * and "a passage gives the figure for X" are read the same way.
 */
class Figure_Context {
	/**
	 * Characters between an answer's figure and the words that say what it is for.
	 *
	 * @var int
	 */
	public const ANSWER_WINDOW = 45;

	/**
	 * Characters between a passage's figure and the words that say what it is for.
	 *
	 * @var int
	 */
	public const PASSAGE_WINDOW = 120;

	/**
	 * Words for the whole population.
	 *
	 * @var string
	 */
	protected const GENERAL = '/\b(?:u\.\s?s\.\s+adults|american adults|all (?:u\.\s?s\.\s+)?adults|americans|the (?:u\.\s?s\.\s+|general\s+)?public|adults overall|(?<![\p{L}-])adults)(?![\p{L}])/iu';

	/**
	 * Figures helper.
	 *
	 * @var Figures
	 */
	protected $figures;

	/**
	 * Groups (from get_groups()), cached for the request.
	 *
	 * @var array|null
	 */
	protected $groups = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->figures = new Figures();
	}

	/**
	 * The populations a text can name, by kind: a figure is for one group of each kind at most
	 * ("Democratic teens": a party and an age). Phrases are lowercase.
	 *
	 * @return array<string, array<string, string[]>> Kind => group => phrases.
	 */
	public function get_groups(): array {
		if ( null !== $this->groups ) {
			return $this->groups;
		}

		$groups = [
			'age'      => [
				'teens'    => [ 'teens', 'teen', 'teenagers', 'teenager', 'ages 13 to 17', '13- to 17-year-olds', '13-to-17-year-olds' ],
				'under 30' => [ 'adults under 30', 'under 30', 'ages 18 to 29', 'ages 18-29', '18-29', '18- to 29-year-olds', '18-to-29-year-olds', 'young adults', 'younger adults' ],
				'30 to 49' => [ 'ages 30 to 49', 'ages 30-49', '30 to 49', '30-49', '30- to 49-year-olds' ],
				'50 to 64' => [ 'ages 50 to 64', 'ages 50-64', '50 to 64', '50-64', '50- to 64-year-olds' ],
				'65+'      => [ 'ages 65 and older', '65 and older', '65 and over', 'ages 65 and over', 'ages 65+', '65+', 'older adults', 'seniors' ],
			],
			'party'    => [
				'Republicans'  => [ 'republicans', 'republican', 'gop', 'republican-leaning' ],
				'Democrats'    => [ 'democrats', 'democratic', 'democrat', 'democratic-leaning' ],
				'independents' => [ 'independents' ],
			],
			'race'     => [
				'Hispanic' => [ 'hispanic', 'hispanics', 'latino', 'latinos', 'latina', 'latinas' ],
				'Black'    => [ 'black' ],
				'White'    => [ 'white' ],
				'Asian'    => [ 'asian', 'asian americans' ],
			],
			'gender'   => [
				'women' => [ 'women' ],
				'men'   => [ 'men' ],
				'girls' => [ 'girls' ],
				'boys'  => [ 'boys' ],
			],
			'internet' => [
				'online adults'      => [ 'online adults', 'adult internet users', 'internet users', 'internet-using adults' ],
				'social media users' => [ 'social media users' ],
				'smartphone owners'  => [ 'smartphone owners' ],
			],
			'other'    => [
				'parents'    => [ 'parents' ],
				'veterans'   => [ 'veterans' ],
				'immigrants' => [ 'immigrants' ],
			],
		];

		/**
		 * Filters the populations Population_Match_Gate and Citations read next to figures. A
		 * figure is for at most one group of each kind.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_population_groups
		 * @param {array} $groups Kind => group => phrases (lowercase). Longer phrases win over
		 *                        shorter ones they contain.
		 * @return {array} Filtered groups.
		 */
		$filtered = apply_filters( 'ep_chat_population_groups', $groups );

		$this->groups = is_array( $filtered ) ? $filtered : $groups;

		return $this->groups;
	}

	/**
	 * Groups a text names, longest phrase first, by position.
	 *
	 * @param string $text Text.
	 * @return array[] `kind`, `group`, `start`, `end`.
	 */
	public function find_groups( string $text ): array {
		$entries = [];

		foreach ( $this->get_groups() as $kind => $groups ) {
			foreach ( (array) $groups as $group => $phrases ) {
				$entries[] = [
					'kind'    => (string) $kind,
					'group'   => (string) $group,
					'phrases' => (array) $phrases,
				];
			}
		}

		return Text::find_phrases( $text, $entries );
	}


	/**
	 * What an answer sentence says a figure is for: the nearest group of each kind right next to
	 * it, or `general` when the words next to it name the whole population and no group.
	 *
	 * @param string $sentence Sentence (plain text, no markers).
	 * @param array  $figure   Located figure (Figures::locate()).
	 * @return array{groups: array<string, string>, general: bool}
	 */
	public function read_answer( string $sentence, array $figure ): array {
		$read = $this->read( $sentence, $figure, self::ANSWER_WINDOW );

		// "Democrats and independents have fallen to 77% and 70%", "Democrats (27%) … Republicans
		// (8%)": with two groups of a kind and two figures, the nearest group may not be the
		// figure's. That kind is not read.
		$statistics = $this->figures->locate_statistics( $sentence );

		if ( count( $statistics ) > 1 ) {
			$named = [];

			foreach ( $this->find_groups( $sentence ) as $match ) {
				$named[ $match['kind'] ][ $match['group'] ] = true;
			}

			foreach ( $named as $kind => $groups ) {
				if ( count( $groups ) > 1 ) {
					unset( $read['groups'][ $kind ] );
				}
			}
		}

		return $read;
	}

	/**
	 * The year an answer sentence ties to a figure: the year right after it with a word that ties
	 * them ("68% in 2024", "37% as of 2025"), else the year right before it ("In 1994, 20%",
	 * "2021: 21%").
	 *
	 * @param string $sentence Sentence (plain text, no markers).
	 * @param array  $figure   Located figure.
	 * @return int|null
	 */
	public function read_answer_year( string $sentence, array $figure ): ?int {
		$after  = null;
		$before = null;

		foreach ( $this->find_years( $sentence ) as $year ) {
			if ( $year['start'] >= $figure['end'] ) {
				// The year after the figure, as in: 68% in 2024, 37% as of 2025, 21% (2021).
				$between = substr( $sentence, $figure['end'], $year['start'] - $figure['end'] );

				if ( null === $after && preg_match( '/^\s*(?:of [^,;]{0,40}?\s+)?(?:in|as of|during|for|by|\()\s*(?:early |late |mid-|the )?(?:spring |summer |fall |autumn |winter |january |february |march |april |may |june |july |august |september |october |november |december )?$/iu', $between ) ) {
					$after = $year['year'];
				}
			} elseif ( $year['end'] <= $figure['start'] ) {
				// The year before the figure, as in: In 2012, 67% or 2021: 21%.
				$between = substr( $sentence, $year['end'], $figure['start'] - $year['end'] );

				if ( preg_match( '/^\s*[:,]?\s*(?:about |roughly |some |just |only |nearly |almost )?$/iu', $between ) ) {
					$before = $year['year'];
				}
			}
		}

		return $after ?? $before;
	}

	/**
	 * Whether a passage gives a figure for the population the answer ties it to.
	 *
	 * @param string $figure  Normalized figure.
	 * @param array  $wanted  From read_answer().
	 * @param array  $texts   Passage texts.
	 * @return bool
	 */
	public function passages_bind_population( string $figure, array $wanted, array $texts ): bool {
		$occurrences = $this->find_occurrences( $figure, $texts );

		if ( ! $occurrences ) {
			return true; // Not this check's job: Figure_Match_Gate declines a figure no passage has, when it runs.
		}

		foreach ( $occurrences as $occurrence ) {
			if ( $this->occurrence_has_population( $occurrence, $wanted ) ) {
				return true;
			}
		}

		return ! $wanted['groups'] && ! $wanted['general'];
	}

	/**
	 * Whether a passage gives a figure for a year: next to it, or the passage is from that year
	 * or the year after (a report published the year after its survey).
	 *
	 * @param string $figure   Normalized figure.
	 * @param int    $year     Year.
	 * @param array  $passages Passages: `title`, `excerpt`, `date`.
	 * @return bool
	 */
	public function passages_bind_year( string $figure, int $year, array $passages ): bool {
		$any = false;

		foreach ( $passages as $passage ) {
			$text        = Text::get_passage_text( $passage );
			$occurrences = $this->find_occurrences( $figure, [ $text ] );

			if ( ! $occurrences ) {
				continue;
			}

			$any = true;

			if ( in_array( $this->get_year( (string) ( $passage['date'] ?? '' ) ), [ $year, $year + 1 ], true ) ) {
				return true;
			}

			foreach ( $occurrences as $occurrence ) {
				if ( in_array( $year, $occurrence['years'], true ) ) {
					return true;
				}
			}
		}

		return ! $any;
	}

	/**
	 * Whether one place in the passages gives a figure for both the population and the year the
	 * answer ties it to. "63% of teens in 2025" is not supported by 63% of teens in 2023 plus 63%
	 * of adults under 30 in 2025, though each check alone passes.
	 *
	 * @param string $figure   Normalized figure.
	 * @param array  $wanted   From read_answer().
	 * @param int    $year     From read_answer_year().
	 * @param array  $passages Passages: `title`, `excerpt`, `date`.
	 * @return bool
	 */
	public function passages_bind_population_and_year( string $figure, array $wanted, int $year, array $passages ): bool {
		$any = false;

		foreach ( $passages as $passage ) {
			$occurrences = $this->find_occurrences( $figure, [ Text::get_passage_text( $passage ) ] );

			if ( ! $occurrences ) {
				continue;
			}

			$any   = true;
			$dated = in_array( $this->get_year( (string) ( $passage['date'] ?? '' ) ), [ $year, $year + 1 ], true );

			foreach ( $occurrences as $occurrence ) {
				if ( ( $dated || in_array( $year, $occurrence['years'], true ) ) && $this->occurrence_has_population( $occurrence, $wanted ) ) {
					return true;
				}
			}
		}

		return ! $any || ( ! $wanted['groups'] && ! $wanted['general'] );
	}

	/**
	 * Whether a place a figure appears gives it for a population: every group the answer names,
	 * or, for the whole population, not only a subgroup.
	 *
	 * @param array $occurrence From find_occurrences().
	 * @param array $wanted     From read_answer().
	 * @return bool
	 */
	protected function occurrence_has_population( array $occurrence, array $wanted ): bool {
		if ( $wanted['groups'] ) {
			foreach ( $wanted['groups'] as $kind => $group ) {
				if ( ! in_array( $group, $occurrence['groups'][ $kind ] ?? [], true ) ) {
					return false;
				}
			}

			return true;
		}

		return $wanted['general'] && ( $occurrence['general'] || ! $occurrence['groups'] );
	}

	/**
	 * How well a passage fits what an answer sentence says a figure is for: 2 when it gives the
	 * figure for the sentence's population and year, 1 for one of them, 0 for neither.
	 *
	 * @param string   $figure  Normalized figure.
	 * @param array    $wanted  From read_answer().
	 * @param int|null $year    From read_answer_year().
	 * @param array    $passage Passage: `title`, `excerpt` (or `text`), `date`.
	 * @return int
	 */
	public function fit( string $figure, array $wanted, ?int $year, array $passage ): int {
		$text = Text::get_passage_text( $passage );
		$fit  = 0;

		if ( $wanted['groups'] && $this->passages_bind_population( $figure, $wanted, [ $text ] ) ) {
			++$fit;
		}

		if ( null !== $year && $this->passages_bind_year( $figure, $year, [ $passage ] ) ) {
			++$fit;
		}

		// Both, but only in different places of the passage: one of them.
		if ( 2 === $fit && ! $this->passages_bind_population_and_year( $figure, $wanted, $year, [ $passage ] ) ) {
			$fit = 1;
		}

		return $fit;
	}

	/**
	 * Every place a figure appears in passages, with what the words around it say: groups by
	 * kind, whether they name the whole population, and the years.
	 *
	 * @param string   $figure Normalized figure.
	 * @param string[] $texts  Passage texts.
	 * @return array[] `groups` (kind => group[]), `general`, `years` (int[]).
	 */
	public function find_occurrences( string $figure, array $texts ): array {
		$found = [];

		foreach ( $texts as $text ) {
			$lines   = explode( "\n", (string) $text );
			$title   = $this->describe( (string) ( $lines[0] ?? '' ) ); // Passage texts start with the title.
			$before  = count( $found );
			$caption = '';
			$header  = '';
			$start   = null;

			foreach ( $lines as $index => $line ) {
				if ( 0 === strpos( ltrim( $line ), '|' ) ) {
					if ( null === $start ) {
						$start   = $index;
						$header  = $line;
						$caption = $this->get_caption( $lines, $index );
					}

					if ( $this->figures->row_has_figure( $line, $figure, $this->figures->is_percentage_table( $lines, $start ) ) ) {
						$found[] = $this->describe( $line . "\n" . $header . "\n" . $caption );
					}

					continue;
				}

				$start = null;

				$sentences = Text::split_sentences( $line );

				foreach ( $sentences as $position => $sentence ) {
					foreach ( $this->figures->locate( $sentence ) as $located ) {
						if ( $located['figure'] !== $figure ) {
							continue;
						}

						$here           = $this->read( $sentence, $located, self::PASSAGE_WINDOW );
						$here['groups'] = array_map( static fn( $group ) => [ $group ], $here['groups'] );

						// "Among Hispanic adults, 29% … and 25% …": a sentence that names one group of a
						// kind gives every figure in it for that group, unless words near the figure
						// name the whole population.
						if ( ! $here['groups'] && ! $here['general'] ) {
							foreach ( $this->describe( $sentence )['groups'] as $kind => $groups ) {
								if ( 1 === count( $groups ) ) {
									$here['groups'][ $kind ] = $groups;
								}
							}
						}

						// "Among Hispanic adults, … . Some 25% say …": the sentence before names the group.
						if ( ! $here['groups'] && ! $here['general'] && $position > 0 ) {
							$before          = $this->describe( $sentences[ $position - 1 ] );
							$here['groups']  = $before['groups'];
							$here['general'] = $before['general'] && ! $before['groups'];
						}

						$here['years'] = array_values( array_unique( array_merge( wp_list_pluck( $this->find_years( $sentence ), 'year' ), $position > 0 ? wp_list_pluck( $this->find_years( $sentences[ $position - 1 ] ), 'year' ) : [] ) ) );
						$found[]       = $here;
					}
				}
			}

			// The title says what the whole passage is about ("Teens, Social Media and Technology
			// 2024", "Striking findings from 2022"): its years count for every figure, and its
			// groups for a figure whose words name none.
			$total = count( $found );

			for ( $i = $before; $i < $total; $i++ ) {
				$found[ $i ]['years'] = array_values( array_unique( array_merge( $found[ $i ]['years'], $title['years'] ) ) );

				if ( ! $found[ $i ]['groups'] && ! $found[ $i ]['general'] && $title['groups'] ) {
					$found[ $i ]['groups'] = $title['groups'];
				}
			}
		}

		return $found;
	}

	/**
	 * What words say a figure is for, within a window around it: the nearest group of each kind
	 * (no other figure between), and whether they name the whole population.
	 *
	 * @param string $text   Text.
	 * @param array  $figure Located figure.
	 * @param int    $window Characters.
	 * @return array{groups: array<string, string>, general: bool}
	 */
	protected function read( string $text, array $figure, int $window ): array {
		$own = $this->read_own( $text, $figure );

		if ( null !== $own ) {
			return $own;
		}

		$located = $this->figures->locate( $text );
		$groups  = [];
		$gaps    = [];

		foreach ( $this->find_groups( $text ) as $match ) {
			$distance = Text::distance( $figure, $match );

			if ( $distance > $window || $this->has_figure_between( $located, $figure, $match ) ) {
				continue;
			}

			if ( ! isset( $gaps[ $match['kind'] ] ) || $distance < $gaps[ $match['kind'] ] ) {
				$groups[ $match['kind'] ] = $match['group'];
				$gaps[ $match['kind'] ]   = $distance;
			}
		}

		$general = false;

		if ( preg_match_all( self::GENERAL, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as [ $found, $offset ] ) {
				$span = [
					'start' => $offset,
					'end'   => $offset + strlen( $found ),
				];

				if ( Text::distance( $figure, $span ) <= $window && ! $this->has_figure_between( $located, $figure, $span ) ) {
					$general = true;
				}
			}
		}

		return [
			'groups'  => $groups,
			'general' => $general && ! $groups,
		];
	}

	/**
	 * The population a figure's own phrase names, as in "28% of all U.S. adults" or "21% among
	 * teens": the words after "of" or "among", up to the next punctuation. These words beat a
	 * nearer group before the figure, as in: 32% of internet users (28% of all U.S. adults).
	 *
	 * @param string $text   Text.
	 * @param array  $figure Located figure.
	 * @return array|null `groups`, `general`; null when the phrase names no population.
	 */
	protected function read_own( string $text, array $figure ): ?array {
		if ( ! preg_match( '/^\s*(?:percent\s+)?(?:of|among)\s+/iu', substr( $text, $figure['end'] ), $lead ) ) {
			return null;
		}

		$start  = $figure['end'] + strlen( $lead[0] );
		$phrase = (string) preg_replace( '/[,;:()\[\]].*$/su', '', substr( $text, $start, 80 ) );
		$end    = $start + strlen( $phrase );
		$groups = [];

		foreach ( $this->find_groups( $text ) as $match ) {
			if ( $match['start'] >= $start && $match['end'] <= $end && ! isset( $groups[ $match['kind'] ] ) ) {
				$groups[ $match['kind'] ] = $match['group'];
			}
		}

		if ( $groups ) {
			return [
				'groups'  => $groups,
				'general' => false,
			];
		}

		return preg_match( self::GENERAL, $phrase ) ? [
			'groups'  => [],
			'general' => true,
		] : null;
	}

	/**
	 * Groups, whole-population words, and years anywhere in a text (a table row with its header
	 * and caption, or a sentence).
	 *
	 * @param string $text Text.
	 * @return array{groups: array<string, string[]>, general: bool, years: int[]}
	 */
	protected function describe( string $text ): array {
		$groups = [];

		foreach ( $this->find_groups( $text ) as $match ) {
			$groups[ $match['kind'] ][] = $match['group'];
		}

		return [
			'groups'  => array_map( 'array_values', array_map( 'array_unique', $groups ) ),
			'general' => (bool) preg_match( self::GENERAL, $text ),
			'years'   => array_values( array_unique( wp_list_pluck( $this->find_years( $text ), 'year' ) ) ),
		];
	}

	/**
	 * Years in a text (1900 to next year), as spans. Not counts with a comma ("2,024").
	 *
	 * @param string $text Text.
	 * @return array[] `year`, `start`, `end`.
	 */
	protected function find_years( string $text ): array {
		$years = [];
		$last  = (int) gmdate( 'Y' ) + 1;

		if ( preg_match_all( '/(?<![\d,.])((?:19|20)\d{2})(?!\d|,\d|\.\d|\s*%)/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as [ $year, $offset ] ) {
				if ( (int) $year <= $last ) {
					$years[] = [
						'year'  => (int) $year,
						'start' => $offset,
						'end'   => $offset + strlen( $year ),
					];
				}
			}
		}

		return $years;
	}

	/**
	 * Lines just above a table that describe it (at most two, not table lines).
	 *
	 * @param string[] $lines Lines.
	 * @param int      $index First table line.
	 * @return string
	 */
	protected function get_caption( array $lines, int $index ): string {
		$caption = [];

		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( count( $caption ) >= 2 ) {
				break;
			}

			$line = trim( $lines[ $i ] );

			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, '|' ) ) {
				break;
			}

			array_unshift( $caption, $line );
		}

		return implode( "\n", $caption );
	}

	/**
	 * Whether another figure sits between a figure and a span: then the words belong to it.
	 *
	 * @param array[] $located Figures of the text.
	 * @param array   $figure  The figure.
	 * @param array   $span    Span: `start`, `end`.
	 * @return bool
	 */
	protected function has_figure_between( array $located, array $figure, array $span ): bool {
		$from = min( $figure['end'], $span['end'] );
		$to   = max( $figure['start'], $span['start'] );

		foreach ( $located as $other ) {
			if ( $other['start'] === $figure['start'] || ! Figures::is_statistic( $other ) ) {
				continue;
			}

			if ( $other['start'] >= $from && $other['end'] <= $to ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Year of a passage date ("March 2, 2026"), or 0.
	 *
	 * @param string $date Date.
	 * @return int
	 */
	protected function get_year( string $date ): int {
		return preg_match( '/\b((?:19|20)\d{2})\b/', $date, $match ) ? (int) $match[1] : 0;
	}
}
