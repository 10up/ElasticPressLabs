<?php
/**
 * Ordered Chat gate registry.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugins add, remove, or replace gates on `ep_chat_register_gate`. Order is registration order
 * per stage. The pipeline also applies each stage as a filter (`ep_chat_before_ask`,
 * `ep_chat_filter_passages`, `ep_chat_synthesis_prompt`, `ep_chat_verify_answer`), for changes
 * that are not a gate.
 */
class Gate_Registry {
	/**
	 * Registered gates, keyed by slug.
	 *
	 * @var array<string, Gate>
	 */
	protected $gates = [];

	/**
	 * Add or replace a gate by slug.
	 *
	 * @param Gate $gate Gate.
	 * @return void
	 */
	public function register( Gate $gate ) {
		$this->gates[ $gate->get_slug() ] = $gate;
	}

	/**
	 * Remove a gate.
	 *
	 * @param string $slug Gate slug.
	 * @return void
	 */
	public function unregister( string $slug ) {
		unset( $this->gates[ $slug ] );
	}

	/**
	 * Replace a gate, keeping its place in the order. A slug that is not registered adds the gate
	 * at the end.
	 *
	 * @param string $slug Gate slug.
	 * @param Gate   $gate Replacement (its slug may differ).
	 * @return void
	 */
	public function replace( string $slug, Gate $gate ) {
		if ( ! isset( $this->gates[ $slug ] ) ) {
			$this->register( $gate );
			return;
		}

		$gates = [];

		foreach ( $this->gates as $key => $current ) {
			if ( $key === $slug ) {
				$gates[ $gate->get_slug() ] = $gate;
			} elseif ( $key !== $gate->get_slug() ) {
				$gates[ $key ] = $current;
			}
		}

		$this->gates = $gates;
	}

	/**
	 * One gate, or null.
	 *
	 * @param string $slug Gate slug.
	 * @return Gate|null
	 */
	public function get( string $slug ) {
		return $this->gates[ $slug ] ?? null;
	}

	/**
	 * All gates in registration order.
	 *
	 * @return array<int, Gate>
	 */
	public function get_all(): array {
		return array_values( $this->gates );
	}

	/**
	 * Gates for one stage, in registration order.
	 *
	 * @param string $stage Stage slug.
	 * @return array<int, Gate>
	 */
	public function get_for_stage( string $stage ): array {
		return array_values(
			array_filter(
				$this->gates,
				static function ( Gate $gate ) use ( $stage ) {
					return $stage === $gate->get_stage();
				}
			)
		);
	}

	/**
	 * Admin/REST summaries (no per-bot enabled flag).
	 *
	 * @return array
	 */
	public function summarize(): array {
		$rows = [];

		foreach ( $this->get_all() as $gate ) {
			$rows[] = [
				'slug'        => $gate->get_slug(),
				'label'       => $gate->get_label(),
				'description' => $gate->get_description(),
				'stage'       => $gate->get_stage(),
				'stage_label' => Gates::get_stage_label( $gate->get_stage() ),
				'required'    => $gate->is_required(),
				'reason'      => $gate->is_required() ? $gate->get_required_reason() : '',
				'default'     => ! $gate->is_required() && $gate->is_enabled_by_default(),
			];
		}

		return $rows;
	}
}
