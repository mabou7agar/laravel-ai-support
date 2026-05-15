<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\NvidiaNim;

use GuzzleHttp\Client;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class NvidiaNimEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => rtrim($this->getBaseUrl(), '/') . '/',
            'headers' => $this->buildHeaders(),
        ]);
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
        return $this->generateTextStream($request);
    }

    public function validateRequest(AIRequest $request): bool
    {
        if (trim($request->getPrompt()) === '') {
            throw new \InvalidArgumentException('Prompt is required');
        }

        if ($this->getApiKey() === '') {
            throw new \InvalidArgumentException('NVIDIA NIM API key is required');
        }

        return $this->supports($request->getModel()->getContentType());
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::NVIDIA_NIM);
    }

    public function test(): bool
    {
        return $this->safeConnectionTest(
            AIRequest::make('Hello', $this->getEngineEnum(), $this->getDefaultModel()),
            fn (): bool => $this->httpClient->get('models')->getStatusCode() < 400
        );
    }

    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->validateRequest($request);
            $this->logApiRequest('generateText', $request);

            $response = $this->httpClient->post('chat/completions', [
                'json' => $this->buildNimPayload($request, false),
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents()) ?? [];
            $content = $data['choices'][0]['message']['content'] ?? '';

            return $this->buildSuccessResponse($content, $request, $data, 'nvidia_nim');
        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'NVIDIA NIM text generation');
        }
    }

    public function generateTextStream(AIRequest $request): \Generator
    {
        try {
            $this->validateRequest($request);

            $response = $this->httpClient->post('chat/completions', [
                'json' => $this->buildNimPayload($request, true),
                'stream' => true,
            ]);

            $buffer = '';
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $buffer .= $stream->read(1024);

                while (($lineEnd = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $lineEnd));
                    $buffer = substr($buffer, $lineEnd + 1);

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $payload = substr($line, 6);
                    if ($payload === '[DONE]') {
                        return;
                    }

                    $data = json_decode($payload, true);
                    $content = $data['choices'][0]['delta']['content'] ?? null;

                    if (is_string($content) && $content !== '') {
                        yield $content;
                    }
                }
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('NVIDIA NIM streaming error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getAvailableModels(): array
    {
        try {
            $response = $this->httpClient->get('models');
            $data = $this->parseJsonResponse($response->getBody()->getContents()) ?? [];

            if (!empty($data['data']) && is_array($data['data'])) {
                return collect($data['data'])
                    ->mapWithKeys(static function (array $model): array {
                        $id = (string) ($model['id'] ?? '');

                        return $id === '' ? [] : [$id => $model];
                    })
                    ->all();
            }
        } catch (\Exception) {
            // Fall back to configured models when the hosted catalog is unavailable.
        }

        return config('ai-engine.engines.nvidia_nim.models', []);
    }

    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'streaming'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::NVIDIA_NIM);
    }

    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(
            $this->config['default_model'] ?? EntityEnum::NVIDIA_NIM_NEMOTRON_70B
        );
    }

    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('NVIDIA NIM API key is required');
        }
    }

    protected function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    private function buildNimPayload(AIRequest $request, bool $stream): array
    {
        $parameters = $request->getParameters();

        $payload = [
            'model' => $request->getModel()->value,
            'messages' => $this->buildStandardMessages($request),
            'stream' => $stream,
            'max_tokens' => $request->getMaxTokens() ?? $parameters['max_tokens'] ?? null,
            'temperature' => $request->getTemperature() ?? $parameters['temperature'] ?? 0.7,
        ];

        foreach ([
            'top_p',
            'frequency_penalty',
            'presence_penalty',
            'stop',
            'seed',
            'response_format',
            'tools',
            'tool_choice',
        ] as $key) {
            if (array_key_exists($key, $parameters)) {
                $payload[$key] = $parameters[$key];
            }
        }

        if (!empty($request->getFunctions())) {
            $payload['functions'] = $request->getFunctions();
        }

        if ($request->getFunctionCall() !== null) {
            $payload['function_call'] = $request->getFunctionCall();
        }

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }
}
