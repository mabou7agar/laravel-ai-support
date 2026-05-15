<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\CloudflareWorkersAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;

class CloudflareWorkersAIEngineDriver extends BaseEngineDriver
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
            default => throw new AIEngineException('Cloudflare Workers AI media driver supports image and audio models.'),
        };
    }

    public function stream(AIRequest $request): \Generator
    {
        yield $this->generate($request)->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        return $this->getApiKey() !== '' && $this->accountId() !== '';
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::CLOUDFLARE_WORKERS_AI);
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::CLOUDFLARE_FLUX_SCHNELL => ['name' => 'FLUX Schnell', 'type' => 'image'],
            EntityEnum::CLOUDFLARE_DREAMSHAPER => ['name' => 'Dreamshaper 8 LCM', 'type' => 'image'],
            EntityEnum::CLOUDFLARE_WHISPER => ['name' => 'Whisper', 'type' => 'audio'],
            EntityEnum::CLOUDFLARE_MELOTTS => ['name' => 'MeloTTS', 'type' => 'audio'],
        ];
    }

    public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
    {
        throw new AIEngineException('Cloudflare Workers AI media driver does not support JSON analysis.');
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        $parameters = $request->getParameters();
        $payload = array_filter(array_merge([
            'prompt' => $request->getPrompt(),
            'width' => $parameters['width'] ?? null,
            'height' => $parameters['height'] ?? null,
            'num_steps' => $parameters['steps'] ?? null,
        ], $parameters), static fn ($value): bool => $value !== null);

        try {
            $response = $this->runModel($request->getModel()->value, ['json' => $payload]);
            $contentType = $response->getHeaderLine('Content-Type');
            $body = $response->getBody()->getContents();

            if (str_starts_with(strtolower($contentType), 'image/')) {
                $files = [$this->storeMediaBytes($body, $request, $this->extensionFromContentType($contentType, 'png'))];
            } else {
                $data = json_decode($body, true) ?: [];
                $image = $data['result']['image'] ?? $data['result']['b64_json'] ?? $data['image'] ?? null;
                $files = is_string($image) && $image !== ''
                    ? [$this->storeMediaBytes(base64_decode($image, true) ?: $image, $request, 'png')]
                    : $this->normalizeOutputFiles($data['result'] ?? $data);
            }

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::CLOUDFLARE_WORKERS_AI,
                'model' => $request->getModel()->value,
            ])->withFiles($files)->withUsage(creditsUsed: count($files) * $request->getModel()->creditIndex());
        } catch (RequestException $e) {
            return AIResponse::error('Cloudflare Workers AI image request failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        $payload = array_merge(['text' => $request->getPrompt()], $request->getParameters());
        $response = $this->runModel($request->getModel()->value, ['json' => $payload]);
        $contentType = $response->getHeaderLine('Content-Type');
        $body = $response->getBody()->getContents();

        if (str_starts_with(strtolower($contentType), 'audio/')) {
            $files = [$this->storeMediaBytes($body, $request, $this->extensionFromContentType($contentType, 'mp3'))];
        } else {
            $data = json_decode($body, true) ?: [];
            $audio = $data['result']['audio'] ?? $data['audio'] ?? null;
            $files = is_string($audio) && $audio !== ''
                ? [$this->storeMediaBytes(base64_decode($audio, true) ?: $audio, $request, 'mp3')]
                : $this->normalizeOutputFiles($data['result'] ?? $data);
        }

        return AIResponse::success('', $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::CLOUDFLARE_WORKERS_AI,
            'model' => $request->getModel()->value,
        ])->withFiles($files)->withUsage(creditsUsed: max(1, strlen($request->getPrompt()) / 1000) * $request->getModel()->creditIndex());
    }

    public function audioToText(AIRequest $request): AIResponse
    {
        $file = $request->getFiles()[0] ?? $request->getParameters()['file'] ?? null;
        if (!is_string($file) || !is_file($file)) {
            throw new AIEngineException('Cloudflare speech-to-text requires an audio file path.');
        }

        $response = $this->runModel($request->getModel()->value, [
            'multipart' => [
                ['name' => 'audio', 'contents' => fopen($file, 'r'), 'filename' => basename($file)],
            ],
        ]);
        $data = json_decode($response->getBody()->getContents(), true) ?: [];
        $text = (string) ($data['result']['text'] ?? $data['text'] ?? $data['result']['response'] ?? '');

        return AIResponse::success($text, $request->getEngine(), $request->getModel(), [
            'provider' => EngineEnum::CLOUDFLARE_WORKERS_AI,
            'model' => $request->getModel()->value,
        ])->withUsage(creditsUsed: $request->getModel()->creditIndex());
    }

    protected function runModel(string $model, array $options): \Psr\Http\Message\ResponseInterface
    {
        return $this->httpClient->post($this->modelPath($model), $options);
    }

    protected function modelPath(string $model): string
    {
        return 'accounts/'.rawurlencode($this->accountId()).'/ai/run/'.$model;
    }

    protected function accountId(): string
    {
        return trim((string) ($this->config['account_id'] ?? ''));
    }

    protected function isSpeechToTextModel(string $model): bool
    {
        return str_contains(strtolower($model), 'whisper');
    }

    protected function getSupportedCapabilities(): array
    {
        return ['image', 'images', 'audio', 'speech_to_text', 'text_to_speech'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::CLOUDFLARE_WORKERS_AI);
    }

    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(EntityEnum::CLOUDFLARE_FLUX_SCHNELL);
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
        return (string) ($this->config['base_url'] ?? 'https://api.cloudflare.com/client/v4');
    }
}
