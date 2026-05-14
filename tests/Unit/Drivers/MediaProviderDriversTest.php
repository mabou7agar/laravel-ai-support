<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Drivers\CloudflareWorkersAI\CloudflareWorkersAIEngineDriver;
use LaravelAIEngine\Drivers\ComfyUI\ComfyUIEngineDriver;
use LaravelAIEngine\Drivers\Gemini\GeminiEngineDriver;
use LaravelAIEngine\Drivers\HuggingFace\HuggingFaceEngineDriver;
use LaravelAIEngine\Drivers\Replicate\ReplicateEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Media\MediaProviderRouter;
use LaravelAIEngine\Tests\UnitTestCase;

class MediaProviderDriversTest extends UnitTestCase
{
    public function test_engine_enum_maps_new_media_providers_to_drivers(): void
    {
        $this->assertSame(CloudflareWorkersAIEngineDriver::class, (new EngineEnum(EngineEnum::CLOUDFLARE_WORKERS_AI))->driverClass());
        $this->assertSame(HuggingFaceEngineDriver::class, (new EngineEnum(EngineEnum::HUGGINGFACE))->driverClass());
        $this->assertSame(ReplicateEngineDriver::class, (new EngineEnum(EngineEnum::REPLICATE))->driverClass());
        $this->assertSame(ComfyUIEngineDriver::class, (new EngineEnum(EngineEnum::COMFYUI))->driverClass());
    }

    public function test_cloudflare_workers_ai_generates_image_from_base64_result(): void
    {
        $driver = new CloudflareWorkersAIEngineDriver([
            'api_key' => 'cf-token',
            'account_id' => 'account-123',
            'base_url' => 'https://api.cloudflare.com/client/v4',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'result' => ['image' => base64_encode('fake-image')],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'cheap image',
            engine: EngineEnum::CLOUDFLARE_WORKERS_AI,
            model: EntityEnum::CLOUDFLARE_FLUX_SCHNELL,
            parameters: ['width' => 512, 'height' => 512]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('image', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('cloudflare_workers_ai', $response->toArray()['metadata']['provider']);
    }

    public function test_replicate_generates_media_with_waiting_prediction(): void
    {
        $driver = new ReplicateEngineDriver([
            'api_key' => 'replicate-token',
            'base_url' => 'https://api.replicate.com/v1',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(201, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'pred_123',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/image.png'],
                'metrics' => ['predict_time' => 1.2],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'open source image',
            engine: EngineEnum::REPLICATE,
            model: EntityEnum::REPLICATE_FLUX_SCHNELL
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(['https://replicate.delivery/image.png'], $response->toArray()['files']);
        $this->assertSame('pred_123', $response->toArray()['metadata']['prediction_id']);
    }

    public function test_huggingface_generates_image_through_inference_provider(): void
    {
        $driver = new HuggingFaceEngineDriver([
            'api_key' => 'hf-token',
            'base_url' => 'https://api-inference.huggingface.co',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'image/png'], 'fake-png'),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'marketplace image',
            engine: EngineEnum::HUGGINGFACE,
            model: EntityEnum::HUGGINGFACE_FLUX_SCHNELL,
            parameters: ['provider' => 'auto']
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('huggingface', $response->toArray()['metadata']['provider']);
    }

    public function test_comfyui_submits_prompt_and_returns_output_urls(): void
    {
        $driver = new ComfyUIEngineDriver([
            'base_url' => 'http://127.0.0.1:8188',
            'timeout' => 30,
            'default_workflow' => ['1' => ['inputs' => ['text' => '{{prompt}}']]],
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['prompt_id' => 'prompt-123'])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'prompt-123' => [
                    'outputs' => [
                        '9' => ['images' => [['filename' => 'out.png', 'subfolder' => '', 'type' => 'output']]],
                    ],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'local image',
            engine: EngineEnum::COMFYUI,
            model: EntityEnum::COMFYUI_DEFAULT_IMAGE
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('/view?filename=out.png', $response->toArray()['files'][0]);
        $this->assertSame('local', $response->toArray()['metadata']['cost_tier']);
    }

    public function test_media_provider_router_selects_lowest_cost_enabled_provider_for_capability(): void
    {
        Config::set('ai-engine.media_routing.enabled', true);
        Config::set('ai-engine.media_routing.providers', [
            'openai' => [
                'enabled' => true,
                'models' => [
                    'image' => ['model' => 'gpt-image-1-mini', 'estimated_unit_cost' => 0.02],
                ],
            ],
            'cloudflare_workers_ai' => [
                'enabled' => true,
                'models' => [
                    'image' => ['model' => '@cf/black-forest-labs/flux-1-schnell', 'estimated_unit_cost' => 0.001],
                ],
            ],
        ]);

        $selection = app(MediaProviderRouter::class)->select('image', 'cheapest');

        $this->assertSame('cloudflare_workers_ai', $selection['provider']);
        $this->assertSame('@cf/black-forest-labs/flux-1-schnell', $selection['model']);
    }

    public function test_gemini_generates_imagen_media_response(): void
    {
        $driver = new GeminiEngineDriver([
            'api_key' => 'gemini-key',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'predictions' => [
                    ['bytesBase64Encoded' => base64_encode('gemini-image')],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'imagen output',
            engine: EngineEnum::GEMINI,
            model: EntityEnum::GEMINI_IMAGEN_4_FAST
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('image', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('gemini', $response->toArray()['metadata']['provider']);
    }

    /**
     * @param array<int, Response> $responses
     */
    private function mockClient(array $responses): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'base_uri' => 'https://example.test',
        ]);
    }
}
