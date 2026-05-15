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
}
