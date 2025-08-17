<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\StableDiffusion;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class StableDiffusionEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->httpClient = new Client([
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
        $contentType = $request->model->getContentType();
        
        return match ($contentType) {
            'image' => $this->generateImage($request),
            'video' => $this->generateVideo($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        throw new \InvalidArgumentException('Streaming not supported by Stable Diffusion');
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($this->getApiKey())) {
            return false;
        }
        
        if (!$this->supports($request->model->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::STABLE_DIFFUSION;
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
            $testRequest = new AIRequest(
                prompt: 'A simple test image',
                engine: EngineEnum::STABLE_DIFFUSION,
                model: EntityEnum::STABLE_DIFFUSION_3_MEDIUM
            );
            
            $response = $this->generateImage($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content (not supported)
     */
    public function generateText(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Text generation not supported by Stable Diffusion',
            $request->engine,
            $request->model
        );
    }

    /**
     * Generate images
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        try {
            $imageCount = $request->parameters['image_count'] ?? 1;
            $width = $request->parameters['width'] ?? 1024;
            $height = $request->parameters['height'] ?? 1024;
            $steps = $request->parameters['steps'] ?? 30;
            $cfgScale = $request->parameters['cfg_scale'] ?? 7.0;
            $seed = $request->seed ?? rand(0, 4294967295);

            $payload = [
                'text_prompts' => [
                    ['text' => $request->prompt, 'weight' => 1.0]
                ],
                'cfg_scale' => $cfgScale,
                'height' => $height,
                'width' => $width,
                'samples' => $imageCount,
                'steps' => $steps,
                'seed' => $seed,
            ];

            // Add negative prompt if provided
            if (isset($request->parameters['negative_prompt'])) {
                $payload['text_prompts'][] = [
                    'text' => $request->parameters['negative_prompt'],
                    'weight' => -1.0
                ];
            }

            $endpoint = $this->getGenerationEndpoint($request->model);
            $response = $this->httpClient->post($endpoint, [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $imageUrls = [];
            foreach ($data['artifacts'] ?? [] as $artifact) {
                if (isset($artifact['base64'])) {
                    // Save base64 image and return URL
                    $imageUrls[] = $this->saveBase64Image($artifact['base64']);
                }
            }

            return AIResponse::success(
                $request->prompt,
                $request->engine,
                $request->model
            )->withFiles($imageUrls)
             ->withUsage(
                 creditsUsed: $imageCount * $request->model->creditIndex()
             )->withDetailedUsage([
                 'seed' => $seed,
                 'steps' => $steps,
                 'cfg_scale' => $cfgScale,
                 'dimensions' => "{$width}x{$height}",
             ]);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Stable Diffusion API error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        } catch (\Exception $e) {
            return AIResponse::error(
                'Unexpected error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Generate video content
     */
    public function generateVideo(AIRequest $request): AIResponse
    {
        try {
            $videoCount = $request->parameters['video_count'] ?? 1;
            $motionBucketId = $request->parameters['motion_bucket_id'] ?? 127;
            $cfgScale = $request->parameters['cfg_scale'] ?? 1.8;
            $seed = $request->seed ?? rand(0, 4294967295);

            // For image-to-video, we need an input image
            $initImage = $request->files[0] ?? null;
            if (!$initImage && !isset($request->parameters['init_image_url'])) {
                throw new \InvalidArgumentException('Input image is required for video generation');
            }

            $payload = [
                'seed' => $seed,
                'cfg_scale' => $cfgScale,
                'motion_bucket_id' => $motionBucketId,
            ];

            // Add image data
            if ($initImage) {
                $payload['image'] = base64_encode(file_get_contents($initImage));
            } elseif (isset($request->parameters['init_image_url'])) {
                $payload['image'] = base64_encode(file_get_contents($request->parameters['init_image_url']));
            }

            $response = $this->httpClient->post('/v2beta/image-to-video', [
                'multipart' => $this->buildMultipartPayload($payload),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // Video generation is typically async, return job ID
            return AIResponse::success(
                'Video generation started',
                $request->engine,
                $request->model
            )->withRequestId($data['id'] ?? null)
             ->withUsage(
                 creditsUsed: $videoCount * $request->model->creditIndex()
             )->withDetailedUsage([
                 'job_id' => $data['id'] ?? null,
                 'status' => 'processing',
                 'motion_bucket_id' => $motionBucketId,
             ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Stable Diffusion video error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->httpClient->get('/v1/engines/list');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($engine) {
                return [
                    'id' => $engine['id'],
                    'name' => $engine['name'],
                    'description' => $engine['description'] ?? '',
                    'type' => $engine['type'] ?? 'image',
                ];
            }, $data['engines'] ?? []);

        } catch (\Exception $e) {
            return [
                ['id' => 'stable-diffusion-xl-1024-v1-0', 'name' => 'Stable Diffusion XL 1.0'],
                ['id' => 'stable-diffusion-v1-6', 'name' => 'Stable Diffusion 1.6'],
                ['id' => 'sd3-large', 'name' => 'Stable Diffusion 3 Large'],
                ['id' => 'sd3-medium', 'name' => 'Stable Diffusion 3 Medium'],
            ];
        }
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['images', 'video'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::STABLE_DIFFUSION;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::SD3_LARGE;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('Stability AI API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'Accept' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Get generation endpoint based on model
     */
    private function getGenerationEndpoint(EntityEnum $model): string
    {
        return match ($model) {
            EntityEnum::SD3_LARGE, EntityEnum::SD3_MEDIUM => '/v2beta/stable-image/generate/sd3',
            EntityEnum::SDXL_1024 => '/v1/generation/stable-diffusion-xl-1024-v1-0/text-to-image',
            default => '/v1/generation/' . $model->value . '/text-to-image',
        };
    }

    /**
     * Save base64 image to storage
     */
    private function saveBase64Image(string $base64Data): string
    {
        $imageData = base64_decode($base64Data);
        $filename = 'ai_generated_' . uniqid() . '.png';
        
        // This would integrate with Laravel's storage system
        $path = storage_path('app/public/ai-images/' . $filename);
        
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, $imageData);
        
        return url('storage/ai-images/' . $filename);
    }

    /**
     * Build multipart payload for file uploads
     */
    private function buildMultipartPayload(array $data): array
    {
        $multipart = [];
        
        foreach ($data as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => $value,
            ];
        }
        
        return $multipart;
    }
}
