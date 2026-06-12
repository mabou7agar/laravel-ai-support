<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Replicate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\VideoModelSpec;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\Media\VideoModelCatalog;

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
        $models = [
            EntityEnum::REPLICATE_FLUX_SCHNELL => ['name' => 'FLUX Schnell', 'type' => 'image'],
            EntityEnum::REPLICATE_WAN_IMAGE_TO_VIDEO => ['name' => 'WAN Image to Video', 'type' => 'video'],
        ];

        foreach (VideoModelCatalog::all() as $model => $spec) {
            if ($spec->isReplicate() && !isset($models[$model])) {
                $models[$model] = ['name' => EntityEnum::from($model)->label(), 'type' => 'video'];
            }
        }

        return $models;
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
        $spec = VideoModelCatalog::get($request->getModel()->value);

        return $spec !== null
            ? $this->runPrediction($request, $this->prepareReplicateVideoInput($request, $spec))
            : $this->runPrediction($request);
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        return $this->runPrediction($request);
    }

    /**
     * Build a WAN (or other Replicate video) prediction input from the catalog spec:
     * route the canonical start/end frame to the model's field names (e.g.
     * image/last_image or first_frame/last_frame) and forward only whitelisted options.
     *
     * @return array<string, mixed>
     */
    protected function prepareReplicateVideoInput(AIRequest $request, VideoModelSpec $spec): array
    {
        $parameters = $request->getParameters();
        $input = (array) ($parameters['input'] ?? []);

        if ($spec->acceptsPrompt() && trim($request->getPrompt()) !== '') {
            $input['prompt'] = $request->getPrompt();
        }

        if ($spec->firstFrameField !== null) {
            $first = VideoModelSpec::firstNonEmpty(
                $parameters['start_image_url'] ?? null,
                $parameters['image_url'] ?? null,
                $parameters['image'] ?? null,
                $parameters['first_frame'] ?? null,
            );
            if ($first !== null) {
                $input[$spec->firstFrameField] = $first;
            } elseif ($spec->firstFrameRequired) {
                throw new AIEngineException("Model {$spec->endpoint} requires a start image.");
            }
        }

        if ($spec->lastFrameField !== null) {
            $last = VideoModelSpec::firstNonEmpty(
                $parameters['end_image_url'] ?? null,
                $parameters['last_image'] ?? null,
                $parameters['last_frame'] ?? null,
            );
            if ($last !== null) {
                $input[$spec->lastFrameField] = $last;
            }
        }

        return $spec->applyOptions($input, $parameters);
    }

    protected function runPrediction(AIRequest $request, ?array $inputOverride = null): AIResponse
    {
        try {
            $parameters = $request->getParameters();
            $input = $inputOverride
                ?? array_merge(['prompt' => $request->getPrompt()], (array) ($parameters['input'] ?? []), $parameters);
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
