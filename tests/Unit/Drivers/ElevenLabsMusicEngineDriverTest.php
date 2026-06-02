<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\ElevenLabs\ElevenLabsEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class ElevenLabsMusicEngineDriverTest extends UnitTestCase
{
    public function test_entity_enum_maps_eleven_music_to_elevenlabs(): void
    {
        $model = EntityEnum::from(EntityEnum::ELEVEN_MUSIC);

        $this->assertSame(EngineEnum::ElevenLabs, $model->engine());
        $this->assertSame('audio', $model->getContentType());
        $this->assertSame('ElevenLabs Music', $model->label());
        $this->assertGreaterThan(0, $model->creditIndex());
    }

    public function test_generate_music_posts_to_music_endpoint_and_returns_saved_file(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.enabled', true);
        Config::set('ai-engine.media_library.persist_records', false);

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'audio/mpeg'], 'FAKE_MP3_AUDIO_BYTES'),
        ]));
        $handler->push(Middleware::history($history));

        $driver = new ElevenLabsEngineDriver(
            ['api_key' => 'test-eleven-key'],
            new Client(['handler' => $handler])
        );

        $request = new AIRequest(
            prompt: 'An upbeat lo-fi track for studying',
            engine: EngineEnum::ElevenLabs,
            model: EntityEnum::from(EntityEnum::ELEVEN_MUSIC),
            parameters: ['music_length_ms' => 30000],
        );

        // Exercise the public generate() entry point for the music model.
        $response = $driver->generate($request);

        $this->assertTrue($response->isSuccessful());
        $this->assertNotEmpty($response->files);
        $this->assertSame('music_generation', $response->metadata['service'] ?? null);
        $this->assertSame(30000, $response->metadata['music_length_ms'] ?? null);

        // The request was POSTed to the music endpoint with the expected params.
        $this->assertCount(1, $history);
        $sentRequest = $history[0]['request'];
        $this->assertSame('POST', $sentRequest->getMethod());
        $this->assertSame('/v1/music', $sentRequest->getUri()->getPath());

        $body = json_decode((string) $sentRequest->getBody(), true);
        $this->assertSame('An upbeat lo-fi track for studying', $body['prompt']);
        $this->assertSame(30000, $body['music_length_ms']);

        // The returned audio bytes were stored on disk.
        $files = Storage::disk('public')->allFiles();
        $this->assertNotEmpty($files);
        $this->assertSame('FAKE_MP3_AUDIO_BYTES', Storage::disk('public')->get($files[0]));
    }

    public function test_tts_path_is_unchanged_for_non_music_models(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.enabled', true);
        Config::set('ai-engine.media_library.persist_records', false);

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'audio/mpeg'], 'TTS_BYTES'),
        ]));
        $handler->push(Middleware::history($history));

        $driver = new ElevenLabsEngineDriver(
            ['api_key' => 'test-eleven-key'],
            new Client(['handler' => $handler])
        );

        $request = new AIRequest(
            prompt: 'Hello world',
            engine: EngineEnum::ElevenLabs,
            model: EntityEnum::from(EntityEnum::ELEVEN_MULTILINGUAL_V2),
        );

        $response = $driver->generate($request);

        $this->assertTrue($response->isSuccessful());
        $this->assertCount(1, $history);
        $this->assertStringContainsString('/v1/text-to-speech/', $history[0]['request']->getUri()->getPath());
    }
}
