<?php
/**
 * Text models Chat can use.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lists the text-generation models of the AI providers configured on the site (the WordPress AI
 * client), and turns a stored choice into the client's model preference.
 *
 * A model is stored as `provider::model`, for example `openai-compatible-servers::amazon-nova-pro-v1`.
 * The client can only use a model its provider lists: a preference for any other model is
 * ignored, and the client's default model answers.
 */
class Models {
	/**
	 * Separator between the provider ID and the model ID.
	 *
	 * @var string
	 */
	public const SEPARATOR = '::';

	/**
	 * Transient that keeps the list between requests. Without a persistent object cache, the AI
	 * client asks every provider again on each request.
	 *
	 * @var string
	 */
	public const TRANSIENT = 'ep_chat_models';

	/**
	 * Per-request cache of the list.
	 *
	 * @var array|null
	 */
	protected static $list = null;

	/**
	 * Text-generation models of every configured provider, and the providers whose list failed.
	 *
	 * Kept in a transient for 12 hours (5 minutes when a provider failed).
	 *
	 * @return array{models: array<int, array{value: string, label: string, provider: string, model: string}>, errors: array<string, string>}
	 */
	public static function get_list(): array {
		if ( null !== self::$list ) {
			return self::$list;
		}

		$list = [
			'models' => [],
			'errors' => [],
		];

		if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) && isset( $cached['models'], $cached['errors'] ) ) {
				$list = $cached;
			} else {
				$list = self::load_list();

				// A list with a failed provider is tried again soon.
				set_transient( self::TRANSIENT, $list, $list['errors'] ? 5 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
			}
		}

		/**
		 * Filters the models Chat offers: text-generation models of the configured AI providers.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_models
		 * @param {array} $list `models` (value, label, provider, model) and `errors` (provider ID => message).
		 * @return {array} Filtered list.
		 */
		$filtered = apply_filters( 'ep_chat_models', $list );

		self::$list = [
			'models' => array_values( is_array( $filtered['models'] ?? null ) ? $filtered['models'] : [] ),
			'errors' => is_array( $filtered['errors'] ?? null ) ? $filtered['errors'] : [],
		];

		return self::$list;
	}

	/**
	 * Whether the site can generate text: the WordPress AI client is available and at least one
	 * active AI provider has its key (in Connectors, a constant, or an environment variable) or
	 * needs none. Then a model always exists, because the client falls back to the first
	 * text model of the providers set up.
	 *
	 * Reads stored credentials only, with no request, so it is cheap enough for every page load;
	 * a provider that is down still counts (its error shows on the question).
	 *
	 * Before WordPress registers its connectors (`init`, priority 15) nothing is known yet, so
	 * this answers true: ElasticPress sets up its features earlier (`init`, priority 0), and Chat
	 * must not lose its screens on every request. The features screen and later checks see the
	 * real answer.
	 *
	 * @return bool
	 */
	public static function is_ai_available(): bool {
		$available = false;

		if ( function_exists( 'wp_get_connectors' ) && ! did_action( 'wp_connectors_init' ) ) {
			$available = true;
		} elseif (
			function_exists( 'wp_ai_client_prompt' )
			&& function_exists( 'wp_get_connectors' )
			&& ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() )
		) {
			foreach ( wp_get_connectors() as $connector ) {
				if ( 'ai_provider' === ( $connector['type'] ?? '' ) && self::is_connector_set_up( $connector ) ) {
					$available = true;
					break;
				}
			}
		}

		/**
		 * Filters whether the site has an AI provider Chat can use.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_ai_available
		 * @param {bool} $available Whether an active AI provider has its credentials.
		 * @return {bool} Filtered value.
		 */
		return (bool) apply_filters( 'ep_chat_ai_available', $available );
	}

	/**
	 * Whether an AI provider connector is active and has its credentials.
	 *
	 * @param array $connector Connector, from wp_get_connectors().
	 * @return bool
	 */
	protected static function is_connector_set_up( array $connector ): bool {
		$is_active = $connector['plugin']['is_active'] ?? null;

		if ( is_callable( $is_active ) && ! call_user_func( $is_active ) ) {
			return false;
		}

		$auth = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : [];

		switch ( $auth['method'] ?? 'none' ) {
			case 'api_key':
				$env      = (string) ( $auth['env_var_name'] ?? '' );
				$constant = (string) ( $auth['constant_name'] ?? '' );

				return ( '' !== $env && '' !== (string) getenv( $env ) )
					|| ( '' !== $constant && defined( $constant ) && '' !== (string) constant( $constant ) )
					|| '' !== (string) get_option( (string) ( $auth['setting_name'] ?? '' ), '' );
			case 'application_password':
				$stored = get_option( (string) ( $auth['setting_name'] ?? '' ), [] );

				return is_array( $stored ) && '' !== (string) ( $stored['password'] ?? '' );
			default:
				// A provider that needs no credentials (a local server, for example).
				return true;
		}
	}

	/**
	 * Drop the cached list, so the next call asks the providers again.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$list = null;
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Options for a select field: an empty option first, then every model.
	 *
	 * @param string $empty_label Label of the empty option.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_options( string $empty_label ): array {
		$options = [
			[
				'value' => '',
				'label' => $empty_label,
			],
		];

		foreach ( self::get_list()['models'] as $model ) {
			$options[] = [
				'value' => $model['value'],
				'label' => $model['label'],
			];
		}

		return $options;
	}

	/**
	 * Whether a stored value can be saved: empty, a listed model, or a model of a provider whose
	 * list could not be loaded (it may still be there).
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		if ( '' === $value ) {
			return true;
		}

		$pair = self::parse( $value );

		if ( ! $pair ) {
			return false;
		}

		$list = self::get_list();

		foreach ( $list['models'] as $model ) {
			if ( $model['value'] === $value ) {
				return true;
			}
		}

		return isset( $list['errors'][ $pair[0] ] );
	}

	/**
	 * Label of a stored value, or the value itself when it is not listed.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function get_label( string $value ): string {
		foreach ( self::get_list()['models'] as $model ) {
			if ( $model['value'] === $value ) {
				return $model['label'];
			}
		}

		return $value;
	}

	/**
	 * A stored value as the AI client's model preference: `[ provider, model ]`.
	 *
	 * @param string $value Stored value.
	 * @return string[] Empty when the value is empty or malformed.
	 */
	public static function parse( string $value ): array {
		$parts = explode( self::SEPARATOR, $value, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return [];
		}

		return [ $parts[0], $parts[1] ];
	}

	/**
	 * Generate text with the WordPress AI client.
	 *
	 * @since 2.6.0
	 * @param string $prompt  User prompt.
	 * @param array  $options {
	 *     Optional.
	 *
	 *     @type string     $system        System instruction.
	 *     @type array      $history       Prior messages (AI client Message objects).
	 *     @type array      $models        Model preferences, most preferred first (see parse());
	 *                                     empty uses the client's default model.
	 *     @type string     $purpose       What the call is for (answer, intent, query, clarifier,
	 *                                     verifier, summary), passed to ep_chat_model_temperature.
	 *     @type float|null $temperature   Temperature to send. Default: none, so the model uses its
	 *                                     own (see ep_chat_model_temperature).
	 *     @type int|null   $max_tokens    Most tokens the model may use.
	 *     @type int|null   $timeout       Seconds the request can take.
	 *     @type array|null $json_schema   JSON schema the reply must follow.
	 *     @type string     $error_code    Error code when the client throws.
	 *     @type string     $error_message Error message when the client throws. Default: the
	 *                                     exception's.
	 * }
	 * @return array{text: string, model: string}|\WP_Error The reply and the model that wrote it.
	 */
	public static function generate( string $prompt, array $options = [] ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error( 'ep_chat_ai_unavailable', esc_html__( 'WordPress AI is not available.', 'elasticpress-labs' ) );
		}

		try {
			$builder = wp_ai_client_prompt( $prompt );

			if ( '' !== (string) ( $options['system'] ?? '' ) ) {
				$builder = $builder->using_system_instruction( (string) $options['system'] );
			}

			if ( ! empty( $options['history'] ) ) {
				$builder = $builder->with_history( ...array_values( $options['history'] ) );
			}

			$models = array_values( array_filter( (array) ( $options['models'] ?? [] ) ) );

			/**
			 * Filters the temperature of a Chat model call. Null (the default) sends none, so the
			 * model uses its own default: many recent models (Claude 5, OpenAI reasoning models)
			 * reject any other value, and others (Gemini 3) are tuned for their default.
			 *
			 * @since 2.6.0
			 * @hook ep_chat_model_temperature
			 * @param {float|null} $temperature Temperature, or null to send none.
			 * @param {string}     $purpose     What the call is for: answer, intent, query,
			 *                                  clarifier, verifier, summary (empty when not given).
			 * @param {array}      $models      Model preferences, most preferred first (see
			 *                                  Models::parse()); empty for the client's default.
			 * @return {float|null} Temperature, or null to send none.
			 */
			$temperature = apply_filters( 'ep_chat_model_temperature', $options['temperature'] ?? null, (string) ( $options['purpose'] ?? '' ), $models );

			if ( is_numeric( $temperature ) ) {
				$builder = $builder->using_temperature( (float) $temperature );
			}

			if ( isset( $options['max_tokens'] ) ) {
				$builder = $builder->using_max_tokens( (int) $options['max_tokens'] );
			}

			if ( isset( $options['timeout'] ) ) {
				$builder = $builder->using_request_options( RequestOptions::fromArray( [ RequestOptions::KEY_TIMEOUT => (int) $options['timeout'] ] ) );
			}

			if ( isset( $options['json_schema'] ) ) {
				$builder = $builder->as_json_response( $options['json_schema'] );
			}

			// The client answers with its default model when no preferred one is offered.
			if ( $models ) {
				$builder = $builder->using_model_preference( ...$models );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $exception ) {
			return new \WP_Error(
				(string) ( $options['error_code'] ?? 'ep_chat_ai_failed' ),
				(string) ( $options['error_message'] ?? $exception->getMessage() )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'text'  => (string) $result->toText(),
			'model' => self::from_result( $result ),
		];
	}

	/**
	 * The model that produced an AI client result, as a stored value.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result Result.
	 * @return string `provider::model`.
	 */
	public static function from_result( $result ): string {
		return self::to_value( $result->getProviderMetadata()->getId(), $result->getModelMetadata()->getId() );
	}

	/**
	 * Stored value for a provider and model.
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return string
	 */
	public static function to_value( string $provider, string $model ): string {
		return $provider . self::SEPARATOR . $model;
	}

	/**
	 * Ask the AI client's registry for each configured provider's text models.
	 *
	 * @return array
	 */
	protected static function load_list(): array {
		$list = [
			'models' => [],
			'errors' => [],
		];

		try {
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
				[ \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ],
				[]
			);
			$provider_ids = $registry->getRegisteredProviderIds();
		} catch ( \Throwable $e ) {
			return $list;
		}

		foreach ( $provider_ids as $provider_id ) {
			try {
				if ( ! $registry->isProviderConfigured( $provider_id ) ) {
					continue;
				}

				$class = $registry->getProviderClassName( $provider_id );
				$name  = $class::metadata()->getName();

				foreach ( $registry->findProviderModelsMetadataForSupport( $provider_id, $requirements ) as $model ) {
					$list['models'][] = [
						'value'    => self::to_value( $provider_id, $model->getId() ),
						/* translators: 1: AI provider name, 2: model ID */
						'label'    => sprintf( __( '%1$s – %2$s', 'elasticpress-labs' ), $name, $model->getId() ),
						'provider' => $provider_id,
						'model'    => $model->getId(),
					];
				}
			} catch ( \Throwable $e ) {
				$list['errors'][ $provider_id ] = $e->getMessage();
			}
		}

		return $list;
	}
}
