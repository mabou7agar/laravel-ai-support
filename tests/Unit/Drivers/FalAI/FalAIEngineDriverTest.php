<?php

namespace LaravelAIEngine\Tests\Unit\Drivers\FalAI;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalAIEngineDriverTest extends TestCase
{
    public function test_default_timeout_falls_back_to_180_seconds(): void
    {
        $driver = new class([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
        ]) extends FalAIEngineDriver {
            public function exposedTimeout(): int
            {
                return $this->getTimeout();
            }
        };

        $this->assertSame(180, $driver->exposedTimeout());
    }

    public function test_nano_banana_maps_frame_count_and_character_sources(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('fal-ai/nano-banana-2', $endpoint);
                $this->assertSame(3, $options['json']['num_images']);
                $this->assertSame('16:9', $options['json']['aspect_ratio']);
                $this->assertSame('high', $options['json']['thinking_level']);
                $this->assertStringContainsString('Character source context', $options['json']['prompt']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'images' => [
                    ['url' => 'https://example.com/generated.png', 'width' => 1024, 'height' => 576],
                ],
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'timeout' => 60,
        ], $client);

        $response = $driver->generate(new AIRequest(
            prompt: 'Create cinematic character keyframes',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_NANO_BANANA_2,
            parameters: [
                'frame_count' => 3,
                'aspect_ratio' => '16:9',
                'thinking_level' => 'high',
                'character_sources' => [
                    [
                        'name' => 'Mina',
                        'description' => 'Athletic sci-fi pilot',
                        'metadata' => ['age' => 24],
                    ],
                ],
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(EntityEnum::FAL_NANO_BANANA_2, $response->getMetadata()['resolved_model']);
        $this->assertCount(1, $response->getMetadata()['images']);
    }

    public function test_kling_reference_video_maps_elements_and_reference_images(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('fal-ai/kling-video/o3/standard/reference-to-video', $endpoint);
                $this->assertSame('8', $options['json']['duration']);
                $this->assertSame('16:9', $options['json']['aspect_ratio']);
                $this->assertCount(1, $options['json']['image_urls']);
                $this->assertCount(1, $options['json']['elements']);
                $this->assertSame('https://example.com/front.png', $options['json']['elements'][0]['frontal_image_url']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'video' => [
                    'url' => 'https://example.com/output.mp4',
                    'content_type' => 'video/mp4',
                ],
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'timeout' => 60,
        ], $client);

        $response = $driver->generate(new AIRequest(
            prompt: 'Have the character walk through the market',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO,
            parameters: [
                'duration' => '8',
                'aspect_ratio' => '16:9',
                'reference_image_urls' => ['https://example.com/scene.png'],
                'character_sources' => [
                    [
                        'name' => 'Mina',
                        'frontal_image_url' => 'https://example.com/front.png',
                        'reference_image_urls' => ['https://example.com/side.png'],
                    ],
                ],
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, $response->getMetadata()['resolved_model']);
        $this->assertSame('https://example.com/output.mp4', $response->getMetadata()['video']['url']);
    }

    public function test_seedance_text_to_video_uses_text_endpoint(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('bytedance/seedance-2.0/text-to-video', $endpoint);
                $this->assertSame('Write a cinematic rainstorm chase', $options['json']['prompt']);
                $this->assertSame('720p', $options['json']['resolution']);
                $this->assertTrue($options['json']['generate_audio']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'video' => [
                    'url' => 'https://example.com/seedance.mp4',
                ],
                'seed' => 42,
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'timeout' => 60,
        ], $client);

        $response = $driver->generate(new AIRequest(
            prompt: 'Write a cinematic rainstorm chase',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO,
            parameters: [
                'resolution' => '720p',
                'generate_audio' => true,
                'duration' => 'auto',
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO, $response->getMetadata()['resolved_model']);
        $this->assertSame(42, $response->getMetadata()['video']['seed']);
    }

    public function test_seedance_reference_to_video_maps_animation_reference_videos(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('bytedance/seedance-2.0/reference-to-video', $endpoint);
                $this->assertSame(['https://example.com/dance-reference.mp4'], $options['json']['video_urls']);
                $this->assertSame('720p', $options['json']['resolution']);
                $this->assertStringContainsString('@Video1 is the motion or animation reference', $options['json']['prompt']);
                $this->assertArrayNotHasKey('image_urls', $options['json']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'output' => 'https://example.com/seedance-dance.mp4',
                'seed' => 12345,
                'duration' => 5.0,
                'width' => 1280,
                'height' => 720,
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'timeout' => 60,
        ], $client);

        $response = $driver->generate(new AIRequest(
            prompt: 'Make the character perform the same dance timing',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO,
            parameters: [
                'reference_video_urls' => ['https://example.com/dance-reference.mp4'],
                'resolution' => '720p',
                'duration' => '5',
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO, $response->getMetadata()['resolved_model']);
        $this->assertSame('https://example.com/seedance-dance.mp4', $response->getMetadata()['video']['url']);
        $this->assertSame(1280, $response->getMetadata()['video']['width']);
        $this->assertSame(720, $response->getMetadata()['video']['height']);
    }

    public function test_seedance_reference_to_video_maps_audio_reference_with_visual_reference(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('bytedance/seedance-2.0/reference-to-video', $endpoint);
                $this->assertSame(['https://example.com/pose.png'], $options['json']['image_urls']);
                $this->assertSame(['https://example.com/beat.mp3'], $options['json']['audio_urls']);
                $this->assertStringContainsString('@Audio1 is the audio reference', $options['json']['prompt']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'output' => 'https://example.com/seedance-audio.mp4',
                'seed' => 12345,
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'timeout' => 60,
        ], $client);

        $response = $driver->generate(new AIRequest(
            prompt: 'Use the beat as timing guidance',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO,
            parameters: [
                'reference_image_urls' => ['https://example.com/pose.png'],
                'reference_audio_urls' => ['https://example.com/beat.mp3'],
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('https://example.com/seedance-audio.mp4', $response->getMetadata()['video']['url']);
    }

    public function test_submit_image_async_uses_queue_endpoint_and_webhook_query(): void
    {
        $syncClient = Mockery::mock(Client::class);
        $queueClient = Mockery::mock(Client::class);
        $queueClient->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('fal-ai/nano-banana-2', $endpoint);
                $this->assertSame('https://app.test/api/v1/ai/generate/preview/fal/webhook?job_id=123&token=abc', $options['query']['fal_webhook']);
                $this->assertSame('Generate Mina', $options['json']['prompt']);
                $this->assertSame(1, $options['json']['num_images']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'request_id' => 'fal-image-1',
                'status_url' => 'https://queue.fal.run/status/fal-image-1',
                'response_url' => 'https://queue.fal.run/response/fal-image-1',
                'queue_position' => 1,
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'queue_base_url' => 'https://queue.fal.run',
            'timeout' => 60,
        ], $syncClient, $queueClient);

        $submitted = $driver->submitImageAsync(new AIRequest(
            prompt: 'Generate Mina',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_NANO_BANANA_2,
            parameters: [
                'frame_count' => 1,
            ]
        ), 'https://app.test/api/v1/ai/generate/preview/fal/webhook?job_id=123&token=abc');

        $this->assertSame('fal-image-1', $submitted['request_id']);
        $this->assertSame(1, $submitted['queue_position']);
        $this->assertSame(EntityEnum::FAL_NANO_BANANA_2, $submitted['operation']['resolved_model']);
    }

    public function test_submit_video_async_uses_queue_endpoint_and_webhook_query(): void
    {
        $syncClient = Mockery::mock(Client::class);
        $queueClient = Mockery::mock(Client::class);
        $queueClient->shouldReceive('post')
            ->once()
            ->withArgs(function (string $endpoint, array $options): bool {
                $this->assertSame('fal-ai/kling-video/o3/standard/image-to-video', $endpoint);
                $this->assertSame('https://app.test/api/v1/ai/generate/video/fal/webhook?job_id=123&token=abc', $options['query']['fal_webhook']);
                $this->assertSame('https://example.com/start.png', $options['json']['image_url']);

                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'request_id' => 'fal-job-1',
                'status_url' => 'https://queue.fal.run/status/fal-job-1',
                'response_url' => 'https://queue.fal.run/response/fal-job-1',
                'queue_position' => 1,
            ])));

        $driver = new FalAIEngineDriver([
            'api_key' => 'test-fal-key',
            'base_url' => 'https://fal.run',
            'queue_base_url' => 'https://queue.fal.run',
            'timeout' => 60,
        ], $syncClient, $queueClient);

        $submitted = $driver->submitVideoAsync(new AIRequest(
            prompt: 'Animate this still image',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            parameters: [
                'start_image_url' => 'https://example.com/start.png',
            ]
        ), 'https://app.test/api/v1/ai/generate/video/fal/webhook?job_id=123&token=abc');

        $this->assertSame('fal-job-1', $submitted['request_id']);
        $this->assertSame(1, $submitted['queue_position']);
        $this->assertSame(EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO, $submitted['operation']['resolved_model']);
    }
}
