<?php

namespace LaravelAIEngine\Drivers\FalAI;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FalAIEngineDriver implements EngineDriverInterface
{
    private Client $client;
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('ai-engine.engines.fal_ai.api_key');
        $this->baseUrl = config('ai-engine.engines.fal_ai.base_url', 'https://fal.run/fal-ai');
        
        if (empty($this->apiKey)) {
            throw new AIEngineException('FAL AI API key is required');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('ai-engine.engines.fal_ai.timeout', 120),
            'headers' => [
                'Authorization' => 'Key ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        try {
            $entity = $request->entity;
            
            switch ($entity->getContentType()) {
                case 'image':
                    return $this->generateImage($request);
                case 'video':
                    return $this->generateVideo($request);
                default:
                    throw new AIEngineException("Content type {$entity->getContentType()} not supported by FAL AI");
            }
        } catch (RequestException $e) {
            throw new AIEngineException('FAL AI API request failed: ' . $e->getMessage());
        }
    }

    private function generateImage(AIRequest $request): AIResponse
    {
        $payload = [
            'prompt' => $request->prompt,
            'image_size' => $request->parameters['image_size'] ?? '1024x1024',
            'num_inference_steps' => $request->parameters['steps'] ?? 50,
            'guidance_scale' => $request->parameters['guidance_scale'] ?? 7.5,
            'num_images' => $request->parameters['num_images'] ?? 1,
            'enable_safety_checker' => $request->parameters['safety_checker'] ?? true,
        ];

        // Add negative prompt if provided
        if (!empty($request->parameters['negative_prompt'])) {
            $payload['negative_prompt'] = $request->parameters['negative_prompt'];
        }

        // Add seed for reproducibility
        if (!empty($request->parameters['seed'])) {
            $payload['seed'] = $request->parameters['seed'];
        }

        $endpoint = $this->getImageEndpoint($request->entity);
        $response = $this->client->post($endpoint, [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($data['images']) || empty($data['images'])) {
            throw new AIEngineException('No images returned from FAL AI');
        }

        $images = [];
        foreach ($data['images'] as $imageData) {
            $imageUrl = $imageData['url'];
            $filename = $this->saveImageFromUrl($imageUrl);
            
            $images[] = [
                'url' => $imageUrl,
                'filename' => $filename,
                'path' => Storage::url($filename),
                'width' => $imageData['width'] ?? null,
                'height' => $imageData['height'] ?? null,
            ];
        }

        return new AIResponse(
            content: json_encode($images),
            usage: [
                'images_generated' => count($images),
                'total_cost' => count($images) * $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::FAL_AI->value,
                'parameters' => $payload,
                'images' => $images,
            ]
        );
    }

    private function generateVideo(AIRequest $request): AIResponse
    {
        $payload = [
            'prompt' => $request->prompt,
            'duration' => $request->parameters['duration'] ?? 5,
            'fps' => $request->parameters['fps'] ?? 24,
            'resolution' => $request->parameters['resolution'] ?? '1280x720',
            'motion_scale' => $request->parameters['motion_scale'] ?? 1.0,
        ];

        // Add image input for image-to-video
        if (!empty($request->parameters['image_url'])) {
            $payload['image_url'] = $request->parameters['image_url'];
        }

        // Add seed for reproducibility
        if (!empty($request->parameters['seed'])) {
            $payload['seed'] = $request->parameters['seed'];
        }

        $endpoint = $this->getVideoEndpoint($request->entity);
        $response = $this->client->post($endpoint, [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($data['video']) || empty($data['video']['url'])) {
            throw new AIEngineException('No video returned from FAL AI');
        }

        $videoUrl = $data['video']['url'];
        $filename = $this->saveVideoFromUrl($videoUrl);
        
        $videoData = [
            'url' => $videoUrl,
            'filename' => $filename,
            'path' => Storage::url($filename),
            'duration' => $data['video']['duration'] ?? $payload['duration'],
            'fps' => $data['video']['fps'] ?? $payload['fps'],
            'resolution' => $data['video']['resolution'] ?? $payload['resolution'],
        ];

        return new AIResponse(
            content: json_encode($videoData),
            usage: [
                'videos_generated' => 1,
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::FAL_AI->value,
                'parameters' => $payload,
                'video' => $videoData,
            ]
        );
    }

    private function getImageEndpoint(EntityEnum $entity): string
    {
        return match ($entity) {
            EntityEnum::FAL_FLUX_PRO => '/flux-pro',
            EntityEnum::FAL_FLUX_DEV => '/flux/dev',
            EntityEnum::FAL_FLUX_SCHNELL => '/flux/schnell',
            EntityEnum::FAL_SDXL => '/stable-diffusion-xl',
            EntityEnum::FAL_SD3_MEDIUM => '/stable-diffusion-v3-medium',
            default => '/flux/dev', // Default fallback
        };
    }

    private function getVideoEndpoint(EntityEnum $entity): string
    {
        return match ($entity) {
            EntityEnum::FAL_STABLE_VIDEO => '/stable-video-diffusion',
            EntityEnum::FAL_ANIMATEDIFF => '/animatediff-lightning',
            EntityEnum::FAL_LUMA_DREAM => '/luma-ai/dream-machine',
            default => '/stable-video-diffusion', // Default fallback
        };
    }

    private function saveImageFromUrl(string $url): string
    {
        $imageContent = file_get_contents($url);
        if ($imageContent === false) {
            throw new AIEngineException('Failed to download image from FAL AI');
        }

        $filename = 'ai-generated/fal-ai/images/' . Str::uuid() . '.png';
        Storage::put($filename, $imageContent);
        
        return $filename;
    }

    private function saveVideoFromUrl(string $url): string
    {
        $videoContent = file_get_contents($url);
        if ($videoContent === false) {
            throw new AIEngineException('Failed to download video from FAL AI');
        }

        $filename = 'ai-generated/fal-ai/videos/' . Str::uuid() . '.mp4';
        Storage::put($filename, $videoContent);
        
        return $filename;
    }

    public function stream(AIRequest $request): \Generator
    {
        // FAL AI doesn't support streaming for image/video generation
        // Return the full response as a single chunk
        $response = $this->generate($request);
        yield $response->content;
    }

    public function getAvailableModels(): array
    {
        return [
            // Image Generation Models
            EntityEnum::FAL_FLUX_PRO->value => [
                'name' => 'FLUX.1 Pro',
                'type' => 'image',
                'description' => 'State-of-the-art image generation model',
                'max_resolution' => '2048x2048',
            ],
            EntityEnum::FAL_FLUX_DEV->value => [
                'name' => 'FLUX.1 Dev',
                'type' => 'image',
                'description' => 'High-quality image generation for development',
                'max_resolution' => '1024x1024',
            ],
            EntityEnum::FAL_FLUX_SCHNELL->value => [
                'name' => 'FLUX.1 Schnell',
                'type' => 'image',
                'description' => 'Fast image generation model',
                'max_resolution' => '1024x1024',
            ],
            EntityEnum::FAL_SDXL->value => [
                'name' => 'Stable Diffusion XL',
                'type' => 'image',
                'description' => 'High-resolution image generation',
                'max_resolution' => '1024x1024',
            ],
            EntityEnum::FAL_SD3_MEDIUM->value => [
                'name' => 'Stable Diffusion 3 Medium',
                'type' => 'image',
                'description' => 'Latest Stable Diffusion model',
                'max_resolution' => '1024x1024',
            ],
            
            // Video Generation Models
            EntityEnum::FAL_STABLE_VIDEO->value => [
                'name' => 'Stable Video Diffusion',
                'type' => 'video',
                'description' => 'Image-to-video generation',
                'max_duration' => 10,
            ],
            EntityEnum::FAL_ANIMATEDIFF->value => [
                'name' => 'AnimateDiff Lightning',
                'type' => 'video',
                'description' => 'Fast video generation',
                'max_duration' => 5,
            ],
            EntityEnum::FAL_LUMA_DREAM->value => [
                'name' => 'Luma AI Dream Machine',
                'type' => 'video',
                'description' => 'Advanced video generation',
                'max_duration' => 10,
            ],
        ];
    }

    public function validateRequest(AIRequest $request): bool
    {
        $entity = $request->entity;
        
        // Check if model is supported
        if (!in_array($entity, [
            EntityEnum::FAL_FLUX_PRO,
            EntityEnum::FAL_FLUX_DEV,
            EntityEnum::FAL_FLUX_SCHNELL,
            EntityEnum::FAL_SDXL,
            EntityEnum::FAL_SD3_MEDIUM,
            EntityEnum::FAL_STABLE_VIDEO,
            EntityEnum::FAL_ANIMATEDIFF,
            EntityEnum::FAL_LUMA_DREAM,
        ])) {
            throw new AIEngineException("Model {$entity->value} is not supported by FAL AI driver");
        }

        // Validate prompt
        if (empty($request->prompt)) {
            throw new AIEngineException('Prompt is required for FAL AI generation');
        }

        // Validate content type
        if (!in_array($entity->getContentType(), ['image', 'video'])) {
            throw new AIEngineException("Content type {$entity->getContentType()} not supported by FAL AI");
        }

        return true;
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::FAL_AI;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, ['image', 'video']);
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            $testRequest = new AIRequest(
                prompt: 'A simple test image',
                engine: EngineEnum::FAL_AI,
                model: EntityEnum::FAL_FLUX_PRO
            );
            
            $response = $this->generate($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }
}
