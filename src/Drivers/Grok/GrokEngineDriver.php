<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Grok;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;

/**
 * xAI Grok engine driver.
 *
 * The xAI API is OpenAI-compatible (Bearer auth, /chat/completions), so this
 * driver mirrors the OpenRouter request/response/stream shape.
 */
class GrokEngineDriver extends BaseEngineDriver
{
    protected string $baseUrl = 'https://api.x.ai/v1';

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? config('ai-engine.engines.xai.base_url', $this->baseUrl)), '/');
    }

    /**
     * Get the API key for xAI
     */
    protected function getApiKey(): string
    {
        $apiKey = $this->config['api_key']
            ?? config('ai-engine.engines.xai.api_key');

        if (is_string($apiKey) && trim($apiKey) !== '') {
            return $apiKey;
        }

        throw new \InvalidArgumentException('xAI API key not configured');
    }

    /**
     * Get the headers for xAI API requests
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Generate content using xAI Grok
     */
    public function generate(AIRequest $request): AIResponse
    {
        return $this->generateText($request);
    }

    /**
     * Generate text content via xAI chat completions API
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->logApiRequest('generateText', $request);

            $messages = $this->buildMessages($request);
            $payload = $this->buildChatCompletionPayload($request, $messages);
            $response = $this->postJson('/chat/completions', $payload);

            if (!$response->successful()) {
                $error = $response->json()['error']['message'] ?? $response->body();
                return AIResponse::error($error, $request->getEngine(), $request->getModel());
            }

            $data = $response->json();
            $message = $data['choices'][0]['message'] ?? [];
            $content = $this->stringifyContent($message['content'] ?? '');
            $toolCalls = $this->extractToolCalls($message);
            $functionCall = $this->firstFunctionCall($toolCalls, $message);

            return AIResponse::success(
                $content,
                $request->getEngine(),
                $request->getModel(),
                [
                    'provider' => EngineEnum::Xai->value,
                    'model' => $data['model'] ?? $request->getModel()->value,
                    'usage' => $data['usage'] ?? [],
                    'xai_id' => $data['id'] ?? null,
                    'tool_calls' => $toolCalls,
                ]
            )->withFunctionCall($functionCall)
             ->withUsage(tokensUsed: $data['usage']['total_tokens'] ?? null);
        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        $payload = $this->buildChatCompletionPayload($request, $this->buildMessages($request));
        $payload['stream'] = true;

        $response = $this->postJson('/chat/completions', $payload, ['stream' => true]);
        if (!$response->successful()) {
            throw new \RuntimeException($response->json()['error']['message'] ?? $response->body());
        }

        yield from $this->parseStreamingResponse($response);
    }

    /**
     * Validate the request
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($request->getPrompt()) && $request->getFiles() === []) {
            throw new \InvalidArgumentException('Prompt is required');
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::Xai;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities(), true);
    }

    public function getEngineName(): string
    {
        return 'xai';
    }

    public function supportsModel(string $model): bool
    {
        return str_starts_with(strtolower($model), 'grok');
    }

    /**
     * Get available models from xAI
     */
    public function getAvailableModels(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(10)
                ->get($this->baseUrl . '/models');

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['data'] ?? [])
                    ->map(static fn (array $model): array => [
                        'id' => $model['id'] ?? '',
                        'name' => $model['id'] ?? '',
                        'raw' => $model,
                    ])
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Fall back to configured models
        }

        return collect(config('ai-engine.engines.xai.models', []))
            ->map(static fn (mixed $model, string $id): array => is_array($model)
                ? array_merge(['id' => $id], $model)
                : ['id' => $id, 'name' => (string) $model])
            ->values()
            ->toArray();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'streaming', 'vision'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::Xai;
    }

    protected function getDefaultModel(): EntityEnum
    {
        $model = config('ai-engine.engines.xai.default_model', EntityEnum::GROK_4_1);

        return EntityEnum::from($model);
    }

    protected function validateConfig(): void
    {
        if (empty($this->getApiKey())) {
            throw new \InvalidArgumentException('xAI API key is required');
        }
    }

    /**
     * Build messages array for chat completion
     */
    protected function buildMessages(AIRequest $request): array
    {
        $messages = [];

        $systemPrompt = $request->getSystemPrompt();
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $existingMessages = $request->getMessages();
        if (!empty($existingMessages)) {
            foreach ($existingMessages as $msg) {
                $messages[] = [
                    'role' => $msg['role'] ?? 'user',
                    'content' => $msg['content'] ?? '',
                ];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $request->getPrompt(),
        ];

        return $messages;
    }

    protected function buildChatCompletionPayload(AIRequest $request, array $messages): array
    {
        $parameters = array_replace_recursive(
            $request->getParameters(),
            $request->getProviderOptions(EngineEnum::Xai->value)
        );

        $payload = [
            'model' => $request->getModel()->value,
            'messages' => $messages,
            'max_tokens' => $request->getMaxTokens() ?? $parameters['max_tokens'] ?? 4096,
            'temperature' => $request->getTemperature() ?? $parameters['temperature'] ?? 0.7,
        ];

        foreach ([
            'max_completion_tokens',
            'frequency_penalty',
            'presence_penalty',
            'top_p',
            'seed',
            'stop',
            'response_format',
            'reasoning_effort',
            'parallel_tool_calls',
        ] as $field) {
            if (array_key_exists($field, $parameters) && $parameters[$field] !== null && $parameters[$field] !== '') {
                $payload[$field] = $parameters[$field];
            }
        }

        $this->applyToolPayload($payload, $request);
        $this->applyStructuredOutput($payload, $request);

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    protected function applyToolPayload(array &$payload, AIRequest $request): void
    {
        if ($request->getFunctions() === []) {
            return;
        }

        $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
            EngineEnum::Xai->value,
            $request->getFunctions()
        );

        if (!empty($split['functions'])) {
            $payload['tools'] = array_merge($payload['tools'] ?? [], array_map(
                static fn (array $function): array => isset($function['type'])
                    ? $function
                    : ['type' => 'function', 'function' => $function],
                $split['functions']
            ));
        }

        if (!empty($split['tools'])) {
            $payload['tools'] = array_merge($payload['tools'] ?? [], $split['tools']);
        }

        if ($request->getFunctionCall() !== null) {
            $payload['tool_choice'] = $request->getFunctionCall();
        }
    }

    protected function applyStructuredOutput(array &$payload, AIRequest $request): void
    {
        if (isset($payload['response_format'])) {
            return;
        }

        $definition = $request->getMetadata()['structured_output'] ?? null;
        if (!is_array($definition) || !is_array($definition['schema'] ?? null)) {
            return;
        }

        $payload['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => (string) ($definition['name'] ?? 'response'),
                'schema' => $definition['schema'],
                'strict' => (bool) ($definition['strict'] ?? true),
            ],
        ];
    }

    protected function postJson(string $path, array $payload, array $options = []): Response
    {
        return Http::withHeaders($this->getHeaders())
            ->withOptions($options)
            ->timeout((int) ($this->config['timeout'] ?? config('ai-engine.engines.xai.timeout', 60)))
            ->post($this->baseUrl . $path, $payload);
    }

    protected function parseStreamingResponse(Response $response): \Generator
    {
        $buffer = '';
        $body = $response->toPsrResponse()->getBody();

        while (!$body->eof()) {
            $buffer .= $body->read(8192);

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $chunk = trim(substr($line, 5));
                if ($chunk === '[DONE]') {
                    return;
                }

                $data = json_decode($chunk, true);
                if (!is_array($data)) {
                    continue;
                }

                $content = $this->stringifyContent($data['choices'][0]['delta']['content'] ?? '');
                if ($content !== '') {
                    yield $content;
                }
            }
        }
    }

    protected function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }

            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    protected function extractToolCalls(array $message): array
    {
        $toolCalls = (array) ($message['tool_calls'] ?? []);
        if ($toolCalls !== []) {
            return array_values($toolCalls);
        }

        if (is_array($message['function_call'] ?? null)) {
            return [[
                'id' => null,
                'type' => 'function',
                'function' => $message['function_call'],
            ]];
        }

        return [];
    }

    protected function firstFunctionCall(array $toolCalls, array $message): ?array
    {
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $function = (array) ($toolCall['function'] ?? []);
            $name = $function['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            return [
                'id' => $toolCall['id'] ?? null,
                'name' => $name,
                'arguments' => $this->decodeFunctionArguments($function['arguments'] ?? []),
                'raw' => $toolCall,
            ];
        }

        return null;
    }

    protected function decodeFunctionArguments(mixed $arguments): array|string
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (!is_string($arguments) || trim($arguments) === '') {
            return [];
        }

        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : $arguments;
        } catch (\JsonException) {
            return $arguments;
        }
    }
}
