<?php
/**
 * Base class for Chat gates.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Defaults for the Gate contract: no refusal reasons, no instructions.
 *
 * A gate that refuses declares its reasons in get_refusal_reasons() and refuses with
 * refuse( $code ): the reader-safe copy comes from the Chat refusal vocabulary, so a
 * plugin can reword it through `ep_chat_refusal_reasons`.
 */
abstract class Base_Gate implements Gate {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_refusal_reasons(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $bot Selected chat bot.
	 * @return string[]
	 */
	public function get_instructions( array $bot ): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_required_reason(): string {
		return __( 'Required by this site.', 'elasticpress-labs' );
	}

	/**
	 * Refuse with one of this gate's reasons.
	 *
	 * @param string $code    Refusal code.
	 * @param array  $context Context keys to merge before stopping.
	 * @return Gate_Result
	 */
	protected function refuse( string $code, array $context = [] ): Gate_Result {
		$reasons = Gates::refusal_reasons();
		$message = (string) ( $reasons[ $code ]['message'] ?? '' );

		if ( '' === $message ) {
			$message = (string) ( $this->get_refusal_reasons()[ $code ]['message'] ?? '' );
		}

		return Gate_Result::refuse( $code, $message, $context );
	}
}
