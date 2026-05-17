<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Drivers\CloudflareWorkersAI\CloudflareWorkersAIEngineDriver;
use LaravelAIEngine\Drivers\ComfyUI\ComfyUIEngineDriver;
use LaravelAIEngine\Drivers\ElevenLabs\ElevenLabsEngineDriver;
use LaravelAIEngine\Drivers\Gemini\GeminiEngineDriver;
use LaravelAIEngine\Drivers\GoogleTTS\GoogleTTSEngineDriver;
use LaravelAIEngine\Drivers\HuggingFace\HuggingFaceEngineDriver;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\Drivers\Pexels\PexelsEngineDriver;
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
        $this->assertSame(CloudflareWorkersAIEngineDriver::class, (EngineEnum::from(EngineEnum::CLOUDFLARE_WORKERS_AI))->driverClass());
        $this->assertSame(HuggingFaceEngineDriver::class, (EngineEnum::from(EngineEnum::HUGGINGFACE))->driverClass());
        $this->assertSame(ReplicateEngineDriver::class, (EngineEnum::from(EngineEnum::REPLICATE))->driverClass());
        $this->assertSame(ComfyUIEngineDriver::class, (EngineEnum::from(EngineEnum::COMFYUI))->driverClass());
        $this->assertSame(PexelsEngineDriver::class, (EngineEnum::from(EngineEnum::PEXELS))->driverClass());
        $this->assertSame(GoogleTTSEngineDriver::class, (EngineEnum::from(EngineEnum::GOOGLE_TTS))->driverClass());
    }

    public function test_pexels_search_uses_stock_photo_api_and_formats_results(): void
    {
        $driver = new PexelsEngineDriver([
            'api_key' => 'pexels-token',
            'base_url' => 'https://api.pexels.com',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'total_results' => 12,
                'next_page' => 'https://api.pexels.com/v1/search?page=2',
                'photos' => [
                    [
                        'id' => 123,
                        'alt' => 'Laptop on a desk',
                        'url' => 'https://www.pexels.com/photo/123',
                        'width' => 1200,
                        'height' => 800,
                        'avg_color' => '#ffffff',
                        'photographer' => 'Jane Creator',
                        'photographer_url' => 'https://www.pexels.com/@jane',
                        'photographer_id' => 456,
                        'src' => [
                            'original' => 'https://images.pexels.com/photos/123/original.jpg',
                            'large' => 'https://images.pexels.com/photos/123/large.jpg',
                            'medium' => 'https://images.pexels.com/photos/123/medium.jpg',
                        ],
                    ],
                ],
            ])),
        ]));

        $response = $driver->generateImage(new AIRequest(
            prompt: 'laptop desk',
            engine: EngineEnum::PEXELS,
            model: EntityEnum::PEXELS_SEARCH,
            parameters: ['per_page' => 100, 'orientation' => 'landscape']
        ));

        $photos = json_decode($response->getContent(), true);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('123', $photos[0]['id']);
        $this->assertSame('Laptop on a desk', $photos[0]['alt']);
        $this->assertSame('Jane Creator', $photos[0]['photographer']['name']);
        $this->assertSame(80, $response->getUsage()['per_page']);
        $this->assertSame('landscape', $response->getUsage()['orientation']);
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
                'api_key_config' => 'services.openai.key',
                'models' => [
                    'image' => ['model' => 'gpt-image-1-mini', 'estimated_unit_cost' => 0.02],
                ],
            ],
            'cloudflare_workers_ai' => [
                'enabled' => true,
                'api_key_config' => 'services.cloudflare.key',
                'models' => [
                    'image' => ['model' => '@cf/black-forest-labs/flux-1-schnell', 'estimated_unit_cost' => 0.001],
                ],
            ],
        ]);
        Config::set('services.openai.key', 'openai-key');
        Config::set('services.cloudflare.key', 'cloudflare-key');

        $selection = app(MediaProviderRouter::class)->select('image', 'cheapest');

        $this->assertSame('cloudflare_workers_ai', $selection['provider']);
        $this->assertSame('@cf/black-forest-labs/flux-1-schnell', $selection['model']);
    }

    public function test_media_provider_router_skips_providers_without_required_credentials(): void
    {
        Config::set('ai-engine.media_routing.providers', [
            'missing_key' => [
                'api_key_config' => 'services.missing.key',
                'models' => [
                    'image' => ['model' => 'missing/image', 'estimated_unit_cost' => 0.01],
                ],
            ],
            'ready' => [
                'api_key_config' => 'services.ready.key',
                'models' => [
                    'image' => ['model' => 'ready/image', 'estimated_unit_cost' => 0.5],
                ],
            ],
        ]);
        Config::set('services.ready.key', 'ready-key');

        $selection = app(MediaProviderRouter::class)->select('image', 'cheapest');

        $this->assertSame('ready', $selection['provider']);
    }

    public function test_media_provider_router_supports_any_credential_group(): void
    {
        Config::set('ai-engine.media_routing.providers', [
            'google_tts' => [
                'any_api_key_config' => [
                    'services.google_tts.api_key',
                    'services.google_tts.access_token',
                ],
                'models' => [
                    'audio_generation' => ['model' => 'google-tts', 'estimated_unit_cost' => 0.004],
                ],
            ],
        ]);
        Config::set('services.google_tts.api_key', null);
        Config::set('services.google_tts.access_token', null);

        $this->expectException(\InvalidArgumentException::class);
        app(MediaProviderRouter::class)->select('audio_generation', 'cheapest');
    }

    public function test_media_provider_router_selects_provider_when_any_credential_is_present(): void
    {
        Config::set('ai-engine.media_routing.providers', [
            'google_tts' => [
                'any_api_key_config' => [
                    'services.google_tts.api_key',
                    'services.google_tts.access_token',
                ],
                'models' => [
                    'audio_generation' => ['model' => 'google-tts', 'estimated_unit_cost' => 0.004],
                ],
            ],
        ]);
        Config::set('services.google_tts.api_key', null);
        Config::set('services.google_tts.access_token', 'access-token');

        $selection = app(MediaProviderRouter::class)->select('audio_generation', 'cheapest');

        $this->assertSame('google_tts', $selection['provider']);
        $this->assertSame('google-tts', $selection['model']);
    }

    public function test_media_provider_router_skips_recently_failed_provider(): void
    {
        Config::set('ai-engine.media_routing.providers', [
            'failed' => [
                'unhealthy_until' => now()->addMinute()->toIso8601String(),
                'models' => [
                    'image' => ['model' => 'failed/image', 'estimated_unit_cost' => 0.01],
                ],
            ],
            'healthy' => [
                'models' => [
                    'image' => ['model' => 'healthy/image', 'estimated_unit_cost' => 0.5],
                ],
            ],
        ]);

        $selection = app(MediaProviderRouter::class)->select('image', 'cheapest');

        $this->assertSame('healthy', $selection['provider']);
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

    public function test_openai_generates_text_to_speech_audio(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $driver = new OpenAIEngineDriver([
            'api_key' => 'openai-token',
            'base_url' => 'https://api.openai.com/v1',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'audio/mpeg'], 'openai-audio'),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'Read this invoice summary.',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::OPENAI_TTS_1,
            parameters: ['voice' => 'nova', 'format' => 'mp3']
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('openai', $response->toArray()['metadata']['provider']);
        $this->assertSame('nova', $response->toArray()['metadata']['voice']);
    }

    public function test_openai_speech_to_speech_uses_transcription_then_tts(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-openai-sts-');
        file_put_contents($audio, 'source-audio');

        $driver = new OpenAIEngineDriver([
            'api_key' => 'openai-token',
            'base_url' => 'https://api.openai.com/v1',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'text' => 'Please confirm the invoice details.',
            ])),
            new Response(200, ['Content-Type' => 'audio/mpeg'], 'openai-sts-audio'),
        ]));

        $response = $driver->speechToSpeech(new AIRequest(
            prompt: '',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::OPENAI_GPT_4O_MINI_TTS,
            files: [$audio],
            parameters: [
                'voice' => 'alloy',
                'format' => 'mp3',
                'transcription_model' => EntityEnum::OPENAI_GPT_4O_TRANSCRIBE,
            ]
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('speech_to_speech', $response->toArray()['metadata']['service']);
        $this->assertSame('Please confirm the invoice details.', $response->toArray()['metadata']['transcript']);
    }

    public function test_google_tts_synthesizes_audio_and_stores_file(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $driver = new GoogleTTSEngineDriver([
            'api_key' => 'google-token',
            'base_url' => 'https://texttospeech.googleapis.com/v1',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'audioContent' => base64_encode('google-audio'),
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'Read this customer note.',
            engine: EngineEnum::GOOGLE_TTS,
            model: EntityEnum::GOOGLE_TTS,
            parameters: [
                'voice' => 'en-US-Neural2-F',
                'language_code' => 'en-US',
                'audio_encoding' => 'MP3',
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('google_tts', $response->toArray()['metadata']['provider']);
        $this->assertSame('en-US-Neural2-F', $response->toArray()['metadata']['voice']['name']);
    }

    public function test_gemini_native_tts_generates_wav_from_inline_audio(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $driver = new GeminiEngineDriver([
            'api_key' => 'gemini-token',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => 'audio/pcm;rate=24000',
                                        'data' => base64_encode('gemini-pcm-audio'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'Read this invoice summary.',
            engine: EngineEnum::GEMINI,
            model: EntityEnum::GEMINI_2_5_FLASH_TTS,
            parameters: ['voice' => 'Kore']
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('gemini', $response->toArray()['metadata']['provider']);
        $this->assertSame('Kore', $response->toArray()['metadata']['voice']);
        $this->assertSame('wav', $response->toArray()['metadata']['audio_format']);
    }

    public function test_elevenlabs_transcribes_audio_with_scribe(): void
    {
        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-stt-');
        file_put_contents($audio, 'audio-bytes');

        $driver = new ElevenLabsEngineDriver([
            'api_key' => 'eleven-token',
            'base_url' => 'https://api.elevenlabs.io',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'text' => 'Create an invoice for Acme.',
                'language_code' => 'en',
            ])),
        ]));

        $response = $driver->audioToText(new AIRequest(
            prompt: '',
            engine: EngineEnum::ELEVENLABS,
            model: EntityEnum::ELEVEN_SCRIBE_V2,
            files: [$audio],
            parameters: ['language_code' => 'en']
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Create an invoice for Acme.', $response->getContent());
        $this->assertSame('speech_to_text', $response->toArray()['metadata']['service']);
        $this->assertSame('scribe_v2', $response->toArray()['metadata']['model']);
    }

    public function test_elevenlabs_converts_speech_to_speech_and_stores_audio(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-sts-');
        file_put_contents($audio, 'source-audio');

        $driver = new ElevenLabsEngineDriver([
            'api_key' => 'eleven-token',
            'base_url' => 'https://api.elevenlabs.io',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'audio/mpeg'], 'converted-audio'),
        ]));

        $response = $driver->speechToSpeech(new AIRequest(
            prompt: '',
            engine: EngineEnum::ELEVENLABS,
            model: EntityEnum::ELEVEN_MULTILINGUAL_STS_V2,
            files: [$audio],
            parameters: [
                'voice_id' => 'JBFqnCBsd6RMkjVDRZzb',
                'output_format' => 'mp3_44100_128',
            ]
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('speech_to_speech', $response->toArray()['metadata']['service']);
        $this->assertSame('eleven_multilingual_sts_v2', $response->toArray()['metadata']['model']);
    }

    public function test_gemini_transcribes_audio_with_generate_content_inline_audio(): void
    {
        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-gemini-stt-');
        file_put_contents($audio, 'gemini-audio');

        $driver = new GeminiEngineDriver([
            'api_key' => 'gemini-token',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Create an invoice for Acme.'],
                            ],
                        ],
                    ],
                ],
            ])),
        ]));

        $response = $driver->audioToText(new AIRequest(
            prompt: 'Transcribe this audio.',
            engine: EngineEnum::GEMINI,
            model: EntityEnum::GEMINI_2_5_FLASH,
            files: [$audio],
            parameters: ['mime_type' => 'audio/wav']
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Create an invoice for Acme.', $response->getContent());
        $this->assertSame('speech_to_text', $response->toArray()['metadata']['service']);
    }

    public function test_gemini_speech_to_speech_uses_audio_understanding_then_native_tts(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-gemini-sts-');
        file_put_contents($audio, 'gemini-source-audio');

        $driver = new GeminiEngineDriver([
            'api_key' => 'gemini-token',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'timeout' => 30,
        ], $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Please confirm the invoice details.'],
                            ],
                        ],
                    ],
                ],
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => 'audio/pcm;rate=24000',
                                        'data' => base64_encode('gemini-sts-pcm'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ]));

        $response = $driver->speechToSpeech(new AIRequest(
            prompt: 'Answer politely based on this audio.',
            engine: EngineEnum::GEMINI,
            model: EntityEnum::GEMINI_2_5_FLASH,
            files: [$audio],
            parameters: [
                'mime_type' => 'audio/wav',
                'tts_model' => EntityEnum::GEMINI_2_5_FLASH_TTS,
                'voice' => 'Kore',
            ]
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->toArray()['files']);
        $this->assertSame('speech_to_speech', $response->toArray()['metadata']['service']);
        $this->assertSame('Please confirm the invoice details.', $response->toArray()['metadata']['transcript']);
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
