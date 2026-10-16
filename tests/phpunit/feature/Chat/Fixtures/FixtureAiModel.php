<?php
/**
 * A fake text model for FixtureAiProvider.
 *
 * @since 2.6.0
 * @package ElasticPressLabsTest
 */

namespace ElasticPressLabsTest;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * A fake text model.
 */
class FixtureAiModel implements ModelInterface, TextGenerationModelInterface {
	/**
	 * Model metadata.
	 *
	 * @var ModelMetadata
	 */
	protected $model;

	/**
	 * Provider metadata.
	 *
	 * @var ProviderMetadata
	 */
	protected $provider;

	/**
	 * Config.
	 *
	 * @var ModelConfig
	 */
	protected $config;

	/**
	 * Create the model.
	 *
	 * @param ModelMetadata    $model    Model metadata.
	 * @param ProviderMetadata $provider Provider metadata.
	 */
	public function __construct( ModelMetadata $model, ProviderMetadata $provider ) {
		$this->model    = $model;
		$this->provider = $provider;
		$this->config   = new ModelConfig();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ModelMetadata
	 */
	public function metadata(): ModelMetadata {
		return $this->model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ProviderMetadata
	 */
	public function providerMetadata(): ProviderMetadata { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->provider;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ModelConfig $config Config.
	 * @return void
	 */
	public function setConfig( ModelConfig $config ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$this->config = $config;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return ModelConfig
	 */
	public function getConfig(): ModelConfig { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->config;
	}

	/**
	 * Reply with the fixed text.
	 *
	 * @param array $prompt Messages.
	 * @return GenerativeAiResult
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$id   = $this->model->getId();
		$text = FixtureAiProvider::$replies[ $id ] ?? ( '' !== FixtureAiProvider::$reply ? FixtureAiProvider::$reply : 'Reply from ' . $id );

		return new GenerativeAiResult(
			'fixture-result',
			[ new Candidate( new ModelMessage( [ new MessagePart( $text ) ] ), FinishReasonEnum::stop() ) ],
			new TokenUsage( 1, 1, 2 ),
			$this->provider,
			$this->model
		);
	}
}
