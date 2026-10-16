<?php
/**
 * Chat gate bootstrap and bot-facing summaries.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Holds the process-wide registry.
 */
class Gates {
	/**
	 * Enabled optional gate slugs postmeta.
	 *
	 * @var string
	 */
	public const META_OPTIONAL_GATES = 'ep_chat_bot_optional_gates';

	/**
	 * Registry singleton.
	 *
	 * @var Gate_Registry|null
	 */
	protected static $registry;

	/**
	 * Build the registry once: Labs defaults, then plugins.
	 *
	 * @return Gate_Registry
	 */
	public static function registry(): Gate_Registry {
		if ( self::$registry instanceof Gate_Registry ) {
			return self::$registry;
		}

		self::$registry = new Gate_Registry();
		self::$registry->register( new Empty_Retrieve_Gate() );
		self::$registry->register( new Injection_Defense_Gate() );
		self::$registry->register( new Injection_Echo_Gate() );
		self::$registry->register( new Figure_Match_Gate() );
		self::$registry->register( new Scope_Gate() );
		self::$registry->register( new Population_Match_Gate() );
		self::$registry->register( new Trend_Gate() );
		self::$registry->register( new Superlative_Gate() );
		self::$registry->register( new Claim_Verifier_Gate() );
		self::$registry->register( new Refusal_Gate() );
		self::$registry->register( new Citation_Attributor_Gate() );

		/**
		 * Register, replace, or remove Chat gates.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_register_gate
		 * @param {Gate_Registry} $registry Registry.
		 */
		do_action( 'ep_chat_register_gate', self::$registry );

		return self::$registry;
	}

	/**
	 * A Labs gate whose test another class reuses (whether or not the gate runs for the bot): the
	 * registered gate with that class's slug, so a replacement subclass is used, or else a new
	 * instance.
	 *
	 * @param string $class_name Labs gate class with a `SLUG` constant.
	 * @return Gate An instance of that class.
	 */
	public static function get_gate( string $class_name ): Gate {
		$gate = self::registry()->get( $class_name::SLUG );

		return $gate instanceof $class_name ? $gate : new $class_name();
	}

	/**
	 * When each stage runs, in plain words, in pipeline order. The bot screen groups gates
	 * under these headings.
	 *
	 * @return array<string, string> Stage => label.
	 */
	public static function get_stage_labels(): array {
		$labels = [
			Gate::STAGE_BEFORE_ASK       => __( 'Before searching', 'elasticpress-labs' ),
			Gate::STAGE_FILTER_PASSAGES  => __( 'Checking the sources', 'elasticpress-labs' ),
			Gate::STAGE_SYNTHESIS_PROMPT => __( 'Before writing the answer', 'elasticpress-labs' ),
			Gate::STAGE_VERIFY_ANSWER    => __( 'Checking the answer', 'elasticpress-labs' ),
			Gate::STAGE_ATTRIBUTE_ANSWER => __( 'Adding citations', 'elasticpress-labs' ),
		];

		/**
		 * Filters the plain-language headings of the gate stages on the bot screen.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_gate_stage_labels
		 * @param {array} $labels Stage => label, in pipeline order.
		 * @return {array} Filtered labels.
		 */
		$filtered = apply_filters( 'ep_chat_gate_stage_labels', $labels );

		return is_array( $filtered ) ? $filtered : $labels;
	}

	/**
	 * Heading of one stage; the stage itself when it has none.
	 *
	 * @param string $stage Stage.
	 * @return string
	 */
	public static function get_stage_label( string $stage ): string {
		return (string) ( self::get_stage_labels()[ $stage ] ?? $stage );
	}

	/**
	 * Drop the singleton. Tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$registry = null;
	}

	/**
	 * Closed refusal vocabulary: the reasons every registered gate declares, then the filter
	 * (to reword them, or add codes that are not tied to a gate).
	 *
	 * @return array<string, array{label?: string, message?: string}>
	 */
	public static function refusal_reasons(): array {
		$reasons = [];

		foreach ( self::registry()->get_all() as $gate ) {
			foreach ( $gate->get_refusal_reasons() as $code => $row ) {
				if ( ! isset( $reasons[ $code ] ) && is_array( $row ) ) {
					$reasons[ (string) $code ] = $row;
				}
			}
		}

		/**
		 * Filters Chat refusal reason codes.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_refusal_reasons
		 * @param {array} $reasons Map of code => [ label, message, description, kind, next_step ].
		 *                         `kind` (see Refusals: `outside`, `no_sources`, `withheld`,
		 *                         `as_asked`) sets the card's next step and whether it lists the
		 *                         reports found; `next_step` replaces the kind's next step.
		 * @return {array} Filtered map.
		 */
		$filtered = apply_filters( 'ep_chat_refusal_reasons', $reasons );

		return is_array( $filtered ) ? $filtered : $reasons;
	}

	/**
	 * Synthesis instruction lines from the gates that run for a bot, in ladder order.
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public static function instructions( array $bot ): array {
		$lines = [];

		foreach ( self::registry()->get_all() as $gate ) {
			if ( ! self::should_run( $gate, $bot ) ) {
				continue;
			}

			foreach ( $gate->get_instructions( $bot ) as $line ) {
				$line = trim( (string) $line );

				if ( '' !== $line && ! in_array( $line, $lines, true ) ) {
					$lines[] = $line;
				}
			}
		}

		return $lines;
	}

	/**
	 * Gate rows for one bot, including enabled.
	 *
	 * @param int $bot_id Bot post ID.
	 * @return array
	 */
	public static function describe_for_bot( int $bot_id ): array {
		$enabled = self::get_optional_enabled( $bot_id );
		$rows    = [];

		foreach ( self::registry()->summarize() as $row ) {
			$row['enabled'] = ! empty( $row['required'] ) || in_array( $row['slug'], $enabled, true );
			$rows[]         = $row;
		}

		return $rows;
	}

	/**
	 * Optional slugs that are on by default (see Gate::is_enabled_by_default()).
	 *
	 * @return array<int, string>
	 */
	public static function get_default_optional(): array {
		$defaults = [];

		foreach ( self::registry()->get_all() as $gate ) {
			if ( ! $gate->is_required() && $gate->is_enabled_by_default() ) {
				$defaults[] = $gate->get_slug();
			}
		}

		return $defaults;
	}

	/**
	 * Optional slugs enabled on a bot. A bot that never saved its choice runs the optional gates
	 * that are enabled by default (see Gate::is_enabled_by_default()).
	 *
	 * @param int $bot_id Bot post ID.
	 * @return array<int, string>
	 */
	public static function get_optional_enabled( int $bot_id ): array {
		$stored = get_post_meta( $bot_id, self::META_OPTIONAL_GATES, true );

		if ( ! is_array( $stored ) ) {
			return self::get_default_optional();
		}

		return array_values(
			array_filter(
				array_map( 'strval', $stored ),
				static function ( $slug ) {
					return '' !== $slug;
				}
			)
		);
	}

	/**
	 * Whether a gate should run for this bot.
	 *
	 * @param Gate  $gate Gate.
	 * @param array $bot  Bot array.
	 * @return bool
	 */
	public static function should_run( Gate $gate, array $bot ): bool {
		if ( $gate->is_required() ) {
			return true;
		}

		if ( isset( $bot['id'] ) ) {
			$enabled = self::get_optional_enabled( (int) $bot['id'] );
		} elseif ( isset( $bot['optional_gates'] ) && is_array( $bot['optional_gates'] ) ) {
			$enabled = array_map( 'strval', $bot['optional_gates'] );
		} else {
			// Optional gates on by default are opt-out: a bot that sends no list (no saved bot, no
			// list) runs them, like a bot that never saved its choice.
			$enabled = self::get_default_optional();
		}

		return in_array( $gate->get_slug(), $enabled, true );
	}

	/**
	 * Store optional gate slugs.
	 *
	 * @param int   $bot_id Bot post ID.
	 * @param mixed $slugs  Slugs.
	 * @return void
	 */
	public static function update_optional_enabled( int $bot_id, $slugs ) {
		if ( ! is_array( $slugs ) ) {
			delete_post_meta( $bot_id, self::META_OPTIONAL_GATES );
			return;
		}

		$known = [];

		foreach ( self::registry()->get_all() as $gate ) {
			if ( ! $gate->is_required() ) {
				$known[] = $gate->get_slug();
			}
		}

		$clean = [];

		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug && in_array( $slug, $known, true ) ) {
				$clean[] = $slug;
			}
		}

		update_post_meta( $bot_id, self::META_OPTIONAL_GATES, array_values( array_unique( $clean ) ) );
	}
}
