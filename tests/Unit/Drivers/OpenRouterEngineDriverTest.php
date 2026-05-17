<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class OpenRouterEngineDriverTest extends UnitTestCase
{
    public function test_openrouter_generates_image_output_from_chat_modalities(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'or-img-1',
                'model' => 'google/gemini-2.5-flash-image',
                'choices' => [[
                    'message' => [
                        'content' => 'Generated image.',
                        'images' => [[
                            'image_url' => [
                                'url' => 'data:image/png;base64,' . base64_encode('png-bytes'),
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['total_tokens' => 12],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateImage(new AIRequest(
            prompt: 'Generate a product photo.',
            engine: EngineEnum::OPENROUTER,
            model: 'google/gemini-2.5-flash-image',
            parameters: ['aspect_ratio' => '1:1']
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('image', $response->getContentType());
        $this->assertNotEmpty($response->getFiles());
        $this->assertSame('openrouter', $response->getMetadata()['provider']);

        Http::assertSent(fn ($request): bool => $request->data()['modalities'] === ['image', 'text']
            && $request->data()['image_config']['aspect_ratio'] === '1:1');
    }

    public function test_openrouter_generates_text_to_speech_audio(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        Http::fake([
            'https://openrouter.ai/api/v1/audio/speech' => Http::response(
                'audio-bytes',
                200,
                ['Content-Type' => 'audio/mpeg', 'X-Generation-Id' => 'gen_123']
            ),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateAudio(new AIRequest(
            prompt: 'Read this invoice summary.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini-tts-2025-12-15',
            parameters: ['voice' => 'alloy', 'response_format' => 'mp3']
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->getFiles());
        $this->assertSame('text_to_speech', $response->getMetadata()['service']);
        $this->assertSame('gen_123', $response->getMetadata()['generation_id']);
    }

    public function test_openrouter_transcribes_audio(): void
    {
        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-openrouter-stt-');
        file_put_contents($audio, 'wav-bytes');

        Http::fake([
            'https://openrouter.ai/api/v1/audio/transcriptions' => Http::response([
                'text' => 'Create an invoice for Acme.',
                'usage' => ['total_tokens' => 8],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->audioToText(new AIRequest(
            prompt: '',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/whisper-1',
            files: [$audio],
            parameters: ['language' => 'en']
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Create an invoice for Acme.', $response->getContent());
        $this->assertSame('speech_to_text', $response->getMetadata()['service']);

        Http::assertSent(fn ($request): bool => $request->data()['model'] === 'openai/whisper-1'
            && $request->data()['input_audio']['format'] !== ''
            && $request->data()['language'] === 'en');
    }

    public function test_openrouter_speech_to_speech_uses_stt_then_tts(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        $audio = tempnam(sys_get_temp_dir(), 'ai-engine-openrouter-sts-');
        file_put_contents($audio, 'wav-bytes');

        Http::fake([
            'https://openrouter.ai/api/v1/audio/transcriptions' => Http::response([
                'text' => 'Please confirm the invoice.',
            ]),
            'https://openrouter.ai/api/v1/audio/speech' => Http::response(
                'speech-bytes',
                200,
                ['Content-Type' => 'audio/mpeg']
            ),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->speechToSpeech(new AIRequest(
            prompt: '',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/whisper-1',
            files: [$audio],
            parameters: [
                'tts_model' => 'openai/gpt-4o-mini-tts-2025-12-15',
                'voice' => 'nova',
            ]
        ));

        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('audio', $response->getContentType());
        $this->assertNotEmpty($response->getFiles());
        $this->assertSame('speech_to_speech', $response->getMetadata()['service']);
        $this->assertSame('Please confirm the invoice.', $response->getMetadata()['transcript']);
    }

    public function test_openrouter_generates_embeddings(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'model' => 'openai/text-embedding-3-small',
                'data' => [
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'usage' => ['total_tokens' => 5],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateEmbeddings(new AIRequest(
            prompt: 'Embed this text.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/text-embedding-3-small'
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('embeddings', $response->getContentType());
        $this->assertSame([[0.1, 0.2, 0.3]], $response->getMetadata()['embeddings']);
        $this->assertSame(3, $response->getMetadata()['dimensions']);
    }

    public function test_openrouter_chat_accepts_image_and_audio_input_parts(): void
    {
        $image = sys_get_temp_dir() . '/ai-engine-openrouter-image-' . uniqid() . '.png';
        $audio = sys_get_temp_dir() . '/ai-engine-openrouter-audio-' . uniqid() . '.wav';
        file_put_contents($image, 'image-bytes');
        file_put_contents($audio, 'audio-bytes');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'I can process both inputs.'],
                ]],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateText(new AIRequest(
            prompt: 'Describe these inputs.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini',
            files: [$image, $audio],
            parameters: ['audio_mime_type' => 'audio/wav']
        ));

        @unlink($image);
        @unlink($audio);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('I can process both inputs.', $response->getContent());

        Http::assertSent(function ($request): bool {
            $content = $request->data()['messages'][0]['content'] ?? [];

            return is_array($content)
                && ($content[0]['type'] ?? null) === 'text'
                && collect($content)->contains(fn ($part): bool => ($part['type'] ?? null) === 'image_url')
                && collect($content)->contains(fn ($part): bool => ($part['type'] ?? null) === 'input_audio');
        });
    }

    public function test_openrouter_streams_sse_content_chunks(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
                . "data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $chunks = iterator_to_array($driver->stream(new AIRequest(
            prompt: 'Stream this.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini'
        )));

        $this->assertSame(['Hello', ' world'], $chunks);

        Http::assertSent(fn ($request): bool => $request->data()['stream'] === true);
    }

    public function test_openrouter_maps_tools_and_structured_output_to_chat_payload(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"status":"ok"}'],
                ]],
            ]),
        ]);

        $request = (new AIRequest(
            prompt: 'Return status.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini'
        ))->withFunctions([
            [
                'name' => 'lookup_invoice',
                'description' => 'Look up an invoice.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_id' => ['type' => 'string'],
                    ],
                    'required' => ['invoice_id'],
                ],
            ],
        ])->withStructuredOutput([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string'],
            ],
            'required' => ['status'],
        ], 'status_response');

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateText($request);

        $this->assertTrue($response->isSuccessful());

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['tools'][0]['type'] ?? null) === 'function'
                && ($payload['tools'][0]['function']['name'] ?? null) === 'lookup_invoice'
                && ($payload['response_format']['type'] ?? null) === 'json_schema'
                && ($payload['response_format']['json_schema']['name'] ?? null) === 'status_response';
        });
    }

    public function test_openrouter_cost_optimization_can_prefer_free_models_and_sort_by_price(): void
    {
        Config::set('ai-engine.engines.openrouter.cost_optimization', [
            'enabled' => true,
            'mode' => 'free_first',
            'free_models' => [
                'meta-llama/llama-3.1-8b-instruct:free',
                'google/gemma-3-27b-it:free',
            ],
            'include_requested_model_fallback' => true,
            'sort_by_price' => true,
            'preferred_max_latency_p90' => 3,
            'max_price' => [
                'prompt' => 0.0,
                'completion' => 0.0,
            ],
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'free ok'],
                ]],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateText(new AIRequest(
            prompt: 'Use the cheapest available model.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini'
        ));

        $this->assertTrue($response->isSuccessful());

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return !isset($payload['model'])
                && ($payload['models'][0] ?? null) === 'meta-llama/llama-3.1-8b-instruct:free'
                && ($payload['models'][1] ?? null) === 'google/gemma-3-27b-it:free'
                && ($payload['models'][2] ?? null) === 'openai/gpt-4o-mini'
                && ($payload['provider']['sort']['by'] ?? null) === 'price'
                && ($payload['provider']['sort']['partition'] ?? null) === 'none'
                && ($payload['provider']['preferred_max_latency']['p90'] ?? null) === 3.0
                && ($payload['provider']['max_price']['prompt'] ?? null) === 0.0;
        });
    }

    public function test_openrouter_cost_optimization_preserves_explicit_model_list(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'custom ok'],
                ]],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateText(new AIRequest(
            prompt: 'Use my explicit model list.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini',
            parameters: [
                'cost_optimization' => true,
                'models' => ['openrouter/auto', 'meta-llama/llama-3.1-8b-instruct:free'],
                'provider' => [
                    'only' => ['openai'],
                ],
            ]
        ));

        $this->assertTrue($response->isSuccessful());

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $payload['models'] === ['openrouter/auto', 'meta-llama/llama-3.1-8b-instruct:free']
                && ($payload['provider']['only'] ?? null) === ['openai']
                && ($payload['provider']['sort']['by'] ?? null) === 'price';
        });
    }

    public function test_openrouter_captures_chat_tool_calls_from_response(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'lookup_invoice',
                                'arguments' => '{"invoice_id":"INV-100"}',
                            ],
                        ]],
                    ],
                ]],
            ]),
        ]);

        $request = (new AIRequest(
            prompt: 'Use the lookup_invoice tool for INV-100.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini'
        ))->withFunctions([
            [
                'name' => 'lookup_invoice',
                'description' => 'Look up an invoice.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_id' => ['type' => 'string'],
                    ],
                    'required' => ['invoice_id'],
                ],
            ],
        ], ['type' => 'function', 'function' => ['name' => 'lookup_invoice']]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateText($request);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('lookup_invoice', $response->getFunctionCall()['name'] ?? null);
        $this->assertSame(['invoice_id' => 'INV-100'], $response->getFunctionCall()['arguments'] ?? null);
        $this->assertSame('call_123', $response->getMetadata()['tool_calls'][0]['id'] ?? null);
    }

    public function test_openrouter_generates_chat_audio_output_from_streaming_chunks(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response(
                'data: {"choices":[{"delta":{"audio":{"transcript":"hello ","data":"' . base64_encode('audio-') . '"}}}]}' . "\n\n"
                . 'data: {"choices":[{"delta":{"audio":{"transcript":"world","data":"' . base64_encode('bytes') . '"}}}]}' . "\n\n"
                . "data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $response = $driver->generateAudio(new AIRequest(
            prompt: 'Say hello world.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-audio',
            parameters: [
                'modalities' => ['text', 'audio'],
                'audio' => ['voice' => 'alloy', 'format' => 'wav'],
            ]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('hello world', $response->getContent());
        $this->assertNotEmpty($response->getFiles());
        $this->assertSame('chat_audio_output', $response->getMetadata()['service']);
        $this->assertSame('audio/wav', $response->getMetadata()['mime_type']);

        Http::assertSent(fn ($request): bool => $request->data()['stream'] === true
            && $request->data()['modalities'] === ['text', 'audio']);
    }

    public function test_openrouter_model_catalog_keeps_metadata_and_capabilities(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'google/gemini-2.5-flash-image',
                        'name' => 'Gemini image',
                        'description' => 'Image model',
                        'context_length' => 8192,
                        'architecture' => ['modality' => 'text->image'],
                        'pricing' => ['prompt' => '0.1', 'completion' => '0.2'],
                        'supported_parameters' => ['tools', 'response_format'],
                    ],
                ],
            ]),
        ]);

        $driver = new OpenRouterEngineDriver(['api_key' => 'or-key']);
        $models = $driver->getAvailableModels();

        $this->assertSame('google/gemini-2.5-flash-image', $models[0]['id']);
        $this->assertContains('image_generation', $models[0]['capabilities']);
        $this->assertContains('function_calling', $models[0]['capabilities']);
        $this->assertSame(['tools', 'response_format'], $models[0]['supported_parameters']);
    }
}
