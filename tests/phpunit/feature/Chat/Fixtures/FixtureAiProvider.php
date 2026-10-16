<?php
/**
 * A fake AI provider for the WordPress AI client, with two text models that echo their ID.
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Provider `fixture-ai` with the models `fixture-small` and `fixture-large`. Each model replies
 * with a fixed text, or with `FixtureAiProvider::$reply` when set, so tests can see which one
 * answered.
 */
class FixtureAiProvider extends AbstractProvider {
	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	public const ID = 'fixture-ai';

	/**
	 * Reply every model gives. Empty: "Reply from <model>".
	 *
	 * @var string
	 */
	public static $reply = '';

	/**
	 * Replies by model ID, over $reply.
	 *
	 * @var array<string, string>
	 */
	public static $replies = [];

	/**
	 * Register the provider once with the default registry.
	 *
	 * @return void
	 */
	public static function register() {
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( self::ID ) ) {
			$registry->registerProvider( self::class );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ModelMetadata    $model_metadata    Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		require_once __DIR__ . '/FixtureAiModel.php';

		return new FixtureAiModel( $model_metadata, $provider_metadata );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return new ProviderMetadata( self::ID, 'Fixture AI', ProviderTypeEnum::server() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return new class() implements ProviderAvailabilityInterface {
			/**
			 * Always configured.
			 *
			 * @return bool
			 */
			public function isConfigured(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				return true;
			}
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return new class() implements ModelMetadataDirectoryInterface {
			/**
			 * The two models.
			 *
			 * @return ModelMetadata[]
			 */
			public function listModelMetadata(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				$options = array_map(
					static function ( $option ) {
						return new SupportedOption( $option );
					},
					[
						OptionEnum::systemInstruction(),
						OptionEnum::temperature(),
						OptionEnum::maxTokens(),
						OptionEnum::inputModalities(),
						OptionEnum::outputModalities(),
						OptionEnum::outputMimeType(),
						OptionEnum::outputSchema(),
						OptionEnum::customOptions(),
					]
				);

				return [
					new ModelMetadata( 'fixture-small', 'Fixture small', [ CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ], $options ),
					new ModelMetadata( 'fixture-large', 'Fixture large', [ CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ], $options ),
				];
			}

			/**
			 * Whether a model exists.
			 *
			 * @param string $model_id Model ID.
			 * @return bool
			 */
			public function hasModelMetadata( string $model_id ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				return in_array( $model_id, [ 'fixture-small', 'fixture-large' ], true );
			}

			/**
			 * One model.
			 *
			 * @param string $model_id Model ID.
			 * @return ModelMetadata
			 * @throws \InvalidArgumentException When the model does not exist.
			 */
			public function getModelMetadata( string $model_id ): ModelMetadata { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				foreach ( $this->listModelMetadata() as $model ) {
					if ( $model->getId() === $model_id ) {
						return $model;
					}
				}

				throw new \InvalidArgumentException( 'No model ' . esc_html( $model_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		};
	}
}
