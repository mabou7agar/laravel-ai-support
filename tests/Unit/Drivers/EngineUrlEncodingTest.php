<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\Gemini\GeminiEngineDriver;
use LaravelAIEngine\Drivers\StableDiffusion\StableDiffusionEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * The model name is free-form and request-controlled (EntityEnum::from accepts any
 * string). Where it lands in a URL path it must be encoded so it cannot inject path,
 * query, or traversal characters into the provider request.
 */
class EngineUrlEncodingTest extends UnitTestCase
{
    public function test_gemini_encodes_a_malicious_model_name_in_the_request_url(): void
    {
        $capturedUrl = null;
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with(Mockery::on(function ($url) use (&$capturedUrl): bool {
                $capturedUrl = (string) $url;

                return true;
            }), Mockery::any())
            ->andReturn(new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
                'usageMetadata' => ['totalTokenCount' => 1],
            ])));

        $driver = new GeminiEngineDriver([
            'api_key' => 'test-gemini-key',
            'base_url' => 'https://generativelanguage.googleapis.com',
        ], $client);

        $driver->generateText(new AIRequest('Hello', EngineEnum::GEMINI, EntityEnum::from('../evil?inject=1')));

        // The ':generateContent' method suffix stays intact; the model segment is encoded.
        $this->assertSame('/v1beta/models/..%2Fevil%3Finject%3D1:generateContent', $capturedUrl);
        // No raw traversal / query characters survive in the model segment.
        $this->assertStringNotContainsString('../evil', (string) $capturedUrl);
        $this->assertStringNotContainsString('?inject', (string) $capturedUrl);
    }

    private function invoke(object $object, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    public function test_path_segment_and_provider_path_encoders_neutralize_injection(): void
    {
        $driver = new StableDiffusionEngineDriver(['api_key' => 'test-key']);

        // Single segment: separators and query chars are percent-encoded.
        $this->assertSame('foo%2F..%2Fbar%3Fx%3D1%23y', $this->invoke($driver, 'encodePathSegment', ['foo/../bar?x=1#y']));
        // Normal model ids are untouched (unreserved chars).
        $this->assertSame('gemini-1.5-pro', $this->invoke($driver, 'encodePathSegment', ['gemini-1.5-pro']));

        // Slash-delimited provider path: '..' / '.' / empty segments dropped (no traversal),
        // legitimate multi-segment ids preserved.
        $this->assertSame('fal-ai/secret', $this->invoke($driver, 'encodeProviderPath', ['fal-ai/../secret']));
        $this->assertSame('fal-ai/flux/dev', $this->invoke($driver, 'encodeProviderPath', ['fal-ai/flux/dev']));
        $this->assertSame('fal-ai/flux/dev', $this->invoke($driver, 'encodeProviderPath', ['/fal-ai/./flux/dev']));
    }
}
