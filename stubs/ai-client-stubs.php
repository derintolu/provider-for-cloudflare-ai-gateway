<?php

/**
 * PHPStan-only stub declarations for the `WordPress\AiClient` SDK.
 *
 * This SDK ships inside WordPress core itself (7.0+), not as a Composer
 * package, so no official stubs exist for static analysis. These skeletons
 * cover only the surface this plugin actually calls, with signatures taken
 * directly from the original aipcf-ai-provider-for-cloudflare source (which
 * this plugin forks) and its class-doc comments — not executed at runtime,
 * loaded only via phpstan.neon's `scanFiles`.
 *
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */

declare(strict_types=1);

namespace WordPress\AiClient {

    class AiClient
    {
        public static function defaultRegistry(): Registry\ProviderRegistry
        {
        }

        public static function prompt(string $prompt): Registry\PromptBuilder
        {
        }
    }
}

namespace WordPress\AiClient\Registry {

    use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
    use WordPress\AiClient\Results\DTO\GenerativeAiResult;

    class ProviderRegistry
    {
        /** @param class-string $providerClass */
        public function hasProvider(string $providerClass): bool
        {
        }

        /** @param class-string $providerClass */
        public function registerProvider(string $providerClass): void
        {
        }

        /** @param class-string $providerClass */
        public function setProviderRequestAuthentication(
            string $providerClass,
            RequestAuthenticationInterface $authentication
        ): void {
        }

        /** @param class-string $providerClass */
        public function getProviderRequestAuthentication(string $providerClass): ?RequestAuthenticationInterface
        {
        }
    }

    class PromptBuilder
    {
        public function usingProvider(string $providerId): self
        {
        }

        public function usingModel(string $modelId): self
        {
        }

        public function generateTextResult(): GenerativeAiResult
        {
        }
    }
}

namespace WordPress\AiClient\Common\Exception {

    class RuntimeException extends \RuntimeException
    {
    }

    class InvalidArgumentException extends \InvalidArgumentException
    {
    }
}

namespace WordPress\AiClient\Messages\Enums {

    class MessageRoleEnum
    {
        public static function user(): self
        {
        }

        public static function model(): self
        {
        }

        public static function system(): self
        {
        }
    }

    class ModalityEnum
    {
        public static function text(): self
        {
        }

        public static function image(): self
        {
        }

        public static function audio(): self
        {
        }
    }

    class MessagePartTypeEnum
    {
        public function isText(): bool
        {
        }

        public function isFile(): bool
        {
        }
    }
}

namespace WordPress\AiClient\Files\DTO {

    /**
     * Represents a file (typically an image) attached to or returned from a message.
     *
     * $file is auto-detected as one of: an http(s) URL, a `data:...;base64,...` URI,
     * plain base64 (requires $mimeType), or a local filesystem path.
     */
    class File
    {
        public function __construct(string $file, ?string $mimeType = null)
        {
        }

        public function isRemote(): bool
        {
        }

        public function isInline(): bool
        {
        }

        public function getUrl(): ?string
        {
        }

        public function getBase64Data(): ?string
        {
        }

        public function getDataUri(): ?string
        {
        }

        public function getMimeType(): string
        {
        }

        public function isImage(): bool
        {
        }
    }
}

namespace WordPress\AiClient\Messages\DTO {

    use WordPress\AiClient\Files\DTO\File;
    use WordPress\AiClient\Messages\Enums\MessagePartTypeEnum;
    use WordPress\AiClient\Messages\Enums\MessageRoleEnum;

    class MessagePart
    {
        public function __construct(string|File $content)
        {
        }

        public function getType(): MessagePartTypeEnum
        {
        }

        public function getText(): string
        {
        }

        public function getFile(): ?File
        {
        }
    }

    class Message
    {
        /** @param list<MessagePart> $parts */
        public function __construct(MessageRoleEnum $role, array $parts)
        {
        }

        public function getRole(): MessageRoleEnum
        {
        }

        /** @return list<MessagePart> */
        public function getParts(): array
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Enums {

    class ProviderTypeEnum
    {
        public static function cloud(): self
        {
        }

        public static function local(): self
        {
        }
    }
}

namespace WordPress\AiClient\Providers\DTO {

    use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
    use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;

    class ProviderMetadata
    {
        public function __construct(
            string $id,
            string $name,
            ProviderTypeEnum $type,
            string $url,
            RequestAuthenticationMethod $authenticationMethod,
            string $description,
            string $iconPath
        ) {
        }

        public function getId(): string
        {
        }

        public function getName(): string
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Contracts {

    interface ModelMetadataDirectoryInterface
    {
    }

    interface ProviderAvailabilityInterface
    {
    }
}

namespace WordPress\AiClient\Providers\Models\Enums {

    class CapabilityEnum
    {
        public static function textGeneration(): self
        {
        }

        public static function imageGeneration(): self
        {
        }

        public static function chatHistory(): self
        {
        }

        public function isTextGeneration(): bool
        {
        }

        public function isImageGeneration(): bool
        {
        }
    }

    class OptionEnum
    {
        public static function systemInstruction(): self
        {
        }

        public static function maxTokens(): self
        {
        }

        public static function temperature(): self
        {
        }

        public static function topP(): self
        {
        }

        public static function outputMimeType(): self
        {
        }

        public static function customOptions(): self
        {
        }

        public static function inputModalities(): self
        {
        }

        public static function outputModalities(): self
        {
        }

        public static function outputFileType(): self
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Models\DTO {

    use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
    use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

    class SupportedOption
    {
        /** @param list<mixed> $allowedValues */
        public function __construct(OptionEnum $option, array $allowedValues = [])
        {
        }
    }

    class ModelMetadata
    {
        /**
         * @param list<CapabilityEnum>  $capabilities
         * @param list<SupportedOption> $options
         */
        public function __construct(string $id, string $name, array $capabilities, array $options)
        {
        }

        public function getId(): string
        {
        }

        public function getName(): string
        {
        }

        /** @return list<CapabilityEnum> */
        public function getSupportedCapabilities(): array
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Models\Contracts {

    interface ModelInterface
    {
    }
}

namespace WordPress\AiClient\Providers\Models\TextGeneration\Contracts {

    use WordPress\AiClient\Messages\DTO\Message;
    use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
    use WordPress\AiClient\Results\DTO\GenerativeAiResult;

    interface TextGenerationModelInterface extends ModelInterface
    {
        /** @param list<Message> $prompt */
        public function generateTextResult(array $prompt): GenerativeAiResult;
    }
}

namespace WordPress\AiClient\Providers\Models\ImageGeneration\Contracts {

    use WordPress\AiClient\Messages\DTO\Message;
    use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
    use WordPress\AiClient\Results\DTO\GenerativeAiResult;

    interface ImageGenerationModelInterface extends ModelInterface
    {
        /** @param list<Message> $prompt */
        public function generateImageResult(array $prompt): GenerativeAiResult;
    }
}

namespace WordPress\AiClient\Providers\Models\TextGeneration\DTO {

    class GenerationConfig
    {
        public function getSystemInstruction(): ?string
        {
        }

        public function getMaxTokens(): ?int
        {
        }

        public function getTemperature(): ?float
        {
        }

        public function getTopP(): ?float
        {
        }

        /** @return array<string, mixed> */
        public function getCustomOptions(): array
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Http\Enums {

    class HttpMethodEnum
    {
        public static function GET(): self
        {
        }

        public static function POST(): self
        {
        }
    }

    class RequestAuthenticationMethod
    {
        public static function apiKey(): self
        {
        }

        public static function none(): self
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Http\Contracts {

    use WordPress\AiClient\Providers\Http\DTO\Request;

    interface RequestAuthenticationInterface
    {
        public function authenticateRequest(Request $request): Request;
    }

    interface HttpTransporterInterface
    {
        public function send(Request $request): \WordPress\AiClient\Providers\Http\DTO\Response;
    }
}

namespace WordPress\AiClient\Providers\Http\DTO {

    use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
    use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

    class Request
    {
        /**
         * @param array<string, string> $headers
         * @param array<string, mixed>  $params
         * @param array<string, mixed>  $options
         */
        public function __construct(
            HttpMethodEnum $method,
            string $url,
            array $headers,
            array $params,
            array $options
        ) {
        }
    }

    class Response
    {
        /** @return array<string, mixed>|null Null when the body isn't valid JSON (e.g. binary image bytes). */
        public function getData(): ?array
        {
        }

        /** Raw response body — the only way to read non-JSON payloads such as binary image bytes. */
        public function getBody(): ?string
        {
        }
    }

    class ApiKeyRequestAuthentication implements RequestAuthenticationInterface
    {
        public function __construct(string $apiKey)
        {
        }

        public function getApiKey(): string
        {
        }

        public function authenticateRequest(Request $request): Request
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Http\Exception {

    class ResponseException extends \RuntimeException
    {
        public static function fromInvalidData(string $providerName, string $field, string $message): self
        {
        }

        public static function fromMissingData(string $providerName, string $field): self
        {
        }
    }
}

namespace WordPress\AiClient\Providers\Http\Util {

    use WordPress\AiClient\Providers\Http\DTO\Response;

    class ResponseUtil
    {
        public static function throwIfNotSuccessful(Response $response): void
        {
        }
    }
}

namespace WordPress\AiClient\Providers\ApiBasedImplementation {

    use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
    use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
    use WordPress\AiClient\Providers\DTO\ProviderMetadata;
    use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
    use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
    use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
    use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

    abstract class AbstractApiProvider
    {
        abstract protected static function baseUrl(): string;

        abstract protected static function createModel(
            ModelMetadata $modelMetadata,
            ProviderMetadata $providerMetadata
        ): ModelInterface;

        abstract protected static function createProviderMetadata(): ProviderMetadata;

        abstract protected static function createProviderAvailability(): ProviderAvailabilityInterface;

        abstract protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface;

        public static function url(string $modelId): string
        {
        }

        public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface
        {
        }
    }

    abstract class AbstractApiBasedModel
    {
        public function __construct(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata)
        {
        }

        protected function metadata(): ModelMetadata
        {
        }

        protected function providerMetadata(): ProviderMetadata
        {
        }

        protected function getConfig(): \WordPress\AiClient\Providers\Models\TextGeneration\DTO\GenerationConfig
        {
        }

        /** @return array<string, mixed> */
        protected function getRequestOptions(): array
        {
        }

        protected function getRequestAuthentication(): RequestAuthenticationInterface
        {
        }

        protected function getHttpTransporter(): HttpTransporterInterface
        {
        }
    }

    abstract class AbstractApiBasedModelMetadataDirectory implements ModelMetadataDirectoryInterface
    {
        /** @return array<string, ModelMetadata> */
        abstract protected function sendListModelsRequest(): array;
    }

    class ListModelsApiBasedProviderAvailability implements ProviderAvailabilityInterface
    {
        public function __construct(ModelMetadataDirectoryInterface $directory)
        {
        }
    }
}

namespace WordPress\AiClient\Results\Enums {

    class FinishReasonEnum
    {
        public static function stop(): self
        {
        }

        public static function length(): self
        {
        }
    }
}

namespace WordPress\AiClient\Results\DTO {

    use WordPress\AiClient\Messages\DTO\Message;
    use WordPress\AiClient\Providers\DTO\ProviderMetadata;
    use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
    use WordPress\AiClient\Results\Enums\FinishReasonEnum;

    class TokenUsage
    {
        public function __construct(int $inputTokens, int $outputTokens, int $totalTokens)
        {
        }
    }

    class Candidate
    {
        public function __construct(Message $message, FinishReasonEnum $finishReason)
        {
        }
    }

    class GenerativeAiResult
    {
        /**
         * @param list<Candidate>      $candidates
         * @param array<string, mixed> $additionalData
         */
        public function __construct(
            string $id,
            array $candidates,
            TokenUsage $usage,
            ProviderMetadata $providerMetadata,
            ModelMetadata $modelMetadata,
            array $additionalData
        ) {
        }

        public function toText(): string
        {
        }

        public function getProviderMetadata(): ProviderMetadata
        {
        }

        public function getModelMetadata(): ModelMetadata
        {
        }
    }
}
