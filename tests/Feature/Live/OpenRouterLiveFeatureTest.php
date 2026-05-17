<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Live;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Tests\TestCase;

class OpenRouterLiveFeatureTest extends TestCase
{
    private OpenRouterEngineDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->readBoolEnv('AI_ENGINE_RUN_OPENROUTER_LIVE_TESTS')) {
            $this->markTestSkipped('Set AI_ENGINE_RUN_OPENROUTER_LIVE_TESTS=true to enable billed OpenRouter live checks.');
        }

        $apiKey = getenv('OPENROUTER_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            $this->markTestSkipped('Missing OPENROUTER_API_KEY.');
        }

        Storage::fake('public');
        Config::set('ai-engine.media_library.disk', 'public');
        Config::set('ai-engine.media_library.directory', 'ai-generated');
        Config::set('ai-engine.engines.openrouter.api_key', $apiKey);

        $this->driver = new OpenRouterEngineDriver([
            'api_key' => $apiKey,
            'timeout' => (int) (getenv('AI_ENGINE_OPENROUTER_LIVE_TIMEOUT') ?: 90),
        ]);
    }

    public function test_openrouter_live_text_stream_tools_structured_output_embeddings_and_catalog(): void
    {
        $textModel = $this->envString('AI_ENGINE_OPENROUTER_LIVE_TEXT_MODEL', 'openai/gpt-4o-mini');

        $text = $this->driver->generateText(new AIRequest(
            prompt: 'Reply with exactly two words: router ok',
            engine: EngineEnum::OPENROUTER,
            model: $textModel,
            parameters: ['temperature' => 0, 'max_tokens' => 20]
        ));

        $this->assertSuccessfulResponse($text, 'OpenRouter text generation failed');
        $this->assertNotSame('', trim($text->getContent()));

        $structured = $this->driver->generateText(new AIRequest(
            prompt: 'Return a JSON object with status set to ok.',
            engine: EngineEnum::OPENROUTER,
            model: $textModel,
            parameters: ['temperature' => 0, 'max_tokens' => 80],
            metadata: [
                'structured_output' => [
                    'name' => 'live_status',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                        ],
                        'required' => ['status'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            functions: [[
                'name' => 'lookup_customer',
                'description' => 'Look up a customer by email.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string'],
                    ],
                    'required' => ['email'],
                ],
            ]]
        ));

        $this->assertSuccessfulResponse($structured, 'OpenRouter structured/tool request failed');

        $toolCall = (new AIRequest(
            prompt: 'Use the lookup_invoice tool for invoice INV-LIVE-100. Do not answer directly.',
            engine: EngineEnum::OPENROUTER,
            model: $textModel,
            parameters: ['temperature' => 0, 'max_tokens' => 120]
        ))->withFunctions([
            [
                'name' => 'lookup_invoice',
                'description' => 'Look up an invoice by id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_id' => ['type' => 'string'],
                    ],
                    'required' => ['invoice_id'],
                ],
            ],
        ], ['type' => 'function', 'function' => ['name' => 'lookup_invoice']]);

        $toolResponse = $this->driver->generateText($toolCall);
        $this->assertSuccessfulResponse($toolResponse, 'OpenRouter forced tool-call request failed');
        $this->assertSame('lookup_invoice', $toolResponse->getFunctionCall()['name'] ?? null);
        $this->assertNotEmpty($toolResponse->getMetadata()['tool_calls'] ?? []);

        $routed = $this->driver->generateText(new AIRequest(
            prompt: 'Reply with exactly two words: routed ok',
            engine: EngineEnum::OPENROUTER,
            model: $textModel,
            parameters: [
                'temperature' => 0,
                'max_tokens' => 20,
                'provider' => [
                    'order' => ['openai'],
                ],
            ]
        ));

        $this->assertSuccessfulResponse($routed, 'OpenRouter provider routing request failed');

        $web = $this->driver->generateText(new AIRequest(
            prompt: 'What is OpenRouter? Reply in one short sentence.',
            engine: EngineEnum::OPENROUTER,
            model: $textModel,
            parameters: [
                'temperature' => 0,
                'max_tokens' => 120,
                'plugins' => [
                    ['id' => 'web', 'max_results' => 1],
                ],
            ]
        ));

        $this->assertSuccessfulResponse($web, 'OpenRouter web plugin request failed');
        $this->assertNotSame('', trim($web->getContent()));

        $chunks = iterator_to_array($this->driver->stream(new AIRequest(
            prompt: 'Stream exactly these two words: stream ok',
            engine: EngineEnum::OPENROUTER,
            model: $textModel,
            parameters: ['temperature' => 0, 'max_tokens' => 20]
        )));

        $this->assertNotEmpty($chunks, 'Expected OpenRouter stream to yield at least one chunk.');
        $this->assertNotSame('', trim(implode('', $chunks)));

        $embeddings = $this->driver->generateEmbeddings(new AIRequest(
            prompt: 'Embed this live OpenRouter verification sentence.',
            engine: EngineEnum::OPENROUTER,
            model: $this->envString('AI_ENGINE_OPENROUTER_LIVE_EMBEDDING_MODEL', 'openai/text-embedding-3-small')
        ));

        $this->assertSuccessfulResponse($embeddings, 'OpenRouter embeddings failed');
        $this->assertNotEmpty($embeddings->getMetadata()['embeddings'] ?? []);
        $this->assertGreaterThan(0, $embeddings->getMetadata()['dimensions'] ?? 0);

        $models = $this->driver->getAvailableModels();
        $this->assertNotEmpty($models, 'Expected OpenRouter model catalog to return models.');
        $this->assertContains($textModel, array_column($models, 'id'));
        foreach ($models as $model) {
            $this->assertNotSame('', (string) ($model['id'] ?? ''), 'Every OpenRouter catalog model must expose an id.');
            $this->assertIsArray($model['capabilities'] ?? null, 'Every OpenRouter catalog model must expose normalized capabilities.');
            $this->assertIsArray($model['supported_parameters'] ?? null, 'Every OpenRouter catalog model must expose supported parameters.');
            $this->assertIsArray($model['raw'] ?? null, 'Every OpenRouter catalog model must keep the raw provider payload.');
        }
    }

    public function test_openrouter_live_image_tts_stt_and_sts(): void
    {
        $image = $this->driver->generateImage(new AIRequest(
            prompt: 'A minimal black square centered on a white background',
            engine: EngineEnum::OPENROUTER,
            model: $this->envString('AI_ENGINE_OPENROUTER_LIVE_IMAGE_MODEL', 'google/gemini-2.5-flash-image'),
            parameters: ['aspect_ratio' => '1:1']
        ));

        $this->assertSuccessfulResponse($image, 'OpenRouter image generation failed');
        $this->assertNotEmpty($image->getFiles(), 'Expected OpenRouter image generation to return at least one file.');

        $ttsModel = $this->envString('AI_ENGINE_OPENROUTER_LIVE_TTS_MODEL', 'openai/gpt-4o-mini-tts-2025-12-15');
        $tts = $this->driver->generateAudio(new AIRequest(
            prompt: 'This is a live OpenRouter speech check.',
            engine: EngineEnum::OPENROUTER,
            model: $ttsModel,
            parameters: ['voice' => 'alloy', 'response_format' => 'mp3']
        ));

        $this->assertSuccessfulResponse($tts, 'OpenRouter text-to-speech failed');
        $this->assertNotEmpty($tts->getFiles(), 'Expected OpenRouter TTS to return an audio file.');

        $audioPath = $this->storedPublicPathFromUrl((string) $tts->getFiles()[0]);
        $this->assertFileExists($audioPath);

        $sttModel = $this->envString('AI_ENGINE_OPENROUTER_LIVE_STT_MODEL', 'openai/whisper-1');
        $transcription = $this->driver->audioToText(new AIRequest(
            prompt: '',
            engine: EngineEnum::OPENROUTER,
            model: $sttModel,
            files: [$audioPath],
            parameters: ['language' => 'en', 'format' => 'mp3', 'audio_minutes' => 0.1]
        ));

        $this->assertSuccessfulResponse($transcription, 'OpenRouter speech-to-text failed');
        $this->assertNotSame('', trim($transcription->getContent()));

        $sts = $this->driver->speechToSpeech(new AIRequest(
            prompt: '',
            engine: EngineEnum::OPENROUTER,
            model: $sttModel,
            files: [$audioPath],
            parameters: [
                'language' => 'en',
                'format' => 'mp3',
                'audio_minutes' => 0.1,
                'tts_model' => $ttsModel,
                'voice' => 'nova',
                'response_format' => 'mp3',
            ]
        ));

        $this->assertSuccessfulResponse($sts, 'OpenRouter speech-to-speech failed');
        $this->assertNotEmpty($sts->getFiles(), 'Expected OpenRouter STS to return an audio file.');
        $this->assertSame('speech_to_speech', $sts->getMetadata()['service'] ?? null);
        $this->assertNotSame('', trim((string) ($sts->getMetadata()['transcript'] ?? '')));

        $chatAudio = $this->driver->generateAudio(new AIRequest(
            prompt: 'Say live audio ok in a calm tone.',
            engine: EngineEnum::OPENROUTER,
            model: $this->envString('AI_ENGINE_OPENROUTER_LIVE_CHAT_AUDIO_MODEL', 'openai/gpt-audio-mini'),
            parameters: [
                'modalities' => ['text', 'audio'],
                'audio' => ['voice' => 'alloy', 'format' => 'pcm16'],
            ]
        ));

        $this->assertSuccessfulResponse($chatAudio, 'OpenRouter chat audio-output stream failed');
        $this->assertSame('chat_audio_output', $chatAudio->getMetadata()['service'] ?? null);
        $this->assertNotEmpty($chatAudio->getFiles(), 'Expected OpenRouter chat audio output to return an audio file.');
    }

    public function test_openrouter_live_multimodal_chat_input_accepts_image_and_audio_files(): void
    {
        $imagePath = $this->createLivePngFile();
        $audioPath = $this->createLiveWavFile();

        try {
            $response = $this->driver->generateText(new AIRequest(
                prompt: 'Inspect the image and audio. Reply with exactly one word: multimodal',
                engine: EngineEnum::OPENROUTER,
                model: $this->envString('AI_ENGINE_OPENROUTER_LIVE_MULTIMODAL_MODEL', 'google/gemini-2.5-flash'),
                parameters: ['temperature' => 0, 'max_tokens' => 20],
                files: [$imagePath, $audioPath]
            ));
        } finally {
            @unlink($imagePath);
            @unlink($audioPath);
        }

        $this->assertSuccessfulResponse($response, 'OpenRouter multimodal image/audio chat input failed');
        $this->assertNotSame('', trim($response->getContent()));
    }

    private function assertSuccessfulResponse($response, string $message): void
    {
        $this->assertTrue(
            $response->isSuccessful(),
            $message . ': ' . ($response->getError() ?: json_encode($response->getMetadata(), JSON_UNESCAPED_SLASHES))
        );
    }

    private function storedPublicPathFromUrl(string $url): string
    {
        $path = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: $url), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->path($path);
    }

    private function envString(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function createLivePngFile(): string
    {
        $path = sys_get_temp_dir() . '/ai-engine-openrouter-live-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        ) ?: '');

        return $path;
    }

    private function createLiveWavFile(): string
    {
        $sampleRate = 16000;
        $durationSeconds = 1;
        $samples = $sampleRate * $durationSeconds;
        $data = '';

        for ($i = 0; $i < $samples; $i++) {
            $amplitude = (int) round(8000 * sin(2 * M_PI * 440 * ($i / $sampleRate)));
            $data .= pack('v', $amplitude & 0xffff);
        }

        $path = sys_get_temp_dir() . '/ai-engine-openrouter-live-' . bin2hex(random_bytes(6)) . '.wav';
        $header = 'RIFF'
            . pack('V', 36 + strlen($data))
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            . 'data'
            . pack('V', strlen($data));

        file_put_contents($path, $header . $data);

        return $path;
    }

    private function readBoolEnv(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
