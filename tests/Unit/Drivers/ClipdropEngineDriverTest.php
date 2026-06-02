<?php

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\Clipdrop\ClipdropEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class ClipdropEngineDriverTest extends TestCase
{
    private array $history = [];

    private function driver(): ClipdropEngineDriver
    {
        $this->history = [];
        $mock = new MockHandler([new Response(200, ['content-type' => 'image/png'], "\x89PNG\x0d\x0a\x1a\x0afake-bytes")]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);

        return new ClipdropEngineDriver(['api_key' => 'cd-key', 'base_url' => 'https://clipdrop-api.co'], $client);
    }

    private function request(array $params): AIRequest
    {
        return new AIRequest(
            prompt: (string) ($params['prompt'] ?? ''),
            engine: EngineEnum::Clipdrop,
            model: EntityEnum::CLIPDROP_IMAGE_EDIT,
            parameters: $params,
        );
    }

    public function test_background_removal_posts_to_clipdrop_and_returns_success(): void
    {
        $response = $this->driver()->editImage($this->request([
            'operation' => 'background_removal',
            'image' => "\x89PNG raw input",
        ]));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('background_removal', $response->getMetadata()['operation']);

        $sent = $this->history[0]['request'];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertStringContainsString('/remove-background/v1', (string) $sent->getUri());
        $this->assertSame('cd-key', $sent->getHeaderLine('x-api-key'));
    }

    public function test_upscale_routes_to_upscale_endpoint(): void
    {
        $this->driver()->editImage($this->request([
            'operation' => 'upscale',
            'image' => "\x89PNG raw input",
            'target_width' => 4096,
            'target_height' => 4096,
        ]));

        $this->assertStringContainsString('/image-upscaling/v1/upscale', (string) $this->history[0]['request']->getUri());
    }

    public function test_cleanup_requires_a_mask(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver()->editImage($this->request([
            'operation' => 'cleanup',
            'image' => "\x89PNG raw input",
            // no mask
        ]));
    }

    public function test_unsupported_operation_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->driver()->editImage($this->request([
            'operation' => 'make_it_pop',
            'image' => "\x89PNG raw input",
        ]));
    }

    public function test_engine_and_entity_wiring(): void
    {
        $this->assertSame(
            \LaravelAIEngine\Drivers\Clipdrop\ClipdropEngineDriver::class,
            EngineEnum::Clipdrop->driverClass()
        );
        $this->assertSame(EngineEnum::Clipdrop, EntityEnum::from(EntityEnum::CLIPDROP_IMAGE_EDIT)->engine());
        $this->assertSame('image', EntityEnum::from(EntityEnum::CLIPDROP_IMAGE_EDIT)->getContentType());
    }
}
