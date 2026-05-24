<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Generator;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AIEngineServiceTranscriptionNormalizationTest extends UnitTestCase
{
    public function test_generate_direct_normalizes_speech_to_text_response_when_requested(): void
    {
        $service = $this->serviceWithDrivers(
            localResponse: AIResponse::success('اريد ان شاء فاتورة', 'local_audio', 'local-whisper', ['service' => 'speech_to_text']),
            normalizedResponse: AIResponse::success('أريد إنشاء فاتورة', 'openai', 'gpt-4o-mini')
        );

        $response = $service->generateDirect(new AIRequest(
            prompt: 'Transcribe this audio file.',
            engine: 'local_audio',
            model: 'local-whisper',
            parameters: [
                'normalize' => true,
                'language' => 'ar',
                'prompt' => 'انشاء فاتورة',
            ],
            files: ['/tmp/audio.wav']
        ));

        $this->assertSame('أريد إنشاء فاتورة', $response->getContent());
        $this->assertTrue($response->getMetadata()['transcription_normalization']['applied']);
        $this->assertSame('اريد ان شاء فاتورة', $response->getMetadata()['transcription_normalization']['raw_transcript']);
    }

    public function test_speech_to_text_operation_normalizes_audio_to_text_response_when_requested(): void
    {
        $service = $this->serviceWithDrivers(
            localResponse: AIResponse::success('create invoys', 'local_audio', 'local-whisper', ['service' => 'speech_to_text']),
            normalizedResponse: AIResponse::success('create invoice', 'openai', 'gpt-4o-mini')
        );

        $response = $service->speechToText(new AIRequest(
            prompt: '',
            engine: 'local_audio',
            model: 'local-whisper',
            parameters: ['normalize_transcript' => true],
            files: ['/tmp/audio.wav']
        ));

        $this->assertSame('create invoice', $response->getContent());
        $this->assertTrue($response->getMetadata()['transcription_normalization']['applied']);
    }

    public function test_request_can_disable_global_transcription_normalization(): void
    {
        config()->set('ai-engine.media.transcription_normalization.enabled', true);

        $service = $this->serviceWithDrivers(
            localResponse: AIResponse::success('raw transcript', 'local_audio', 'local-whisper', ['service' => 'speech_to_text']),
            normalizedResponse: AIResponse::success('normalized transcript', 'openai', 'gpt-4o-mini'),
            expectNormalizer: false
        );

        $response = $service->generateDirect(new AIRequest(
            prompt: 'Transcribe this audio file.',
            engine: 'local_audio',
            model: 'local-whisper',
            parameters: ['normalize' => false],
            files: ['/tmp/audio.wav']
        ));

        $this->assertSame('raw transcript', $response->getContent());
        $this->assertArrayNotHasKey('transcription_normalization', $response->getMetadata());
    }

    private function serviceWithDrivers(
        AIResponse $localResponse,
        AIResponse $normalizedResponse,
        bool $expectNormalizer = true
    ): AIEngineService {
        $localDriver = $this->driver(EngineEnum::LocalAudio, $localResponse);
        $normalizerDriver = $this->driver(EngineEnum::OpenAI, $normalizedResponse, function (AIRequest $request): void {
            $this->assertSame('openai', $request->getEngine()->value);
            $this->assertSame('gpt-4o-mini', $request->getModel()->value);
            $this->assertStringContainsString('Normalize this speech-to-text transcript.', $request->getPrompt());
        });

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')
            ->with(Mockery::on(fn ($engine): bool => $engine instanceof EngineEnum && $engine === EngineEnum::LocalAudio))
            ->andReturn($localDriver);

        $openAiExpectation = $registry->shouldReceive('resolve')
            ->with(Mockery::on(fn ($engine): bool => $engine instanceof EngineEnum && $engine === EngineEnum::OpenAI));

        $expectNormalizer
            ? $openAiExpectation->andReturn($normalizerDriver)
            : $openAiExpectation->never();

        config()->set('ai-engine.credits.enabled', false);

        return new AIEngineService(app(CreditManager::class), null, $registry);
    }

    private function driver(EngineEnum $engine, AIResponse $response, ?callable $assertRequest = null): EngineDriverInterface
    {
        return new class($engine, $response, $assertRequest) implements EngineDriverInterface {
            public function __construct(
                private readonly EngineEnum $engine,
                private readonly AIResponse $response,
                private readonly mixed $assertRequest = null
            ) {}

            public function generate(AIRequest $request): AIResponse
            {
                if (is_callable($this->assertRequest)) {
                    ($this->assertRequest)($request);
                }

                return $this->response;
            }

            public function audioToText(AIRequest $request): AIResponse
            {
                return $this->generate($request);
            }

            public function stream(AIRequest $request): Generator
            {
                yield $this->response->getContent();
            }

            public function validateRequest(AIRequest $request): bool
            {
                return true;
            }

            public function getEngine(): EngineEnum
            {
                return $this->engine;
            }

            public function supports(string $capability): bool
            {
                return true;
            }

            public function getAvailableModels(): array
            {
                return [];
            }

            public function test(): bool
            {
                return true;
            }

            public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
            {
                return '{}';
            }
        };
    }
}
