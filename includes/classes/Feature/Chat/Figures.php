<?php
/**
 * Figures in answers and passages.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Extracts, normalizes, and matches the figures (numbers) of a text.
 *
 * A figure is a percentage (`7%`, `3.5 percent`), a percentage-point change
 * (`5 percentage points`), a magnitude (`3.4 million`), a decimal (`2.5`), or a count of
 * two or more digits (`34`, `1,502`, `2024`). Single-digit counts are not figures.
 *
 * Figures are compared whole, after normalization: `7%` is not `37%`, `342` is not
 * `1,342`, `50 million` is not `1950`, `3.4 million` is not `3.4 billion`, and
 * `5 percentage points` is not `5%`.
 *
 * Used by the Figure_Match_Gate (an answer figure must be in a passage) and by citations.
 */
class Figures {
	/**
	 * Magnitude words.
	 */
	protected const MAGNITUDES = 'thousand|million|billion|trillion';

	/**
	 * Spans that never hold figures: citation markers, URLs, Markdown list numbers.
	 */
	protected const SKIPPED = [ '/\[\[\s*\d+\s*\]\]/u', '#\b(?:https?://|www\.)\S+#iu', '/^\s*\d+[.)]\s/mu' ];

	/**
	 * Figure kinds (see locate()).
	 */
	public const KIND_PERCENT     = 'percent';
	public const KIND_PPT         = 'ppt';
	public const KIND_MAGNITUDE   = 'magnitude';
	public const KIND_DECIMAL     = 'decimal';
	public const KIND_LARGE_COUNT = 'large_count';
	public const KIND_COUNT       = 'count';
	public const KIND_YEAR        = 'year';

	/**
	 * Kinds that state a statistic (not a year or a small count): what gates check an answer's
	 * claims by.
	 *
	 * @var string[]
	 */
	public const STATISTIC_KINDS = [
		self::KIND_PERCENT,
		self::KIND_PPT,
		self::KIND_MAGNITUDE,
		self::KIND_LARGE_COUNT,
		self::KIND_DECIMAL,
	];

	/**
	 * Normalize text so equal figures are written the same way.
	 *
	 * NFKC (full-width digits, non-breaking spaces), lowercase, `percentage points` → `ppt`,
	 * `percent` / `per cent` → `%`, no space before `%`, no thousands separators, single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function normalize( string $text ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_KC );
			$text       = false === $normalized ? $text : $normalized;
		}

		$text = mb_strtolower( $text, 'UTF-8' );
		$text = (string) preg_replace( '/(\d)\s*(?:percentage|pct\.?)\s*points?\b/u', '$1 ppt', $text );
		$text = (string) preg_replace( '/(\d)\s*(?:per\s?cent|percent)\b/u', '$1%', $text );
		$text = (string) preg_replace( '/(\d)\s+%/u', '$1%', $text );
		$text = (string) preg_replace( '/(?<=\d),(?=\d{3}(?!\d))/u', '', $text );

		return trim( (string) preg_replace( '/[^\S\n]+/u', ' ', $text ) );
	}

	/**
	 * Figures of an answer, in order of appearance, normalized.
	 *
	 * Citation markers (`[[2]]`), Markdown list numbers (`10. `), and URLs are not figures.
	 *
	 * @param string $text Answer text.
	 * @return string[]
	 */
	public function extract( string $text ): array {
		$text = (string) preg_replace( self::SKIPPED, ' ', $text );

		return $this->match_figures( $this->normalize( $text ) );
	}

	/**
	 * Figures of a text as written, with their kind and byte position.
	 *
	 * The normalized `figure` is the same value extract() and grounded() use. Kinds: percent,
	 * ppt (percentage points), magnitude ("3.4 million"), decimal, large_count (1,000 or more),
	 * count (two or three digits), and year (1900–2099 written without a thousands comma, so
	 * "2,024" is a count). Citation markers, Markdown list numbers, and URLs are skipped.
	 *
	 * @param string $text Text.
	 * @return array[] `[ 'figure' => string, 'kind' => string, 'text' => string, 'start' => int, 'end' => int ]`.
	 */
	public function locate( string $text ): array {
		$digit  = '[0-9\x{FF10}-\x{FF19}]';
		$number = "{$digit}(?:{$digit}|,(?={$digit}{3}(?!{$digit})))*(?:\.{$digit}+)?";
		$suffix = '\s*(?:percentage|pct\.?)\s*points?\b'
			. '|\s*(?:%|\x{FF05}|per\s?cent\b|percent\b)'
			. '|\s+(?:' . self::MAGNITUDES . ')\b';

		if ( ! preg_match_all( "/(?<![0-9\x{FF10}-\x{FF19}.,])({$number})(?:{$suffix})?(?!{$digit})/iu", $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return [];
		}

		$skipped = $this->get_skipped_spans( $text );
		$found   = [];

		foreach ( $matches as $match ) {
			$raw   = $match[0][0];
			$start = $match[0][1];

			foreach ( $skipped as [ $from, $to ] ) {
				if ( $start >= $from && $start < $to ) {
					continue 2;
				}
			}

			$figures = $this->match_figures( $this->normalize( $raw ) );

			if ( ! $figures ) {
				continue;
			}

			$found[] = [
				'figure' => $figures[0],
				'kind'   => $this->get_kind( $figures[0], $match[1][0] ),
				'text'   => $raw,
				'start'  => $start,
				'end'    => $start + strlen( $raw ),
			];
		}

		return $found;
	}

	/**
	 * Kind of a normalized figure.
	 *
	 * @param string $figure Normalized figure.
	 * @param string $number The number as written (a thousands comma makes "2,024" a count).
	 * @return string
	 */
	protected function get_kind( string $figure, string $number ): string {
		if ( '%' === substr( $figure, -1 ) ) {
			return self::KIND_PERCENT;
		}

		if ( ' ppt' === substr( $figure, -4 ) ) {
			return self::KIND_PPT;
		}

		if ( preg_match( '/ (?:' . self::MAGNITUDES . ')$/u', $figure ) ) {
			return self::KIND_MAGNITUDE;
		}

		if ( false !== strpos( $figure, '.' ) ) {
			return self::KIND_DECIMAL;
		}

		$value = (int) $figure;

		if ( false === strpos( $number, ',' ) && $value >= 1900 && $value <= 2099 ) {
			return self::KIND_YEAR;
		}

		return $value >= 1000 ? self::KIND_LARGE_COUNT : self::KIND_COUNT;
	}

	/**
	 * Byte spans that never hold figures: citation markers, URLs, Markdown list numbers.
	 *
	 * @param string $text Text.
	 * @return array[] `[ start, end ]` pairs.
	 */
	protected function get_skipped_spans( string $text ): array {
		$spans = [];

		foreach ( self::SKIPPED as $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches[0] as [ $found, $offset ] ) {
					$spans[] = [ $offset, $offset + strlen( $found ) ];
				}
			}
		}

		return $spans;
	}

	/**
	 * Every figure a set of passages grounds.
	 *
	 * Besides the figures written in them, a whole number cell of a Markdown table that the
	 * passage marks as percentages (a `%` in its header row or in the lines just above it)
	 * grounds that number as a percentage: `| 2026 | 12 |` under "% of U.S. adults" grounds `12%`.
	 *
	 * @param string[] $passages Passage texts.
	 * @return array<string, true> Normalized figures.
	 */
	public function grounded( array $passages ): array {
		$grounded = [];

		foreach ( $passages as $passage ) {
			$text = $this->normalize( (string) $passage );

			foreach ( $this->match_figures( $text ) as $figure ) {
				$grounded[ $figure ] = true;
			}

			foreach ( $this->table_percentages( $text ) as $figure ) {
				$grounded[ $figure ] = true;
			}
		}

		return $grounded;
	}

	/**
	 * Answer figures that no passage grounds.
	 *
	 * @param string   $answer   Answer text.
	 * @param string[] $passages Passage texts.
	 * @return string[] Unmatched figures, each once, in order of appearance.
	 */
	public function unmatched( string $answer, array $passages ): array {
		$grounded = $this->grounded( $passages );

		return array_values(
			array_unique(
				array_filter(
					$this->extract( $answer ),
					function ( $figure ) use ( $grounded ) {
						return ! isset( $grounded[ $figure ] );
					}
				)
			)
		);
	}

	/**
	 * Figures in normalized text.
	 *
	 * Longer forms are matched first, and a figure never starts or ends inside another
	 * number, so `37%` is one figure (not `7%`), and `1950` is not `50`.
	 *
	 * @param string $text Normalized text.
	 * @return string[]
	 */
	protected function match_figures( string $text ): array {
		$number  = '\d+(?:\.\d+)?';
		$pattern = '/(?<![\d.])(?:'
			. "(?<pct>{$number})%"
			. "|(?<ppt>{$number}) ppt\\b"
			. "|(?<mag>{$number}) (?<unit>" . self::MAGNITUDES . ')\b'
			. '|(?<dec>\d+\.\d+)'
			. '|(?<cnt>\d{2,})'
			. ')(?![\d])(?!\.\d)/u';

		if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		$figures = [];

		foreach ( $matches as $match ) {
			if ( '' !== ( $match['pct'] ?? '' ) ) {
				$figures[] = $this->canonical( $match['pct'] ) . '%';
			} elseif ( '' !== ( $match['ppt'] ?? '' ) ) {
				$figures[] = $this->canonical( $match['ppt'] ) . ' ppt';
			} elseif ( '' !== ( $match['mag'] ?? '' ) ) {
				$figures[] = $this->canonical( $match['mag'] ) . ' ' . $match['unit'];
			} elseif ( '' !== ( $match['dec'] ?? '' ) ) {
				$figures[] = $this->canonical( $match['dec'] );
			} else {
				$figures[] = $this->canonical( $match['cnt'] );
			}
		}

		return $figures;
	}

	/**
	 * Percentages grounded by the number cells of percentage tables.
	 *
	 * @param string $text Normalized text (one table row per line).
	 * @return string[]
	 */
	protected function table_percentages( string $text ): array {
		$lines   = explode( "\n", $text );
		$figures = [];
		$count   = count( $lines );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( 0 !== strpos( ltrim( $lines[ $i ] ), '|' ) ) {
				continue;
			}

			// A table: this line and the next `|` lines.
			$start = $i;
			while ( $i + 1 < $count && 0 === strpos( ltrim( $lines[ $i + 1 ] ), '|' ) ) {
				++$i;
			}

			if ( ! $this->is_percentage_table( $lines, $start ) ) {
				continue;
			}

			for ( $row = $start; $row <= $i; $row++ ) {
				foreach ( explode( '|', $lines[ $row ] ) as $cell ) {
					$cell = trim( $cell );

					if ( preg_match( '/^\d+(?:\.\d+)?$/u', $cell ) ) {
						$figures[] = $this->canonical( $cell ) . '%';
					}
				}
			}
		}

		return $figures;
	}

	/**
	 * Whether a located figure states a statistic (see STATISTIC_KINDS).
	 *
	 * @param array $found Located figure (see locate()).
	 * @return bool
	 */
	public static function is_statistic( array $found ): bool {
		return in_array( $found['kind'] ?? '', self::STATISTIC_KINDS, true );
	}

	/**
	 * The statistics a text states (see is_statistic()), located.
	 *
	 * @param string $text Text.
	 * @return array[]
	 */
	public function locate_statistics( string $text ): array {
		return array_values( array_filter( $this->locate( $text ), [ self::class, 'is_statistic' ] ) );
	}

	/**
	 * The statistics of an answer, sentence by sentence (markers and Markdown marks removed). A
	 * list item can take what its list's lead-in line ("Among teens:") says, through `$read_lead`;
	 * a blank line or a line that is not an item ends the list.
	 *
	 * @param string        $answer    Answer.
	 * @param callable|null $read_lead Reads a lead-in line (ending with ":"); returns what list
	 *                                 items take, or null.
	 * @return array[] `figure` (located), `sentence`, and `lead` (the lead-in's reading for a list
	 *                 item, else null).
	 */
	public function locate_answer_statistics( string $answer, ?callable $read_lead = null ): array {
		$found = [];
		$lead  = null;

		foreach ( explode( "\n", Text::strip_markers( $answer ) ) as $raw ) {
			$line = trim( Text::to_plain( $raw ) );

			if ( '' === $line ) {
				$lead = null;
				continue;
			}

			$is_item = (bool) preg_match( '/^\s*(?:[-*+•]|\d+[.)])\s+/u', $raw );

			if ( ! $is_item ) {
				$lead = null;
			}

			foreach ( Text::split_sentences( $line ) as $sentence ) {
				foreach ( $this->locate_statistics( $sentence ) as $figure ) {
					$found[] = [
						'figure'   => $figure,
						'sentence' => $sentence,
						'lead'     => $is_item ? $lead : null,
					];
				}
			}

			if ( $read_lead && ':' === substr( rtrim( $line, " *_\t" ), -1 ) ) {
				$lead = $read_lead( $line );
			}
		}

		return $found;
	}

	/**
	 * Whether a located figure is a figure (a bare number of a percentage table is that
	 * percentage).
	 *
	 * @param array  $found      Located figure (see locate()).
	 * @param string $figure     Normalized figure.
	 * @param bool   $percentage Whether bare numbers of the table are percentages.
	 * @return bool
	 */
	public function is_same_figure( array $found, string $figure, bool $percentage ): bool {
		if ( $found['figure'] === $figure ) {
			return true;
		}

		return $percentage
			&& in_array( $found['kind'], [ self::KIND_COUNT, self::KIND_DECIMAL ], true )
			&& $found['figure'] . '%' === $figure;
	}

	/**
	 * Whether a table row holds a figure (see is_same_figure()), or any figure.
	 *
	 * @param string      $row        Row.
	 * @param string|null $figure     Normalized figure, or null for any figure.
	 * @param bool        $percentage Whether bare numbers of the table are percentages.
	 * @return bool
	 */
	public function row_has_figure( string $row, ?string $figure, bool $percentage ): bool {
		foreach ( $this->locate( $row ) as $found ) {
			if ( null === $figure || $this->is_same_figure( $found, $figure, $percentage ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the Markdown table that starts at a line is a percentage table: a `%` (or
	 * "percent", once normalized) in its header row or in the three lines above it.
	 *
	 * @param string[] $lines Lines of a text.
	 * @param int      $start Position of the table's header row.
	 * @return bool
	 */
	public function is_percentage_table( array $lines, int $start ): bool {
		$context = implode( ' ', array_slice( $lines, max( 0, $start - 3 ), $start - max( 0, $start - 3 ) + 1 ) );

		return false !== strpos( $this->normalize( $context ), '%' );
	}

	/**
	 * A number without trailing decimal zeros (`3.50` → `3.5`, `45.0` → `45`).
	 *
	 * @param string $number Number.
	 * @return string
	 */
	protected function canonical( string $number ): string {
		return false === strpos( $number, '.' ) ? $number : rtrim( rtrim( $number, '0' ), '.' );
	}
}
