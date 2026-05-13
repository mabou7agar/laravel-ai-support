<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\FalAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Repositories\AIModelRepository;
use LaravelAIEngine\Services\AIMediaManager;
use LaravelAIEngine\Services\Fal\FalCatalogExecutionService;

class FalAIEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;
    private Client $queueHttpClient;

    public function __construct(array $config, ?Client $httpClient = null, ?Client $queueHttpClient = null)
    {
        parent::__construct($config);
        $baseUrl = preg_replace('#/fal-ai/?$#', '', rtrim($this->getBaseUrl(), '/')) ?: 'https://fal.run';
        $queueBaseUrl = rtrim((string) ($this->config['queue_base_url'] ?? 'https://queue.fal.run'), '/');

        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout' => $this->getTimeout(),
            'headers' => $this->buildHeaders(),
        ]);

        $this->queueHttpClient = $queueHttpClient ?? new Client([
            'base_uri' => $queueBaseUrl . '/',
            'timeout' => $this->getTimeout(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        $model = $request->getModel()->value;
        if ($this->isCatalogModel($model) && !$this->isDriverManagedModel($model)) {
            return app(FalCatalogExecutionService::class)->executeRequest($request);
        }

        return match ($request->getContentType()) {
            'image' => $this->generateImage($request),
            'video' => $this->generateVideo($request),
            default => throw new AIEngineException("Content type {$request->getContentType()} not supported by FAL AI"),
        };
    }

    public function stream(AIRequest $request): \Generator
    {
        yield $this->generate($request)->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        if ($this->getApiKey() === '') {
            throw new AIEngineException('FAL AI API key is required');
        }

        $model = $request->getModel()->value;
        if (!$this->isDriverManagedModel($model) && !$this->isCatalogModel($model)) {
            throw new AIEngineException("Model {$model} is not supported by FAL AI driver");
        }

        if ($this->isCatalogModel($model) && !$this->isDriverManagedModel($model)) {
            return true;
        }

        $parameters = $request->getParameters();
        if ($request->isImageGeneration() && trim($request->getPrompt()) === '') {
            throw new AIEngineException('Prompt is required for FAL image generation');
        }

        if ($request->isVideoGeneration()) {
            $hasPrompt = trim($request->getPrompt()) !== '' || !empty($parameters['multi_prompt']);
            $hasStartImage = !empty($parameters['start_image_url']) || !empty($parameters['image_url']);
            $hasReferences = !empty($parameters['reference_image_urls'])
                || !empty($parameters['character_sources'])
                || !empty($parameters['reference_video_urls'])
                || !empty($parameters['video_urls']);
            $isTextToVideo = $model === EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO;

            if ($isTextToVideo && !$hasPrompt) {
                throw new AIEngineException('Prompt is required for text-to-video generation');
            }

            if (!$isTextToVideo && !$hasStartImage && !$hasReferences && !$this->isLegacyTextToVideoModel($model)) {
                throw new AIEngineException('Video generation requires a start image or references for the selected model');
            }
        }

        return true;
    }

    public function getEngine(): EngineEnum
    {
        return new EngineEnum(EngineEnum::FAL_AI);
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::FAL_FLUX_PRO => ['name' => 'FLUX.1 Pro', 'type' => 'image'],
            EntityEnum::FAL_FLUX_DEV => ['name' => 'FLUX.1 Dev', 'type' => 'image'],
            EntityEnum::FAL_FLUX_SCHNELL => ['name' => 'FLUX.1 Schnell', 'type' => 'image'],
            EntityEnum::FAL_SDXL => ['name' => 'Stable Diffusion XL', 'type' => 'image'],
            EntityEnum::FAL_SD3_MEDIUM => ['name' => 'Stable Diffusion 3 Medium', 'type' => 'image'],
            EntityEnum::FAL_NANO_BANANA_2 => ['name' => 'Nano Banana 2', 'type' => 'image'],
            EntityEnum::FAL_NANO_BANANA_2_EDIT => ['name' => 'Nano Banana 2 Edit', 'type' => 'image'],
            EntityEnum::FAL_STABLE_VIDEO => ['name' => 'Stable Video Diffusion', 'type' => 'video'],
            EntityEnum::FAL_ANIMATEDIFF => ['name' => 'AnimateDiff Lightning', 'type' => 'video'],
            EntityEnum::FAL_LUMA_DREAM => ['name' => 'Luma Dream Machine', 'type' => 'video'],
            EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO => ['name' => 'Kling O3 Image to Video', 'type' => 'video'],
            EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO => ['name' => 'Kling O3 Reference to Video', 'type' => 'video'],
            EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO => ['name' => 'Seedance 2.0 Text to Video', 'type' => 'video'],
            EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO => ['name' => 'Seedance 2.0 Image to Video', 'type' => 'video'],
            EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO => ['name' => 'Seedance 2.0 Reference to Video', 'type' => 'video'],
        ];
    }

    public function test(): bool
    {
        try {
            $request = new AIRequest(
                prompt: 'A small blue cube on a white background',
                engine: EngineEnum::FAL_AI,
                model: EntityEnum::FAL_FLUX_PRO
            );

            return $this->generateImage($request)->isSuccess();
        } catch (\Throwable) {
            return false;
        }
    }

    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300
    ): string {
        throw new AIEngineException('FAL AI does not support JSON analysis for text tasks');
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        $operation = $this->prepareImageOperation($request);

        try {
            $data = $this->postToEndpoint($operation['endpoint'], $operation['payload']);

            return $this->buildImageResponseFromOperation($request, $operation, $data);
        } catch (RequestException $e) {
            return AIResponse::error(
                'FAL AI image request failed: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        } catch (\Throwable $e) {
            return AIResponse::error(
                $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        $operation = $this->prepareVideoOperation($request);

        try {
            $data = $this->postToEndpoint($operation['endpoint'], $operation['payload']);

            return $this->buildVideoResponseFromOperation($request, $operation, $data);
        } catch (RequestException $e) {
            return AIResponse::error(
                'FAL AI video request failed: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        } catch (\Throwable $e) {
            return AIResponse::error(
                $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    protected function getSupportedCapabilities(): array
    {
        return ['image', 'images', 'video'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return new EngineEnum(EngineEnum::FAL_AI);
    }

    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(EntityEnum::FAL_FLUX_PRO);
    }

    protected function validateConfig(): void
    {
        if (($this->config['api_key'] ?? '') === '') {
            return;
        }
    }

    protected function buildHeaders(): array
    {
        return [
            'Authorization' => 'Key ' . $this->getApiKey(),
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    protected function getTimeout(): int
    {
        $timeout = $this->config['timeout'] ?? 180;

        return is_numeric($timeout) ? (int) $timeout : 180;
    }

    public function prepareImageOperation(AIRequest $request): array
    {
        $parameters = $request->getParameters();
        $requestedModel = $request->getModel()->value;
        $characterSources = $this->normalizeCharacterSources($parameters['character_sources'] ?? []);
        $sourceImages = $this->normalizeStringList($parameters['source_images'] ?? []);
        $imageUrls = $sourceImages;

        foreach ($characterSources as $source) {
            if (isset($source['frontal_image_url'])) {
                $imageUrls[] = $source['frontal_image_url'];
            }
            foreach ($source['reference_image_urls'] ?? [] as $referenceImageUrl) {
                $imageUrls[] = $referenceImageUrl;
            }
        }

        $useEditEndpoint = $requestedModel === EntityEnum::FAL_NANO_BANANA_2_EDIT
            || ($requestedModel === EntityEnum::FAL_NANO_BANANA_2 && ($imageUrls !== [] || ($parameters['mode'] ?? null) === 'edit'));

        $payload = [
            'prompt' => $this->augmentPromptWithCharacterSources($request->getPrompt(), $characterSources),
        ];

        $numImages = $parameters['frame_count'] ?? $parameters['num_images'] ?? $parameters['image_count'] ?? 1;
        if ($requestedModel === EntityEnum::FAL_NANO_BANANA_2 || $requestedModel === EntityEnum::FAL_NANO_BANANA_2_EDIT) {
            $payload['num_images'] = (int) $numImages;
            $payload['aspect_ratio'] = $parameters['aspect_ratio'] ?? 'auto';
            $payload['resolution'] = $parameters['resolution'] ?? '1K';
            $payload['output_format'] = $parameters['output_format'] ?? 'png';
            $payload['limit_generations'] = $parameters['limit_generations'] ?? true;
            if (isset($parameters['seed'])) {
                $payload['seed'] = (int) $parameters['seed'];
            }
            if (!empty($parameters['thinking_level'])) {
                $payload['thinking_level'] = $parameters['thinking_level'];
            }
            if (!empty($parameters['enable_web_search'])) {
                $payload['enable_web_search'] = (bool) $parameters['enable_web_search'];
            }
            if ($useEditEndpoint) {
                $payload['image_urls'] = array_values(array_unique($imageUrls));
            }
        } else {
            $payload = [
                'prompt' => $request->getPrompt(),
                'image_size' => $parameters['image_size'] ?? '1024x1024',
                'num_inference_steps' => $parameters['steps'] ?? 50,
                'guidance_scale' => $parameters['guidance_scale'] ?? 7.5,
                'num_images' => (int) $numImages,
                'enable_safety_checker' => $parameters['safety_checker'] ?? true,
            ];

            if (!empty($parameters['negative_prompt'])) {
                $payload['negative_prompt'] = $parameters['negative_prompt'];
            }

            if (isset($parameters['seed'])) {
                $payload['seed'] = (int) $parameters['seed'];
            }
        }

        $resolvedModel = $useEditEndpoint ? EntityEnum::FAL_NANO_BANANA_2_EDIT : $requestedModel;

        return [
            'endpoint' => $this->resolveEndpointForModel($resolvedModel),
            'payload' => $payload,
            'resolved_model' => $resolvedModel,
        ];
    }

    public function submitImageAsync(AIRequest $request, ?string $webhookUrl = null): array
    {
        $operation = $this->prepareImageOperation($request);
        $query = [];

        if (is_string($webhookUrl) && trim($webhookUrl) !== '') {
            $query['fal_webhook'] = trim($webhookUrl);
        }

        $data = $this->postToQueueEndpoint($operation['endpoint'], $operation['payload'], $query);

        return [
            'request_id' => $data['request_id'] ?? null,
            'gateway_request_id' => $data['gateway_request_id'] ?? null,
            'status_url' => $data['status_url'] ?? null,
            'response_url' => $data['response_url'] ?? null,
            'cancel_url' => $data['cancel_url'] ?? null,
            'queue_position' => $data['queue_position'] ?? null,
            'operation' => $operation,
        ];
    }

    public function prepareVideoOperation(AIRequest $request): array
    {
        $parameters = $request->getParameters();
        $characterSources = $this->normalizeCharacterSources($parameters['character_sources'] ?? []);
        $referenceImageUrls = $this->normalizeStringList($parameters['reference_image_urls'] ?? []);
        $referenceVideoUrls = array_values(array_unique(array_merge(
            $this->normalizeStringList($parameters['reference_video_urls'] ?? []),
            $this->normalizeStringList($parameters['video_urls'] ?? [])
        )));
        $referenceAudioUrls = array_values(array_unique(array_merge(
            $this->normalizeStringList($parameters['reference_audio_urls'] ?? []),
            $this->normalizeStringList($parameters['audio_urls'] ?? [])
        )));
        $multiPrompt = $this->normalizeMultiPrompt($parameters['multi_prompt'] ?? []);
        $requestedModel = $request->getModel()->value;

        if ($requestedModel === EntityEnum::KLING_VIDEO) {
            $requestedModel = ($characterSources !== [] || $referenceImageUrls !== [])
                ? EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO
                : EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO;
        }

        if ($requestedModel === EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO) {
            if ($characterSources === [] && $referenceImageUrls === []) {
                throw new AIEngineException('Kling reference-to-video requires reference_image_urls or character_sources');
            }

            $payload = [
                'prompt' => $this->augmentPromptWithCharacterReferences($request->getPrompt(), $characterSources, $referenceImageUrls),
                'duration' => (string) ($parameters['duration'] ?? '5'),
                'aspect_ratio' => $parameters['aspect_ratio'] ?? '16:9',
                'generate_audio' => $parameters['generate_audio'] ?? true,
                'image_urls' => $referenceImageUrls,
                'elements' => $this->buildKlingElements($characterSources),
            ];

            if (!empty($parameters['start_image_url'])) {
                $payload['start_image_url'] = $parameters['start_image_url'];
            }
            if (!empty($parameters['end_image_url'])) {
                $payload['end_image_url'] = $parameters['end_image_url'];
            }
            if ($multiPrompt !== []) {
                $payload['multi_prompt'] = $multiPrompt;
                $payload['shot_type'] = $parameters['shot_type'] ?? 'customize';
                unset($payload['prompt']);
            }

            return [
                'endpoint' => $this->resolveEndpointForModel($requestedModel),
                'payload' => $payload,
                'resolved_model' => $requestedModel,
            ];
        }

        if ($requestedModel === EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO) {
            $imageUrl = $parameters['start_image_url'] ?? $parameters['image_url'] ?? null;
            if (!is_string($imageUrl) || trim($imageUrl) === '') {
                throw new AIEngineException('Kling image-to-video requires start_image_url');
            }

            $payload = [
                'image_url' => $imageUrl,
                'duration' => (string) ($parameters['duration'] ?? '5'),
                'generate_audio' => $parameters['generate_audio'] ?? true,
            ];

            if (trim($request->getPrompt()) !== '') {
                $payload['prompt'] = $request->getPrompt();
            }
            if (!empty($parameters['end_image_url'])) {
                $payload['end_image_url'] = $parameters['end_image_url'];
            }
            if ($multiPrompt !== []) {
                $payload['multi_prompt'] = $multiPrompt;
                $payload['shot_type'] = $parameters['shot_type'] ?? 'customize';
                unset($payload['prompt']);
            }

            return [
                'endpoint' => $this->resolveEndpointForModel($requestedModel),
                'payload' => $payload,
                'resolved_model' => $requestedModel,
            ];
        }

        if ($requestedModel === EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO) {
            $payload = [
                'prompt' => $request->getPrompt(),
                'duration' => (string) ($parameters['duration'] ?? 'auto'),
                'aspect_ratio' => $parameters['aspect_ratio'] ?? '16:9',
                'resolution' => $parameters['resolution'] ?? '720p',
                'generate_audio' => $parameters['generate_audio'] ?? true,
            ];
            if (isset($parameters['seed'])) {
                $payload['seed'] = (int) $parameters['seed'];
            }

            return [
                'endpoint' => $this->resolveEndpointForModel($requestedModel),
                'payload' => $payload,
                'resolved_model' => $requestedModel,
            ];
        }

        if ($requestedModel === EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO) {
            $imageUrl = $parameters['start_image_url'] ?? $parameters['image_url'] ?? null;
            if (!is_string($imageUrl) || trim($imageUrl) === '') {
                throw new AIEngineException('Seedance image-to-video requires start_image_url');
            }

            $payload = [
                'prompt' => $request->getPrompt(),
                'image_url' => $imageUrl,
                'duration' => (string) ($parameters['duration'] ?? 'auto'),
                'aspect_ratio' => $parameters['aspect_ratio'] ?? 'auto',
                'resolution' => $parameters['resolution'] ?? '720p',
                'generate_audio' => $parameters['generate_audio'] ?? true,
            ];
            if (!empty($parameters['end_image_url'])) {
                $payload['end_image_url'] = $parameters['end_image_url'];
            }
            if (isset($parameters['seed'])) {
                $payload['seed'] = (int) $parameters['seed'];
            }

            return [
                'endpoint' => $this->resolveEndpointForModel($requestedModel),
                'payload' => $payload,
                'resolved_model' => $requestedModel,
            ];
        }

        if ($requestedModel === EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO) {
            $imageUrls = array_values(array_unique(array_merge(
                $referenceImageUrls,
                $this->collectCharacterSourceImages($characterSources)
            )));

            if ($imageUrls === [] && $referenceVideoUrls === []) {
                throw new AIEngineException('Seedance reference-to-video requires reference_image_urls, reference_video_urls, or character_sources');
            }

            $payload = [
                'prompt' => $this->augmentPromptWithSeedanceReferences($request->getPrompt(), $characterSources, $referenceImageUrls, $referenceVideoUrls, $referenceAudioUrls),
                'image_urls' => $imageUrls,
                'video_urls' => $referenceVideoUrls,
                'audio_urls' => $referenceAudioUrls,
                'duration' => (string) ($parameters['duration'] ?? 'auto'),
                'aspect_ratio' => $parameters['aspect_ratio'] ?? '16:9',
                'resolution' => $parameters['resolution'] ?? '720p',
                'generate_audio' => $parameters['generate_audio'] ?? true,
            ];
            if ($referenceAudioUrls === []) {
                unset($payload['audio_urls']);
            }
            if ($referenceVideoUrls === []) {
                unset($payload['video_urls']);
            }
            if ($imageUrls === []) {
                unset($payload['image_urls']);
            }
            if (isset($parameters['seed'])) {
                $payload['seed'] = (int) $parameters['seed'];
            }

            return [
                'endpoint' => $this->resolveEndpointForModel($requestedModel),
                'payload' => $payload,
                'resolved_model' => $requestedModel,
            ];
        }

        return [
            'endpoint' => $this->resolveEndpointForModel($requestedModel),
            'payload' => $this->buildLegacyVideoPayload($request),
            'resolved_model' => $requestedModel,
        ];
    }

    public function submitVideoAsync(AIRequest $request, ?string $webhookUrl = null): array
    {
        $operation = $this->prepareVideoOperation($request);
        $query = [];

        if (is_string($webhookUrl) && trim($webhookUrl) !== '') {
            $query['fal_webhook'] = trim($webhookUrl);
        }

        $data = $this->postToQueueEndpoint($operation['endpoint'], $operation['payload'], $query);

        return [
            'request_id' => $data['request_id'] ?? null,
            'gateway_request_id' => $data['gateway_request_id'] ?? null,
            'status_url' => $data['status_url'] ?? null,
            'response_url' => $data['response_url'] ?? null,
            'cancel_url' => $data['cancel_url'] ?? null,
            'queue_position' => $data['queue_position'] ?? null,
            'operation' => $operation,
        ];
    }

    public function getAsyncStatus(string $statusUrl, bool $withLogs = true): array
    {
        return $this->getJsonFromAbsoluteUrl($statusUrl, $withLogs ? ['logs' => 1] : []);
    }

    public function getAsyncResult(string $responseUrl): array
    {
        return $this->getJsonFromAbsoluteUrl($responseUrl);
    }

    public function buildImageResponseFromOperation(AIRequest $request, array $operation, array $data): AIResponse
    {
        $images = $this->normalizeImageResult($data);

        if ($images === []) {
            throw new AIEngineException('No images returned from FAL AI');
        }

        return AIResponse::success(
            json_encode($images, JSON_UNESCAPED_SLASHES),
            $request->getEngine(),
            $request->getModel()
        )->withFiles(array_values(array_filter(array_map(
            static fn (array $image): ?string => $image['url'] ?? $image['source_url'] ?? null,
            $images
        ))))->withDetailedUsage([
            'images_generated' => count($images),
            'total_cost' => count($images) * $request->getModel()->creditIndex(),
        ])->withMetadata([
            'engine' => EngineEnum::FAL_AI,
            'model' => $request->getModel()->value,
            'resolved_model' => $operation['resolved_model'] ?? $request->getModel()->value,
            'resolved_endpoint' => $operation['endpoint'] ?? $request->getModel()->value,
            'parameters' => $operation['payload'] ?? [],
            'images' => $images,
            'character_sources' => $this->normalizeCharacterSources($request->getParameters()['character_sources'] ?? []),
        ]);
    }

    public function buildVideoResponseFromOperation(AIRequest $request, array $operation, array $data): AIResponse
    {
        $video = $this->normalizeVideoResult($data);

        if ($video === null) {
            throw new AIEngineException('No video returned from FAL AI');
        }

        return AIResponse::success(
            json_encode($video, JSON_UNESCAPED_SLASHES),
            $request->getEngine(),
            $request->getModel()
        )->withFiles(array_values(array_filter([$video['url'] ?? $video['source_url'] ?? null])))
            ->withDetailedUsage([
                'videos_generated' => 1,
                'total_cost' => $request->getModel()->creditIndex(),
            ])->withMetadata([
                'engine' => EngineEnum::FAL_AI,
                'model' => $request->getModel()->value,
                'resolved_model' => $operation['resolved_model'] ?? $request->getModel()->value,
                'resolved_endpoint' => $operation['endpoint'] ?? $request->getModel()->value,
                'parameters' => $operation['payload'] ?? [],
                'video' => $video,
                'character_sources' => $this->normalizeCharacterSources($request->getParameters()['character_sources'] ?? []),
            ]);
    }

    private function resolveEndpointForModel(string $model): string
    {
        return match ($model) {
            EntityEnum::FAL_FLUX_PRO => 'fal-ai/flux-pro',
            EntityEnum::FAL_FLUX_DEV => 'fal-ai/flux/dev',
            EntityEnum::FAL_FLUX_SCHNELL => 'fal-ai/flux/schnell',
            EntityEnum::FAL_SDXL => 'fal-ai/stable-diffusion-xl',
            EntityEnum::FAL_SD3_MEDIUM => 'fal-ai/stable-diffusion-v3-medium',
            EntityEnum::FAL_STABLE_VIDEO => 'fal-ai/stable-video-diffusion',
            EntityEnum::FAL_ANIMATEDIFF => 'fal-ai/animatediff-lightning',
            EntityEnum::FAL_LUMA_DREAM => 'fal-ai/luma-ai/dream-machine',
            EntityEnum::KLING_VIDEO => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            EntityEnum::LUMA_DREAM_MACHINE => 'fal-ai/luma-ai/dream-machine',
            default => $model,
        };
    }

    private function isDriverManagedModel(string $model): bool
    {
        return isset($this->getAvailableModels()[$model]) || $this->isLegacyAlias($model);
    }

    private function isCatalogModel(string $model): bool
    {
        return app(AIModelRepository::class)->findActiveByProviderAndModel('fal_ai', $model) !== null
            || app(AIModelRepository::class)->findActiveByProviderAndModel('fal', $model) !== null;
    }

    private function postToEndpoint(string $endpoint, array $payload): array
    {
        $response = $this->httpClient->post(ltrim($endpoint, '/'), [
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function postToQueueEndpoint(string $endpoint, array $payload, array $query = []): array
    {
        $response = $this->queueHttpClient->post(ltrim($endpoint, '/'), [
            'query' => $query,
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function getJsonFromAbsoluteUrl(string $url, array $query = []): array
    {
        $response = $this->queueHttpClient->request('GET', $url, [
            'query' => $query,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function normalizeImageResult(array $data): array
    {
        $images = $data['images'] ?? [];
        if (isset($data['image']['url'])) {
            $images[] = $data['image'];
        }

        $normalized = [];
        foreach ($images as $imageData) {
            if (!isset($imageData['url'])) {
                continue;
            }

            $stored = $this->saveRemoteFile($imageData['url'], 'images', [
                'engine' => EngineEnum::FAL_AI,
                'ai_model' => $data['model'] ?? null,
                'content_type' => 'image',
                'collection_name' => 'generated-images',
                'width' => $imageData['width'] ?? null,
                'height' => $imageData['height'] ?? null,
                'mime_type' => $imageData['content_type'] ?? null,
            ]);

            $normalized[] = [
                'media_id' => $stored['id'] ?? null,
                'url' => $stored['url'] ?? $imageData['url'],
                'source_url' => $imageData['url'],
                'filename' => $stored['file_name'] ?? null,
                'path' => $stored['path'] ?? null,
                'disk' => $stored['disk'] ?? null,
                'width' => $imageData['width'] ?? null,
                'height' => $imageData['height'] ?? null,
                'content_type' => $imageData['content_type'] ?? null,
            ];
        }

        return $normalized;
    }

    private function normalizeVideoResult(array $data): ?array
    {
        $videoData = $data['video'] ?? null;
        if (!is_array($videoData) && is_string($data['output'] ?? null) && trim((string) $data['output']) !== '') {
            $videoData = [
                'url' => trim((string) $data['output']),
                'duration' => $data['duration'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
            ];
        }

        if (!is_array($videoData) || !isset($videoData['url'])) {
            return null;
        }

        $stored = $this->saveRemoteFile($videoData['url'], 'videos', [
            'engine' => EngineEnum::FAL_AI,
            'ai_model' => $data['model'] ?? null,
            'content_type' => 'video',
            'collection_name' => 'generated-videos',
            'mime_type' => $videoData['content_type'] ?? 'video/mp4',
            'duration' => $data['duration'] ?? $videoData['duration'] ?? null,
        ]);

        return [
            'media_id' => $stored['id'] ?? null,
            'url' => $stored['url'] ?? $videoData['url'],
            'source_url' => $videoData['url'],
            'filename' => $stored['file_name'] ?? null,
            'path' => $stored['path'] ?? null,
            'disk' => $stored['disk'] ?? null,
            'duration' => $data['duration'] ?? $videoData['duration'] ?? null,
            'resolution' => $data['resolution'] ?? $videoData['resolution'] ?? null,
            'width' => $data['width'] ?? $videoData['width'] ?? null,
            'height' => $data['height'] ?? $videoData['height'] ?? null,
            'fps' => $data['fps'] ?? $videoData['fps'] ?? null,
            'seed' => $data['seed'] ?? null,
            'content_type' => $videoData['content_type'] ?? 'video/mp4',
        ];
    }

    private function saveRemoteFile(string $url, string $type, array $attributes = []): array
    {
        try {
            $extension = $type === 'videos' ? 'mp4' : 'png';
            $fileName = (string) Str::uuid() . ".{$extension}";

            return app(AIMediaManager::class)->storeRemoteFile($url, array_merge($attributes, [
                'file_name' => $fileName,
            ]));
        } catch (\Throwable) {
            return [
                'id' => null,
                'uuid' => null,
                'disk' => null,
                'path' => null,
                'url' => $url,
                'file_name' => null,
                'mime_type' => $attributes['mime_type'] ?? null,
                'size' => null,
                'source_url' => $url,
            ];
        }
    }

    private function normalizeCharacterSources(array $characterSources): array
    {
        $normalized = array_map(function ($source) {
            if (!is_array($source)) {
                return null;
            }

            $frontalImageUrl = isset($source['frontal_image_url']) && is_string($source['frontal_image_url'])
                ? trim($source['frontal_image_url'])
                : null;
            $referenceImageUrls = $this->normalizeStringList($source['reference_image_urls'] ?? []);

            return array_filter([
                'name' => isset($source['name']) ? trim((string) $source['name']) : null,
                'description' => isset($source['description']) ? trim((string) $source['description']) : null,
                'frontal_image_url' => $frontalImageUrl !== '' ? $frontalImageUrl : null,
                'reference_image_urls' => $referenceImageUrls,
                'metadata' => is_array($source['metadata'] ?? null) ? $source['metadata'] : [],
            ], static fn ($value): bool => $value !== null);
        }, $characterSources);

        return array_values(array_filter($normalized));
    }

    private function normalizeStringList(array $values): array
    {
        return array_values(array_filter(array_map(static function ($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }, $values)));
    }

    private function normalizeMultiPrompt(array $multiPrompt): array
    {
        return array_values(array_filter(array_map(static function ($shot): ?array {
            if (!is_array($shot)) {
                return null;
            }

            $prompt = trim((string) ($shot['prompt'] ?? ''));
            if ($prompt === '') {
                return null;
            }

            $normalized = ['prompt' => $prompt];
            if (isset($shot['duration']) && $shot['duration'] !== '') {
                $normalized['duration'] = (string) $shot['duration'];
            }

            return $normalized;
        }, $multiPrompt)));
    }

    private function buildKlingElements(array $characterSources): array
    {
        $elements = [];

        foreach ($characterSources as $source) {
            if (empty($source['frontal_image_url'])) {
                continue;
            }

            $element = [
                'frontal_image_url' => $source['frontal_image_url'],
            ];

            if (!empty($source['reference_image_urls'])) {
                $element['reference_image_urls'] = $source['reference_image_urls'];
            }

            $elements[] = $element;
        }

        return $elements;
    }

    private function buildLegacyVideoPayload(AIRequest $request): array
    {
        $parameters = $request->getParameters();

        $payload = [
            'prompt' => $request->getPrompt(),
            'duration' => $parameters['duration'] ?? 5,
            'fps' => $parameters['fps'] ?? 24,
            'resolution' => $parameters['resolution'] ?? '1280x720',
            'motion_scale' => $parameters['motion_scale'] ?? 1.0,
        ];

        if (!empty($parameters['image_url'])) {
            $payload['image_url'] = $parameters['image_url'];
        }

        if (isset($parameters['seed'])) {
            $payload['seed'] = (int) $parameters['seed'];
        }

        return $payload;
    }

    private function augmentPromptWithCharacterSources(string $prompt, array $characterSources): string
    {
        if ($characterSources === []) {
            return $prompt;
        }

        $lines = [$prompt, '', 'Character source context:'];
        foreach ($characterSources as $index => $source) {
            $name = $source['name'] ?? 'Character ' . ($index + 1);
            $description = $source['description'] ?? 'No description provided.';
            $metadata = $source['metadata'] ?? [];
            $lines[] = '- ' . $name . ': ' . $description . ($metadata !== [] ? ' Metadata: ' . json_encode($metadata, JSON_UNESCAPED_SLASHES) : '');
        }

        return trim(implode("\n", $lines));
    }

    private function augmentPromptWithCharacterReferences(string $prompt, array $characterSources, array $referenceImageUrls): string
    {
        $segments = [];
        if (trim($prompt) !== '') {
            $segments[] = trim($prompt);
        }

        foreach (array_values($characterSources) as $index => $source) {
            $label = '@Element' . ($index + 1);
            $name = $source['name'] ?? ('character ' . ($index + 1));
            $description = $source['description'] ?? null;
            $segments[] = "{$label} is {$name}" . ($description ? " ({$description})" : '');
        }

        foreach (array_values($referenceImageUrls) as $index => $imageUrl) {
            $segments[] = '@Image' . ($index + 1) . ' is a reference scene/style image';
        }

        return trim(implode(". ", array_filter($segments)));
    }

    private function augmentPromptWithSeedanceReferences(
        string $prompt,
        array $characterSources,
        array $referenceImageUrls,
        array $referenceVideoUrls,
        array $referenceAudioUrls
    ): string {
        $segments = [];
        if (trim($prompt) !== '') {
            $segments[] = trim($prompt);
        }

        foreach (array_values($characterSources) as $index => $source) {
            $name = $source['name'] ?? ('character ' . ($index + 1));
            $description = $source['description'] ?? null;
            $segments[] = '@Image' . ($index + 1) . ' shows ' . $name . ($description ? " ({$description})" : '');
        }

        $imageOffset = count($characterSources);
        foreach (array_values($referenceImageUrls) as $index => $imageUrl) {
            $segments[] = '@Image' . ($imageOffset + $index + 1) . ' is a visual reference';
        }

        foreach (array_values($referenceVideoUrls) as $index => $videoUrl) {
            $segments[] = '@Video' . ($index + 1) . ' is the motion or animation reference';
        }

        foreach (array_values($referenceAudioUrls) as $index => $audioUrl) {
            $segments[] = '@Audio' . ($index + 1) . ' is the audio reference';
        }

        return trim(implode(". ", array_filter($segments)));
    }

    private function collectCharacterSourceImages(array $characterSources): array
    {
        $images = [];

        foreach ($characterSources as $source) {
            if (!empty($source['frontal_image_url'])) {
                $images[] = $source['frontal_image_url'];
            }

            foreach ($source['reference_image_urls'] ?? [] as $referenceImageUrl) {
                $images[] = $referenceImageUrl;
            }
        }

        return $images;
    }

    private function isLegacyAlias(string $model): bool
    {
        return in_array($model, [
            EntityEnum::FLUX_PRO,
            EntityEnum::KLING_VIDEO,
            EntityEnum::LUMA_DREAM_MACHINE,
        ], true);
    }

    private function isLegacyTextToVideoModel(string $model): bool
    {
        return in_array($model, [
            EntityEnum::FAL_STABLE_VIDEO,
            EntityEnum::FAL_ANIMATEDIFF,
            EntityEnum::FAL_LUMA_DREAM,
            EntityEnum::KLING_VIDEO,
            EntityEnum::LUMA_DREAM_MACHINE,
        ], true);
    }
}
