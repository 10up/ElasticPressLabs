<?php
/**
 * Chat answering settings.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPress\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings that shape answers, and where each value comes from.
 *
 * Shared settings are on the Chat feature (the default) and on each bot (an override). A bot
 * value wins over the feature value, which wins over the code default. Feature-only settings
 * skip the bot. The filters that already existed for these values (such as
 * `ep_chat_passage_chunks`) still run last, so code keeps the last word.
 */
class Settings {
	/**
	 * Search method value: the algorithm the site search uses.
	 *
	 * @var string
	 */
	public const SEARCH_SITE = 'site';

	/**
	 * Fallback search value: the hybrid search where it is registered, else the site search's.
	 *
	 * @var string
	 */
	public const SEARCH_AUTO = 'auto';

	/**
	 * Fallback search value: no second search.
	 *
	 * @var string
	 */
	public const SEARCH_NONE = 'none';

	/**
	 * Settings: type, whether bots can override it, code default, and range (or, for text, the
	 * maximum length; for a choice, the allowed values). An empty value of a setting with a
	 * `fallback` means that other setting's value.
	 *
	 * @var array<string, array>
	 */
	public const FIELDS = [
		'answer_model'        => [
			'type'    => 'model',
			'shared'  => true,
			'default' => '',
		],
		'verifier_model'      => [
			'type'     => 'model',
			'shared'   => true,
			'default'  => '',
			'fallback' => 'answer_model',
		],
		'answer_instructions' => [
			'type'       => 'text',
			'shared'     => true,
			'default'    => '',
			'max_length' => 4000,
		],
		'retrieve_count'      => [
			'type'    => 'int',
			'shared'  => true,
			'default' => 10,
			'min'     => 1,
			'max'     => 50,
		],
		'rerank_floor'        => [
			'type'    => 'float',
			'shared'  => true,
			'default' => null,
			'min'     => 0,
			'max'     => 1,
		],
		'history_messages'    => [
			'type'    => 'int',
			'shared'  => true,
			'default' => 4,
			'min'     => 0,
			'max'     => 20,
		],
		'summary_enabled'     => [
			'type'    => 'bool',
			'shared'  => true,
			'default' => false,
		],
		'summary_model'       => [
			'type'     => 'model',
			'shared'   => false,
			'default'  => '',
			'fallback' => 'answer_model',
		],
		'intent_enabled'      => [
			'type'    => 'bool',
			'shared'  => true,
			'default' => false,
		],
		'intent_model'        => [
			'type'     => 'model',
			'shared'   => false,
			'default'  => '',
			'fallback' => 'answer_model',
		],
		'search_algorithm'    => [
			'type'    => 'choice',
			'shared'  => true,
			'default' => 'site',
			'choices' => [],
		],
		'search_fallback'     => [
			'type'    => 'choice',
			'shared'  => true,
			'default' => 'auto',
			'choices' => [],
		],
		'query_model'         => [
			'type'     => 'model',
			'shared'   => true,
			'default'  => '',
			'fallback' => 'intent_model',
		],
		'date_range_enabled'  => [
			'type'    => 'bool',
			'shared'  => true,
			'default' => false,
		],
		'clarify_enabled'     => [
			'type'    => 'bool',
			'shared'  => true,
			'default' => false,
		],
		'decline_display'     => [
			'type'    => 'choice',
			'shared'  => true,
			'default' => 'show',
			'choices' => [ 'show', 'hide', 'uniform' ],
		],
		'passage_chunks'      => [
			'type'    => 'int',
			'shared'  => false,
			'default' => 3,
			'min'     => 1,
			'max'     => 10,
		],
		'passage_word_budget' => [
			'type'    => 'int',
			'shared'  => false,
			'default' => 6000,
			'min'     => 500,
			'max'     => 20000,
		],
		'max_in_flight'       => [
			'type'    => 'int',
			'shared'  => false,
			'default' => 1,
			'min'     => 1,
			'max'     => 10,
		],
		'max_per_hour'        => [
			'type'    => 'int',
			'shared'  => false,
			'default' => 30,
			'min'     => 0,
			'max'     => 1000,
		],
		'retry_after'         => [
			'type'    => 'int',
			'shared'  => false,
			'default' => 10,
			'min'     => 1,
			'max'     => 300,
		],
	];

	/**
	 * Keys bots can override.
	 *
	 * @return string[]
	 */
	public static function get_shared_keys(): array {
		return array_keys( array_filter( self::FIELDS, static fn( $field ) => $field['shared'] ) );
	}

	/**
	 * The value in effect: the bot's (for shared settings), else the feature's, else the code
	 * default. An empty value of a setting with a `fallback` (the verifier, summary, and
	 * question models) is that setting's value.
	 *
	 * @param string $key Setting key.
	 * @param array  $bot Selected chat bot (from Bots::get()). Empty for feature and default only.
	 * @return mixed
	 */
	public static function get( string $key, array $bot = [] ) {
		$value = self::get_own( $key, $bot );

		if ( null === $value ) {
			$value = self::get_feature_value( $key );
		}

		if ( null === $value ) {
			$value = self::get_default( $key );
		}

		if ( '' === $value && isset( self::FIELDS[ $key ]['fallback'] ) ) {
			$value = self::get( self::FIELDS[ $key ]['fallback'], $bot );
		}

		return $value;
	}

	/**
	 * What a bot inherits for a shared setting: the feature value, else the code default.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get_inherited( string $key ) {
		$value = self::get_feature_value( $key );

		return null === $value ? self::get_default( $key ) : $value;
	}

	/**
	 * A bot's own value for a shared setting, or null when it inherits.
	 *
	 * @param string $key Setting key.
	 * @param array  $bot Bot.
	 * @return mixed|null
	 */
	public static function get_own( string $key, array $bot ) {
		if ( empty( self::FIELDS[ $key ]['shared'] ) || ! array_key_exists( $key, $bot ) ) {
			return null;
		}

		$value = self::sanitize( $key, $bot[ $key ], false );

		return is_wp_error( $value ) ? null : $value;
	}

	/**
	 * The Chat feature's value, or null when it is empty.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public static function get_feature_value( string $key ) {
		$feature = Features::factory()->get_registered_feature( 'chat' );

		if ( ! $feature ) {
			return null;
		}

		$value = self::sanitize( $key, $feature->get_setting( $key ), false );

		return is_wp_error( $value ) ? null : $value;
	}

	/**
	 * Code default. For the answer model, `ep_chat_default_model`; for the answer instructions,
	 * `ep_chat_default_answer_instructions`.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get_default( string $key ) {
		$default = self::FIELDS[ $key ]['default'] ?? null;

		if ( 'answer_model' === $key ) {
			/**
			 * Filters the answer model used when neither the bot nor the Chat feature sets one.
			 * Empty uses the AI client's default model.
			 *
			 * @since 2.6.0
			 * @hook ep_chat_default_model
			 * @param {string} $model `provider::model`, for example `openai-compatible-servers::amazon-nova-pro-v1`. Default empty.
			 * @return {string} Filtered model.
			 */
			$default = (string) apply_filters( 'ep_chat_default_model', '' );
			$default = Models::parse( $default ) ? $default : '';
		}

		if ( 'answer_instructions' === $key ) {
			/**
			 * Filters the answer instructions used when neither the bot nor the Chat feature sets
			 * them. They go into the answer prompt after the base rules, so they can shape tone,
			 * length, and format, but not remove the rules.
			 *
			 * @since 2.6.0
			 * @hook ep_chat_default_answer_instructions
			 * @param {string} $instructions Instructions. Default empty.
			 * @return {string} Filtered instructions.
			 */
			$default = self::sanitize( $key, apply_filters( 'ep_chat_default_answer_instructions', '' ), false );
			$default = is_string( $default ) ? $default : '';
		}

		return $default;
	}

	/**
	 * Where a bot's value comes from: `bot`, `feature`, `default`, or `none` (empty everywhere).
	 *
	 * @param string $key Setting key.
	 * @param array  $bot Selected chat bot.
	 * @return string
	 */
	public static function get_source( string $key, array $bot = [] ): string {
		if ( null !== self::get_own( $key, $bot ) ) {
			return 'bot';
		}

		if ( null !== self::get_feature_value( $key ) ) {
			return 'feature';
		}

		$default = self::get_default( $key );

		return null === $default || '' === $default ? 'none' : 'default';
	}

	/**
	 * Clean a value. Empty means "not set" (null). Out of range or malformed is an error, and so
	 * is, on save, a model the configured providers do not list. Reading a stored value only
	 * checks its format, so answering never loads the model lists.
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $value  Value.
	 * @param bool   $saving Whether the value is being saved (checks the model lists).
	 * @return mixed|null|\WP_Error
	 */
	public static function sanitize( string $key, $value, bool $saving = true ) {
		$field = self::FIELDS[ $key ] ?? null;

		if ( ! $field ) {
			return new \WP_Error( 'ep_chat_unknown_setting', esc_html__( 'Unknown Chat setting.', 'elasticpress-labs' ) );
		}

		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}

		if ( 'model' === $field['type'] ) {
			$value = trim( (string) $value );

			if ( ! Models::parse( $value ) || ( $saving && ! Models::is_valid( $value ) ) ) {
				return new \WP_Error(
					'ep_chat_invalid_model',
					/* translators: %s: model */
					sprintf( esc_html__( '%s is not a model of the configured AI providers.', 'elasticpress-labs' ), esc_html( $value ) ),
					[ 'status' => 400 ]
				);
			}

			return $value;
		}

		if ( 'bool' === $field['type'] ) {
			if ( is_bool( $value ) ) {
				return $value;
			}

			$flag = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			return null === $flag
				? new \WP_Error(
					'ep_chat_invalid_setting',
					/* translators: %s: setting label */
					sprintf( esc_html__( '%s must be on or off.', 'elasticpress-labs' ), esc_html( self::get_label( $key ) ) ),
					[ 'status' => 400 ]
				)
				: $flag;
		}

		if ( 'choice' === $field['type'] ) {
			$value = trim( (string) $value );

			return in_array( $value, self::get_choices( $key ), true )
				? $value
				: new \WP_Error(
					'ep_chat_invalid_setting',
					/* translators: %s: setting label */
					sprintf( esc_html__( '%s has an unknown value.', 'elasticpress-labs' ), esc_html( self::get_label( $key ) ) ),
					[ 'status' => 400 ]
				);
		}

		if ( 'text' === $field['type'] ) {
			// Tags are stripped, so the text cannot open or close the prompt's own blocks.
			$value = trim( sanitize_textarea_field( (string) $value ) );

			if ( '' === $value ) {
				return null;
			}

			if ( mb_strlen( $value ) > $field['max_length'] ) {
				return new \WP_Error(
					'ep_chat_setting_too_long',
					sprintf(
						/* translators: 1: setting label, 2: maximum number of characters */
						esc_html__( '%1$s can have at most %2$s characters.', 'elasticpress-labs' ),
						esc_html( self::get_label( $key ) ),
						esc_html( number_format_i18n( $field['max_length'] ) )
					),
					[ 'status' => 400 ]
				);
			}

			return $value;
		}

		if ( ! is_numeric( $value ) || ( 'int' === $field['type'] && floor( (float) $value ) !== (float) $value ) ) {
			return self::range_error( $key );
		}

		$value = 'int' === $field['type'] ? (int) $value : (float) $value;

		if ( $value < $field['min'] || $value > $field['max'] ) {
			return self::range_error( $key );
		}

		return $value;
	}

	/**
	 * A clean value as stored in an option or post meta: booleans as `1` / `0`.
	 *
	 * @param mixed $value Value from sanitize() (not an error).
	 * @return string Empty for "not set".
	 */
	public static function to_stored( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Error for a value out of range.
	 *
	 * @param string $key Setting key.
	 * @return \WP_Error
	 */
	protected static function range_error( string $key ): \WP_Error {
		$field = self::FIELDS[ $key ];

		return new \WP_Error(
			'ep_chat_setting_out_of_range',
			sprintf(
				/* translators: 1: setting key, 2: minimum, 3: maximum */
				esc_html__( '%1$s must be a number from %2$s to %3$s.', 'elasticpress-labs' ),
				esc_html( self::get_label( $key ) ),
				esc_html( (string) $field['min'] ),
				esc_html( (string) $field['max'] )
			),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Field label, shared by the Chat feature and the bot screen.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get_label( string $key ): string {
		$labels = [
			'answer_model'        => __( 'Answer model', 'elasticpress-labs' ),
			'answer_instructions' => __( 'Answer instructions', 'elasticpress-labs' ),
			'verifier_model'      => __( 'Claim checker model', 'elasticpress-labs' ),
			'retrieve_count'      => __( 'Reports per question', 'elasticpress-labs' ),
			'rerank_floor'        => __( 'Minimum score', 'elasticpress-labs' ),
			'history_messages'    => __( 'Conversation memory', 'elasticpress-labs' ),
			'summary_enabled'     => __( 'Write a conversation summary', 'elasticpress-labs' ),
			'summary_model'       => __( 'Summary model', 'elasticpress-labs' ),
			'intent_enabled'      => __( 'Understand the question', 'elasticpress-labs' ),
			'intent_model'        => __( 'Question model', 'elasticpress-labs' ),
			'query_model'         => __( 'Query model', 'elasticpress-labs' ),
			'search_algorithm'    => __( 'Search method', 'elasticpress-labs' ),
			'search_fallback'     => __( 'Fallback search', 'elasticpress-labs' ),
			'date_range_enabled'  => __( 'Use dates in the question', 'elasticpress-labs' ),
			'clarify_enabled'     => __( 'Offer clarifying choices', 'elasticpress-labs' ),
			'decline_display'     => __( 'Declined questions', 'elasticpress-labs' ),
			'passage_chunks'      => __( 'Sections per report', 'elasticpress-labs' ),
			'passage_word_budget' => __( 'Word budget', 'elasticpress-labs' ),
			'max_in_flight'       => __( 'Questions at once per person', 'elasticpress-labs' ),
			'max_per_hour'        => __( 'Questions per hour per person', 'elasticpress-labs' ),
			'retry_after'         => __( 'Wait before retrying (seconds)', 'elasticpress-labs' ),
		];

		return $labels[ $key ] ?? $key;
	}

	/**
	 * Labels of a choice setting's values.
	 *
	 * @param string $key Setting key.
	 * @return array<string, string> Value => label.
	 */
	public static function get_choice_labels( string $key ): array {
		$labels = [
			'decline_display' => [
				'show'    => __( 'Show the reason', 'elasticpress-labs' ),
				'hide'    => __( 'Hide the reason', 'elasticpress-labs' ),
				'uniform' => __( 'Same message for every decline', 'elasticpress-labs' ),
			],
		];

		if ( 'search_algorithm' === $key || 'search_fallback' === $key ) {
			return self::get_search_choice_texts( $key, 'label' );
		}

		return $labels[ $key ] ?? array_combine( self::get_choices( $key ), self::get_choices( $key ) );
	}

	/**
	 * One line on what each value of a choice setting does.
	 *
	 * @param string $key Setting key.
	 * @return array<string, string> Value => description.
	 */
	public static function get_choice_descriptions( string $key ): array {
		$descriptions = [
			'decline_display' => [
				'show'    => __( 'The reason, a “Why?” explanation, a next step, and related reports.', 'elasticpress-labs' ),
				'hide'    => __( 'A general message. The next step and related reports stay.', 'elasticpress-labs' ),
				'uniform' => __( 'One message and one next step, without related reports.', 'elasticpress-labs' ),
			],
		];

		if ( 'search_algorithm' === $key || 'search_fallback' === $key ) {
			return self::get_search_choice_texts( $key, 'description' );
		}

		return $descriptions[ $key ] ?? [];
	}

	/**
	 * The registered search algorithms that call a model (`$uses_model`): the AI search methods.
	 *
	 * @return string[] Slugs.
	 */
	public static function get_ai_search_methods(): array {
		$slugs = [];

		foreach ( \ElasticPress\SearchAlgorithms::factory()->get_all() as $slug => $algorithm ) {
			if ( ! empty( $algorithm->uses_model ) ) {
				$slugs[] = (string) $slug;
			}
		}

		return $slugs;
	}

	/**
	 * The fallback search a value stands for: Automatic is the hybrid search where it is
	 * registered, else the site search's (`site`).
	 *
	 * @param string $fallback Fallback search value.
	 * @return string `site`, `none`, or an algorithm slug.
	 */
	public static function resolve_fallback( string $fallback ): string {
		if ( self::SEARCH_AUTO !== $fallback ) {
			return $fallback;
		}

		return in_array( 'hybrid_knn', \ElasticPress\SearchAlgorithms::factory()->get_all( true ), true ) ? 'hybrid_knn' : self::SEARCH_SITE;
	}

	/**
	 * Allowed values of a choice setting. The search method and its fallback list the
	 * registered search algorithms (see get_search_choice_texts()).
	 *
	 * @param string $key Setting key.
	 * @return string[]
	 */
	public static function get_choices( string $key ): array {
		if ( 'search_algorithm' === $key || 'search_fallback' === $key ) {
			return array_map( 'strval', array_keys( self::get_search_choice_texts( $key, 'label' ) ) );
		}

		return self::FIELDS[ $key ]['choices'] ?? [];
	}

	/**
	 * Labels or descriptions of the search method's values: the site search's algorithm, then
	 * every registered search algorithm (the hidden ones too: hidden means not offered to site
	 * search). The fallback starts with Automatic (see resolve_fallback()), also has None, and
	 * leaves out the algorithms that call a model (`$uses_model`), so a fallback never needs
	 * another fallback.
	 *
	 * @param string $key  `search_algorithm` or `search_fallback`.
	 * @param string $text `label` or `description`.
	 * @return array<string, string> Value => text.
	 */
	protected static function get_search_choice_texts( string $key, string $text ): array {
		$is_label = 'label' === $text;
		$texts    = [];

		if ( 'search_fallback' === $key ) {
			$texts[ self::SEARCH_AUTO ] = $is_label
				? __( 'Automatic', 'elasticpress-labs' )
				: __( 'The hybrid search (kNN and a regular query) where it is available, else the algorithm the site search uses.', 'elasticpress-labs' );
		}

		$texts += [
			self::SEARCH_SITE => $is_label
				? __( 'Same as site search', 'elasticpress-labs' )
				: __( 'The algorithm the site search uses.', 'elasticpress-labs' ),
		];

		if ( 'search_fallback' === $key ) {
			$texts[ self::SEARCH_NONE ] = $is_label
				? __( 'None', 'elasticpress-labs' )
				: __( 'No second search: the reader gets an error to retry, or a decline when nothing is found.', 'elasticpress-labs' );
		}

		foreach ( \ElasticPress\SearchAlgorithms::factory()->get_all() as $slug => $algorithm ) {
			if ( 'search_fallback' === $key && ! empty( $algorithm->uses_model ) ) {
				continue;
			}

			$value          = $is_label ? $algorithm->get_name() : $algorithm->get_description();
			$texts[ $slug ] = trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}

		return $texts;
	}

	/**
	 * Field help: one short line on what the setting does. Longer details are in get_details().
	 *
	 * @param string $key     Setting key.
	 * @param string $context `feature` or `bot` (the bot screen adds its own default sentence).
	 * @return string
	 */
	public static function get_help( string $key, string $context = 'feature' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$help = [
			'answer_model'        => __( 'Writes the answers.', 'elasticpress-labs' ),
			'answer_instructions' => __( 'Tone, length, or format of the answers.', 'elasticpress-labs' ),
			'verifier_model'      => __( 'Checks rankings and place claims against the sources.', 'elasticpress-labs' ),
			'retrieve_count'      => __( 'How many reports the search finds for each question (1 to 50).', 'elasticpress-labs' ),
			'rerank_floor'        => __( 'Re-ranked passages below this score (0 to 1) are dropped. Empty keeps them all.', 'elasticpress-labs' ),
			'history_messages'    => __( 'Earlier questions and answers sent with a follow-up question (0 to 20).', 'elasticpress-labs' ),
			'summary_enabled'     => __( 'A short summary of each conversation, for the History search and the export.', 'elasticpress-labs' ),
			'summary_model'       => __( 'Writes the conversation summaries.', 'elasticpress-labs' ),
			'intent_enabled'      => __( 'Reads each question before the search, to rewrite or decline it.', 'elasticpress-labs' ),
			'intent_model'        => __( 'Reads each question before the search, and writes clarifying choices.', 'elasticpress-labs' ),
			'query_model'         => __( 'Writes the search query, for the AI search methods.', 'elasticpress-labs' ),
			'search_algorithm'    => __( 'How the search finds the reports for each question.', 'elasticpress-labs' ),
			'search_fallback'     => __( 'Runs when an AI search method fails or finds nothing.', 'elasticpress-labs' ),
			'date_range_enabled'  => __( 'A year or words like “latest” in the question guide the search.', 'elasticpress-labs' ),
			'clarify_enabled'     => __( 'For a short, vague query such as “trust”, offers questions to pick from.', 'elasticpress-labs' ),
			'decline_display'     => __( 'What readers see when a question is declined.', 'elasticpress-labs' ),
			'passage_chunks'      => __( 'How many of each report’s best-matching sections the model reads (1 to 10).', 'elasticpress-labs' ),
			'passage_word_budget' => __( 'The most words the model reads for one question (500 to 20,000).', 'elasticpress-labs' ),
			'max_in_flight'       => __( 'Questions one person can have waiting for an answer (1 to 10).', 'elasticpress-labs' ),
			'max_per_hour'        => __( 'Questions one person can ask in an hour, with any chat bot (0 for no limit).', 'elasticpress-labs' ),
			'retry_after'         => __( 'How long a person over that limit waits before asking again (1 to 300).', 'elasticpress-labs' ),
		];

		return $help[ $key ] ?? '';
	}

	/**
	 * Longer details of a setting, for those who want them (behind "More").
	 *
	 * @param string $key Setting key.
	 * @return string Plain text; empty when the help says it all.
	 */
	public static function get_details( string $key ): string {
		$details = [
			'answer_model'        => __( 'Lists the models of the AI providers set up in Settings → Connectors. Empty uses the site’s default model.', 'elasticpress-labs' ),
			'answer_instructions' => __( 'Added to the prompt after the built-in rules, which always take precedence. Tags are removed. At most 4,000 characters.', 'elasticpress-labs' ),
			'verifier_model'      => __( 'Used only by chat bots with the claim verifier check on. A small, fast model works well.', 'elasticpress-labs' ),
			'retrieve_count'      => __( 'Counted before re-ranking. More reports give the model more to work with, but make answers slower.', 'elasticpress-labs' ),
			'rerank_floor'        => __( 'Used only by chat bots with re-ranking on. If no passage is left, the question is declined.', 'elasticpress-labs' ),
			'history_messages'    => __( 'They help the model understand a follow-up question. 0 sends none.', 'elasticpress-labs' ),
			'summary_enabled'     => __( 'After each answer, a model call updates the summary: the topics covered and search keywords, without figures. Each summary is an extra model call.', 'elasticpress-labs' ),
			'summary_model'       => __( 'A small, fast model works well.', 'elasticpress-labs' ),
			'max_per_hour'        => __( 'Each question calls the AI provider several times, and the provider bills each call. Every question counts, whatever its answer. Up to 1,000.', 'elasticpress-labs' ),
			'intent_enabled'      => __( 'A model call answers questions about the assistant itself with a fixed reply, declines questions that are unclear or outside what the site covers without searching, and rewrites follow-up questions into one standalone search. The answer model still reads the person’s own words. Each question is an extra model call.', 'elasticpress-labs' ),
			'intent_model'        => __( 'Used by “Understand the question” and “Offer clarifying choices”. The reader waits for it, so a small, fast model works well.', 'elasticpress-labs' ),
			'search_algorithm'    => __( 'Only Chat uses this; site search keeps its own algorithm. With “AI-generated query”, a model reads the question and a description of the search index and writes the query; each question is an extra model call, and the reader is told when the fallback search ran.', 'elasticpress-labs' ),
			'search_fallback'     => __( 'Used by the AI search methods, such as the AI-generated query. Algorithms that call a model are not offered. The answer records which search ran and why.', 'elasticpress-labs' ),
			'query_model'         => __( 'Used by the AI search methods. Empty uses the question model. It reads the question and a description of the search index, and writes an Elasticsearch query; the reader waits for it.', 'elasticpress-labs' ),
			'date_range_enabled'  => __( 'No model call. A year the question asks about limits the search to content published in or after that year; words such as “latest” or “these days” rank newer content higher. The search is never limited to content published before a date.', 'elasticpress-labs' ),
			'clarify_enabled'     => __( 'Only for queries of up to three words, not phrased as questions, that are about to be declined while at least two reports were found. The question model looks for the different things the query could mean. Each offer is an extra model call.', 'elasticpress-labs' ),
			'decline_display'     => __( 'History and the export always keep the reason.', 'elasticpress-labs' ),
			'passage_chunks'      => __( 'Sent in reading order.', 'elasticpress-labs' ),
			'passage_word_budget' => __( 'Over the budget, the lowest-ranked reports lose their extra sections first. A bigger budget gives the model more to read, but makes answers slower: each sentence of the answer is matched against more text to find its source.', 'elasticpress-labs' ),
		];

		return $details[ $key ] ?? '';
	}

	/**
	 * Help as HTML for a settings screen: the short line, then the details behind "More".
	 *
	 * @param string $help    Short help (plain text).
	 * @param string $details Details (plain text, may be empty).
	 * @return string
	 */
	public static function to_help_html( string $help, string $details ): string {
		$html = esc_html( $help );

		if ( '' !== $details ) {
			$html .= sprintf(
				' <details class="ep-chat-help-more"><summary>%1$s</summary>%2$s</details>',
				esc_html__( 'More', 'elasticpress-labs' ),
				esc_html( $details )
			);
		}

		return $html;
	}
}
