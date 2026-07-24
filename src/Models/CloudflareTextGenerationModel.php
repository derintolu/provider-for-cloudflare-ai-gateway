<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput -- No HTML output in this file; exception messages are not rendered.

namespace ProviderForCloudflareAiGateway\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use ProviderForCloudflareAiGateway\Gateway\GatewayClient;
use ProviderForCloudflareAiGateway\Provider\CloudflareAiGatewayProvider;

/**
 * Text generation model backed by Cloudflare Workers AI, routed through AI Gateway.
 *
 * Sends POST requests to:
 *   https://gateway.ai.cloudflare.com/v1/{ACCOUNT_ID}/{GATEWAY_ID}/workers-ai/run/{MODEL_ID}
 *
 * The request/response shape at this route is identical to the direct Workers AI
 * API — see Provider\CloudflareAiGatewayProvider::baseUrl().
 *
 * Request body format:
 * {
 *   "messages": [
 *     {"role": "system", "content": "..."},
 *     {"role": "user",   "content": "..."}
 *   ],
 *   "max_tokens": 2048,
 *   "temperature": 0.7
 * }
 *
 * Response format — confirmed live against the actual gateway route (2026-07),
 * and confirmed to NOT be uniform across models. Routing through AI Gateway
 * unwraps Cloudflare's usual `{result, success, errors, messages}` envelope,
 * but the shape of what's left depends on the model's underlying serving
 * stack — there are two confirmed shapes:
 *
 * Native Workers AI models (e.g. llama-4-scout) — flat:
 * {
 *   "response": "...",
 *   "tool_calls": [],
 *   "usage": { "prompt_tokens": N, "completion_tokens": N, "total_tokens": N }
 * }
 *
 * OpenAI-compatible models (e.g. gpt-oss-120b) — chat-completions shape:
 * {
 *   "choices": [ { "message": { "content": "...", "role": "assistant" }, ... } ],
 *   "usage": { "prompt_tokens": N, "completion_tokens": N, "total_tokens": N }
 * }
 *
 * parseResponseToGenerativeAiResult() checks for `choices` first, then falls
 * back to the flat `response` field, so both are handled.
 *
 * @since 0.1.0
 *
 * @phpstan-type UsageData array{
 *     prompt_tokens?: int,
 *     completion_tokens?: int,
 *     total_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     response?: string,
 *     choices?: list<array{message?: array{content?: string}}>,
 *     usage?: UsageData
 * }
 */
class CloudflareTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * {@inheritDoc}
	 *
	 * Every credential path (our Credentials page, wp-config.php constants, or a
	 * key entered directly on WordPress core's Connectors screen) can leave a
	 * site with valid Account ID + API token but no gateway provisioned yet —
	 * ensuring one here, right before it's actually needed, is what makes all of
	 * those paths work regardless of which one supplied the credentials.
	 *
	 * @since 0.1.0
	 *
	 * @throws ResponseException If a gateway is not configured and cannot be provisioned.
	 */
	final public function generateTextResult( array $prompt ): GenerativeAiResult {
		GatewayClient::ensureGatewayOrThrow( $this->providerMetadata() );

		$params = $this->prepareGenerateTextParams( $prompt );

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
	 * Prepares request parameters from the prompt messages and model configuration.
	 *
	 * @since 0.1.0
	 *
	 * @param list<Message> $prompt
	 * @return array<string, mixed>
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$config   = $this->getConfig();
		$messages = $this->prepareMessagesParam( $prompt );

		$systemInstruction = $config->getSystemInstruction();
		if ( $systemInstruction ) {
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => $systemInstruction,
				)
			);
		}

		$params = array( 'messages' => $messages );

		$maxTokens = $config->getMaxTokens();
		if ( $maxTokens !== null ) {
			$params['max_tokens'] = $maxTokens;
		}

		$temperature = $config->getTemperature();
		if ( $temperature !== null ) {
			$params['temperature'] = $temperature;
		}

		$topP = $config->getTopP();
		if ( $topP !== null ) {
			$params['top_p'] = $topP;
		}

		$customOptions = $config->getCustomOptions();
		foreach ( $customOptions as $key => $value ) {
			if ( isset( $params[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'The custom option "%s" conflicts with an existing parameter.', $key )
				);
			}
			$params[ $key ] = $value;
		}

		return $params;
	}

	/**
	 * Converts the prompt messages to the Cloudflare Workers AI messages array format.
	 *
	 * Text-only messages keep the original flat-string `content` shape. A message
	 * that also carries an image part (vision models only — see
	 * Metadata\CloudflareModelMetadataDirectory::VISION_MODEL_IDS) switches to the
	 * OpenAI-style content-parts array Cloudflare documents for image input:
	 * `content: [{type:"text", text}, {type:"image_url", image_url:{url}}]`.
	 *
	 * @since 0.1.0
	 *
	 * @param list<Message> $messages
	 * @return list<array{role: string, content: string|list<array<string, mixed>>}>
	 */
	protected function prepareMessagesParam( array $messages ): array {
		$result = array();
		foreach ( $messages as $message ) {
			$content = $this->hasImagePart( $message )
				? $this->buildContentParts( $message )
				: $this->extractTextContent( $message );

			if ( $content === '' || $content === array() ) {
				continue;
			}

			$result[] = array(
				'role'    => $this->getMessageRoleString( $message->getRole() ),
				'content' => $content,
			);
		}
		return $result;
	}

	/**
	 * Whether any part of the message is an image file.
	 *
	 * @since 0.3.0
	 *
	 * @param Message $message
	 * @return bool
	 */
	protected function hasImagePart( Message $message ): bool {
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFile() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Concatenates all text parts of a message into a single string.
	 *
	 * @since 0.1.0
	 *
	 * @param Message $message
	 * @return string
	 */
	protected function extractTextContent( Message $message ): string {
		$text = '';
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isText() ) {
				$text .= $part->getText();
			}
		}
		return $text;
	}

	/**
	 * Builds an OpenAI-style content-parts array (text + image_url entries) for
	 * a message that includes at least one image, in the shape Cloudflare's
	 * vision-capable Workers AI models document for image input.
	 *
	 * @since 0.3.0
	 *
	 * @param Message $message
	 * @return list<array<string, mixed>>
	 */
	protected function buildContentParts( Message $message ): array {
		$parts = array();
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isText() ) {
				$text = $part->getText();
				if ( $text !== '' ) {
					$parts[] = array(
						'type' => 'text',
						'text' => $text,
					);
				}
				continue;
			}

			if ( ! $part->getType()->isFile() ) {
				continue;
			}

			$file = $part->getFile();
			if ( $file === null || ! $file->isImage() ) {
				continue;
			}

			$url = $file->getDataUri() ?? $file->getUrl();
			if ( $url === null ) {
				continue;
			}

			$parts[] = array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => $url ),
			);
		}
		return $parts;
	}

	/**
	 * Returns the Cloudflare Workers AI role string for the given message role.
	 *
	 * @since 0.1.0
	 *
	 * @param MessageRoleEnum $role
	 * @return string
	 */
	protected function getMessageRoleString( MessageRoleEnum $role ): string {
		if ( $role === MessageRoleEnum::model() ) {
			return 'assistant';
		}
		return 'user';
	}

	/**
	 * Parses the Cloudflare Workers AI HTTP response into a GenerativeAiResult.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response
	 * @return GenerativeAiResult
	 * @throws ResponseException If the response structure is invalid or indicates failure.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		/** @var ResponseData $responseData */
		$responseData = $response->getData();

		if ( ! is_array( $responseData ) ) {
			throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'response body' );
		}

		$text = $responseData['choices'][0]['message']['content'] ?? $responseData['response'] ?? null;
		if ( ! is_string( $text ) ) {
			throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'response' );
		}

		$part       = new MessagePart( $text );
		$message    = new Message( MessageRoleEnum::model(), array( $part ) );
		$candidates = array( new Candidate( $message, FinishReasonEnum::stop() ) );

		if ( isset( $responseData['usage'] ) && is_array( $responseData['usage'] ) ) {
			$usage        = $responseData['usage'];
			$inputTokens  = (int) ( $usage['prompt_tokens'] ?? 0 );
			$outputTokens = (int) ( $usage['completion_tokens'] ?? 0 );
			$totalTokens  = (int) ( $usage['total_tokens'] ?? ( $inputTokens + $outputTokens ) );
			$tokenUsage   = new TokenUsage( $inputTokens, $outputTokens, $totalTokens );
		} else {
			$tokenUsage = new TokenUsage( 0, 0, 0 );
		}

		return new GenerativeAiResult(
			'',
			$candidates,
			$tokenUsage,
			$this->providerMetadata(),
			$this->metadata(),
			$responseData
		);
	}
}
