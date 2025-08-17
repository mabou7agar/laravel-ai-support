<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Enums;

use MagicAI\LaravelAIEngine\Tests\TestCase;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;

class EntityEnumTest extends TestCase
{
    public function test_entity_enum_cases_exist()
    {
        $expectedModels = [
            'gpt-4o',
            'gpt-4o-mini',
            'dall-e-3',
            'claude-3-5-sonnet-20240620',
            'gemini-1.5-pro',
            'sd3-large',
            'eleven_multilingual_v2',
            'deepseek-chat',
            'fal-flux-pro',
            'plagiarism-basic',
            'midjourney-v6',
            'azure-tts',
        ];

        $actualModels = array_map(fn($case) => $case->value, EntityEnum::cases());

        foreach ($expectedModels as $model) {
            $this->assertContains($model, $actualModels, "Model '{$model}' should exist in EntityEnum");
        }
    }

    public function test_entity_engine_mapping()
    {
        $this->assertEquals(EngineEnum::OPENAI, EntityEnum::GPT_4O->engine());
        $this->assertEquals(EngineEnum::ANTHROPIC, EntityEnum::CLAUDE_3_5_SONNET->engine());
        $this->assertEquals(EngineEnum::GEMINI, EntityEnum::GEMINI_1_5_PRO->engine());
        $this->assertEquals(EngineEnum::STABLE_DIFFUSION, EntityEnum::SD3_LARGE->engine());
        $this->assertEquals(EngineEnum::FAL_AI, EntityEnum::FAL_FLUX_PRO->engine());
        $this->assertEquals(EngineEnum::PLAGIARISM_CHECK, EntityEnum::PLAGIARISM_BASIC->engine());
        $this->assertEquals(EngineEnum::MIDJOURNEY, EntityEnum::MIDJOURNEY_V6->engine());
        $this->assertEquals(EngineEnum::AZURE, EntityEnum::AZURE_TTS->engine());
    }

    public function test_entity_content_types()
    {
        $this->assertEquals('text', EntityEnum::GPT_4O->getContentType());
        $this->assertEquals('image', EntityEnum::DALL_E_3->getContentType());
        $this->assertEquals('audio', EntityEnum::WHISPER_1->getContentType());
        $this->assertEquals('video', EntityEnum::FAL_STABLE_VIDEO->getContentType());
        $this->assertEquals('search', EntityEnum::PERPLEXITY_SONAR_LARGE->getContentType());
        $this->assertEquals('plagiarism', EntityEnum::PLAGIARISM_BASIC->getContentType());
    }

    public function test_entity_credit_indices()
    {
        $this->assertIsFloat(EntityEnum::GPT_4O->creditIndex());
        $this->assertGreaterThan(0, EntityEnum::GPT_4O->creditIndex());
        
        // Premium models should have higher credit indices
        $this->assertGreaterThan(
            EntityEnum::GPT_4O_MINI->creditIndex(),
            EntityEnum::GPT_4O->creditIndex()
        );
    }

    public function test_entity_max_tokens()
    {
        $this->assertIsInt(EntityEnum::GPT_4O->maxTokens());
        $this->assertGreaterThan(0, EntityEnum::GPT_4O->maxTokens());
        
        // GPT-4o should have high token limit
        $this->assertGreaterThanOrEqual(128000, EntityEnum::GPT_4O->maxTokens());
    }

    public function test_entity_streaming_support()
    {
        $this->assertTrue(EntityEnum::GPT_4O->supportsStreaming());
        $this->assertTrue(EntityEnum::CLAUDE_3_5_SONNET->supportsStreaming());
        $this->assertFalse(EntityEnum::DALL_E_3->supportsStreaming());
        $this->assertFalse(EntityEnum::MIDJOURNEY_V6->supportsStreaming());
    }

    public function test_entity_vision_support()
    {
        $this->assertTrue(EntityEnum::GPT_4O->supportsVision());
        $this->assertTrue(EntityEnum::CLAUDE_3_5_SONNET->supportsVision());
        $this->assertTrue(EntityEnum::GEMINI_1_5_PRO->supportsVision());
        $this->assertFalse(EntityEnum::GPT_3_5_TURBO->supportsVision());
    }

    public function test_entity_calculation_methods()
    {
        $this->assertEquals('words', EntityEnum::GPT_4O->calculationMethod());
        $this->assertEquals('images', EntityEnum::DALL_E_3->calculationMethod());
        $this->assertEquals('minutes', EntityEnum::WHISPER_1->calculationMethod());
        $this->assertEquals('videos', EntityEnum::FAL_STABLE_VIDEO->calculationMethod());
    }

    public function test_all_entities_have_valid_engines()
    {
        foreach (EntityEnum::cases() as $entity) {
            $engine = $entity->engine();
            $this->assertInstanceOf(EngineEnum::class, $engine);
            $this->assertNotEmpty($engine->value);
        }
    }

    public function test_all_entities_have_positive_credit_indices()
    {
        foreach (EntityEnum::cases() as $entity) {
            $creditIndex = $entity->creditIndex();
            $this->assertIsFloat($creditIndex);
            $this->assertGreaterThan(0, $creditIndex, "Entity {$entity->value} should have positive credit index");
        }
    }

    public function test_all_entities_have_valid_content_types()
    {
        $validContentTypes = ['text', 'image', 'video', 'audio', 'search', 'plagiarism', 'translation'];
        
        foreach (EntityEnum::cases() as $entity) {
            $contentType = $entity->getContentType();
            $this->assertContains(
                $contentType,
                $validContentTypes,
                "Entity {$entity->value} has invalid content type: {$contentType}"
            );
        }
    }
}
