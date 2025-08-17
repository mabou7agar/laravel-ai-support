<?php

namespace LaravelAIEngine\Drivers\Midjourney;

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

class MidjourneyEngineDriver implements EngineDriverInterface
{
    private Client $client;
    private string $apiKey;
    private string $baseUrl;
    private string $discordToken;
    private string $serverId;
    private string $channelId;

    public function __construct()
    {
        $this->apiKey = config('ai-engine.engines.midjourney.api_key');
        $this->baseUrl = config('ai-engine.engines.midjourney.base_url', 'https://api.midjourney.com');
        $this->discordToken = config('ai-engine.engines.midjourney.discord_token');
        $this->serverId = config('ai-engine.engines.midjourney.server_id');
        $this->channelId = config('ai-engine.engines.midjourney.channel_id');
        
        if (empty($this->apiKey) || empty($this->discordToken)) {
            throw new AIEngineException('Midjourney API key and Discord token are required');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('ai-engine.engines.midjourney.timeout', 300), // 5 minutes for image generation
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        try {
            switch ($request->entity) {
                case EntityEnum::MIDJOURNEY_V6:
                    return $this->generateImage($request, 'v6');
                case EntityEnum::MIDJOURNEY_V5:
                    return $this->generateImage($request, 'v5');
                case EntityEnum::MIDJOURNEY_NIJI:
                    return $this->generateImage($request, 'niji');
                default:
                    throw new AIEngineException("Entity {$request->entity->value} not supported by Midjourney driver");
            }
        } catch (RequestException $e) {
            throw new AIEngineException('Midjourney API request failed: ' . $e->getMessage());
        }
    }

    private function generateImage(AIRequest $request, string $version): AIResponse
    {
        // Step 1: Submit the imagine request
        $jobId = $this->submitImagineRequest($request, $version);
        
        // Step 2: Poll for completion
        $result = $this->pollForCompletion($jobId);
        
        // Step 3: Process and save images
        $images = $this->processImages($result);

        return new AIResponse(
            content: json_encode($images),
            usage: [
                'images_generated' => count($images),
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::MIDJOURNEY->value,
                'version' => $version,
                'job_id' => $jobId,
                'images' => $images,
                'generation_time' => $result['generation_time'] ?? null,
            ]
        );
    }

    private function submitImagineRequest(AIRequest $request, string $version): string
    {
        $prompt = $this->buildPrompt($request, $version);
        
        $payload = [
            'type' => 'imagine',
            'prompt' => $prompt,
            'discord_token' => $this->discordToken,
            'server_id' => $this->serverId,
            'channel_id' => $this->channelId,
            'callback_url' => $request->parameters['callback_url'] ?? null,
        ];

        $response = $this->client->post('/v1/imagine', [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($data['job_id'])) {
            throw new AIEngineException('Failed to submit Midjourney request');
        }

        return $data['job_id'];
    }

    private function buildPrompt(AIRequest $request, string $version): string
    {
        $prompt = $request->prompt;
        
        // Add version parameter
        if ($version === 'v6') {
            $prompt .= ' --v 6';
        } elseif ($version === 'v5') {
            $prompt .= ' --v 5';
        } elseif ($version === 'niji') {
            $prompt .= ' --niji';
        }

        // Add aspect ratio if specified
        if (!empty($request->parameters['aspect_ratio'])) {
            $prompt .= ' --ar ' . $request->parameters['aspect_ratio'];
        }

        // Add quality setting
        if (!empty($request->parameters['quality'])) {
            $prompt .= ' --q ' . $request->parameters['quality'];
        }

        // Add stylize parameter
        if (!empty($request->parameters['stylize'])) {
            $prompt .= ' --s ' . $request->parameters['stylize'];
        }

        // Add chaos parameter
        if (!empty($request->parameters['chaos'])) {
            $prompt .= ' --c ' . $request->parameters['chaos'];
        }

        // Add seed for reproducibility
        if (!empty($request->parameters['seed'])) {
            $prompt .= ' --seed ' . $request->parameters['seed'];
        }

        // Add style reference
        if (!empty($request->parameters['style_ref'])) {
            $prompt .= ' --sref ' . $request->parameters['style_ref'];
        }

        return $prompt;
    }

    private function pollForCompletion(string $jobId): array
    {
        $maxAttempts = 60; // 5 minutes with 5-second intervals
        $attempt = 0;
        $startTime = time();

        while ($attempt < $maxAttempts) {
            $response = $this->client->get("/v1/jobs/{$jobId}");
            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['status'] === 'completed') {
                $data['generation_time'] = time() - $startTime;
                return $data;
            }

            if ($data['status'] === 'failed') {
                throw new AIEngineException('Midjourney generation failed: ' . ($data['error'] ?? 'Unknown error'));
            }

            sleep(5);
            $attempt++;
        }

        throw new AIEngineException('Midjourney generation timed out');
    }

    private function processImages(array $result): array
    {
        $images = [];
        
        if (!isset($result['images']) || empty($result['images'])) {
            throw new AIEngineException('No images returned from Midjourney');
        }

        foreach ($result['images'] as $index => $imageData) {
            $imageUrl = $imageData['url'];
            $filename = $this->saveImageFromUrl($imageUrl, $index);
            
            $images[] = [
                'url' => $imageUrl,
                'filename' => $filename,
                'path' => Storage::url($filename),
                'index' => $index + 1,
                'width' => $imageData['width'] ?? 1024,
                'height' => $imageData['height'] ?? 1024,
                'hash' => $imageData['hash'] ?? null,
            ];
        }

        return $images;
    }

    private function saveImageFromUrl(string $url, int $index): string
    {
        $imageContent = file_get_contents($url);
        if ($imageContent === false) {
            throw new AIEngineException('Failed to download image from Midjourney');
        }

        $filename = 'ai-generated/midjourney/images/' . Str::uuid() . '_' . ($index + 1) . '.png';
        Storage::put($filename, $imageContent);
        
        return $filename;
    }

    public function upscale(string $jobId, int $imageIndex): AIResponse
    {
        $payload = [
            'type' => 'upscale',
            'job_id' => $jobId,
            'index' => $imageIndex,
            'discord_token' => $this->discordToken,
            'server_id' => $this->serverId,
            'channel_id' => $this->channelId,
        ];

        $response = $this->client->post('/v1/upscale', [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $upscaleJobId = $data['job_id'];
        
        // Poll for upscale completion
        $result = $this->pollForCompletion($upscaleJobId);
        $images = $this->processImages($result);

        return new AIResponse(
            content: json_encode($images),
            usage: [
                'images_upscaled' => count($images),
                'total_cost' => 1.0, // Upscale cost
            ],
            metadata: [
                'type' => 'upscale',
                'original_job_id' => $jobId,
                'upscale_job_id' => $upscaleJobId,
                'image_index' => $imageIndex,
                'images' => $images,
            ]
        );
    }

    public function vary(string $jobId, int $imageIndex, string $variationType = 'strong'): AIResponse
    {
        $payload = [
            'type' => 'vary',
            'job_id' => $jobId,
            'index' => $imageIndex,
            'variation_type' => $variationType, // 'strong', 'subtle'
            'discord_token' => $this->discordToken,
            'server_id' => $this->serverId,
            'channel_id' => $this->channelId,
        ];

        $response = $this->client->post('/v1/vary', [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $varyJobId = $data['job_id'];
        
        // Poll for variation completion
        $result = $this->pollForCompletion($varyJobId);
        $images = $this->processImages($result);

        return new AIResponse(
            content: json_encode($images),
            usage: [
                'images_varied' => count($images),
                'total_cost' => 1.0, // Variation cost
            ],
            metadata: [
                'type' => 'vary',
                'original_job_id' => $jobId,
                'vary_job_id' => $varyJobId,
                'image_index' => $imageIndex,
                'variation_type' => $variationType,
                'images' => $images,
            ]
        );
    }

    public function stream(AIRequest $request): \Generator
    {
        // Midjourney doesn't support streaming for image generation
        // Return the full response as a single chunk
        $response = $this->generate($request);
        yield $response->content;
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::MIDJOURNEY_V6->value => [
                'name' => 'Midjourney v6',
                'description' => 'Latest Midjourney model with improved coherence and detail',
                'features' => ['high_quality', 'coherent_text', 'detailed_images'],
                'max_resolution' => '2048x2048',
            ],
            EntityEnum::MIDJOURNEY_V5->value => [
                'name' => 'Midjourney v5',
                'description' => 'Stable Midjourney model with excellent artistic quality',
                'features' => ['artistic_style', 'creative_interpretation'],
                'max_resolution' => '1024x1024',
            ],
            EntityEnum::MIDJOURNEY_NIJI->value => [
                'name' => 'Midjourney Niji',
                'description' => 'Anime and illustration focused model',
                'features' => ['anime_style', 'illustration', 'character_design'],
                'max_resolution' => '1024x1024',
            ],
        ];
    }

    public function validateRequest(AIRequest $request): bool
    {
        // Check if model is supported
        if (!in_array($request->entity, [
            EntityEnum::MIDJOURNEY_V6,
            EntityEnum::MIDJOURNEY_V5,
            EntityEnum::MIDJOURNEY_NIJI,
        ])) {
            throw new AIEngineException("Model {$request->entity->value} is not supported by Midjourney driver");
        }

        // Validate prompt
        if (empty($request->prompt)) {
            throw new AIEngineException('Prompt is required for Midjourney generation');
        }

        // Validate prompt length
        if (strlen($request->prompt) > 4000) {
            throw new AIEngineException('Prompt exceeds maximum length of 4000 characters');
        }

        return true;
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::MIDJOURNEY;
    }
}
