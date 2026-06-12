<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers\Replicate;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\Replicate\ReplicateEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class ReplicateWanVideoTest extends TestCase
{
    private array $sentBodies = [];
    private array $sentPaths = [];

    private function driver(): ReplicateEngineDriver
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'pred-1',
                'status' => 'succeeded',
                'output' => 'https://example.com/out.mp4',
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) {
            return function (PsrRequest $request, array $options) use ($handler) {
                $this->sentPaths[] = (string) $request->getUri()->getPath();
                $this->sentBodies[] = json_decode((string) $request->getBody(), true) ?: [];

                return $handler($request, $options);
            };
        });

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.replicate.com/v1/',
        ]);

        return new ReplicateEngineDriver(['api_key' => 'test-key'], $client);
    }

    public function test_wan_27_maps_first_and_last_frame(): void
    {
        $response = $this->driver()->generateVideo(new AIRequest(
            prompt: 'a cat',
            engine: 'replicate',
            model: EntityEnum::REPLICATE_WAN_27_I2V,
            parameters: [
                'start_image_url' => 'https://example.com/start.png',
                'end_image_url' => 'https://example.com/end.png',
                'duration' => 8,
            ],
        ));

        $this->assertTrue($response->isSuccessful());
        $input = $this->sentBodies[0]['input'];
        $this->assertSame('https://example.com/start.png', $input['first_frame']);
        $this->assertSame('https://example.com/end.png', $input['last_frame']);
        $this->assertSame(8, $input['duration']);
        $this->assertStringContainsString('wan-2.7-i2v', $this->sentPaths[0]);
    }

    public function test_wan_22_fast_maps_image_and_last_image(): void
    {
        $this->driver()->generateVideo(new AIRequest(
            prompt: 'a cat',
            engine: 'replicate',
            model: EntityEnum::REPLICATE_WAN_22_I2V_FAST,
            parameters: [
                'start_image_url' => 'https://example.com/start.png',
                'end_image_url' => 'https://example.com/end.png',
                'model' => 'should-be-stripped',
            ],
        ));

        $input = $this->sentBodies[0]['input'];
        $this->assertSame('https://example.com/start.png', $input['image']);
        $this->assertSame('https://example.com/end.png', $input['last_image']);
        // Package-only key must not leak into the Replicate input.
        $this->assertArrayNotHasKey('model', $input);
        $this->assertArrayNotHasKey('start_image_url', $input);
        $this->assertArrayNotHasKey('end_image_url', $input);
    }
}
