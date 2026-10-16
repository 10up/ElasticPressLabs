<?php
/**
 * Vector Embeddings - Chunker
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Splits normalized text into chunks at paragraph and section boundaries.
 *
 * Input is the plain text `VectorEmbeddings::normalize_content()` returns: one paragraph
 * per line, headings as Markdown heading lines (`## Methodology`), and tables as Markdown
 * table lines (`| 2026 | 12 |`).
 *
 * - Whole paragraphs are packed into a chunk until the next one does not fit.
 * - A paragraph longer than a chunk is split at sentence ends, then at words.
 * - A table longer than a chunk is split at row boundaries; every piece repeats the
 *   table's header row.
 * - A heading starts a new chunk (unless the current chunk is still very small), so an
 *   edit only changes the chunks of its own section.
 * - Each chunk starts with the previous chunk's last whole sentences, up to the overlap
 *   size, never across a heading.
 *
 * Level-1 headings (`# Title`, `# Content`) are the section labels Labs itself writes;
 * they reset the heading path but do not start a new chunk.
 */
class Chunker {
	/**
	 * Maximum words per chunk.
	 *
	 * @var int
	 */
	protected $chunk_size;

	/**
	 * Maximum words carried over from the previous chunk.
	 *
	 * @var int
	 */
	protected $overlap_size;

	/**
	 * Class constructor.
	 *
	 * @param int $chunk_size   Maximum words per chunk.
	 * @param int $overlap_size Maximum words carried over from the previous chunk.
	 */
	public function __construct( int $chunk_size, int $overlap_size ) {
		$this->chunk_size   = max( 1, $chunk_size );
		$this->overlap_size = max( 0, min( $overlap_size, $this->chunk_size - 1 ) );
	}

	/**
	 * Chunk sectioned text (a post's title, summary, and content).
	 *
	 * @param string $text Normalized text.
	 * @return array[] `[ 'text' => string, 'headings' => string[] ]` per chunk. `headings` is the
	 *                 heading path (level 2 and below) where the chunk starts.
	 */
	public function chunk_sections( string $text ): array {
		$chunks   = [];
		$current  = [];
		$words    = 0;
		$headings = [];
		$start    = [];
		$min_size = max( 1, (int) floor( $this->chunk_size / 4 ) );

		// A chunk's heading path is the path when its content starts: headings before the
		// first paragraph (`# Content`, then `#### Box`) all count.
		$add = function ( array $unit ) use ( &$current, &$words, &$headings, &$start ) {
			if ( ! $this->has_content( $current ) ) {
				$start = wp_list_pluck( $headings, 'title' );
			}

			$current[] = $unit;
			$words    += $unit['words'];
		};

		$flush = function ( bool $with_overlap ) use ( &$chunks, &$current, &$words, &$start, $add ) {
			// A chunk never ends with a heading: trailing headings move to the next chunk.
			$trailing = [];
			while ( $current && 'heading' === end( $current )['kind'] ) {
				array_unshift( $trailing, array_pop( $current ) );
			}

			if ( $current ) {
				$chunks[] = [
					'text'     => implode( "\n", wp_list_pluck( $current, 'text' ) ),
					'headings' => $start,
				];
			}

			$overlap = ( $with_overlap && ! $trailing ) ? $this->get_overlap( $current ) : '';
			$current = [];
			$words   = 0;

			if ( '' !== $overlap ) {
				$add(
					[
						'kind'    => 'text',
						'text'    => $overlap,
						'words'   => $this->count_words( $overlap ),
						'overlap' => true,
					]
				);
			}

			foreach ( $trailing as $unit ) {
				$add( $unit );
			}
		};

		foreach ( $this->get_section_units( $text ) as $unit ) {
			if ( 'heading' === $unit['kind'] ) {
				// A content heading starts a new chunk (no overlap: it is a new section), unless
				// the current chunk is still very small. The level-1 labels Labs writes (Title,
				// Summary, Content) do not: the title and summary lead into the content.
				if ( $unit['level'] > 1 && $words >= $min_size && $this->has_content( $current ) ) {
					$flush( false );
				}

				// Overlap never crosses a heading.
				if ( $current && ! array_filter( $current, [ $this, 'is_not_overlap' ] ) ) {
					$current = [];
					$words   = 0;
				}

				$headings = $this->update_headings( $headings, $unit );
				$add( $unit );
				continue;
			}

			foreach ( $this->fit_unit( $unit ) as $piece ) {
				if ( $this->has_content( $current ) && $words + $piece['words'] > $this->chunk_size ) {
					$flush( true );

					// Drop the overlap when the piece would not fit with it.
					if ( $words + $piece['words'] > $this->chunk_size && ! empty( $current[0]['overlap'] ) ) {
						$current = [];
						$words   = 0;
					}
				}

				$add( $piece );
			}
		}

		$flush( false );

		return $chunks;
	}

	/**
	 * Chunk labelled lines: a `# Header` line and `## label: value` lines (taxonomy terms,
	 * meta fields).
	 *
	 * Lines are packed like paragraphs. A value longer than a chunk is split at sentences,
	 * then commas, then words; every piece repeats its label, and every chunk starts with
	 * the header line.
	 *
	 * @param string $text Normalized text.
	 * @return array[] `[ 'text' => string, 'headings' => [] ]` per chunk.
	 */
	public function chunk_labelled( string $text ): array {
		$header = '';
		$units  = [];

		foreach ( $this->get_lines( $text ) as $line ) {
			if ( preg_match( '/^#\s+(.+)$/u', $line ) && ! $units ) {
				$header = $line;
				continue;
			}

			if ( preg_match( '/^(##\s*[^:]+:)\s*(.*)$/u', $line, $matches ) ) {
				$units[] = [
					'label' => $matches[1],
					'value' => $matches[2],
				];
				continue;
			}

			// Lines without a label continue the previous value (multi-line meta values).
			if ( $units ) {
				$last = count( $units ) - 1;

				$units[ $last ]['value'] = trim( $units[ $last ]['value'] . ' ' . $line );
			} else {
				$units[] = [
					'label' => '',
					'value' => $line,
				];
			}
		}

		$header_words = $this->count_words( $header );
		$budget       = max( 1, $this->chunk_size - $header_words );
		$chunks       = [];
		$current      = [];
		$words        = 0;

		$flush = function () use ( &$chunks, &$current, &$words, $header ) {
			if ( $current ) {
				$chunks[] = [
					'text'     => implode( "\n", array_filter( array_merge( [ $header ], $current ) ) ),
					'headings' => [],
				];
			}

			$current = [];
			$words   = 0;
		};

		foreach ( $units as $unit ) {
			$label       = $unit['label'];
			$label_words = $this->count_words( $label );
			$pieces      = [ $unit['value'] ];

			if ( $label_words + $this->count_words( $unit['value'] ) > $budget ) {
				$pieces = $this->split_text( $unit['value'], max( 1, $budget - $label_words ), true );
			}

			foreach ( $pieces as $piece ) {
				$line       = trim( $label . ' ' . $piece );
				$line_words = $this->count_words( $line );

				if ( $current && $words + $line_words > $budget ) {
					$flush();
				}

				$current[] = $line;
				$words    += $line_words;
			}
		}

		$flush();

		return $chunks;
	}

	/**
	 * Split sectioned text into units: headings, paragraphs, and tables.
	 *
	 * @param string $text Normalized text.
	 * @return array[] `kind` (heading, text, table), `text`, `words`, and for headings `level` and `title`.
	 */
	protected function get_section_units( string $text ): array {
		$units = [];
		$table = [];

		$close_table = function () use ( &$units, &$table ) {
			if ( $table ) {
				$units[] = [
					'kind'  => 'table',
					'lines' => $table,
					'text'  => implode( "\n", $table ),
					'words' => $this->count_words( implode( ' ', $table ) ),
				];
			}

			$table = [];
		};

		foreach ( $this->get_lines( $text ) as $line ) {
			if ( 0 === strpos( $line, '|' ) ) {
				$table[] = $line;
				continue;
			}

			$close_table();

			if ( preg_match( '/^(#{1,6})\s+(.+)$/u', $line, $matches ) ) {
				$units[] = [
					'kind'  => 'heading',
					'text'  => $line,
					'words' => $this->count_words( $line ),
					'level' => strlen( $matches[1] ),
					'title' => trim( $matches[2] ),
				];
				continue;
			}

			$units[] = [
				'kind'  => 'text',
				'text'  => $line,
				'words' => $this->count_words( $line ),
			];
		}

		$close_table();

		return $units;
	}

	/**
	 * Split a unit that is longer than a chunk.
	 *
	 * @param array $unit Paragraph or table unit.
	 * @return array[] Units that each fit in a chunk.
	 */
	protected function fit_unit( array $unit ): array {
		if ( $unit['words'] <= $this->chunk_size ) {
			return [ $unit ];
		}

		if ( 'table' === $unit['kind'] ) {
			return $this->split_table( $unit['lines'] );
		}

		return array_map(
			function ( $piece ) {
				return [
					'kind'  => 'text',
					'text'  => $piece,
					'words' => $this->count_words( $piece ),
				];
			},
			$this->split_text( $unit['text'], $this->chunk_size )
		);
	}

	/**
	 * Split a long table at row boundaries, repeating the header rows in every piece.
	 *
	 * @param string[] $lines Table lines.
	 * @return array[] Table units.
	 */
	protected function split_table( array $lines ): array {
		$header = [ array_shift( $lines ) ];

		if ( $lines && preg_match( '/^\|(\s*-{3,}\s*\|)+$/', $lines[0] ) ) {
			$header[] = array_shift( $lines );
		}

		$header_words = $this->count_words( implode( ' ', $header ) );
		$pieces       = [];
		$rows         = [];
		$words        = $header_words;

		$close = function () use ( &$pieces, &$rows, &$words, $header, $header_words ) {
			if ( $rows ) {
				$pieces[] = [
					'kind'  => 'table',
					'lines' => array_merge( $header, $rows ),
					'text'  => implode( "\n", array_merge( $header, $rows ) ),
					'words' => $words,
				];
			}

			$rows  = [];
			$words = $header_words;
		};

		foreach ( $lines as $row ) {
			$row_words = $this->count_words( $row );

			// A row that does not fit even with the header alone becomes plain text pieces.
			if ( $header_words + $row_words > $this->chunk_size ) {
				$close();

				foreach ( $this->split_text( $row, $this->chunk_size ) as $piece ) {
					$pieces[] = [
						'kind'  => 'text',
						'text'  => $piece,
						'words' => $this->count_words( $piece ),
					];
				}
				continue;
			}

			if ( $rows && $words + $row_words > $this->chunk_size ) {
				$close();
			}

			$rows[] = $row;
			$words += $row_words;
		}

		$close();

		return $pieces;
	}

	/**
	 * Split text into pieces of at most `$max_words`: at sentence ends, then (for lists) at
	 * commas, then at words.
	 *
	 * @param string $text      Text.
	 * @param int    $max_words Maximum words per piece.
	 * @param bool   $commas    Whether to try commas before words.
	 * @return string[]
	 */
	protected function split_text( string $text, int $max_words, bool $commas = false ): array {
		$pieces  = [];
		$current = '';

		foreach ( $this->split_sentences( $text ) as $sentence ) {
			// A sentence longer than a piece becomes pieces of its own, so the separators
			// between its parts (commas in a list) are kept.
			if ( $this->count_words( $sentence ) > $max_words ) {
				if ( '' !== $current ) {
					$pieces[] = $current;
				}

				$parts   = $commas ? $this->split_list( $sentence, $max_words ) : $this->split_words( $sentence, $max_words );
				$current = (string) array_pop( $parts );
				$pieces  = array_merge( $pieces, $parts );
				continue;
			}

			$joined = '' === $current ? $sentence : $current . ' ' . $sentence;

			if ( '' !== $current && $this->count_words( $joined ) > $max_words ) {
				$pieces[] = $current;
				$joined   = $sentence;
			}

			$current = $joined;
		}

		if ( '' !== $current ) {
			$pieces[] = $current;
		}

		return $pieces;
	}

	/**
	 * Split a comma-separated list into pieces of at most `$max_words`.
	 *
	 * @param string $text      Text.
	 * @param int    $max_words Maximum words per piece.
	 * @return string[]
	 */
	protected function split_list( string $text, int $max_words ): array {
		$pieces  = [];
		$current = '';

		foreach ( preg_split( '/,\s*/u', $text ) as $item ) {
			$items = $this->count_words( $item ) > $max_words ? $this->split_words( $item, $max_words ) : [ $item ];

			foreach ( $items as $part ) {
				$joined = '' === $current ? $part : $current . ', ' . $part;

				if ( '' !== $current && $this->count_words( $joined ) > $max_words ) {
					$pieces[] = $current;
					$joined   = $part;
				}

				$current = $joined;
			}
		}

		if ( '' !== $current ) {
			$pieces[] = $current;
		}

		return $pieces;
	}

	/**
	 * Split text into pieces of `$max_words` words.
	 *
	 * @param string $text      Text.
	 * @param int    $max_words Words per piece.
	 * @return string[]
	 */
	protected function split_words( string $text, int $max_words ): array {
		return array_map(
			function ( $words ) {
				return implode( ' ', $words );
			},
			array_chunk( $this->get_words( $text ), max( 1, $max_words ) )
		);
	}

	/**
	 * Split text into sentences.
	 *
	 * A sentence ends at `.`, `!`, `?`, or `…` followed by a space. Initials and common
	 * abbreviations ("U.S.", "Dr.", "e.g.") do not end a sentence.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public function split_sentences( string $text ): array {
		$parts     = preg_split( '/(?<=[.!?…])["”’)\]]*\K\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$sentences = [];

		foreach ( (array) $parts as $part ) {
			if ( $sentences && $this->ends_with_abbreviation( end( $sentences ) ) ) {
				$sentences[ count( $sentences ) - 1 ] .= ' ' . $part;
				continue;
			}

			$sentences[] = $part;
		}

		return $sentences;
	}

	/**
	 * Whether text ends with an abbreviation or initials rather than a sentence end.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	protected function ends_with_abbreviation( string $text ): bool {
		$abbreviations = 'Mr|Mrs|Ms|Mx|Dr|Prof|Sr|Jr|St|Mt|No|Nos|Vol|Fig|vs|etc|approx|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec|Inc|Ltd|Co|Corp|Gov|Sen|Rep|Gen|Rev';

		return (bool) preg_match( '/(?:^|[\s(])(?:(?:\p{L}\.){1,4}|(?:' . $abbreviations . ')\.|e\.g\.|i\.e\.)$/u', $text );
	}

	/**
	 * The overlap for the next chunk: the last whole sentences of the chunk's last
	 * paragraphs, up to the overlap size.
	 *
	 * @param array $units Units of the chunk that was just closed.
	 * @return string
	 */
	protected function get_overlap( array $units ): string {
		if ( ! $this->overlap_size ) {
			return '';
		}

		$sentences = [];
		$words     = 0;

		foreach ( array_reverse( $units ) as $unit ) {
			if ( 'text' !== $unit['kind'] ) {
				break;
			}

			foreach ( array_reverse( $this->split_sentences( $unit['text'] ) ) as $sentence ) {
				$sentence_words = $this->count_words( $sentence );

				if ( $words + $sentence_words > $this->overlap_size ) {
					break 2;
				}

				array_unshift( $sentences, $sentence );
				$words += $sentence_words;
			}
		}

		return implode( ' ', $sentences );
	}

	/**
	 * The heading path after a heading.
	 *
	 * Headings at the same or a deeper level than the new one are closed, so skipped
	 * levels (an `h4` right after the title) and siblings work.
	 *
	 * @param array[] $headings Current path: `[ 'level' => int, 'title' => string ]`, outermost first.
	 * @param array   $unit     Heading unit.
	 * @return array[]
	 */
	protected function update_headings( array $headings, array $unit ): array {
		// Level 1 is the section Labs writes (Title, Summary, Content): it starts a new path.
		if ( 1 === $unit['level'] ) {
			return [];
		}

		while ( $headings && end( $headings )['level'] >= $unit['level'] ) {
			array_pop( $headings );
		}

		$headings[] = [
			'level' => $unit['level'],
			'title' => $unit['title'],
		];

		return $headings;
	}

	/**
	 * Whether a unit is not overlap carried from the previous chunk.
	 *
	 * @param array $unit Unit.
	 * @return bool
	 */
	protected function is_not_overlap( array $unit ): bool {
		return empty( $unit['overlap'] );
	}

	/**
	 * Whether units hold anything but headings.
	 *
	 * @param array $units Units.
	 * @return bool
	 */
	protected function has_content( array $units ): bool {
		foreach ( $units as $unit ) {
			if ( 'heading' !== $unit['kind'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Trimmed, non-empty lines, with runs of spaces collapsed.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	protected function get_lines( string $text ): array {
		$lines = preg_split( '/\R/u', $text );
		$lines = array_map(
			function ( $line ) {
				return trim( (string) preg_replace( '/[^\S\n]+/u', ' ', $line ) );
			},
			(array) $lines
		);

		return array_values( array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Words of a text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	protected function get_words( string $text ): array {
		return (array) preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Number of words of a text.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function count_words( string $text ): int {
		return count( $this->get_words( $text ) );
	}
}
