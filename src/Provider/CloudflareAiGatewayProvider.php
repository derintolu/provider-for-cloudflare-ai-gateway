<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput -- No HTML output in this file; exception messages are not rendered.

namespace WordPress\CloudflareAiGateway\Provider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\CloudflareAiGateway\Metadata\CloudflareModelMetadataDirectory;
use WordPress\CloudflareAiGateway\Models\CloudflareImageGenerationModel;
use WordPress\CloudflareAiGateway\Models\CloudflareTextGenerationModel;

/**
 * AI Provider for Cloudflare AI Gateway — text generation (including vision)
 * and a curated set of image generation models, all routed through AI Gateway.
 *
 * @since 0.1.0
 */
class CloudflareAiGatewayProvider extends AbstractApiProvider {

	/**
	 * The provider ID used to reference this provider in the AI Client SDK.
	 *
	 * @since 0.1.0
	 */
	public const ID = 'cloudflare-ai-gateway';

	/**
	 * {@inheritDoc}
	 *
	 * Routes every inference request through the configured Cloudflare AI Gateway
	 * (classic per-provider route) rather than calling Workers AI directly, so
	 * caching, rate limiting, guardrails and logging configured on the gateway
	 * apply automatically. This is the only inference path this plugin uses —
	 * there is no direct-API fallback. The request/response shape at this route
	 * is byte-for-byte identical to the direct Workers AI API, so the model and
	 * metadata-directory classes that build/parse requests need no changes.
	 *
	 * Returns an empty base ("") when Account ID or Gateway ID aren't configured
	 * yet, which resolves to an invalid URL — deliberately, since the provider is
	 * already reported unavailable in that state (see
	 * Metadata\CloudflareModelMetadataDirectory::sendListModelsRequest()), so this
	 * method is never actually called with incomplete configuration in practice.
	 *
	 * @since 0.2.0
	 */
	protected static function baseUrl(): string {
		$accountId = function_exists( 'WordPress\\CloudflareAiGateway\\get_account_id' )
			? \WordPress\CloudflareAiGateway\get_account_id()
			: '';
		$gatewayId = function_exists( 'WordPress\\CloudflareAiGateway\\get_gateway_id' )
			? \WordPress\CloudflareAiGateway\get_gateway_id()
			: '';

		if ( $accountId === '' || $gatewayId === '' ) {
			return '';
		}

		return sprintf(
			'https://gateway.ai.cloudflare.com/v1/%s/%s/workers-ai/run',
			rawurlencode( $accountId ),
			rawurlencode( $gatewayId )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createModel(
		ModelMetadata $modelMetadata,
		ProviderMetadata $providerMetadata
	): ModelInterface {
		$capabilities = $modelMetadata->getSupportedCapabilities();
		foreach ( $capabilities as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new CloudflareTextGenerationModel( $modelMetadata, $providerMetadata );
			}
			if ( $capability->isImageGeneration() ) {
				return new CloudflareImageGenerationModel( $modelMetadata, $providerMetadata );
			}
		}

		throw new RuntimeException(
			sprintf( 'Model "%s" has no supported capability.', $modelMetadata->getId() )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::ID,
			'Cloudflare AI Gateway',
			ProviderTypeEnum::cloud(),
			'https://dash.cloudflare.com/?to=/:account/ai/ai-gateway',
			RequestAuthenticationMethod::apiKey(),
			__( 'Text and image generation with Cloudflare Workers AI models, routed through AI Gateway.', 'cloudflare-ai-gateway' ),
			dirname( __DIR__, 2 ) . '/assets/images/cloudflare.svg'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Uses ListModelsApiBasedProviderAvailability backed by the static model directory.
	 * The directory throws if Account ID is not configured, making the provider appear unavailable.
	 *
	 * @since 0.1.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new CloudflareModelMetadataDirectory();
	}
}
