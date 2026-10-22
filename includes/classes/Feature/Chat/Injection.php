<?php
/**
 * Instructions planted in text the model reads.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Finds and removes instruction-shaped text: sentences addressed to the model ("ignore the
 * previous instructions", "reply only with…", "you are now…"), role markers, and HTML comments
 * addressed to the assistant. Used on passages before the model sees them.
 */
class Injection {
	/**
	 * Patterns for instruction-shaped text (English).
	 *
	 * @return string[] Regexes.
	 */
	public function get_patterns(): array {
		$patterns = [
			// Telling the model to ignore earlier instructions or figures. Only wording that points
			// back at the prompt (previous, above) or names it (instructions, system prompt), so
			// prose about courts ignoring rules or not forgetting the context stays.
			'/\b(?:ignore|disregard|forget|override)\s+(?:(?:all|any|the|these|those|your)\s+){0,2}(?:previous|prior|above|earlier|preceding|former|foregoing)\s+(?:\w+\s+)?(?:instructions?|rules|directions|prompts?|passages|figures|guidelines|context|sources|messages|text)\b/iu',
			'/\b(?:ignore|disregard|override)\s+(?:(?:all|any|the|these|those|your)\s+){0,2}(?:instructions?|system prompt|prompt|retrieved passages|grounding (?:rules|checks))\b/iu',
			// Giving the model a new role or mode.
			'/\byou are now (?:a|an|the|acting|free|in (?:developer|debug|admin|test) mode)\b/iu',
			// Naming the system prompt, a developer message, or an override.
			'/\b(?:system|developer)\s+(?:prompt|message|override|instructions?)\b/iu',
			// Chat-template tokens and role tags.
			'/<\|[a-z_]+\|>|\[(?:system|assistant|inst)\]/iu',
			// A line that opens like a chat turn (system, assistant, or developer and a colon).
			'/^\s*(?:#{1,6}\s*)?(?:system|assistant|developer)\s*:/imu',
			// Telling the model to reply only with some text or a single word.
			'/\b(?:reply|respond|answer)\b[^.\n]{0,20}\b(?:only|just|exactly)\b[^.\n]{0,20}\bwith\b|\breply with the (?:single )?word\b/iu',
			// Telling the model not to cite the sources or mention these instructions.
			"/\\b(?:do not|don't|never)\\s+(?:cite|mention|include|reveal)\\b[^.\\n]{0,40}\\b(?:sources?|citations?|passages|th(?:is|ese) instructions?)\\b/iu",
			// Asking the model to reveal, print, or translate its instructions or configuration.
			'/\b(?:reveal|print|repeat|paste|output|show|translate)\b[^.\n]{0,30}\b(?:your|the)\b[^.\n]{0,20}\b(?:instructions|system prompt|hidden prompt|operating rules|configuration)\b/iu',
			// Text addressed to the assistant, the AI, or the model.
			'/\b(?:attention|note to)\s+(?:the\s+)?(?:assistant|ai|chatbot|model|llm)\b|\bassistant note\b/iu',
		];

		/**
		 * Filters the patterns for instruction-shaped text in passages. A sentence (or line)
		 * that matches is removed before the model reads the passage.
		 *
		 * This is one layer of defense, not a boundary: anyone who can publish a post can write
		 * text that tries to steer the answers other people read, and patterns only catch the
		 * wording they describe (the defaults are English). Add patterns for the site's
		 * languages here. An invalid pattern is ignored, with a notice.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_injection_patterns
		 * @param {string[]} $patterns Regexes (English defaults).
		 * @return {string[]} Filtered patterns.
		 */
		$patterns = apply_filters( 'ep_chat_injection_patterns', $patterns );
		$patterns = array_values( array_filter( array_map( 'strval', is_array( $patterns ) ? $patterns : [] ) ) );

		return array_values( array_filter( $patterns, [ $this, 'is_valid_pattern' ] ) );
	}

	/**
	 * Whether a pattern is a valid regex. An invalid one gets a notice, once per request.
	 *
	 * @param string $pattern Regex.
	 * @return bool
	 */
	protected function is_valid_pattern( string $pattern ): bool {
		static $checked = [];

		if ( isset( $checked[ $pattern ] ) ) {
			return $checked[ $pattern ];
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checking whether the pattern compiles.
		$checked[ $pattern ] = false !== @preg_match( $pattern, '' );

		if ( ! $checked[ $pattern ] ) {
			_doing_it_wrong(
				'ep_chat_injection_patterns',
				/* translators: %s: regex */
				esc_html( sprintf( __( 'The pattern %s is not a valid regular expression, so it is ignored.', 'elasticpress-labs' ), $pattern ) ),
				'ElasticPress Labs 2.6.0'
			);
		}

		return $checked[ $pattern ];
	}

	/**
	 * Remove instruction-shaped text: HTML comments addressed to the assistant, then each line
	 * that opens like a chat turn, then each sentence that matches a pattern.
	 *
	 * @param string $text Text.
	 * @return array{text: string, removed: string[]} The text without them, and what was removed.
	 */
	public function strip( string $text ): array {
		$removed  = [];
		$patterns = $this->get_patterns();

		$text = (string) preg_replace_callback(
			'/<!--.*?(?:-->|$)/su',
			function ( $comment ) use ( &$removed ) {
				if ( preg_match( '/\b(?:assistant|ai|model|chatbot|llm|instructions?)\b/iu', $comment[0] ) ) {
					$removed[] = trim( $comment[0] );

					return '';
				}

				return $comment[0];
			},
			$text
		);

		$lines = [];

		foreach ( explode( "\n", $text ) as $line ) {
			$kept = [];

			foreach ( $this->split_sentences( $line ) as $sentence ) {
				if ( $this->matches( $sentence, $patterns ) ) {
					$removed[] = trim( $sentence );
				} else {
					$kept[] = $sentence;
				}
			}

			$lines[] = implode( ' ', $kept );
		}

		return [
			'text'    => trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) ),
			'removed' => array_values( array_filter( $removed, 'strlen' ) ),
		];
	}

	/**
	 * Whether a text has instruction-shaped content.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public function has_injection( string $text ): bool {
		return (bool) $this->strip( $text )['removed'];
	}

	/**
	 * Whether a sentence matches any pattern.
	 *
	 * @param string   $sentence Sentence.
	 * @param string[] $patterns Patterns.
	 * @return bool
	 */
	protected function matches( string $sentence, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $sentence ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sentences of one line, split before a capital, a digit, or an opening bracket.
	 *
	 * @param string $line Line.
	 * @return string[]
	 */
	protected function split_sentences( string $line ): array {
		$parts = preg_split( Text::get_sentence_break( '[A-Z0-9"“(\[<]' ), $line );

		return is_array( $parts ) ? $parts : [ $line ];
	}
}
