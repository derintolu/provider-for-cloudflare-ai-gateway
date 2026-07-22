<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiGateway\Metadata;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\CloudflareAiGateway\Provider\CloudflareAiGatewayProvider;

/**
 * Provides Cloudflare Workers AI models to the WordPress AI Client — text
 * generation (including vision-capable models) and a small curated set of
 * image generation models, matching the AiClient model interfaces WordPress
 * core actually exposes today. The plugin's own Model Catalog admin page (a
 * later milestone) surfaces every model across every modality (embeddings,
 * ASR/TTS, video, etc.), not just this slice.
 *
 * Attempts a live fetch from the Cloudflare models API (cached for 12 hours)
 * for the text-generation list. Falls back to a built-in static catalog when
 * the API is unreachable. Image generation models are always the small,
 * hand-verified set in IMAGE_MODEL_IDS — see CloudflareImageGenerationModel
 * for why: Cloudflare's image models don't share a single request/response
 * shape, so only explicitly-supported ones are safe to advertise.
 *
 * @since 0.1.0
 */
class CloudflareModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	/**
	 * Cloudflare Workers AI text-generation models confirmed (as of 2026-07) to
	 * accept image input alongside text — i.e. "vision" models, badged as such
	 * on developers.cloudflare.com/workers-ai/models/. Cloudflare's model-search
	 * API exposes no machine-readable capability flag for this, so this list is
	 * maintained by hand; the Model Catalog admin page (a later milestone) is the
	 * place to verify/extend it against the live catalog.
	 *
	 * Deliberately excludes a couple of models whose Cloudflare doc *prose*
	 * claims vision support (mistral-small-3.1-24b-instruct, gemma-3-12b-it) but
	 * whose catalog page carries no Vision capability badge — safer to under- than
	 * over-claim a capability the AI plugin will actually try to use.
	 *
	 * @since 0.3.0
	 */
	private const VISION_MODEL_IDS = array(
		'@cf/meta/llama-3.2-11b-vision-instruct',
		'@cf/meta/llama-4-scout-17b-16e-instruct',
	);

	/**
	 * Cloudflare Workers AI text-to-image models this plugin knows how to call —
	 * see CloudflareImageGenerationModel::JSON_BASE64_MODEL_IDS for the response
	 * format each one actually returns. Model ID => human-readable name.
	 *
	 * @since 0.3.0
	 */
	private const IMAGE_MODEL_IDS = array(
		'@cf/black-forest-labs/flux-1-schnell'         => 'FLUX.1 [schnell]',
		'@cf/stabilityai/stable-diffusion-xl-base-1.0' => 'Stable Diffusion XL',
	);

	/**
	 * {@inheritDoc}
	 *
	 * Fetches text generation models from the Cloudflare API, falling back to a
	 * static list when the API is unavailable. Throws if credentials are missing
	 * so the provider shows as unavailable until configured.
	 *
	 * When only an API key is available (e.g. during the WordPress Connectors
	 * screen key-validation flow, before an Account ID has been saved) the token
	 * is verified via Cloudflare's /user/tokens/verify endpoint and the static
	 * model catalog is returned so the Connectors screen test can succeed.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, ModelMetadata>
	 * @throws RuntimeException If credentials are not configured or invalid.
	 */
	protected function sendListModelsRequest(): array {
		// Resolve the API key. Check the AI Client registry first so that keys
		// set via the WordPress Connectors screen are picked up, then fall back
		// to the plugin's own settings / constants / environment variables.
		$apiKey = $this->resolveApiKey();

		if ( $apiKey === '' ) {
			throw new RuntimeException(
				'Cloudflare API Token is not configured. Please add it on the Cloudflare AI Gateway Credentials page.'
			);
		}

		$accountId = function_exists( 'WordPress\\CloudflareAiGateway\\get_account_id' )
			? \WordPress\CloudflareAiGateway\get_account_id()
			: '';

		$textCapabilities = $this->buildCapabilities();
		$textOptions      = $this->buildOptions( false );

		$defaultModelId = function_exists( 'WordPress\\CloudflareAiGateway\\get_default_model_id' )
			? \WordPress\CloudflareAiGateway\get_default_model_id()
			: '@cf/meta/llama-4-scout-17b-16e-instruct';

		// No Account ID yet — validate the token alone and return the static
		// catalog. This allows the Connectors screen to confirm the key is valid
		// before the user has saved their Account ID in the plugin settings.
		if ( $accountId === '' ) {
			$this->verifyTokenOnly( $apiKey );
			return $this->indexByDefault(
				array_merge( $this->buildStaticList( $textCapabilities ), $this->buildImageModels() ),
				$defaultModelId,
				$textCapabilities,
				$textOptions
			);
		}

		// Try live model list; fall back to static catalog on failure.
		$liveItems = function_exists( 'WordPress\\CloudflareAiGateway\\fetch_available_models' )
			? \WordPress\CloudflareAiGateway\fetch_available_models()
			: array();

		if ( ! empty( $liveItems ) ) {
			$models = array();
			foreach ( $liveItems as $item ) {
				$options  = in_array( $item['id'], self::VISION_MODEL_IDS, true )
					? $this->buildOptions( true )
					: $textOptions;
				$models[] = new ModelMetadata( $item['id'], $item['name'], $textCapabilities, $options );
			}
		} else {
			$models = $this->buildStaticList( $textCapabilities );
		}

		$models = array_merge( $models, $this->buildImageModels() );

		return $this->indexByDefault( $models, $defaultModelId, $textCapabilities, $textOptions );
	}

	/**
	 * Builds ModelMetadata entries for this plugin's curated, hand-verified
	 * image generation models (see IMAGE_MODEL_IDS and
	 * Models\CloudflareImageGenerationModel).
	 *
	 * @since 0.3.0
	 *
	 * @return list<ModelMetadata>
	 */
	private function buildImageModels(): array {
		$capabilities = array( CapabilityEnum::imageGeneration() );

		$models = array();
		foreach ( self::IMAGE_MODEL_IDS as $id => $name ) {
			// Confirmed live per model — Cloudflare doesn't document this and it
			// isn't uniform (flux-1-schnell's response is JPEG, not PNG, despite
			// every other Workers AI image model returning PNG). Keep in sync
			// with Models\CloudflareImageGenerationModel::JSON_BASE64_MODEL_IDS.
			$mimeType = $id === '@cf/black-forest-labs/flux-1-schnell' ? 'image/jpeg' : 'image/png';

			$options  = array(
				new SupportedOption( OptionEnum::outputMimeType(), array( $mimeType ) ),
				new SupportedOption( OptionEnum::customOptions() ),
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
				// Required — confirmed live: without this, ModelRequirements::areMetBy()
				// rejects every image model outright whenever a caller sets an output
				// file type (e.g. the official "AI" plugin's ->as_output_file_type()),
				// since a model with no declared outputFileType option can never satisfy
				// that requirement. CloudflareImageGenerationModel only ever returns
				// inline (base64) files, never remote URLs, so any value is accurate.
				new SupportedOption( OptionEnum::outputFileType() ),
			);
			$models[] = new ModelMetadata( $id, $name, $capabilities, $options );
		}
		return $models;
	}

	/**
	 * Returns the effective API key.
	 *
	 * Checks the AI Client registry first so that keys entered on the WordPress
	 * Connectors screen are honoured, then falls back to the plugin's own
	 * get_api_key() helper (plugin settings / constant / environment variable).
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function resolveApiKey(): string {
		// Try the AI Client registry (covers the Connectors screen flow).
		if ( class_exists( AiClient::class ) ) {
			try {
				$registry = AiClient::defaultRegistry();
				if ( method_exists( $registry, 'getProviderRequestAuthentication' ) ) {
					$auth = $registry->getProviderRequestAuthentication( CloudflareAiGatewayProvider::class );
					if (
						$auth instanceof ApiKeyRequestAuthentication
						&& method_exists( $auth, 'getApiKey' )
					) {
						$key = (string) $auth->getApiKey();
						if ( $key !== '' ) {
							return $key;
						}
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Registry unavailable or method absent — fall through.
			}
		}

		// Fall back to plugin settings / constant / environment variable.
		return function_exists( 'WordPress\\CloudflareAiGateway\\get_api_key' )
			? (string) \WordPress\CloudflareAiGateway\get_api_key()
			: '';
	}

	/**
	 * Validates an API token against Cloudflare's token-verify endpoint.
	 *
	 * This endpoint requires only the Bearer token — no Account ID needed —
	 * making it suitable for the Connectors screen key-validation flow.
	 *
	 * @since 0.1.0
	 *
	 * @param string $apiKey The Cloudflare API token to verify.
	 * @return void
	 * @throws RuntimeException If the token is invalid or the request fails.
	 */
	private function verifyTokenOnly( string $apiKey ): void {
		$response = wp_remote_get(
			'https://api.cloudflare.com/client/v4/user/tokens/verify',
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException(
				sprintf( 'Invalid API token (HTTP %d).', $status ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			);
		}
	}

	/**
	 * Returns the shared text generation capabilities.
	 *
	 * @since 0.3.0
	 *
	 * @return list<CapabilityEnum>
	 */
	private function buildCapabilities(): array {
		return array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
	}

	/**
	 * Returns the supported-options list for a text generation model.
	 *
	 * When $withVision is true, the model also accepts image input alongside
	 * text — this is what the WordPress AI plugin's Alt Text Generation feature
	 * (and its `wpai_preferred_vision_models` filter) actually checks for: plain
	 * text-generation capability with `image` present in supported input
	 * modalities, not a distinct "vision" capability.
	 *
	 * @since 0.3.0
	 *
	 * @param bool $withVision
	 * @return list<SupportedOption>
	 */
	private function buildOptions( bool $withVision ): array {
		$inputModalities = $withVision
			? array( array( ModalityEnum::text() ), array( ModalityEnum::text(), ModalityEnum::image() ) )
			: array( array( ModalityEnum::text() ) );

		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain' ) ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), $inputModalities ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);
	}

	/**
	 * Builds the static fallback catalog used when the Cloudflare API is unreachable.
	 *
	 * @since 0.1.0
	 *
	 * @param list<CapabilityEnum> $capabilities
	 * @return list<ModelMetadata>
	 */
	private function buildStaticList( array $capabilities ): array {
		$textOptions   = $this->buildOptions( false );
		$visionOptions = $this->buildOptions( true );

		return array(
			new ModelMetadata(
				'@cf/meta/llama-4-scout-17b-16e-instruct',
				'Meta Llama 4 Scout 17B',
				$capabilities,
				$visionOptions
			),
			new ModelMetadata(
				'@cf/meta/llama-3.2-11b-vision-instruct',
				'Meta Llama 3.2 11B Vision Instruct',
				$capabilities,
				$visionOptions
			),
			new ModelMetadata(
				'@cf/meta/llama-3.3-70b-instruct-fp8-fast',
				'Meta Llama 3.3 70B Instruct (Fast)',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/meta/llama-3.1-8b-instruct',
				'Meta Llama 3.1 8B Instruct',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/meta/llama-3-8b-instruct',
				'Meta Llama 3 8B Instruct',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/meta/llama-3.2-3b-instruct',
				'Meta Llama 3.2 3B Instruct',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/mistral/mistral-small-3.1-24b-instruct',
				'Mistral Small 3.1 24B Instruct',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/mistral/mistral-7b-instruct-v0.1',
				'Mistral 7B Instruct',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/google/gemma-2-2b-it',
				'Google Gemma 2 2B',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/deepseek-ai/deepseek-r1-distill-qwen-32b',
				'DeepSeek R1 Distill Qwen 32B',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/qwen/qwq-32b',
				'Qwen QwQ 32B',
				$capabilities,
				$textOptions
			),
			new ModelMetadata(
				'@cf/qwen/qwen2.5-coder-32b-instruct',
				'Qwen 2.5 Coder 32B Instruct',
				$capabilities,
				$textOptions
			),
		);
	}

	/**
	 * Ensures the default model is first in the returned index.
	 *
	 * If the default model ID is not in the list (e.g. a custom model set via
	 * wp-config.php) a dynamic entry is prepended so it is still usable.
	 *
	 * @since 0.1.0
	 *
	 * @param list<ModelMetadata>   $models
	 * @param string                $defaultModelId
	 * @param list<CapabilityEnum>  $capabilities
	 * @param list<SupportedOption> $options
	 * @return array<string, ModelMetadata>
	 */
	private function indexByDefault(
		array $models,
		string $defaultModelId,
		array $capabilities,
		array $options
	): array {
		$defaultModel = null;
		$rest         = array();
		foreach ( $models as $model ) {
			if ( $defaultModel === null && $model->getId() === $defaultModelId ) {
				$defaultModel = $model;
			} else {
				$rest[] = $model;
			}
		}

		if ( $defaultModel === null ) {
			$defaultModel = new ModelMetadata( $defaultModelId, $defaultModelId, $capabilities, $options );
		}

		$index = array( $defaultModel->getId() => $defaultModel );
		foreach ( $rest as $model ) {
			$index[ $model->getId() ] = $model;
		}
		return $index;
	}
}
