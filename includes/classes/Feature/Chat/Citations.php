<?php
/**
 * Citation markers and provenance for answers.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Refusal_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Adds `[[k]]` markers to an answer, from its text and the passages alone (no model call).
 *
 * Each marker is one fact bound to one source; `k` counts the facts in the order they appear.
 *
 * - A figure of a marked kind (see Figures::locate(); default: percentages, percentage points,
 *   magnitudes, counts of 1,000 or more) gets a marker right after it. The source is a passage
 *   that contains the figure: the one that contains the most figures of the answer sentence,
 *   then the one sharing the most content words with it, then the first. Figures of one
 *   sentence from the same source share one marker, after the last of them.
 * - A sentence without such figures gets a marker at its end only when it shares at least
 *   three content words (four or more letters) with a passage.
 *
 * Each marker has a provenance entry: the source sentences (or table header and rows) with the
 * figures and their character ranges, the source title, URL, date, and a URL with a text
 * fragment that opens the source scrolled to the sentence. Markers the model wrote are removed
 * first: only this class cites.
 */
class Citations {
	/**
	 * Longest quote of the first source sentence, in characters.
	 *
	 * @var int
	 */
	public const QUOTE_LENGTH = 260;

	/**
	 * Longest quote of each further source sentence of one marker, in characters.
	 *
	 * @var int
	 */
	public const EXTRA_QUOTE_LENGTH = 160;

	/**
	 * Most source sentences quoted for one marker.
	 *
	 * @var int
	 */
	public const MAX_QUOTED_SENTENCES = 3;

	/**
	 * Shared content words a sentence needs to be cited without a figure.
	 *
	 * @var int
	 */
	public const MIN_SHARED_WORDS = 3;

	/**
	 * Figures helper.
	 *
	 * @var Figures
	 */
	protected $figures;

	/**
	 * Table row each source quoted last (`table:row`), keyed by source position.
	 *
	 * @var array<int, string>
	 */
	protected $last_rows = [];

	/**
	 * Figure kinds that get no marker of their own (see get_figure_kinds()), but that must be in
	 * the passage of the marker the reader sees next to them. Years are left out: Figure Match
	 * lets them come from the question or the source's date.
	 *
	 * @var string[]
	 */
	protected const UNCITED_CHECKED_KINDS = [ Figures::KIND_COUNT, Figures::KIND_DECIMAL, Figures::KIND_LARGE_COUNT, Figures::KIND_PERCENT, Figures::KIND_PPT, Figures::KIND_MAGNITUDE ];

	/**
	 * Constructor.
	 *
	 * @param Figures|null $figures Figures helper.
	 */
	public function __construct( ?Figures $figures = null ) {
		$this->figures = $figures ? $figures : new Figures();
	}

	/**
	 * Figure kinds that get a marker.
	 *
	 * @return string[]
	 */
	public function get_figure_kinds(): array {
		$kinds = [
			Figures::KIND_PERCENT,
			Figures::KIND_PPT,
			Figures::KIND_MAGNITUDE,
			Figures::KIND_LARGE_COUNT,
		];

		/**
		 * Filters the figure kinds that get a citation marker. Other figures are still checked
		 * against the passages (Figure_Match_Gate), but not cited one by one.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_citation_figure_kinds
		 * @param {string[]} $kinds Kinds: percent, ppt, magnitude, large_count (1,000 or more),
		 *                          count (under 1,000), year (1900–2099), decimal.
		 *                          Default: percent, ppt, magnitude, large_count.
		 * @return {string[]} Filtered kinds.
		 */
		$kinds = apply_filters( 'ep_chat_citation_figure_kinds', $kinds );

		return array_values( array_map( 'strval', is_array( $kinds ) ? $kinds : [] ) );
	}

	/**
	 * Add markers to an answer.
	 *
	 * @param string $answer   Answer text (Markdown).
	 * @param array  $passages Numbered passages the model saw (`number`, `title`, `url`, `excerpt`, `date`).
	 * @return array{content: string, provenance: array[]} The answer with markers, and one
	 *               provenance entry per marker: `k`, `n` (passage number), `figure` (as
	 *               written in the answer, empty for a sentence without figures), `quote`,
	 *               `marks` (`[ start, end ]` character ranges of the figures in the quote),
	 *               `title`, `url`, `date`, `verify_url`.
	 */
	public function attribute( string $answer, array $passages ): array {
		$content = $this->strip_markers( $answer );
		$sources = $this->prepare_sources( $passages );
		$inserts = [];
		$kinds   = array_flip( $this->get_figure_kinds() );
		$refusal = Gates::get_gate( Refusal_Gate::class );

		$this->last_rows = [];

		if ( '' === trim( $content ) || ! $sources ) {
			return [
				'content'    => $content,
				'provenance' => [],
			];
		}

		foreach ( $this->split_sentences( $content ) as [ $sentence, $offset ] ) {
			$words   = Text::get_content_words( $sentence );
			$located = $this->figures->locate( $sentence );
			$figures = array_values(
				array_filter(
					$located,
					function ( $figure ) use ( $kinds ) {
						return isset( $kinds[ $figure['kind'] ] );
					}
				)
			);

			// Counts and decimals are not cited one by one, but they must not borrow a marker whose
			// passage lacks them (see add_uncovered_figures()).
			$others = array_values(
				array_filter(
					$located,
					function ( $figure ) use ( $kinds, $sentence ) {
						return ! isset( $kinds[ $figure['kind'] ] )
							&& in_array( $figure['kind'], self::UNCITED_CHECKED_KINDS, true )
							&& ! $this->is_list_number( $sentence, $figure );
					}
				)
			);

			if ( $figures ) {
				$figures = $this->add_local_years( $figures, $located );
				$groups  = [];

				foreach ( $figures as $figure ) {
					$source = $this->choose_source( $sources, $words, $figure['figure'], $figures, $sentence, $figure );

					if ( null !== $source ) {
						$groups[ $source ][] = $figure;
					}
				}

				$groups = $this->add_uncovered_figures( $groups, $others, $sources, $words, $figures );

				// One marker per source, after the last of its figures.
				foreach ( $groups as $source => $group ) {
					$last      = end( $group );
					$inserts[] = [
						'at'       => $offset + $this->after_emphasis( $sentence, $last['end'] ),
						'source'   => $source,
						'figures'  => $group,
						'words'    => $words,
						'sentence' => $sentence,
					];
				}

				continue;
			}

			// Headings, table rules, lines that introduce a list ("…by age:"), and sentences that
			// say what the sources lack ("the sources do not contain…") are not facts to cite.
			if ( $this->is_structural( $sentence ) || ':' === substr( rtrim( $sentence, " *_\t" ), -1 ) || $refusal->has_refusal_phrase( $sentence ) ) {
				continue;
			}

			$source = $this->choose_source( $sources, $words, null, [] );

			if ( null !== $source && $this->shared_count( $words, $sources[ $source ]['words'] ) >= self::MIN_SHARED_WORDS ) {
				$inserts[] = [
					'at'       => $offset + $this->before_final_punctuation( $sentence ),
					'source'   => $source,
					'figures'  => [],
					'words'    => $words,
					'sentence' => $sentence,
				];

				// The sentence's marker must not seem to back a count its passage lacks.
				foreach ( $this->add_uncovered_figures( [ $source => [] ], $others, $sources, $words, [] ) as $extra => $group ) {
					if ( $extra !== $source && $group ) {
						$last      = end( $group );
						$inserts[] = [
							'at'       => $offset + $this->after_emphasis( $sentence, $last['end'] ),
							'source'   => $extra,
							'figures'  => $group,
							'words'    => $words,
							'sentence' => $sentence,
						];
					}
				}
			}
		}

		usort(
			$inserts,
			function ( $a, $b ) {
				return $a['at'] <=> $b['at'];
			}
		);

		$provenance = [];
		$output     = '';
		$cursor     = 0;

		foreach ( $inserts as $index => $insert ) {
			$k            = $index + 1;
			$provenance[] = $this->build_provenance( $k, $insert['source'], $sources[ $insert['source'] ], $insert );
			$output      .= substr( $content, $cursor, $insert['at'] - $cursor ) . '[[' . $k . ']]';
			$cursor       = $insert['at'];
		}

		$output .= substr( $content, $cursor );

		return [
			'content'    => $output,
			'provenance' => $provenance,
		];
	}

	/**
	 * Give each figure of a sentence that the sentence's markers do not back its own marker, to a
	 * passage that has it. Without this, "Across the 24 countries surveyed, a median of 58%¹"
	 * shows 24 as if source 1 said it, when only source 2 does. A figure no passage has gets no
	 * marker (Figure Match already declines such an answer).
	 *
	 * @param array $groups  Figures by source position, from the cited figures.
	 * @param array $others  Located figures of kinds that get no marker of their own.
	 * @param array $sources Prepared sources.
	 * @param array $words   Sentence content words.
	 * @param array $figures The sentence's cited figures.
	 * @return array Groups, each sorted by position in the sentence.
	 */
	protected function add_uncovered_figures( array $groups, array $others, array $sources, array $words, array $figures ): array {
		foreach ( $others as $figure ) {
			$covered = false;

			foreach ( array_keys( $groups ) as $source ) {
				if ( isset( $sources[ $source ]['grounded'][ $figure['figure'] ] ) ) {
					$covered = true;
					break;
				}
			}

			if ( $covered ) {
				continue;
			}

			$source = $this->choose_source( $sources, $words, $figure['figure'], array_merge( $figures, $others ) );

			if ( null !== $source ) {
				$groups[ $source ][] = $figure;
			}
		}

		foreach ( $groups as $source => $group ) {
			usort(
				$group,
				function ( $a, $b ) {
					return $a['start'] <=> $b['start'];
				}
			);

			$groups[ $source ] = $group;
		}

		return $groups;
	}

	/**
	 * Whether a figure is a list item's number ("1. About…", "2) …") at the start of a line.
	 *
	 * @param string $sentence Sentence.
	 * @param array  $figure   Located figure: `start`, `end`.
	 * @return bool
	 */
	protected function is_list_number( string $sentence, array $figure ): bool {
		return (bool) preg_match( '/^\s*[*_]*$/u', substr( $sentence, 0, $figure['start'] ) )
			&& (bool) preg_match( '/^[.)]\s/u', substr( $sentence, $figure['end'], 2 ) );
	}

	/**
	 * Remove `[[…]]` markers (and the space before them).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function strip_markers( string $text ): string {
		return Text::strip_markers( $text, true );
	}

	/**
	 * The year each marked figure refers to, when the answer says it next to the figure:
	 * after it ("34% in 2018"), else before it ("In 2026, 12%…"), never past another figure.
	 * Looser than Figure_Context::read_answer_year(), which needs a word that ties the two:
	 * this year only picks the table column and quote to cite, and declines nothing.
	 *
	 * @param array[] $figures Marked figures of a sentence, located.
	 * @param array[] $located Every figure of the sentence, located.
	 * @return array[] The figures with `year` (normalized, or empty).
	 */
	protected function add_local_years( array $figures, array $located ): array {
		$years = array_values(
			array_filter(
				$located,
				function ( $figure ) {
					return Figures::KIND_YEAR === $figure['kind'];
				}
			)
		);
		$count = count( $figures );

		foreach ( $figures as $index => $figure ) {
			$next = $index + 1 < $count ? $figures[ $index + 1 ]['start'] : PHP_INT_MAX;
			$prev = $index > 0 ? $figures[ $index - 1 ]['end'] : 0;
			$year = '';

			foreach ( $years as $candidate ) {
				if ( $candidate['start'] >= $figure['end'] && $candidate['start'] < $next ) {
					$year = $candidate['figure'];
					break;
				}
			}

			if ( '' === $year ) {
				foreach ( $years as $candidate ) {
					if ( $candidate['start'] >= $prev && $candidate['end'] <= $figure['start'] ) {
						$year = $candidate['figure'];
					}
				}
			}

			$figures[ $index ]['year'] = $year;
		}

		return $figures;
	}

	/**
	 * Passages with what attribution needs, keyed by position.
	 *
	 * @param array $passages Passages.
	 * @return array[]
	 */
	protected function prepare_sources( array $passages ): array {
		$sources = [];

		foreach ( $passages as $passage ) {
			if ( ! is_array( $passage ) ) {
				continue;
			}

			$title   = trim( (string) ( $passage['title'] ?? '' ) );
			$excerpt = trim( (string) ( $passage['excerpt'] ?? '' ) );
			$lines   = $this->get_body_lines( $excerpt, $title );

			$sources[] = [
				'n'         => (int) ( $passage['number'] ?? count( $sources ) + 1 ),
				'title'     => $title,
				'url'       => (string) ( $passage['url'] ?? '' ),
				'date'      => (string) ( $passage['date'] ?? '' ),
				'text'      => trim( $title . "\n" . $excerpt ),
				'grounded'  => $this->figures->grounded( [ trim( $title . "\n" . $excerpt ) ] ),
				'words'     => Text::get_content_words( $title . "\n" . $excerpt ),
				'sentences' => $this->get_source_sentences( $lines ),
				'tables'    => $this->get_tables( $lines ),
			];
		}

		return $sources;
	}

	/**
	 * Lines of a passage without chunk context lines ("Title › Heading") and headings.
	 *
	 * A passage can join several chunks, each opening with its context line. Inside a meta
	 * chunk (`# Metadata`, then `## key: value` lines), each field is its value.
	 *
	 * @param string $excerpt Passage text.
	 * @param string $title   Passage title.
	 * @return string[]
	 */
	protected function get_body_lines( string $excerpt, string $title ): array {
		$body    = [];
		$in_meta = false;

		foreach ( array_filter( array_map( 'trim', explode( "\n", $excerpt ) ), 'strlen' ) as $line ) {
			if ( false !== strpos( $line, ' › ' ) || ( '' !== $title && $line === $title ) ) {
				// A context line opens the next chunk.
				$in_meta = false;
				continue;
			}

			if ( '# Metadata' === $line ) {
				$in_meta = true;
				continue;
			}

			if ( $in_meta && preg_match( '/^##\s*[^:]+:\s*(.+)$/u', $line, $match ) ) {
				$body[] = trim( $match[1] );
				continue;
			}

			if ( 0 !== strpos( $line, '#' ) ) {
				$body[] = $line;
			}
		}

		return $body;
	}

	/**
	 * Sentences of the prose lines of a passage body.
	 *
	 * @param string[] $lines Body lines.
	 * @return string[]
	 */
	protected function get_source_sentences( array $lines ): array {
		$sentences = [];

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, '|' ) ) {
				continue;
			}

			foreach ( $this->split_sentences( $line ) as [ $sentence ] ) {
				$sentences[] = trim( $sentence );
			}
		}

		return $sentences;
	}

	/**
	 * Markdown tables of a passage body: header row, data rows, caption, and whether bare numbers
	 * are percentages (Figures::is_percentage_table(), the rule Figures::grounded() uses).
	 *
	 * The caption is the prose line right above the table (a chart subtitle such as "% of U.S.
	 * adults who…"). Text fragments cannot target a table row, so a table quote links to it.
	 *
	 * @param string[] $lines Body lines.
	 * @return array[] `[ 'header' => string, 'caption' => string, 'percent' => bool, 'rows' => string[] ]`.
	 */
	protected function get_tables( array $lines ): array {
		$tables = [];
		$count  = count( $lines );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( 0 !== strpos( $lines[ $i ], '|' ) ) {
				continue;
			}

			$start = $i;
			$rows  = [];

			while ( $i + 1 < $count && 0 === strpos( $lines[ $i + 1 ], '|' ) ) {
				++$i;

				if ( ! preg_match( '/^\|[\s:|-]+\|?$/', $lines[ $i ] ) ) {
					$rows[] = $lines[ $i ];
				}
			}

			$tables[] = [
				'header'  => $lines[ $start ],
				'caption' => $start > 0 && 0 !== strpos( $lines[ $start - 1 ], '|' ) ? $lines[ $start - 1 ] : '',
				'percent' => $this->figures->is_percentage_table( $lines, $start ),
				'rows'    => $rows,
			];
		}

		return $tables;
	}

	/**
	 * The passage for a figure (or for a sentence without one).
	 *
	 * Among the passages that contain the figure: the one that gives it for the population and the
	 * year the sentence gives it for (see Figure_Context), then the one that contains the most
	 * figures of the sentence, then the one sharing the most content words with it, then the
	 * first.
	 *
	 * @param array[]     $sources  Sources.
	 * @param array       $words    Sentence content words.
	 * @param string|null $figure   Normalized figure, or null to consider every passage.
	 * @param array[]     $figures  Marked figures of the sentence, located.
	 * @param string      $sentence The sentence (for the population and year of the figure).
	 * @param array       $located  The figure, located in the sentence.
	 * @return int|null Source position; null when no passage has the figure.
	 */
	protected function choose_source( array $sources, array $words, ?string $figure, array $figures, string $sentence = '', array $located = [] ): ?int {
		$best    = null;
		$score   = [ -1, -1, -1 ];
		$context = new Figure_Context();
		$wanted  = null;
		$year    = null;

		// What the sentence says the figure is for: a passage that gives it for that population and
		// year is the right source, even when another passage has more of the sentence's figures.
		if ( null !== $figure && '' !== $sentence && $located ) {
			$plain  = Text::strip_markers( $sentence );
			$shift  = strlen( $sentence ) - strlen( $plain );
			$wanted = $context->read_answer( $plain, $located );
			$year   = $context->read_answer_year( $plain, $located );

			// Markers before the figure move it in the plain text; read again where it is there.
			if ( $shift ) {
				foreach ( $this->figures->locate( $plain ) as $candidate ) {
					if ( $candidate['figure'] === $figure ) {
						$wanted = $context->read_answer( $plain, $candidate );
						$year   = $context->read_answer_year( $plain, $candidate );
						break;
					}
				}
			}
		}

		foreach ( $sources as $index => $source ) {
			if ( null !== $figure && ! isset( $source['grounded'][ $figure ] ) ) {
				continue;
			}

			$grounds = 0;

			foreach ( $figures as $other ) {
				$grounds += isset( $source['grounded'][ $other['figure'] ] ) ? 1 : 0;
			}

			$fit       = null !== $wanted ? $context->fit( (string) $figure, $wanted, $year, $source ) : 0;
			$candidate = [ $fit, $grounds, $this->shared_count( $words, $source['words'] ) ];

			if ( $candidate > $score ) {
				$best  = $index;
				$score = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Provenance entry for one marker.
	 *
	 * @param int   $k        Marker number.
	 * @param int   $position Source position.
	 * @param array $source   Source.
	 * @param array $insert   Marker: `figures` (located answer figures bound to it, empty for a
	 *                        sentence), `words` (sentence content words), `sentence`.
	 * @return array
	 */
	protected function build_provenance( int $k, int $position, array $source, array $insert ): array {
		$figures = $insert['figures'];
		$rows    = [];
		$prose   = [];

		foreach ( $figures as $figure ) {
			$row = $this->find_table_row( $position, $source['tables'], $figure, $insert['sentence'] );

			if ( null !== $row ) {
				$rows[ $row['key'] ]['table']    = $row['table'];
				$rows[ $row['key'] ]['row']      = $row['row'];
				$rows[ $row['key'] ]['ranges'][] = $row['range'];
				continue;
			}

			$found = $this->find_source_sentence( $source['sentences'], $figure );

			if ( null !== $found ) {
				$prose[ $found['index'] ][] = $found['range'];
			}
		}

		if ( ! $figures ) {
			$best = $this->find_best_sentence( $source['sentences'], $insert['words'] );

			if ( null !== $best ) {
				$prose[ $best ] = [];
			}
		}

		[ $quote, $marks ] = $this->compose_quote( $source, $rows, $prose );

		$verify = $source['url'];
		$target = '';

		if ( $prose ) {
			ksort( $prose );
			$target = $source['sentences'][ array_key_first( $prose ) ];
		} elseif ( $rows ) {
			$first  = reset( $rows );
			$target = $source['tables'][ $first['table'] ]['caption'];
		}

		if ( '' !== $target && '' !== $verify ) {
			$verify = $this->get_verify_url( $verify, $target );
		}

		return [
			'k'          => $k,
			'n'          => $source['n'],
			'figure'     => implode( ', ', wp_list_pluck( $figures, 'text' ) ),
			'quote'      => $quote,
			'marks'      => $marks,
			'title'      => $source['title'],
			'url'        => $source['url'],
			'date'       => $source['date'],
			'verify_url' => $verify,
		];
	}

	/**
	 * The table row that holds a figure.
	 *
	 * The column is the header cell that shares the most numbers and words with the answer
	 * sentence ("Ages 18–29: 51%" → the "Ages 18-29" column), when one does. Among the rows with
	 * the figure in that column, a row with the figure's year wins, then the row this source
	 * quoted last (list items of an answer usually walk one row), then the first.
	 *
	 * @param int     $position Source position.
	 * @param array[] $tables   Source tables.
	 * @param array   $figure   Located answer figure, with `year`.
	 * @param string  $sentence Answer sentence.
	 * @return array|null `[ key, table, row, range ]`, range in bytes of the row.
	 */
	protected function find_table_row( int $position, array $tables, array $figure, string $sentence ): ?array {
		$best = null;

		foreach ( $tables as $t => $table ) {
			$column = $this->find_column( $table['header'], $sentence, $figure['year'] );

			foreach ( $table['rows'] as $r => $row ) {
				$range = null;
				$year  = false;

				foreach ( $this->figures->locate( $row ) as $found ) {
					$cell = substr_count( substr( $row, 0, $found['start'] ), '|' ) - 1;

					if ( '' !== $figure['year'] && Figures::KIND_YEAR === $found['kind'] && $found['figure'] === $figure['year'] ) {
						$year = true;
					}

					if ( null === $range && ( null === $column || $cell === $column ) && $this->figures->is_same_figure( $found, $figure['figure'], $table['percent'] ) ) {
						$range = [ $found['start'], $found['end'] ];
					}
				}

				if ( null === $range ) {
					continue;
				}

				$key   = $t . ':' . $r;
				$score = [ $year ? 1 : 0, ( $this->last_rows[ $position ] ?? '' ) === $key ? 1 : 0 ];

				if ( null === $best || $score > $best['score'] ) {
					$best = [
						'score' => $score,
						'key'   => $key,
						'table' => $t,
						'row'   => $r,
						'range' => $range,
					];
				}
			}
		}

		if ( null === $best ) {
			return null;
		}

		$this->last_rows[ $position ] = $best['key'];

		return $best;
	}

	/**
	 * The column (from 0, as cells are counted in rows) a figure is in, from the table header.
	 *
	 * A header cell with the figure's year ("| | 2018 | 2022 |", "46% in 2022") wins. Else the
	 * one header cell that shares the most numbers, then words, with the answer sentence
	 * ("Ages 18–29: 51%" → "Ages 18-29"). Null when no cell (or more than one) does.
	 *
	 * @param string $header   Table header row.
	 * @param string $sentence Answer sentence.
	 * @param string $year     Normalized year of the figure, or empty.
	 * @return int|null
	 */
	protected function find_column( string $header, string $sentence, string $year ): ?int {
		preg_match_all( '/\d+/', $sentence, $numbers );

		$numbers = array_fill_keys( $numbers[0], true );
		$words   = Text::get_content_words( $sentence );
		$cells   = array_slice( explode( '|', $header ), 1 ); // Before the first `|` is not a cell.
		$best    = null;
		$top     = [ 0, 0 ];
		$tie     = false;

		foreach ( $cells as $index => $cell ) {
			if ( '' !== $year && (bool) preg_match( '/(?<!\d)' . preg_quote( $year, '/' ) . '(?!\d)/', $cell ) ) {
				return $index;
			}

			preg_match_all( '/\d+/', $cell, $cell_numbers );

			$score = [
				count( array_intersect_key( array_fill_keys( $cell_numbers[0], true ), $numbers ) ),
				$this->shared_count( $words, Text::get_content_words( $cell ) ),
			];

			if ( $score > $top ) {
				$best = $index;
				$top  = $score;
				$tie  = false;
			} elseif ( $score === $top && [ 0, 0 ] !== $score ) {
				$tie = true;
			}
		}

		return $tie ? null : $best;
	}

	/**
	 * The first source sentence with a figure.
	 *
	 * @param string[] $sentences Source sentences.
	 * @param array    $figure    Located answer figure.
	 * @return array|null `[ index, range ]`, range in bytes of the sentence.
	 */
	protected function find_source_sentence( array $sentences, array $figure ): ?array {
		foreach ( $sentences as $index => $sentence ) {
			foreach ( $this->figures->locate( $sentence ) as $found ) {
				if ( $found['figure'] === $figure['figure'] ) {
					return [
						'index' => $index,
						'range' => [ $found['start'], $found['end'] ],
					];
				}
			}
		}

		return null;
	}

	/**
	 * The source sentence sharing the most content words with an answer sentence.
	 *
	 * @param string[] $sentences Source sentences.
	 * @param array    $words     Answer sentence content words.
	 * @return int|null Sentence position.
	 */
	protected function find_best_sentence( array $sentences, array $words ): ?int {
		$best  = null;
		$score = 0;

		foreach ( $sentences as $index => $sentence ) {
			$shared = $this->shared_count( $words, Text::get_content_words( $sentence ) );

			if ( $shared > $score ) {
				$best  = $index;
				$score = $shared;
			}
		}

		return $best;
	}

	/**
	 * Quote text and figure ranges, in characters.
	 *
	 * Table rows come first, under their header row, in table order. Then source sentences in
	 * order, joined by " … ": the first clipped to QUOTE_LENGTH around its first figure, the
	 * others to EXTRA_QUOTE_LENGTH, at most MAX_QUOTED_SENTENCES.
	 *
	 * @param array $source Source.
	 * @param array $rows   Rows by `table:row` key: `table`, `row`, `ranges` (bytes of the row).
	 * @param array $prose  Byte ranges by source sentence position.
	 * @return array{0: string, 1: array[]} Quote and `[ start, end ]` character ranges.
	 */
	protected function compose_quote( array $source, array $rows, array $prose ): array {
		$parts = [];
		$marks = [];
		$at    = 0;

		$add = function ( string $text, array $ranges ) use ( &$parts, &$marks, &$at ) {
			if ( $parts ) {
				++$at; // The newline between parts.
			}

			foreach ( $ranges as [ $start, $end ] ) {
				$marks[] = [ $at + $start, $at + $end ];
			}

			$parts[] = $text;
			$at     += mb_strlen( $text, 'UTF-8' );
		};

		uasort(
			$rows,
			function ( $a, $b ) {
				return [ $a['table'], $a['row'] ] <=> [ $b['table'], $b['row'] ];
			}
		);

		$header = null;

		foreach ( $rows as $row ) {
			if ( $header !== $row['table'] ) {
				$header = $row['table'];
				$add( $source['tables'][ $row['table'] ]['header'], [] );
			}

			$text = $source['tables'][ $row['table'] ]['rows'][ $row['row'] ];
			$add( $text, $this->to_char_ranges( $text, $row['ranges'] ) );
		}

		ksort( $prose );

		$sentences = [];
		$ranges    = [];
		$offset    = 0;

		foreach ( array_slice( $prose, 0, self::MAX_QUOTED_SENTENCES, true ) as $index => $byte_ranges ) {
			$sentence = $source['sentences'][ $index ];
			$chars    = $this->to_char_ranges( $sentence, $byte_ranges );
			$clipped  = $this->clip( $sentence, $chars, $sentences ? self::EXTRA_QUOTE_LENGTH : self::QUOTE_LENGTH );

			if ( $sentences ) {
				$offset += 3; // " … ".
			}

			foreach ( $clipped['ranges'] as [ $start, $end ] ) {
				$ranges[] = [ $offset + $start, $offset + $end ];
			}

			$sentences[] = $clipped['text'];
			$offset     += mb_strlen( $clipped['text'], 'UTF-8' );
		}

		if ( $sentences ) {
			$add( implode( ' … ', $sentences ), $ranges );
		}

		return [ implode( "\n", $parts ), $marks ];
	}

	/**
	 * Byte ranges of a text as character ranges.
	 *
	 * @param string  $text   Text.
	 * @param array[] $ranges `[ start, end ]` byte ranges.
	 * @return array[]
	 */
	protected function to_char_ranges( string $text, array $ranges ): array {
		return array_map(
			function ( $range ) use ( $text ) {
				return [ mb_strlen( substr( $text, 0, $range[0] ), 'UTF-8' ), mb_strlen( substr( $text, 0, $range[1] ), 'UTF-8' ) ];
			},
			$ranges
		);
	}

	/**
	 * Clip a sentence to a length in characters, centered on its first range, with ellipses.
	 * Words are not cut; ranges outside the clip are dropped.
	 *
	 * @param string  $sentence Sentence.
	 * @param array[] $ranges   `[ start, end ]` character ranges.
	 * @param int     $limit    Longest text, in characters.
	 * @return array{text: string, ranges: array[]}
	 */
	protected function clip( string $sentence, array $ranges, int $limit ): array {
		$length = mb_strlen( $sentence, 'UTF-8' );

		if ( $length <= $limit ) {
			return [
				'text'   => $sentence,
				'ranges' => $ranges,
			];
		}

		$budget = $limit - 2; // Room for the ellipses.
		$center = $ranges ? (int) ( ( $ranges[0][0] + $ranges[0][1] ) / 2 ) : 0;
		$from   = max( 0, min( $center - (int) ( $budget / 2 ), $length - $budget ) );
		$text   = mb_substr( $sentence, $from, $budget, 'UTF-8' );
		$cut    = $from + $budget < $length;

		// Drop the partial words at the cut edges.
		if ( $from > 0 && preg_match( '/^\S*\s+/u', $text, $match ) ) {
			$from += mb_strlen( $match[0], 'UTF-8' );
			$text  = mb_substr( $text, mb_strlen( $match[0], 'UTF-8' ), null, 'UTF-8' );
		}

		if ( $cut ) {
			$text = (string) preg_replace( '/\s+\S*$/u', '', $text );
		}

		$prefix = $from > 0 ? '…' : '';
		$shift  = mb_strlen( $prefix, 'UTF-8' ) - $from;
		$end    = $from + mb_strlen( $text, 'UTF-8' );
		$kept   = [];

		foreach ( $ranges as [ $start, $stop ] ) {
			if ( $start >= $from && $stop <= $end ) {
				$kept[] = [ $start + $shift, $stop + $shift ];
			}
		}

		return [
			'text'   => $prefix . $text . ( $cut ? '…' : '' ),
			'ranges' => $kept,
		];
	}

	/**
	 * Source URL with a text fragment (`#:~:text=start,end`) for a sentence.
	 *
	 * Browsers that support text fragments scroll to the sentence and highlight it; others
	 * open the page. Long sentences use their first and last four words.
	 *
	 * @param string $url      Source URL.
	 * @param string $sentence Source sentence.
	 * @return string
	 */
	public function get_verify_url( string $url, string $sentence ): string {
		$words = preg_split( '/\s+/u', trim( (string) preg_replace( '/[*_`|#]+/u', ' ', $sentence ) ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! $words ) {
			return $url;
		}

		if ( count( $words ) <= 8 ) {
			$fragment = $this->encode_fragment( implode( ' ', $words ) );
		} else {
			$fragment = $this->encode_fragment( implode( ' ', array_slice( $words, 0, 4 ) ) )
				. ',' . $this->encode_fragment( implode( ' ', array_slice( $words, -4 ) ) );
		}

		$url = (string) preg_replace( '/#.*$/', '', $url );

		return $url . '#:~:text=' . $fragment;
	}

	/**
	 * Percent-encode text for a text fragment (`-`, `,`, and `&` are syntax there).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function encode_fragment( string $text ): string {
		return str_replace( '-', '%2D', rawurlencode( $text ) );
	}

	/**
	 * Sentences of a text, with their byte offsets. Lines split too (list items, rows).
	 *
	 * @param string $text Text.
	 * @return array[] `[ sentence, offset ]` pairs, without empty ones.
	 */
	protected function split_sentences( string $text ): array {
		$parts     = preg_split( '/(?<=[.!?])(?<!\b[A-Z]\.)[\s]+(?=[A-Z0-9"“(\[*_])|\n+/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE );
		$sentences = [];

		foreach ( is_array( $parts ) ? $parts : [] as [ $part, $offset ] ) {
			$trimmed = rtrim( $part );

			if ( '' !== trim( $trimmed ) ) {
				$sentences[] = [ $trimmed, $offset ];
			}
		}

		return $sentences;
	}

	/**
	 * Number of words two sets share.
	 *
	 * @param array $a Word set.
	 * @param array $b Word set.
	 * @return int
	 */
	protected function shared_count( array $a, array $b ): int {
		return count( array_intersect_key( $a, $b ) );
	}

	/**
	 * Whether a line is a heading or a table rule, which never gets a sentence marker.
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	protected function is_structural( string $sentence ): bool {
		$line = ltrim( $sentence );

		return 0 === strpos( $line, '#' ) || (bool) preg_match( '/^\|?[\s:|-]+\|?$/', $line );
	}

	/**
	 * Position after a figure and any closing emphasis (`**42%**` → after the `**`).
	 *
	 * @param string $sentence Sentence.
	 * @param int    $end      Figure end (bytes).
	 * @return int
	 */
	protected function after_emphasis( string $sentence, int $end ): int {
		return preg_match( '/\G[*_]+/u', $sentence, $match, 0, $end ) ? $end + strlen( $match[0] ) : $end;
	}

	/**
	 * Position at the end of a sentence, before its final punctuation (`cash.` → before `.`).
	 *
	 * @param string $sentence Sentence.
	 * @return int
	 */
	protected function before_final_punctuation( string $sentence ): int {
		return preg_match( '/[.!?:;,]+$/u', $sentence, $match ) ? strlen( $sentence ) - strlen( $match[0] ) : strlen( $sentence );
	}
}
