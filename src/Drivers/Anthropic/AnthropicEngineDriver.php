<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolRunService;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;

class AnthropicEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config, Client $httpClient = null)
    {
        parent::__construct($config);
        
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        // Route to appropriate generation method based on content type
        $contentType = $request->getModel()->getContentType();
        
        return match ($contentType) {
            'text' => $this->generateText($request),
            'image' => $this->generateImage($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        return $this->generateTextStream($request);
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        // Check if API key is configured
        if (empty($this->getApiKey())) {
            return false;
        }

        // Check if model is supported
        if (!$this->supports($request->getModel()->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::Anthropic;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            // Create a simple test request
            $testRequest = new AIRequest(
                prompt: 'Hello',
                engine: EngineEnum::Anthropic,
                model: EntityEnum::from(EntityEnum::CLAUDE_3_5_SONNET)
            );
            
            $response = $this->generateText($testRequest);
            return $response->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content
     */
    public function generateText(AIRequest $request): AIResponse
    {
        $toolRunResult = null;

        try {
            $this->logApiRequest('generateText', $request);
            
            $messages = $this->buildMessages($request);
            $payload = $this->buildChatPayload($request, $messages);

            if ($request->getSystemPrompt()) {
                $payload['system'] = $request->getSystemPrompt();
            }

            $this->applyStreamingToolPayload($payload, $request);
            $providerTools = array_merge($payload['tools'] ?? [], $payload['mcp_servers'] ?? []);

            if ((bool) config('ai-engine.provider_tools.lifecycle.enabled', true)
                && Schema::hasTable('ai_provider_tool_runs')
                && $providerTools !== []) {
                $toolRunResult = app(ProviderToolRunService::class)->prepare('anthropic', $request, $request->getFunctions(), $payload);
                if (!$toolRunResult->canExecute()) {
                    return AIResponse::success(
                        'Provider tool run requires approval before execution.',
                        $request->getEngine(),
                        $request->getModel(),
                        ['provider_tool_lifecycle' => $toolRunResult->jsonSerialize()],
                        [[
                            'type' => 'provider_tool_approval',
                            'label' => 'Approve provider tools',
                            'payload' => $toolRunResult->jsonSerialize(),
                        ]]
                    );
                }
            }

            $response = $this->httpClient->post('/v1/messages', [
                'json' => $payload,
                'headers' => $this->buildRequestHeaders($request),
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $content = $data['content'][0]['text'] ?? '';

            $aiResponse = $this->buildSuccessResponse(
                $content,
                $request,
                $data,
                'anthropic'
            );

            if ($toolRunResult !== null) {
                $run = app(ProviderToolRunService::class)->complete($toolRunResult->run, is_array($data) ? $data : []);
                $artifacts = app(HostedArtifactService::class)->recordFromProviderResponse($run, is_array($data) ? $data : [], [
                    'provider_api' => 'messages',
                ]);

                $lifecycle = $toolRunResult->jsonSerialize();
                $lifecycle['run']['status'] = $run->status;
                $aiResponse = $aiResponse->withMetadata([
                    'provider_tool_lifecycle' => $lifecycle,
                    'hosted_artifacts' => array_map(static fn ($artifact): array => $artifact->toArray(), $artifacts),
                ]);
            }

            return $aiResponse;

        } catch (\Exception $e) {
            if ($toolRunResult !== null) {
                app(ProviderToolRunService::class)->fail($toolRunResult->run, $e->getMessage());
            }

            return $this->handleApiError($e, $request, 'text generation');
        }
    }

    /**
     * Generate streaming text content
     */
    public function generateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            $payload = [
                'model' => $request->getModel()->value,
                'messages' => $messages,
                'max_tokens' => $request->getMaxTokens() ?? 4096,
                'temperature' => $request->getTemperature() ?? 0.7,
                'stream' => true,
            ];

            if ($request->getSystemPrompt()) {
                $payload['system'] = $request->getSystemPrompt();
            }

            $response = $this->httpClient->post('/v1/messages', [
                'json' => $payload,
                'stream' => true,
                'headers' => $this->buildRequestHeaders($request),
            ]);

            $stream = $response->getBody();
            while (!$stream->eof()) {
                $line = trim($stream->read(1024));
                if (strpos($line, 'data: ') === 0) {
                    $data = json_decode(substr($line, 6), true);
                    if (isset($data['delta']['text'])) {
                        yield $data['delta']['text'];
                    }
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Anthropic streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Generate images (not supported by Anthropic)
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Image generation not supported by Anthropic',
            $request->getEngine(),
            $request->getModel()
        );
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        return [
            ['id' => 'claude-3-5-sonnet-20240620', 'name' => 'Claude 3.5 Sonnet'],
            ['id' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus'],
            ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku'],
        ];
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'vision', 'streaming'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::Anthropic;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(EntityEnum::CLAUDE_3_5_SONNET);
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new AIEngineException('Anthropic API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->getApiKey(),
            'anthropic-version' => '2023-06-01',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Build messages array for chat completion
     */
    private function buildMessages(AIRequest $request): array
    {
        // Use centralized method (Anthropic doesn't include system in messages array)
        return $this->buildStandardMessages($request, includeSystemPrompt: false);
    }

    private function applyStreamingToolPayload(array &$payload, AIRequest $request): void
    {
        if (empty($request->getFunctions())) {
            return;
        }

        $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
            EngineEnum::Anthropic->value,
            $request->getFunctions()
        );

        if (!empty($split['tools'])) {
            $payload['tools'] = $split['tools'];
        }

        if (!empty($split['mcp_servers'])) {
            $payload['mcp_servers'] = $split['mcp_servers'];
        }
    }

    private function buildRequestHeaders(AIRequest $request): array
    {
        if (empty($request->getFunctions())) {
            return [];
        }

        $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
            EngineEnum::Anthropic->value,
            $request->getFunctions()
        );

        if (empty($split['beta_headers'])) {
            return [];
        }

        return [
            'anthropic-beta' => implode(',', $split['beta_headers']),
        ];
    }
}
