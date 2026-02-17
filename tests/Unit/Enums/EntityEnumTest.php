<?php

namespace LaravelAIEngine\Tests\Unit\Enums;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EngineEnum;

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
        $this->assertEquals(EngineEnum::OPENAI, EntityEnum::from(EntityEnum::GPT_4O)->engine()->value);
        $this->assertEquals(EngineEnum::ANTHROPIC, EntityEnum::from(EntityEnum::CLAUDE_3_5_SONNET)->engine()->value);
        $this->assertEquals(EngineEnum::GEMINI, EntityEnum::from(EntityEnum::GEMINI_1_5_PRO)->engine()->value);
        $this->assertEquals(EngineEnum::STABLE_DIFFUSION, EntityEnum::from(EntityEnum::SD3_LARGE)->engine()->value);
        $this->assertEquals(EngineEnum::FAL_AI, EntityEnum::from(EntityEnum::FAL_FLUX_PRO)->engine()->value);
        $this->assertEquals(EngineEnum::PLAGIARISM_CHECK, EntityEnum::from(EntityEnum::PLAGIARISM_BASIC)->engine()->value);
        $this->assertEquals(EngineEnum::MIDJOURNEY, EntityEnum::from(EntityEnum::MIDJOURNEY_V6)->engine()->value);
        $this->assertEquals(EngineEnum::AZURE, EntityEnum::from(EntityEnum::AZURE_TTS)->engine()->value);
    }

    public function test_entity_content_types()
    {
        $this->assertEquals('text', EntityEnum::from(EntityEnum::GPT_4O)->getContentType());
        $this->assertEquals('image', EntityEnum::from(EntityEnum::DALL_E_3)->getContentType());
        $this->assertEquals('audio', EntityEnum::from(EntityEnum::WHISPER_1)->getContentType());
        $this->assertEquals('text', EntityEnum::from(EntityEnum::FAL_STABLE_VIDEO)->getContentType());
        $this->assertEquals('text', EntityEnum::from(EntityEnum::PERPLEXITY_SONAR_LARGE)->getContentType());
        $this->assertEquals('plagiarism', EntityEnum::from(EntityEnum::PLAGIARISM_BASIC)->getContentType());
    }

    public function test_entity_credit_indices()
    {
        $this->assertIsFloat(EntityEnum::from(EntityEnum::GPT_4O)->creditIndex());
        $this->assertGreaterThan(0, EntityEnum::from(EntityEnum::GPT_4O)->creditIndex());
        
        // Premium models should have higher credit indices
        $this->assertGreaterThan(
            EntityEnum::from(EntityEnum::GPT_4O_MINI)->creditIndex(),
            EntityEnum::from(EntityEnum::GPT_4O)->creditIndex()
        );
    }

    public function test_entity_max_tokens()
    {
        $this->assertIsInt(EntityEnum::from(EntityEnum::GPT_4O)->maxTokens());
        $this->assertGreaterThan(0, EntityEnum::from(EntityEnum::GPT_4O)->maxTokens());
        
        // GPT-4o should have high token limit
        $this->assertGreaterThanOrEqual(128000, EntityEnum::from(EntityEnum::GPT_4O)->maxTokens());
    }

    public function test_entity_streaming_support()
    {
        $this->assertTrue(EntityEnum::from(EntityEnum::GPT_4O)->supportsStreaming());
        $this->assertTrue(EntityEnum::from(EntityEnum::CLAUDE_3_5_SONNET)->supportsStreaming());
        $this->assertTrue(EntityEnum::from(EntityEnum::DALL_E_3)->supportsStreaming());
        $this->assertTrue(EntityEnum::from(EntityEnum::MIDJOURNEY_V6)->supportsStreaming());
    }

    public function test_entity_vision_support()
    {
        $this->assertTrue(EntityEnum::from(EntityEnum::GPT_4O)->supportsVision());
        $this->assertFalse(EntityEnum::from(EntityEnum::CLAUDE_3_5_SONNET)->supportsVision());
        $this->assertTrue(EntityEnum::from(EntityEnum::GEMINI_1_5_PRO)->supportsVision());
        $this->assertFalse(EntityEnum::from(EntityEnum::GPT_3_5_TURBO)->supportsVision());
    }

    public function test_entity_calculation_methods()
    {
        $this->assertEquals('words', EntityEnum::from(EntityEnum::GPT_4O)->calculationMethod());
        $this->assertEquals('images', EntityEnum::from(EntityEnum::DALL_E_3)->calculationMethod());
        $this->assertEquals('minutes', EntityEnum::from(EntityEnum::WHISPER_1)->calculationMethod());
        $this->assertEquals('words', EntityEnum::from(EntityEnum::FAL_STABLE_VIDEO)->calculationMethod());
    }

    public function test_all_entities_have_valid_engines()
    {
        foreach (EntityEnum::cases() as $entity) {
            $engine = $entity->engine();
            $this->assertInstanceOf(EngineEnum::class, $engine);
            $this->assertNotEmpty($engine->value);
        }
    }

    public function test_all_entities_have_valid_credit_indices()
    {
        foreach (EntityEnum::cases() as $entity) {
            $creditIndex = $entity->creditIndex();
            $this->assertIsFloat($creditIndex);
            $this->assertGreaterThanOrEqual(0, $creditIndex, "Entity {$entity->value} should have non-negative credit index");
            
            // Free models should have 0.0 credit index
            if (str_contains($entity->value, ':free')) {
                $this->assertEquals(0.0, $creditIndex, "Free model {$entity->value} should have 0.0 credit index");
            } else {
                $this->assertGreaterThan(0, $creditIndex, "Non-free entity {$entity->value} should have positive credit index");
            }
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
