<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\ParsesSseStream;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolRunService;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;
use LaravelAIEngine\Support\Http\RetryPolicy;

class AnthropicEngineDriver extends BaseEngineDriver
{
    use ParsesSseStream;

    public const DEFAULT_MODEL = EntityEnum::CLAUDE_SONNET_5;

    private Client $httpClient;

    public function __construct(array $config, Client $httpClient = null)
    {
        parent::__construct($config);
        
        $this->httpClient = $httpClient ?? RetryPolicy::resolve()->guzzleClient([
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
                model: $this->getDefaultModel()
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
            
            $payload = $this->buildMessagesPayload($request);
            // Only provider-hosted tools (web search, code execution, MCP ...) go
            // through the approval lifecycle; plain function tools do not.
            $split = empty($request->getFunctions())
                ? ['tools' => [], 'mcp_servers' => []]
                : app(ProviderToolPayloadMapper::class)->splitForProvider(EngineEnum::Anthropic->value, $request->getFunctions());
            $providerTools = array_merge((array) $split['tools'], (array) $split['mcp_servers']);

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
            $data = is_array($data) ? $data : [];
            $blocks = is_array($data['content'] ?? null) ? $data['content'] : [];

            $content = '';
            $toolCalls = [];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'text') {
                    $content .= (string) ($block['text'] ?? '');
                } elseif (($block['type'] ?? null) === 'tool_use') {
                    $toolCalls[] = $this->toolCallFromBlock(
                        (string) ($block['id'] ?? ''),
                        (string) ($block['name'] ?? ''),
                        is_array($block['input'] ?? null) ? $block['input'] : []
                    );
                }
            }

            $aiResponse = $this->withToolCalls(
                $this->buildSuccessResponse($content, $request, $data, 'anthropic'),
                $toolCalls
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
     * Generate streaming text content.
     *
     * Yields text deltas as plain strings (backward compatible) and returns the
     * completed AIResponse from the generator — including streamed tool calls in
     * metadata['tool_calls'] (OpenAI shape, same as the non-streaming path),
     * the first call via getFunctionCall(), stop reason and token usage.
     */
    public function generateTextStream(AIRequest $request): \Generator
    {
        try {
            $payload = $this->buildMessagesPayload($request);
            $payload['stream'] = true;

            $response = $this->httpClient->post('/v1/messages', [
                'json' => $payload,
                'stream' => true,
                'headers' => $this->buildRequestHeaders($request),
            ]);

            $content = '';
            /** @var array<int, array{type: string, id?: string, name?: string, json?: string, input?: array}> $blocks */
            $blocks = [];
            $toolCalls = [];
            $usage = [];
            $stopReason = null;
            $messageId = null;

            foreach ($this->parseSseEvents($response->getBody()) as $event) {
                foreach (explode("\n", $event['data']) as $line) {
                    $data = json_decode(trim($line), true);
                    if (!is_array($data)) {
                        continue;
                    }

                    switch ($data['type'] ?? $event['event']) {
                        case 'message_start':
                            $messageId = $data['message']['id'] ?? $messageId;
                            $usage = array_merge($usage, (array) ($data['message']['usage'] ?? []));
                            break;

                        case 'content_block_start':
                            $index = (int) ($data['index'] ?? count($blocks));
                            $block = (array) ($data['content_block'] ?? []);
                            $blocks[$index] = [
                                'type' => (string) ($block['type'] ?? ''),
                                'id' => (string) ($block['id'] ?? ''),
                                'name' => (string) ($block['name'] ?? ''),
                                'json' => '',
                                'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
                            ];
                            if (($block['type'] ?? null) === 'text' && ($block['text'] ?? '') !== '') {
                                $content .= (string) $block['text'];
                                yield (string) $block['text'];
                            }
                            break;

                        case 'content_block_delta':
                            $index = (int) ($data['index'] ?? 0);
                            $delta = (array) ($data['delta'] ?? []);
                            if (($delta['type'] ?? null) === 'text_delta' && ($delta['text'] ?? '') !== '') {
                                $content .= (string) $delta['text'];
                                yield (string) $delta['text'];
                            } elseif (($delta['type'] ?? null) === 'input_json_delta' && isset($blocks[$index])) {
                                $blocks[$index]['json'] .= (string) ($delta['partial_json'] ?? '');
                            }
                            break;

                        case 'content_block_stop':
                            $index = (int) ($data['index'] ?? 0);
                            $block = $blocks[$index] ?? null;
                            if ($block !== null && $block['type'] === 'tool_use') {
                                $input = $block['input'];
                                if (trim($block['json']) !== '') {
                                    $decoded = json_decode($block['json'], true);
                                    $input = is_array($decoded) ? $decoded : $input;
                                }
                                $toolCalls[] = $this->toolCallFromBlock($block['id'], $block['name'], $input);
                            }
                            break;

                        case 'message_delta':
                            $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
                            $usage = array_merge($usage, (array) ($data['usage'] ?? []));
                            break;

                        case 'error':
                            throw new \RuntimeException((string) ($data['error']['message'] ?? 'stream error'));
                    }
                }
            }

            $apiResponse = array_filter([
                'id' => $messageId,
                'stop_reason' => $stopReason,
                'usage' => $usage,
            ], static fn ($value): bool => $value !== null && $value !== []);

            return $this->withToolCalls(
                $this->buildSuccessResponse($content, $request, $apiResponse, 'anthropic'),
                $toolCalls
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('Anthropic streaming error: ' . $e->getMessage(), 0, $e);
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
            ['id' => EntityEnum::CLAUDE_OPUS_5, 'name' => 'Claude Opus 5'],
            ['id' => EntityEnum::CLAUDE_SONNET_5, 'name' => 'Claude Sonnet 5'],
            ['id' => EntityEnum::CLAUDE_HAIKU_4_5, 'name' => 'Claude Haiku 4.5'],
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
        return EntityEnum::from((string) ($this->config['default_model'] ?? config('ai-engine.engines.anthropic.default_model') ?: self::DEFAULT_MODEL));
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

    /**
     * Build the Messages API payload shared by the streaming and non-streaming
     * paths.
     *
     * Order is stable and cache-friendly: tools (caller order, provider tools
     * after functions) render before system, which renders before messages.
     * With prompt caching on, the last tool and the system block carry
     * cache_control breakpoints so that prefix is reused across calls.
     */
    protected function buildMessagesPayload(AIRequest $request): array
    {
        $model = $request->getModel()->value;

        $payload = [
            'model' => $model,
            'messages' => $this->buildMessages($request),
            'max_tokens' => $request->getMaxTokens()
                ?? (int) ($this->config['max_tokens'] ?? config('ai-engine.engines.anthropic.max_tokens', 4096)),
        ];

        if ($this->supportsSamplingParameters($model)) {
            $payload['temperature'] = $request->getTemperature() ?? 0.7;
        }

        if ($request->getSystemPrompt()) {
            $payload['system'] = $this->systemPayload($request);
        }

        if (!empty($request->getFunctions())) {
            $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
                EngineEnum::Anthropic->value,
                $request->getFunctions()
            );

            $tools = array_merge(
                array_values(array_filter(array_map(
                    fn ($definition): ?array => is_array($definition) ? $this->anthropicToolDefinition($definition) : null,
                    (array) $split['functions']
                ))),
                array_values((array) $split['tools'])
            );

            if ($tools !== []) {
                if ($this->promptCachingEnabled()) {
                    $last = array_key_last($tools);
                    $tools[$last]['cache_control'] = ['type' => 'ephemeral'];
                }
                $payload['tools'] = $tools;

                $toolChoice = $this->anthropicToolChoice(
                    $request->getFunctionCall(),
                    $request->getParameters()['parallel_tool_calls'] ?? null
                );
                if ($toolChoice !== null) {
                    $payload['tool_choice'] = $toolChoice;
                }
            }

            if (!empty($split['mcp_servers'])) {
                $payload['mcp_servers'] = $split['mcp_servers'];
            }
        }

        return array_replace_recursive($payload, $this->providerPayloadOptions($request, 'anthropic'));
    }

    /**
     * Convert an OpenAI-style function definition ({name, description, parameters}
     * or {type: function, function: {...}}) to an Anthropic tool definition.
     */
    protected function anthropicToolDefinition(array $definition): ?array
    {
        if (isset($definition['input_schema'])) {
            return $definition;
        }

        $function = is_array($definition['function'] ?? null) ? $definition['function'] : $definition;
        $name = trim((string) ($function['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $schema = is_array($function['parameters'] ?? null) && $function['parameters'] !== []
            ? $function['parameters']
            : ['type' => 'object', 'properties' => new \stdClass()];
        if (($schema['type'] ?? null) === 'object' && ($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }

        return array_filter([
            'name' => $name,
            'description' => isset($function['description']) ? (string) $function['description'] : null,
            'input_schema' => $schema,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Map an OpenAI-style function_call / tool_choice to Anthropic tool_choice.
     */
    protected function anthropicToolChoice(array|string|null $functionCall, mixed $parallelToolCalls): ?array
    {
        $choice = null;

        if (is_string($functionCall)) {
            $choice = match (strtolower($functionCall)) {
                'auto' => ['type' => 'auto'],
                'none' => ['type' => 'none'],
                'required', 'any' => ['type' => 'any'],
                default => ['type' => 'tool', 'name' => $functionCall],
            };
        } elseif (is_array($functionCall)) {
            $type = (string) ($functionCall['type'] ?? '');
            $name = $functionCall['name'] ?? ($functionCall['function']['name'] ?? null);
            if (in_array($type, ['auto', 'any', 'none'], true)) {
                $choice = ['type' => $type];
            } elseif (is_string($name) && $name !== '') {
                $choice = ['type' => 'tool', 'name' => $name];
            }
        }

        if ($parallelToolCalls === false) {
            $choice ??= ['type' => 'auto'];
            if ($choice['type'] !== 'none') {
                $choice['disable_parallel_tool_use'] = true;
            }
        }

        return $choice;
    }

    /**
     * Claude Opus 4.7+/Sonnet 5+ (and the Fable/Mythos tiers) reject temperature,
     * top_p and top_k; older models keep the historical 0.7 default.
     */
    protected function supportsSamplingParameters(string $model): bool
    {
        return preg_match('/^claude-(opus-(4-[7-9]|[5-9])|sonnet-([5-9]|\d{2})|fable|mythos)/', $model) !== 1;
    }

    private function promptCachingEnabled(): bool
    {
        return (bool) ($this->config['prompt_caching'] ?? config('ai-engine.engines.anthropic.prompt_caching', true));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     */
    private function toolCallFromBlock(string $id, string $name, array $input): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($input === [] ? new \stdClass() : $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ],
        ];
    }

    /**
     * Expose tool_use blocks the same way the OpenAI-compatible drivers do:
     * metadata['tool_calls'] (OpenAI shape) plus the first call as functionCall.
     */
    private function withToolCalls(AIResponse $response, array $toolCalls): AIResponse
    {
        if ($toolCalls === []) {
            return $response;
        }

        $first = $toolCalls[0];

        return $response
            ->withMetadata(['tool_calls' => $toolCalls])
            ->withFunctionCall([
                'id' => $first['id'],
                'name' => $first['function']['name'],
                'arguments' => json_decode($first['function']['arguments'], true) ?: [],
                'raw' => $first,
            ]);
    }

    /**
     * Anthropic `system` field. When prompt caching is enabled (default) and a system prompt is
     * present, send it as a content block marked `cache_control: ephemeral` so Anthropic caches
     * this stable prefix across the steps of a turn (and across turns within the cache TTL).
     * Blocks below Anthropic's minimum cacheable size are simply not cached — no error — so this
     * is safe to leave on. Falls back to a plain string when caching is disabled.
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function systemPayload(AIRequest $request): string|array
    {
        $system = (string) $request->getSystemPrompt();
        if ($system === '' || !$this->promptCachingEnabled()) {
            return $system;
        }

        return [[
            'type' => 'text',
            'text' => $system,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    }

    private function buildRequestHeaders(AIRequest $request): array
    {
        $headers = (array) ($request->getProviderOptions('anthropic')['headers'] ?? []);

        if (!empty($request->getFunctions())) {
            $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
                EngineEnum::Anthropic->value,
                $request->getFunctions()
            );

            if (!empty($split['beta_headers'])) {
                $toolBeta = implode(',', $split['beta_headers']);
                $headers['anthropic-beta'] = isset($headers['anthropic-beta']) && $headers['anthropic-beta'] !== ''
                    ? $headers['anthropic-beta'] . ',' . $toolBeta
                    : $toolBeta;
            }
        }

        return array_filter($headers, static fn ($value): bool => $value !== null && $value !== '');
    }
}
