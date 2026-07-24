<?php

declare(strict_types=1);

namespace ProviderForCloudflareAiGateway\Models;

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use ProviderForCloudflareAiGateway\Gateway\GatewayClient;
use ProviderForCloudflareAiGateway\Provider\CloudflareAiGatewayProvider;

/**
 * Image generation model backed by Cloudflare Workers AI, routed through AI Gateway.
 *
 * Cloudflare's text-to-image models don't share one response format: some
 * (Black Forest Labs' FLUX family, Leonardo's lucid-origin) return a JSON
 * envelope `{"image": "<base64>"}`, others (Stability AI SDXL, ByteDance
 * SDXL-Lightning, lykon dreamshaper, RunwayML SD 1.5, Leonardo phoenix-1.0)
 * return the raw image bytes directly with an `image/png` or `image/jpeg`
 * Content-Type. Every model this class is registered for must be listed in
 * exactly one of the two branches below.
 *
 * @since 0.3.0
 */
class CloudflareImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface {

	/**
	 * Models whose response body is a JSON object `{"image": "<base64>"}`,
	 * mapped to the actual MIME type of the decoded bytes — confirmed live
	 * per model, since Cloudflare doesn't document this and it isn't uniform
	 * (flux-1-schnell's "image" field is base64 JPEG, not PNG, despite every
	 * other Workers AI image model returning PNG). Any Workers AI image model
	 * not listed here is assumed to return raw binary image bytes instead
	 * (see parseResponseToGenerativeAiResult()).
	 *
	 * @since 0.3.0
	 */
	private const JSON_BASE64_MODEL_IDS = array(
		'@cf/black-forest-labs/flux-1-schnell' => 'image/jpeg',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 *
	 * @throws ResponseException If a gateway is not configured and cannot be provisioned,
	 *                           or the response doesn't match this model's expected shape.
	 */
	final public function generateImageResult( array $prompt ): GenerativeAiResult {
		GatewayClient::ensureGatewayOrThrow( $this->providerMetadata() );

		$params = $this->prepareGenerateImageParams( $prompt );

		$request = new Request(
			HttpMethodEnum::POST(),
			CloudflareAiGatewayProvider::url( $this->metadata()->getId() ),
			array( 'Content-Type' => 'application/json' ),
			$params,
			$this->getRequestOptions()
		);

		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		$response = $this->getHttpTransporter()->send( $request );
		ResponseUtil::throwIfNotSuccessful( $response );
		return $this->parseResponseToGenerativeAiResult( $response );
	}

	/**
	 * Builds the request body. Cloudflare's image models don't share one
	 * parameter shape either (`steps` vs `num_steps`, differing defaults) —
	 * this only needs to cover the models actually listed in this plugin's
	 * catalog; unrecognised model IDs get a plain `{prompt}` body.
	 *
	 * @since 0.3.0
	 *
	 * @param list<Message> $prompt
	 * @return array<string, mixed>
	 */
	protected function prepareGenerateImageParams( array $prompt ): array {
		$promptText = $this->extractPromptText( $prompt );
		$config     = $this->getConfig();

		if ( $this->metadata()->getId() === '@cf/black-forest-labs/flux-1-schnell' ) {
			return array(
				'prompt' => $promptText,
				'steps'  => 4,
			);
		}

		// Stable-Diffusion-family request shape (SDXL, SDXL-Lightning, dreamshaper).
		$params = array( 'prompt' => $promptText );

		$negativePrompt = $config->getCustomOptions()['negative_prompt'] ?? null;
		if ( is_string( $negativePrompt ) && $negativePrompt !== '' ) {
			$params['negative_prompt'] = $negativePrompt;
		}

		return $params;
	}

	/**
	 * Concatenates the text parts of every message into a single prompt string.
	 *
	 * @since 0.3.0
	 *
	 * @param list<Message> $prompt
	 * @return string
	 */
	protected function extractPromptText( array $prompt ): string {
		$text = '';
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isText() ) {
					$text .= $part->getText();
				}
			}
		}
		return $text;
	}

	/**
	 * Parses the Cloudflare Workers AI HTTP response into a GenerativeAiResult
	 * containing the generated image, branching on this model's known response
	 * shape (see JSON_BASE64_MODEL_IDS).
	 *
	 * @since 0.3.0
	 *
	 * @param Response $response
	 * @return GenerativeAiResult
	 * @throws ResponseException If the response doesn't contain image data.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$jsonBase64MimeType = self::JSON_BASE64_MODEL_IDS[ $this->metadata()->getId() ] ?? null;

		if ( $jsonBase64MimeType !== null ) {
			$data = $response->getData();
			if ( ! is_array( $data ) || ! isset( $data['image'] ) || ! is_string( $data['image'] ) ) {
				throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$imageFile = new File( $data['image'], $jsonBase64MimeType );
		} else {
			$bytes = $response->getBody();
			if ( $bytes === null || $bytes === '' ) {
				throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'body' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$imageFile = new File( base64_encode( $bytes ), 'image/png' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding raw image bytes for the AI Client File DTO, not obfuscation.
		}

		$part      = new MessagePart( $imageFile );
		$message   = new Message( MessageRoleEnum::model(), array( $part ) );
		$candidate = new Candidate( $message, FinishReasonEnum::stop() );

		return new GenerativeAiResult(
			'',
			array( $candidate ),
			new TokenUsage( 0, 0, 0 ),
			$this->providerMetadata(),
			$this->metadata(),
			array()
		);
	}
}
