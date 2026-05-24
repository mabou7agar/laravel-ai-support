<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\LocalAudio\LocalAudioEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Media\MediaProviderRouter;
use LaravelAIEngine\Tests\UnitTestCase;

class LocalAudioEngineDriverTest extends UnitTestCase
{
    public function test_engine_enum_maps_local_audio_to_driver_and_models(): void
    {
        $engine = EngineEnum::from(EngineEnum::LOCAL_AUDIO);

        $this->assertSame(LocalAudioEngineDriver::class, $engine->driverClass());
        $this->assertTrue($engine->supports('speech_to_text'));
        $this->assertTrue($engine->supports('text_to_speech'));
        $this->assertSame('local-whisper', $engine->getDefaultModels()[0]->value);
    }

    public function test_openai_compatible_speech_to_text_posts_multipart_audio(): void
    {
        $audioPath = sys_get_temp_dir().'/ai-engine-local-stt.wav';
        file_put_contents($audioPath, 'fake wav');

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'text' => 'local transcript',
                'duration' => 1.25,
            ], JSON_THROW_ON_ERROR)),
        ]));
        $handler->push(Middleware::history($history));

        $driver = new LocalAudioEngineDriver([
            'base_url' => 'http://127.0.0.1:8080/v1',
            'stt' => [
                'mode' => 'openai_compatible',
                'path' => '/audio/transcriptions',
                'model' => 'whisper-local',
                'language' => 'ar',
                'prompt' => 'فاتورة أحمد ماك بوك آيفون',
            ],
        ], new Client(['handler' => $handler, 'base_uri' => 'http://127.0.0.1:8080/v1/']));

        $response = $driver->audioToText(new AIRequest(
            prompt: '',
            engine: EngineEnum::LocalAudio,
            model: EntityEnum::LOCAL_WHISPER,
            files: [$audioPath],
            parameters: []
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('local transcript', $response->getContent());
        $this->assertSame('speech_to_text', $response->getMetadata()['service']);
        $this->assertSame('/v1/audio/transcriptions', $history[0]['request']->getUri()->getPath());
        $this->assertStringContainsString('name="language"', (string) $history[0]['request']->getBody());
        $this->assertStringContainsString('name="prompt"', (string) $history[0]['request']->getBody());
        $this->assertStringContainsString('name="model"', (string) $history[0]['request']->getBody());
    }

    public function test_openai_compatible_text_to_speech_stores_audio_response(): void
    {
        Storage::fake('public');

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'audio/wav'], 'voice-bytes'),
        ]));
        $handler->push(Middleware::history($history));

        $driver = new LocalAudioEngineDriver([
            'base_url' => 'http://127.0.0.1:8880/v1',
            'tts' => [
                'mode' => 'openai_compatible',
                'path' => '/audio/speech',
                'model' => 'kokoro',
                'voice' => 'af_heart',
            ],
        ], new Client(['handler' => $handler, 'base_uri' => 'http://127.0.0.1:8880/v1/']));

        $response = $driver->generateAudio(new AIRequest(
            prompt: 'Hello from local voice.',
            engine: EngineEnum::LocalAudio,
            model: EntityEnum::LOCAL_TTS,
            parameters: ['response_format' => 'wav']
        ));

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('text_to_speech', $response->getMetadata()['service']);
        $this->assertSame('kokoro', $payload['model']);
        $this->assertSame('af_heart', $payload['voice']);
        $this->assertNotEmpty($response->toArray()['files']);
    }

    public function test_command_mode_can_transcribe_and_generate_audio_without_http(): void
    {
        Storage::fake('public');

        $audioPath = sys_get_temp_dir().'/ai-engine-command-stt.wav';
        $ttsOutput = sys_get_temp_dir().'/ai-engine-command-tts.wav';
        file_put_contents($audioPath, 'fake wav');
        @unlink($ttsOutput);

        $driver = new LocalAudioEngineDriver([
            'stt' => [
                'mode' => 'command',
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'echo "command transcript";',
                ],
            ],
            'tts' => [
                'mode' => 'command',
                'output_path' => $ttsOutput,
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'file_put_contents($argv[1], "command voice");',
                    '{output}',
                ],
            ],
        ]);

        $transcription = $driver->audioToText(new AIRequest(
            prompt: '',
            engine: EngineEnum::LocalAudio,
            model: EntityEnum::LOCAL_WHISPER,
            files: [$audioPath]
        ));
        $speech = $driver->generateAudio(new AIRequest(
            prompt: 'Generate this.',
            engine: EngineEnum::LocalAudio,
            model: EntityEnum::LOCAL_TTS
        ));

        $this->assertSame('command transcript', $transcription->getContent());
        $this->assertTrue($speech->isSuccessful());
        $this->assertSame('command', $speech->getMetadata()['mode']);
        $this->assertNotEmpty($speech->toArray()['files']);
    }

    public function test_media_router_can_prefer_local_audio_for_voice_capabilities(): void
    {
        config()->set('ai-engine.media_routing.providers.local_audio', [
            'enabled' => true,
            'models' => [
                'audio_transcription' => ['model' => 'local-whisper', 'estimated_unit_cost' => 0.0, 'quality_score' => 1.5, 'latency_score' => 1.0, 'local' => true],
                'audio_generation' => ['model' => 'local-tts', 'estimated_unit_cost' => 0.0, 'quality_score' => 1.5, 'latency_score' => 1.0, 'local' => true],
            ],
        ]);

        $this->assertSame('local_audio', (new MediaProviderRouter())->select('audio_transcription', 'local')['provider']);
        $this->assertSame('local_audio', (new MediaProviderRouter())->select('audio_generation', 'local')['provider']);
    }
}
