<?php
/**
 * Chat gate contract.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * One step in the Chat gate ladder.
 *
 * Receives a context array and returns pass, refuse-with-reason, or modify.
 * Re-ranker is a pipeline stage, not a gate.
 */
interface Gate {
	/**
	 * Before retrieve.
	 *
	 * @var string
	 */
	public const STAGE_BEFORE_ASK = 'before_ask';

	/**
	 * After retrieve/re-rank, before synthesis.
	 *
	 * @var string
	 */
	public const STAGE_FILTER_PASSAGES = 'filter_passages';

	/**
	 * Before the LLM system instruction is sent.
	 *
	 * @var string
	 */
	public const STAGE_SYNTHESIS_PROMPT = 'synthesis_prompt';

	/**
	 * After a draft answer exists.
	 *
	 * @var string
	 */
	public const STAGE_VERIFY_ANSWER = 'verify_answer';

	/**
	 * After the answer passed every check: add citations.
	 *
	 * @var string
	 */
	public const STAGE_ATTRIBUTE_ANSWER = 'attribute_answer';

	/**
	 * Stable slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * What the gate does, in plain words, for the bot screen. Empty shows no description.
	 *
	 * @since 2.6.0
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Ladder stage.
	 *
	 * @return string
	 */
	public function get_stage(): string;

	/**
	 * Required gates always run and have no bot toggle.
	 *
	 * Gates that call an external service (a model, an API) should be optional, so a bot can
	 * switch them off.
	 *
	 * @return bool
	 */
	public function is_required(): bool;

	/**
	 * Why a required gate cannot be turned off, for the bot screen.
	 *
	 * @since 2.6.0
	 * @return string
	 */
	public function get_required_reason(): string;

	/**
	 * Whether an optional gate runs on bots that never saved their gate choice.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public function is_enabled_by_default(): bool;

	/**
	 * Evaluate one question.
	 *
	 * @param array $context Question, bot, passages, prompt, content, history.
	 * @return Gate_Result
	 */
	public function run( array $context ): Gate_Result;

	/**
	 * Refusal reasons this gate can return, added to the Chat refusal vocabulary.
	 *
	 * Codes follow the shared refusal vocabulary (snake_case names such as `no_supporting_passage`
	 * or `numeric_mismatch`). Declare every code the gate refuses with, and only those.
	 *
	 * @since 2.6.0
	 * @return array<string, array{label?: string, message?: string, description?: string, kind?: string, next_step?: string}>
	 *         Code => label, reader-safe copy, when the reason fires, and optionally the kind of
	 *         decline (see Refusals) and the next step the card suggests.
	 */
	public function get_refusal_reasons(): array;

	/**
	 * Lines this gate adds to the synthesis system instruction, when it runs for the bot.
	 *
	 * @since 2.6.0
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array;
}
