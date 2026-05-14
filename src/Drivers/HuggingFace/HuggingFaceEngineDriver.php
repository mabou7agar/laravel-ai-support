<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\HuggingFace;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;

class HuggingFaceEngineDriver extends BaseEngineDriver
{
    use BuildsMediaResponses;

    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => rtrim($this->getBaseUrl(), '/').'/',
            'headers' => $this->buildHeaders(),
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        return match ($request->getModel()->getContentType()) {
            'image' => $this->generateImage($request),
            'audio' => $this->isSpeechToTextModel($request->getModel()->value)
                ? $this->audioToText($request)
                : $this->generateAudio($request),
            'video' => $this->generateVideo($request),
            default => throw new AIEngineException('Hugging Face media driver supports image, video, and audio models.'),
        };
    }

    public function stream(AIRequest $request): \Generator
    {
        yield $this->generate($request)->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        return $this->getApiKey() !== '';
    }

    public function getEngine(): EngineEnum
    {
        return new EngineEnum(EngineEnum::HUGGINGFACE);
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::HUGGINGFACE_FLUX_SCHNELL => ['name' => 'FLUX Schnell', 'type' => 'image'],
            EntityEnum::HUGGINGFACE_WHISPER_LARGE_V3 => ['name' => 'Whisper Large v3', 'type' => 'audio'],
            EntityEnum::HUGGINGFACE_MMS_TTS => ['name' => 'MMS TTS', 'type' => 'audio'],
        ];
    }

    public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
    {
        throw new AIEngineException('Hugging Face media driver does not support JSON analysis.');
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        return $this->runTask($request, 'image');
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        return $this->runTask($request, 'video');
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        return $this->runTask($request, 'audio');
    }

    public function audioToText(AIRequest $request): AIResponse
    {
        $file = $request->getFiles()[0] ?? $request->getParameters()['file'] ?? null;
        if (!is_string($file) || !is_file($file)) {
            throw new AIEngineException('Hugging Face speech-to-text requires an audio file path.');
        }

        $response = $this->httpClient->post($this->modelPath($request->getModel()->value), [
            'body' => fopen($file, 'r'),
            'headers' => ['Content-Type' => mime_content_type($file) ?: 'application/octet-stream'],
            'query' => $this->providerQuery($request),
        ]);
        $data = json_decode($response->getBody()->getContents(), true) ?: [];

        return AIResponse::success((string) ($data['text'] ?? ''), $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::HUGGINGFACE,
            'raw' => $data,
        ])->withUsage(creditsUsed: $request->getModel()->creditIndex());
    }

    protected function runTask(AIRequest $request, string $fallbackExtension): AIResponse
    {
        try {
            $response = $this->httpClient->post($this->modelPath($request->getModel()->value), [
                'json' => [
                    'inputs' => $request->getPrompt(),
                    'parameters' => $request->getParameters()['parameters'] ?? array_diff_key($request->getParameters(), ['provider' => true]),
                ],
                'query' => $this->providerQuery($request),
            ]);

            $contentType = $response->getHeaderLine('Content-Type');
            $body = $response->getBody()->getContents();

            if (str_starts_with(strtolower($contentType), 'image/')
                || str_starts_with(strtolower($contentType), 'audio/')
                || str_starts_with(strtolower($contentType), 'video/')) {
                $files = [$this->storeMediaBytes($body, $request, $this->extensionFromContentType($contentType, $fallbackExtension))];
            } else {
                $data = json_decode($body, true) ?: [];
                $files = $this->normalizeOutputFiles($data);
            }

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::HUGGINGFACE,
                'model' => $request->getModel()->value,
            ])->withFiles($files)->withUsage(creditsUsed: max(1, count($files)) * $request->getModel()->creditIndex());
        } catch (RequestException $e) {
            return AIResponse::error('Hugging Face inference failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    protected function modelPath(string $model): string
    {
        return trim((string) ($this->config['model_path_prefix'] ?? 'models'), '/').'/'.trim($model, '/');
    }

    protected function providerQuery(AIRequest $request): array
    {
        $provider = $request->getParameters()['provider'] ?? $this->config['provider'] ?? null;

        return $provider ? ['provider' => $provider] : [];
    }

    protected function isSpeechToTextModel(string $model): bool
    {
        return str_contains(strtolower($model), 'whisper') || str_contains(strtolower($model), 'speech-recognition');
    }

    protected function getSupportedCapabilities(): array
    {
        return ['image', 'images', 'video', 'audio', 'speech_to_text', 'text_to_speech'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return new EngineEnum(EngineEnum::HUGGINGFACE);
    }

    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(EntityEnum::HUGGINGFACE_FLUX_SCHNELL);
    }

    protected function validateConfig(): void
    {
    }

    protected function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getApiKey(),
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    protected function getBaseUrl(): string
    {
        return (string) ($this->config['base_url'] ?? 'https://api-inference.huggingface.co');
    }
}
