<?php
/**
 * Optional gate: a figure the answer gives for a population must be given for it in a passage.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

use ElasticPressLabs\Feature\Chat\Figure_Context;
use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Population_Match_Gate, read from the text (no per-figure metadata).
 *
 * When an answer sentence ties a figure to a population ("25% of Hispanic adults", "among teens,
 * 21%"), some passage must give that figure for that population: the group near the figure in
 * the same sentence, in the sentence before, or in the table row, header, or caption. And a
 * figure the answer gives for everyone ("68% of U.S. adults") is declined when every passage that
 * has it gives it only for a subgroup ("68% of online adults"). When the sentence also ties the
 * figure to a year that the passages give it for, one place must give it for both: the group's
 * figure for another year plus another group's figure for that year is declined. Other checks
 * are other gates', each when it runs: a figure no passage has is Figure_Match_Gate's, a place
 * is Scope_Gate's, and a year no passage gives the figure for is Trend_Gate's.
 */
class Population_Match_Gate extends Base_Gate {
	/**
	 * Slug.
	 *
	 * @var string
	 */
	public const SLUG = 'population_match';

	/**
	 * Refusal code (shared with Scope_Gate).
	 *
	 * @var string
	 */
	public const REASON = 'unsupported_subgroup';

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
		return esc_html__( 'Population match', 'elasticpress-labs' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Declines an answer that gives a figure for a group (teens, Republicans, Hispanic adults, online adults…) when the sources give it for a different group, or presents a group’s figure as everyone’s.', 'elasticpress-labs' );
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
	 * Check each figure the answer ties to a population.
	 *
	 * @param array $context Pipeline context.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result {
		$passages = Text::get_passages( $context );

		if ( ! $passages ) {
			return Gate_Result::pass();
		}

		$texts   = array_map( [ Text::class, 'get_passage_text' ], $passages );
		$unbound = [];

		foreach ( $this->get_claims( (string) ( $context['content'] ?? '' ) ) as $claim ) {
			if ( ! $this->context->passages_bind_population( $claim['figure'], $claim['wanted'], $texts ) ) {
				$unbound[] = $claim;
				continue;
			}

			// A year no passage gives the figure for is Trend_Gate's to decline (when it runs), not this gate's.
			if (
				null !== $claim['year']
				&& $this->context->passages_bind_year( $claim['figure'], $claim['year'], $passages )
				&& ! $this->context->passages_bind_population_and_year( $claim['figure'], $claim['wanted'], $claim['year'], $passages )
			) {
				$unbound[] = $claim;
			}
		}

		if ( ! $unbound ) {
			return Gate_Result::pass();
		}

		/**
		 * Fires when an answer is withheld because the passages do not give a figure for the
		 * population the answer gives it for.
		 *
		 * For logging and evaluation; the answer is not stored or returned.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_population_mismatch
		 * @param {array[]} $unbound Claims: `figure`, `wanted` (`groups`, `general`), `year`, `sentence`.
		 * @param {array}   $context Pipeline context, with the withheld answer in `content`.
		 */
		do_action( 'ep_chat_population_mismatch', $unbound, $context );

		return $this->refuse( self::REASON );
	}

	/**
	 * The figures an answer ties to a population (a group, or everyone). A list item takes the
	 * population of its lead-in line ("Among teens:") when it names none.
	 *
	 * @param string $content Answer.
	 * @return array[] `figure`, `wanted`, `year` (null when the sentence ties it to none), `sentence`.
	 */
	public function get_claims( string $content ): array {
		$claims = [];

		foreach ( $this->figures->locate_answer_statistics( $content, fn( $line ) => $this->read_lead( $line ) ) as $found ) {
			$wanted = $this->context->read_answer( $found['sentence'], $found['figure'] );

			if ( ! $wanted['groups'] && ! $wanted['general'] && $found['lead'] ) {
				$wanted = $found['lead'];
			}

			if ( $wanted['groups'] || $wanted['general'] ) {
				$claims[] = [
					'figure'   => $found['figure']['figure'],
					'wanted'   => $wanted,
					'year'     => $this->context->read_answer_year( $found['sentence'], $found['figure'] ),
					'sentence' => $found['sentence'],
				];
			}
		}

		return $claims;
	}

	/**
	 * The population a list's lead-in line names ("Among Hispanic adults:"): groups of each kind.
	 *
	 * @param string $line Lead-in line.
	 * @return array|null
	 */
	protected function read_lead( string $line ): ?array {
		$groups = [];

		foreach ( $this->context->find_groups( $line ) as $match ) {
			$groups[ $match['kind'] ] = $groups[ $match['kind'] ] ?? $match['group'];
		}

		return $groups ? [
			'groups'  => $groups,
			'general' => false,
		] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return Gates::get_gate( Scope_Gate::class )->get_refusal_reasons();
	}

	/**
	 * Ask the model to keep figures with their own population.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [
			__( 'Give each figure for the population the source gives it for (for example teens, online adults, or Republicans); never present one group’s figure as another group’s or as everyone’s.', 'elasticpress-labs' ),
		];
	}
}
