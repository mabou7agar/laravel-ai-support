<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Replicate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class ReplicateEngineDriver extends BaseEngineDriver
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
            'video' => $this->generateVideo($request),
            'audio' => $this->generateAudio($request),
            default => throw new \InvalidArgumentException('Replicate driver supports image, video, and audio models.'),
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
        return EngineEnum::Replicate;
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::REPLICATE_FLUX_SCHNELL => ['name' => 'FLUX Schnell', 'type' => 'image'],
            EntityEnum::REPLICATE_WAN_IMAGE_TO_VIDEO => ['name' => 'WAN Image to Video', 'type' => 'video'],
        ];
    }

    public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
    {
        throw new \InvalidArgumentException('Replicate media driver does not support JSON analysis.');
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        return $this->runPrediction($request);
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        return $this->runPrediction($request);
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        return $this->runPrediction($request);
    }

    protected function runPrediction(AIRequest $request): AIResponse
    {
        try {
            $parameters = $request->getParameters();
            $input = array_merge(['prompt' => $request->getPrompt()], (array) ($parameters['input'] ?? []), $parameters);
            unset($input['input'], $input['version'], $input['webhook'], $input['webhook_events_filter']);

            $version = $parameters['version'] ?? null;
            $payload = array_filter([
                'version' => $version,
                'input' => $input,
                'webhook' => $parameters['webhook'] ?? null,
                'webhook_events_filter' => $parameters['webhook_events_filter'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []);

            $path = $version
                ? 'predictions'
                : 'models/'.trim($request->getModel()->value, '/').'/predictions';

            $response = $this->httpClient->post($path, [
                'headers' => ['Prefer' => ($parameters['prefer'] ?? 'wait')],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true) ?: [];
            $files = $this->normalizeOutputFiles($data['output'] ?? []);

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::Replicate->value,
                'prediction_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'metrics' => $data['metrics'] ?? [],
            ])->withFiles($files)->withUsage(creditsUsed: max(1, count($files)) * $request->getModel()->creditIndex());
        } catch (RequestException $e) {
            return AIResponse::error('Replicate prediction failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    protected function getSupportedCapabilities(): array
    {
        return ['image', 'images', 'video', 'audio'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::Replicate;
    }

    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(EntityEnum::REPLICATE_FLUX_SCHNELL);
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
        return (string) ($this->config['base_url'] ?? 'https://api.replicate.com/v1');
    }
}
