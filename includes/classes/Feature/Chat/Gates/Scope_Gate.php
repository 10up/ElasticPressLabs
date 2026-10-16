<?php
/**
 * Gate (optional, on by default): a figure given for a place must be given for that place in a passage.
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
 * Scope_Gate (deterministic).
 *
 * When the question names a place (by default a U.S. state, by name or demonym), each statistic
 * of the answer must be bound to one of those places in a passage: written in the same sentence
 * within WINDOW characters of it, or in the same Markdown table row. That refuses a national
 * figure pinned to a state ("71% of Ohioans" when the passage says "Nationally, 71%") and keeps
 * a real multi-state comparison. A statistic the answer marks as national (a word such as
 * "nationally" or "U.S. adults" is closer to it than any place, or it is a list item under a
 * national lead-in line) is not checked, so "Nationally, 71%…; the sources have no Ohio figure"
 * passes. An answer without statistics needs a passage with some figure bound to the place,
 * unless it says the sources lack it (Refusal_Gate declines that, when it runs).
 */
class Scope_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'scope';

	/**
	 * Refusal code.
	 *
	 * @var string
	 */
	public const REASON = 'unsupported_subgroup';

	/**
	 * Most characters between a place and its figure in one sentence.
	 *
	 * @var int
	 */
	public const WINDOW = 55;

	/**
	 * Words that mark an answer sentence as national.
	 *
	 * @var string
	 */
	protected const NATIONAL = '/\b(?:nationally|nationwide|national|americans|united states|the country|overall)\b|\bu\.\s?s\.(?=\s|$|[,;:)])/iu';

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
		return esc_html__( 'Scope', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Declines an answer that gives a national (or another place’s) figure for a question about a specific place.', 'elasticpress-labs' );
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
	 * Refuse a figure the passages do not give for the place the question names.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$places = array_unique( wp_list_pluck( $this->find_places( (string) ( $context['question'] ?? '' ) ), 'place' ) );
		$answer = Text::to_plain( (string) ( $context['content'] ?? '' ) );

		if ( ! $places || '' === trim( $answer ) ) {
			return Gate_Result::pass();
		}

		$texts      = Text::get_passage_texts( $context['passages'] ?? [] );
		$statistics = [];
		$has_any    = false;
		$read_lead  = function ( string $line ) {
			// Scope of a list's lead-in line ("…U.S. adults:").
			return $this->find_places( $line ) ? 'place' : ( $this->find_national( $line ) ? 'national' : null );
		};

		foreach ( $this->figures->locate_answer_statistics( (string) ( $context['content'] ?? '' ), $read_lead ) as $found ) {
			$has_any = true;
			$scope   = $this->get_scope( $found['figure'], $this->find_places( $found['sentence'] ), $this->find_national( $found['sentence'] ) ) ?? $found['lead'];

			// "Nationally, 71%…": a national figure the answer does not give for the place.
			if ( 'national' !== $scope ) {
				$statistics[ $found['figure']['figure'] ] = true;
			}
		}

		$unbound = [];

		if ( $statistics ) {
			foreach ( array_keys( $statistics ) as $figure ) {
				if ( ! $this->is_bound( (string) $figure, $places, $texts ) ) {
					$unbound[] = (string) $figure;
				}
			}
		} elseif ( ! $has_any && ! Gates::get_gate( Refusal_Gate::class )->has_refusal_phrase( $answer ) && ! $this->is_bound( null, $places, $texts ) ) {
			// Without statistics, the answer needs place data in a passage. An answer that says
			// the sources lack it is not this gate's to decline (Refusal_Gate does, when it runs).
			$unbound[] = '';
		}

		if ( ! $unbound ) {
			return Gate_Result::pass();
		}

		/**
		 * Fires when an answer is withheld because the passages do not give its figures for the
		 * place the question names.
		 *
		 * For logging and evaluation; the answer is not stored or returned.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_unscoped_figures
		 * @param {string[]} $unbound Normalized figures not bound to the place (an empty string
		 *                            when the answer has no statistic and no passage has data
		 *                            for the place).
		 * @param {string[]} $places  Places the question names.
		 * @param {array}    $context Pipeline context, with the withheld answer in `content`.
		 */
		do_action( 'ep_chat_unscoped_figures', $unbound, $places, $context );

		return $this->refuse( self::REASON );
	}

	/**
	 * Words that mark a figure as national, as spans.
	 *
	 * @param string $sentence Sentence.
	 * @return array[] `[ 'start' => int, 'end' => int ]`.
	 */
	protected function find_national( string $sentence ): array {
		$spans = [];

		if ( preg_match_all( self::NATIONAL, $sentence, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as [ $found, $offset ] ) {
				$spans[] = [
					'start' => $offset,
					'end'   => $offset + strlen( $found ),
				];
			}
		}

		return $spans;
	}

	/**
	 * What a sentence says a figure is for: the nearer of a place and a national marker.
	 *
	 * @param array   $figure   Located figure.
	 * @param array[] $named    Places in the sentence.
	 * @param array[] $national National markers in the sentence.
	 * @return string|null `place`, `national`, or null when the sentence has neither.
	 */
	protected function get_scope( array $figure, array $named, array $national ): ?string {
		if ( ! $named && ! $national ) {
			return null;
		}

		return $this->nearest( $figure, $national ) < $this->nearest( $figure, $named ) ? 'national' : 'place';
	}

	/**
	 * Characters between a figure and the nearest of some spans (PHP_INT_MAX for none).
	 *
	 * @param array   $figure Located figure: `start`, `end`.
	 * @param array[] $spans  Spans: `start`, `end`.
	 * @return int
	 */
	protected function nearest( array $figure, array $spans ): int {
		$nearest = PHP_INT_MAX;

		foreach ( $spans as $span ) {
			$nearest = min( $nearest, Text::distance( $figure, $span ) );
		}

		return $nearest;
	}

	/**
	 * Places a text names, longest match first: "West Virginia" is never also "Virginia".
	 *
	 * @param string $text Text.
	 * @return array[] `[ 'place' => string, 'start' => int, 'end' => int ]`, by position.
	 */
	public function find_places( string $text ): array {
		$entries = [];

		foreach ( $this->get_places() as $place => $phrases ) {
			$entries[] = [
				'place'   => (string) $place,
				'phrases' => (array) $phrases,
			];
		}

		return Text::find_phrases( $text, $entries );
	}


	/**
	 * Places the gate knows, with the phrases that name each (lowercase).
	 *
	 * @return array<string, string[]> Place => names and demonyms.
	 */
	public function get_places(): array {
		$places = [
			'alabama'        => [ 'alabama', 'alabamians', 'alabamans' ],
			'alaska'         => [ 'alaska', 'alaskans' ],
			'arizona'        => [ 'arizona', 'arizonans', 'arizonians' ],
			'arkansas'       => [ 'arkansas', 'arkansans' ],
			'california'     => [ 'california', 'californians' ],
			'colorado'       => [ 'colorado', 'coloradans', 'coloradoans' ],
			'connecticut'    => [ 'connecticut', 'connecticuters', 'nutmeggers' ],
			'delaware'       => [ 'delaware', 'delawareans' ],
			'florida'        => [ 'florida', 'floridians' ],
			'georgia'        => [ 'georgia', 'georgians' ],
			'hawaii'         => [ 'hawaii', 'hawaiians' ],
			'idaho'          => [ 'idaho', 'idahoans' ],
			'illinois'       => [ 'illinois', 'illinoisans' ],
			'indiana'        => [ 'indiana', 'indianans', 'hoosiers' ],
			'iowa'           => [ 'iowa', 'iowans' ],
			'kansas'         => [ 'kansas', 'kansans' ],
			'kentucky'       => [ 'kentucky', 'kentuckians' ],
			'louisiana'      => [ 'louisiana', 'louisianans', 'louisianians' ],
			'maine'          => [ 'maine', 'mainers' ],
			'maryland'       => [ 'maryland', 'marylanders' ],
			'massachusetts'  => [ 'massachusetts', 'massachusettsans' ],
			'michigan'       => [ 'michigan', 'michiganders', 'michiganians' ],
			'minnesota'      => [ 'minnesota', 'minnesotans' ],
			'mississippi'    => [ 'mississippi', 'mississippians' ],
			'missouri'       => [ 'missouri', 'missourians' ],
			'montana'        => [ 'montana', 'montanans' ],
			'nebraska'       => [ 'nebraska', 'nebraskans' ],
			'nevada'         => [ 'nevada', 'nevadans' ],
			'new hampshire'  => [ 'new hampshire', 'new hampshirites' ],
			'new jersey'     => [ 'new jersey', 'new jerseyans', 'new jerseyites' ],
			'new mexico'     => [ 'new mexico', 'new mexicans' ],
			'new york'       => [ 'new york state', 'new york', 'new yorkers' ],
			'north carolina' => [ 'north carolina', 'north carolinians' ],
			'north dakota'   => [ 'north dakota', 'north dakotans' ],
			'ohio'           => [ 'ohio', 'ohioans' ],
			'oklahoma'       => [ 'oklahoma', 'oklahomans' ],
			'oregon'         => [ 'oregon', 'oregonians' ],
			'pennsylvania'   => [ 'pennsylvania', 'pennsylvanians' ],
			'rhode island'   => [ 'rhode island', 'rhode islanders' ],
			'south carolina' => [ 'south carolina', 'south carolinians' ],
			'south dakota'   => [ 'south dakota', 'south dakotans' ],
			'tennessee'      => [ 'tennessee', 'tennesseans' ],
			'texas'          => [ 'texas', 'texans' ],
			'utah'           => [ 'utah', 'utahns', 'utahans' ],
			'vermont'        => [ 'vermont', 'vermonters' ],
			'virginia'       => [ 'virginia', 'virginians' ],
			// Not "Washington" alone: the Post, D.C., and the federal government.
			'washington'     => [ 'washington state', 'state of washington', 'washingtonians' ],
			'west virginia'  => [ 'west virginia', 'west virginians' ],
			'wisconsin'      => [ 'wisconsin', 'wisconsinites' ],
			'wyoming'        => [ 'wyoming', 'wyomingites' ],
		];

		/**
		 * Filters the places Scope_Gate checks: a question that names one makes the answer's
		 * figures need that place in a passage. Longer phrases win over shorter ones they
		 * contain.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_scope_places
		 * @param {array} $places Place => phrases that name it (names, demonyms). Default: the
		 *                        50 U.S. states.
		 * @return {array} Filtered places.
		 */
		$places = apply_filters( 'ep_chat_scope_places', $places );

		return is_array( $places ) ? array_filter( array_map( 'array_values', array_filter( $places, 'is_array' ) ) ) : [];
	}

	/**
	 * Whether a passage binds a figure (or, with null, any figure) to one of the places.
	 *
	 * @param string|null $figure Normalized figure, or null for any figure.
	 * @param string[]    $places Places.
	 * @param string[]    $texts  Passage texts.
	 * @return bool
	 */
	protected function is_bound( ?string $figure, array $places, array $texts ): bool {
		$places = array_flip( $places );

		foreach ( $texts as $text ) {
			$lines = explode( "\n", $text );
			$start = null;

			foreach ( $lines as $index => $line ) {
				if ( 0 === strpos( ltrim( $line ), '|' ) ) {
					$start = $start ?? $index;

					// A table row binds its place and its figures at any distance.
					if ( $this->has_place( $line, $places ) && $this->figures->row_has_figure( $line, $figure, $this->figures->is_percentage_table( $lines, $start ) ) ) {
						return true;
					}

					continue;
				}

				$start = null;

				foreach ( Text::split_sentences( $line ) as $sentence ) {
					if ( $this->sentence_binds( $sentence, $figure, $places ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Whether a sentence has the figure within WINDOW characters of one of the places.
	 *
	 * @param string      $sentence Sentence.
	 * @param string|null $figure   Normalized figure, or null for any figure.
	 * @param array       $places   Places (as keys).
	 * @return bool
	 */
	protected function sentence_binds( string $sentence, ?string $figure, array $places ): bool {
		$named = array_filter(
			$this->find_places( $sentence ),
			function ( $found ) use ( $places ) {
				return isset( $places[ $found['place'] ] );
			}
		);

		if ( ! $named ) {
			return false;
		}

		foreach ( $this->figures->locate( $sentence ) as $found ) {
			if ( null !== $figure && $found['figure'] !== $figure ) {
				continue;
			}

			foreach ( $named as $match ) {
				if ( Text::distance( $found, $match ) <= self::WINDOW ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a table row names one of the places.
	 *
	 * @param string $row    Row.
	 * @param array  $places Places (as keys).
	 * @return bool
	 */
	protected function has_place( string $row, array $places ): bool {
		foreach ( $this->find_places( $row ) as $match ) {
			if ( isset( $places[ $match['place'] ] ) ) {
				return true;
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
				'label'       => __( 'Figure not given for that group or place', 'elasticpress-labs' ),
				'message'     => __( 'The answer was withheld because the sources do not give that figure for the group or place it was given for.', 'elasticpress-labs' ),
				'description' => __( 'An answer was written, but it gave a figure for a group of people or a place that the sources do not give it for. A figure for another group, or for the whole country, is not shown as if it were for the one you asked about.', 'elasticpress-labs' ),
				'kind'        => Refusals::KIND_WITHHELD,
			],
		];
	}

	/**
	 * Ask the model not to pin a national figure on a place.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [
			__( 'Only attribute a figure to a state when a source gives it for that state; if the sources only have national figures, say so.', 'elasticpress-labs' ),
		];
	}
}
