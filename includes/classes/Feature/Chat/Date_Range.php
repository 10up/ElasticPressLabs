<?php
/**
 * Time words in a question, as limits the search can use.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads the time words of a question, without a model call.
 *
 * Only the publish date can be searched, and it bounds the data in one direction only: data from
 * a year can only be in content published in or after that year, but content published later
 * (a trend report) can hold data from any earlier year. So a year the question asks about becomes
 * a lower limit ("published on or after"), never an upper one, and words such as "latest" rank
 * newer content higher instead of removing older content.
 */
class Date_Range {
	/**
	 * Words before a year that ask about the time before it, so the year is not a lower limit.
	 *
	 * @var string
	 */
	protected const BEFORE_WORDS = '(?:before|prior\s+to|until|till|up\s+to|through|thru|by|pre)';

	/**
	 * Recency words ("latest", "these days"). "current" and "currently" are not among them: they
	 * describe a topic ("the current president"), not a time.
	 *
	 * @var string
	 */
	protected const RECENT_PATTERN = '/\b(?:recent|recently|most\s+recent|latest|lately|nowadays|these\s+days|right\s+now|newest|as\s+of\s+today|today)\b/iu';

	/**
	 * The limits for a question.
	 *
	 * Every cue in the user's question counts. The search query (the rewrite of question intent)
	 * adds only years the user wrote, in this question or an earlier one: a follow-up ("And
	 * teens?") keeps the year of the question it follows, and a year the model added is ignored.
	 * The earliest lower limit wins.
	 *
	 * @param string   $question User question.
	 * @param string   $query    Search query (the rewrite), or empty.
	 * @param string[] $earlier  The user's earlier questions in the conversation.
	 * @param int|null $now      Current timestamp (tests). Default now.
	 * @return array{published_after: string|null, recent: bool} `published_after` as `Y-m-d`.
	 */
	public function read( string $question, string $query = '', array $earlier = [], ?int $now = null ): array {
		$now     = $now ?? time();
		$year    = (int) wp_date( 'Y', $now );
		$years   = $this->get_lower_years( $question, $year );
		$written = implode( "\n", array_merge( [ $question ], array_map( 'strval', $earlier ) ) );

		if ( '' !== trim( $query ) && $query !== $question ) {
			foreach ( $this->get_asked_years( $query, $year ) as $found ) {
				if ( preg_match( '/(?<![\d,.])' . $found . '(?![\d,]|\.\d)/', $written ) ) {
					$years[] = $found;
				}
			}
		}

		$range = [
			'published_after' => $years ? sprintf( '%04d-01-01', min( $years ) ) : null,
			'recent'          => (bool) preg_match( self::RECENT_PATTERN, $question ),
		];

		/**
		 * Filters the date limits Chat reads from a question.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_date_range
		 * @param {array}    $range    `published_after` (`Y-m-d` or null) and `recent` (bool).
		 * @param {string}   $question User question.
		 * @param {string}   $query    Search query (the rewrite), or empty.
		 * @param {string[]} $earlier  The user's earlier questions.
		 * @param {int}      $now      Current timestamp.
		 * @return {array} Filtered range.
		 */
		$filtered = apply_filters( 'ep_chat_date_range', $range, $question, $query, $earlier, $now );

		return self::clean( is_array( $filtered ) ? $filtered : $range );
	}

	/**
	 * Years that give a lower limit: the years the text asks about (see get_asked_years()) and
	 * relative years ("last 5 years", "last year", "this year").
	 *
	 * @param string $text Text.
	 * @param int    $year This year.
	 * @return int[]
	 */
	protected function get_lower_years( string $text, int $year ): array {
		$years = $this->get_asked_years( $text, $year );

		if ( preg_match_all( '/\b(?:last|past|previous)\s+(\d{1,2}|two|three|four|five|six|seven|eight|nine|ten)\s+years?\b/iu', $text, $matches ) ) {
			$words = [
				'two'   => 2,
				'three' => 3,
				'four'  => 4,
				'five'  => 5,
				'six'   => 6,
				'seven' => 7,
				'eight' => 8,
				'nine'  => 9,
				'ten'   => 10,
			];

			foreach ( $matches[1] as $count ) {
				$count   = $words[ strtolower( $count ) ] ?? (int) $count;
				$years[] = $year - max( 1, $count );
			}
		}

		if ( preg_match( '/\b(?:(?:the\s+)?(?:last|past)\s+(?:year|12\s+months|twelve\s+months)|a\s+year\s+ago)\b/iu', $text ) ) {
			$years[] = $year - 1;
		}

		if ( preg_match( '/\bthis\s+year\b/iu', $text ) ) {
			$years[] = $year;
		}

		return $years;
	}

	/**
	 * Years a text asks about ("in 2016", "since 2020", "2016 to 2020", "the 1990s"): not years
	 * after "before", "until", and similar, nor future years.
	 *
	 * @param string $text Text.
	 * @param int    $year This year.
	 * @return int[]
	 */
	protected function get_asked_years( string $text, int $year ): array {
		$years = [];

		// A comma or a decimal point makes a number a count ("2,024 adults"), never a year.
		preg_match_all( '/(?<![\d,.])(?:(' . self::BEFORE_WORDS . ')[\s-]+(?:the\s+)?(?:end\s+of\s+)?)?((?:19|20)\d{2})s?(?![\d,]|\.\d)/iu', $text, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$found = (int) $match[2];

			if ( '' === $match[1] && $found <= $year ) {
				$years[] = $found;
			}
		}

		return $years;
	}

	/**
	 * A well-formed range.
	 *
	 * @param array $range Range.
	 * @return array{published_after: string|null, recent: bool}
	 */
	public static function clean( array $range ): array {
		$after = isset( $range['published_after'] ) ? (string) $range['published_after'] : '';

		return [
			'published_after' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ? $after : null,
			'recent'          => ! empty( $range['recent'] ),
		];
	}
}
