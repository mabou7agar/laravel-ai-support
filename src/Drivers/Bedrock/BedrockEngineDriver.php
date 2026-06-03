<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Bedrock;

use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\MissingDependencyException;

/**
 * AWS Bedrock engine driver.
 *
 * Generates text via Bedrock-hosted Anthropic Claude models using the AWS SDK's
 * BedrockRuntimeClient (converse). The AWS SDK is an OPTIONAL dependency: it is
 * only referenced behind a class_exists() guard. If aws/aws-sdk-php is not
 * installed, a clear MissingDependencyException is thrown telling the user how to
 * install it.
 *
 * The runtime client can be injected (constructor seam) to allow mocking the SDK
 * in tests without performing any real network calls.
 */
class BedrockEngineDriver extends BaseEngineDriver
{
    public const SDK_CLIENT_CLASS = '\\Aws\\BedrockRuntime\\BedrockRuntimeClient';

    public const SDK_PACKAGE = 'aws/aws-sdk-php';

    /**
     * The AWS BedrockRuntimeClient (or any object exposing converse()/invokeModel()).
     *
     * Typed as object because the AWS SDK class may not be installed; the client
     * is only ever the real SDK client or a test double providing the same seam.
     */
    private ?object $client;

    public function __construct(array $config, ?object $client = null)
    {
        parent::__construct($config);

        $this->client = $client;
    }

    public function generate(AIRequest $request): AIResponse
    {
        return match ($request->getModel()->getContentType()) {
            'text' => $this->generateText($request),
            default => throw new \InvalidArgumentException(
                "Unsupported content type: {$request->getModel()->getContentType()}"
            ),
        };
    }

    public function stream(AIRequest $request): \Generator
    {
        // Bedrock streaming (converseStream) is not implemented; emit the full
        // text result as a single chunk so callers relying on stream() still work.
        $response = $this->generateText($request);

        yield $response->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        if (trim($request->getPrompt()) === '') {
            throw new \InvalidArgumentException('Prompt is required');
        }

        return $this->supports($request->getModel()->getContentType());
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::Bedrock;
    }

    public function getAvailableModels(): array
    {
        return config('ai-engine.engines.bedrock.models', []);
    }

    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->validateRequest($request);
            $this->logApiRequest('generateText', $request);

            $client = $this->getClient();

            $payload = $this->buildConversePayload($request);
            $result = $client->converse($payload);

            $data = $this->normalizeResult($result);
            $content = $this->extractConverseContent($data);

            return $this->buildBedrockResponse($content, $request, $data);
        } catch (MissingDependencyException $e) {
            // Surface the actionable dependency error directly; it is not a
            // transient API failure and should not be swallowed by failover.
            throw $e;
        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'AWS Bedrock text generation');
        }
    }

    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::Bedrock;
    }

    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(
            $this->config['default_model'] ?? EntityEnum::BEDROCK_CLAUDE_SONNET
        );
    }

    protected function validateConfig(): void
    {
        // Credentials may also be sourced from the ambient AWS environment
        // (instance profiles, shared config), so no hard requirement here.
    }

    /**
     * Resolve the Bedrock runtime client, building the real AWS SDK client when
     * one was not injected. Guarded by class_exists() so the optional SDK is
     * never a hard dependency.
     */
    private function getClient(): object
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(self::SDK_CLIENT_CLASS)) {
            throw MissingDependencyException::forPackage(
                self::SDK_PACKAGE,
                'to use the AWS Bedrock engine'
            );
        }

        $clientClass = self::SDK_CLIENT_CLASS;

        return $this->client = new $clientClass($this->buildClientConfig());
    }

    /**
     * Build the AWS SDK client configuration from the engine config block.
     */
    private function buildClientConfig(): array
    {
        $config = [
            'region' => $this->config['region'] ?? 'us-east-1',
            'version' => $this->config['version'] ?? 'latest',
        ];

        if (!empty($this->config['profile'])) {
            $config['profile'] = $this->config['profile'];
        }

        $key = $this->config['key'] ?? null;
        $secret = $this->config['secret'] ?? null;

        if (!empty($key) && !empty($secret)) {
            $config['credentials'] = array_filter([
                'key' => $key,
                'secret' => $secret,
                'token' => $this->config['token'] ?? null,
            ], static fn ($value): bool => $value !== null);
        }

        return $config;
    }

    /**
     * Build the Bedrock "converse" request payload from the AIRequest.
     *
     * Converse uses a normalized message shape across Bedrock-hosted models:
     *   messages: [ ['role' => 'user', 'content' => [ ['text' => '...'] ]] ]
     *   system:   [ ['text' => '...'] ]
     */
    private function buildConversePayload(AIRequest $request): array
    {
        $parameters = $request->getParameters();

        $payload = [
            'modelId' => $request->getModel()->value,
            'messages' => $this->buildConverseMessages($request),
        ];

        if ($system = $request->getSystemPrompt()) {
            $payload['system'] = [['text' => $system]];
        }

        $inferenceConfig = array_filter([
            'maxTokens' => $request->getMaxTokens() ?? $parameters['max_tokens'] ?? null,
            'temperature' => $request->getTemperature() ?? $parameters['temperature'] ?? null,
            'topP' => $parameters['top_p'] ?? null,
            'stopSequences' => $parameters['stop_sequences'] ?? $parameters['stop'] ?? null,
        ], static fn ($value): bool => $value !== null);

        if ($inferenceConfig !== []) {
            $payload['inferenceConfig'] = $inferenceConfig;
        }

        return $payload;
    }

    /**
     * Convert standard role/content messages into the Bedrock converse shape.
     */
    private function buildConverseMessages(AIRequest $request): array
    {
        $standard = $this->buildStandardMessages($request, includeSystemPrompt: false);

        $messages = [];
        foreach ($standard as $message) {
            $role = $message['role'] ?? 'user';

            // Converse only accepts user/assistant roles; system is sent separately.
            if ($role === 'system') {
                continue;
            }

            $content = $message['content'] ?? '';

            $messages[] = [
                'role' => $role,
                'content' => [['text' => is_string($content) ? $content : json_encode($content)]],
            ];
        }

        return $messages;
    }

    /**
     * Normalize the SDK result (an \Aws\Result, ArrayAccess, or plain array) to
     * a plain associative array.
     */
    private function normalizeResult(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        if ($result instanceof \ArrayAccess || $result instanceof \Traversable) {
            return iterator_to_array($result);
        }

        return (array) $result;
    }

    /**
     * Extract the assistant text from a converse response.
     */
    private function extractConverseContent(array $data): string
    {
        $blocks = $data['output']['message']['content'] ?? [];

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (isset($block['text']) && is_string($block['text'])) {
                    return $block['text'];
                }
            }
        }

        return '';
    }

    /**
     * Build an AIResponse with Bedrock usage/stop-reason metadata.
     */
    private function buildBedrockResponse(string $content, AIRequest $request, array $data): AIResponse
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['inputTokens'] ?? 0);
        $outputTokens = (int) ($usage['outputTokens'] ?? 0);
        $totalTokens = (int) ($usage['totalTokens'] ?? ($inputTokens + $outputTokens));

        if ($totalTokens === 0) {
            $totalTokens = $this->calculateTokensUsed($content);
        }

        $response = AIResponse::success(
            $content,
            $request->getEngine(),
            $request->getModel()
        )->withUsage(
            tokensUsed: $totalTokens,
            creditsUsed: $totalTokens * $request->getModel()->creditIndex()
        );

        if (!empty($data['stopReason'])) {
            $response = $response->withFinishReason((string) $data['stopReason']);
        }

        if ($usage !== []) {
            $response = $response->withDetailedUsage([
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
            ]);
        }

        return $response;
    }
}
