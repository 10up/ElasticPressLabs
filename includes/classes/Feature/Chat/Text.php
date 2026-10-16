<?php
/**
 * Text helpers Chat's checks share.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads answers, passages, and model replies the same way in every check: sentences, markers,
 * words, phrases a text names, and how far apart two spans are.
 */
class Text {
	/**
	 * What may start a sentence after a sentence end: a capital, a digit, a quote, or a bracket.
	 * So "U.S. adults" and "3.5%" stay whole.
	 *
	 * @var string
	 */
	public const SENTENCE_START = '[A-Z0-9"“(]';

	/**
	 * The split between two sentences: an end mark (not of an initial such as "U.S."), then
	 * space before what may start a sentence.
	 *
	 * @param string $start Character class of what may start a sentence.
	 * @return string Regular expression.
	 */
	public static function get_sentence_break( string $start = self::SENTENCE_START ): string {
		return '/(?<=[.!?])(?<!\b[A-Z]\.)\s+(?=' . $start . ')/u';
	}

	/**
	 * Sentences of a text: its lines, then sentence breaks. Trimmed, without empty ones.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function split_sentences( string $text ): array {
		$sentences = [];

		foreach ( explode( "\n", $text ) as $line ) {
			foreach ( (array) preg_split( self::get_sentence_break(), $line ) as $sentence ) {
				$sentence = trim( (string) $sentence );

				if ( '' !== $sentence ) {
					$sentences[] = $sentence;
				}
			}
		}

		return $sentences;
	}

	/**
	 * Text without `[[N]]` citation markers.
	 *
	 * @param string $text       Text.
	 * @param bool   $with_space Whether to remove the spaces before each marker too.
	 * @return string
	 */
	public static function strip_markers( string $text, bool $with_space = false ): string {
		return (string) preg_replace( '/' . ( $with_space ? '[^\S\n]*' : '' ) . '\[\[[^\]\n]*\]\]/u', '', $text );
	}

	/**
	 * Answer text without citation markers or Markdown bold / code marks.
	 *
	 * @param string $answer Answer.
	 * @return string
	 */
	public static function to_plain( string $answer ): string {
		return (string) preg_replace( '/[*`]+/u', '', self::strip_markers( $answer ) );
	}

	/**
	 * Lowercased words of four or more letters, as a set.
	 *
	 * @param string $text Text.
	 * @return array<string, true>
	 */
	public static function get_content_words( string $text ): array {
		preg_match_all( '/\p{L}{4,}/u', mb_strtolower( $text, 'UTF-8' ), $matches );

		return array_fill_keys( $matches[0], true );
	}

	/**
	 * The passages of a pipeline context, as a list.
	 *
	 * @param array $context Pipeline context.
	 * @return array[]
	 */
	public static function get_passages( array $context ): array {
		return array_values( array_filter( is_array( $context['passages'] ?? null ) ? $context['passages'] : [], 'is_array' ) );
	}

	/**
	 * A passage's text: its `text` (as Citations prepares it), else its title and excerpt.
	 *
	 * @param array $passage Passage.
	 * @return string
	 */
	public static function get_passage_text( array $passage ): string {
		if ( isset( $passage['text'] ) ) {
			return (string) $passage['text'];
		}

		return trim( (string) ( $passage['title'] ?? '' ) . "\n" . (string) ( $passage['excerpt'] ?? '' ) );
	}

	/**
	 * Texts of the passages the model saw.
	 *
	 * @param mixed $passages Passages.
	 * @return string[]
	 */
	public static function get_passage_texts( $passages ): array {
		return array_map( [ self::class, 'get_passage_text' ], self::get_passages( [ 'passages' => $passages ] ) );
	}

	/**
	 * The last balanced JSON object in a text (a model reply), decoded.
	 *
	 * @param string $text Text.
	 * @return array|null
	 */
	public static function get_last_json_object( string $text ): ?array {
		$end = strrpos( $text, '}' );

		while ( false !== $end ) {
			$depth = 0;

			for ( $start = $end; $start >= 0; $start-- ) {
				if ( '}' === $text[ $start ] ) {
					++$depth;
				} elseif ( '{' === $text[ $start ] ) {
					--$depth;
				}

				if ( 0 === $depth ) {
					$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );

					if ( is_array( $data ) ) {
						return $data;
					}

					break;
				}
			}

			$end = $end > 0 ? strrpos( $text, '}', $end - strlen( $text ) - 1 ) : false;
		}

		return null;
	}

	/**
	 * Phrases a text names, whole words only, longest match first: "West Virginia" is never also
	 * "Virginia". Spaces in a phrase match any whitespace.
	 *
	 * @param string  $text    Text.
	 * @param array[] $entries Each `phrases` (string[]) plus any data to return with its matches.
	 * @return array[] The entry's data with `start` and `end` (bytes), by position.
	 */
	public static function find_phrases( string $text, array $entries ): array {
		$found = [];

		foreach ( $entries as $entry ) {
			$data = array_diff_key( $entry, [ 'phrases' => true ] );

			foreach ( (array) ( $entry['phrases'] ?? [] ) as $phrase ) {
				$pattern = '/(?<![\p{L}\p{N}])' . str_replace( '\ ', '\s+', preg_quote( (string) $phrase, '/' ) ) . '(?![\p{L}\p{N}])/iu';

				if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $matches[0] as [ $match, $offset ] ) {
						$found[] = $data + [
							'start' => $offset,
							'end'   => $offset + strlen( $match ),
						];
					}
				}
			}
		}

		usort(
			$found,
			static function ( $a, $b ) {
				return [ $a['start'], $b['end'] ] <=> [ $b['start'], $a['end'] ];
			}
		);

		$kept = [];
		$end  = -1;

		foreach ( $found as $match ) {
			if ( $match['start'] >= $end ) {
				$kept[] = $match;
				$end    = $match['end'];
			}
		}

		return $kept;
	}

	/**
	 * Characters between two spans (0 when they touch or overlap).
	 *
	 * @param array $a Span: `start`, `end`.
	 * @param array $b Span: `start`, `end`.
	 * @return int
	 */
	public static function distance( array $a, array $b ): int {
		return max( $b['start'] - $a['end'], $a['start'] - $b['end'], 0 );
	}
}
