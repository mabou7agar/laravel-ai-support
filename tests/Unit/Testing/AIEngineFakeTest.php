<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Testing;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Testing\AIEngineFake;
use LaravelAIEngine\Tests\UnitTestCase;

class AIEngineFakeTest extends UnitTestCase
{
    public function test_fake_records_image_stream_embedding_engine_model_and_temperature_assertions(): void
    {
        $fake = new AIEngineFake(['image-url', 'first chunk', 'embedding']);

        $fake->generateImage(new AIRequest('draw', EngineEnum::OPENAI, EntityEnum::DALL_E_3));
        iterator_to_array($fake->streamPrompt('stream this', [
            'engine' => EngineEnum::ANTHROPIC,
            'model' => EntityEnum::CLAUDE_3_5_SONNET,
            'temperature' => 0.2,
        ]));
        $fake->generateEmbeddings(new AIRequest('embed', EngineEnum::OPENAI, EntityEnum::GPT_4O_MINI));

        $fake->assertImageGenerated();
        $fake->assertStreamed();
        $fake->assertEmbeddingsRequested();
        $fake->assertEngineUsed(EngineEnum::ANTHROPIC);
        $fake->assertModelUsed(EntityEnum::CLAUDE_3_5_SONNET);
        $fake->assertTemperatureUsed(0.2);

        $this->assertCount(3, $fake->calls());
    }

    public function test_fake_records_speech_to_text_and_speech_to_speech_from_proxy(): void
    {
        $fake = new AIEngineFake();

        $fake->engine(EngineEnum::ELEVENLABS)
            ->model(EntityEnum::ELEVEN_SCRIBE_V2)
            ->speechToText('/tmp/input.wav');

        $fake->engine(EngineEnum::ELEVENLABS)
            ->model(EntityEnum::ELEVEN_MULTILINGUAL_STS_V2)
            ->speechToSpeech('/tmp/input.wav', parameters: ['voice_id' => 'voice_123']);

        $fake->assertAudioTranscribed();
        $fake->assertSpeechToSpeechGenerated();
        $fake->assertEngineUsed(EngineEnum::ELEVENLABS);
        $fake->assertModelUsed(EntityEnum::ELEVEN_MULTILINGUAL_STS_V2);

        $this->assertCount(4, $fake->calls());
    }
}
