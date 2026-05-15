<?php

namespace LaravelAIEngine\Tests\Unit\Enums;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekEngineDriver;
use LaravelAIEngine\Drivers\Ollama\OllamaEngineDriver;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;

class EntityEnumTest extends TestCase
{
    protected function entity(string $model): EntityEnum
    {
        return EntityEnum::from($model);
    }

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
            'fal-ai/nano-banana-2',
            'fal-ai/kling-video/o3/standard/image-to-video',
            'bytedance/seedance-2.0/text-to-video',
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
        $this->assertEquals(EngineEnum::OPENAI, $this->entity(EntityEnum::GPT_4O)->engine()->value);
        $this->assertEquals(EngineEnum::ANTHROPIC, $this->entity(EntityEnum::CLAUDE_3_5_SONNET)->engine()->value);
        $this->assertEquals(EngineEnum::GEMINI, $this->entity(EntityEnum::GEMINI_1_5_PRO)->engine()->value);
        $this->assertEquals(EngineEnum::STABLE_DIFFUSION, $this->entity(EntityEnum::SD3_LARGE)->engine()->value);
        $this->assertEquals(EngineEnum::FAL_AI, $this->entity(EntityEnum::FAL_FLUX_PRO)->engine()->value);
        $this->assertEquals(EngineEnum::FAL_AI, $this->entity(EntityEnum::FAL_NANO_BANANA_2)->engine()->value);
        $this->assertEquals(EngineEnum::FAL_AI, $this->entity(EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO)->engine()->value);
        $this->assertEquals(EngineEnum::FAL_AI, $this->entity(EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO)->engine()->value);
        $this->assertEquals(EngineEnum::PLAGIARISM_CHECK, $this->entity(EntityEnum::PLAGIARISM_BASIC)->engine()->value);
        $this->assertEquals(EngineEnum::MIDJOURNEY, $this->entity(EntityEnum::MIDJOURNEY_V6)->engine()->value);
        $this->assertEquals(EngineEnum::AZURE, $this->entity(EntityEnum::AZURE_TTS)->engine()->value);
    }

    public function test_entity_content_types()
    {
        $this->assertEquals('text', $this->entity(EntityEnum::GPT_4O)->getContentType());
        $this->assertEquals('image', $this->entity(EntityEnum::DALL_E_3)->getContentType());
        $this->assertEquals('image', $this->entity(EntityEnum::FAL_NANO_BANANA_2)->getContentType());
        $this->assertEquals('audio', $this->entity(EntityEnum::WHISPER_1)->getContentType());
        $this->assertEquals('video', $this->entity(EntityEnum::FAL_STABLE_VIDEO)->getContentType());
        $this->assertEquals('video', $this->entity(EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO)->getContentType());
        $this->assertEquals('video', $this->entity(EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO)->getContentType());
        $this->assertEquals('search', $this->entity(EntityEnum::PERPLEXITY_SONAR_LARGE)->getContentType());
        $this->assertEquals('plagiarism', $this->entity(EntityEnum::PLAGIARISM_BASIC)->getContentType());
    }

    public function test_deepseek_entities_use_existing_driver_class(): void
    {
        $this->assertSame(DeepSeekEngineDriver::class, $this->entity(EntityEnum::DEEPSEEK_CHAT)->driverClass());
        $this->assertSame(DeepSeekEngineDriver::class, $this->entity(EntityEnum::DEEPSEEK_REASONER)->driverClass());
    }

    public function test_dynamic_provider_models_use_existing_driver_classes(): void
    {
        config(['ai-engine.engines.ollama.models.custom-ollama-model' => []]);

        $this->assertSame(OpenRouterEngineDriver::class, $this->entity('meta-llama/custom-openrouter-model')->driverClass());
        $this->assertSame(OllamaEngineDriver::class, $this->entity('custom-ollama-model')->driverClass());
    }

    public function test_entity_credit_indices()
    {
        $this->assertIsFloat($this->entity(EntityEnum::GPT_4O)->creditIndex());
        $this->assertGreaterThan(0, $this->entity(EntityEnum::GPT_4O)->creditIndex());
        
        // Premium models should have higher credit indices
        $this->assertGreaterThan(
            $this->entity(EntityEnum::GPT_4O_MINI)->creditIndex(),
            $this->entity(EntityEnum::GPT_4O)->creditIndex()
        );
    }

    public function test_entity_max_tokens()
    {
        $this->assertIsInt($this->entity(EntityEnum::GPT_4O)->maxTokens());
        $this->assertGreaterThan(0, $this->entity(EntityEnum::GPT_4O)->maxTokens());
        
        // GPT-4o should have high token limit
        $this->assertGreaterThanOrEqual(128000, $this->entity(EntityEnum::GPT_4O)->maxTokens());
    }

    public function test_entity_streaming_support()
    {
        $this->assertTrue($this->entity(EntityEnum::GPT_4O)->supportsStreaming());
        $this->assertTrue($this->entity(EntityEnum::CLAUDE_3_5_SONNET)->supportsStreaming());
        $this->assertFalse($this->entity(EntityEnum::DALL_E_3)->supportsStreaming());
        $this->assertFalse($this->entity(EntityEnum::MIDJOURNEY_V6)->supportsStreaming());
    }

    public function test_entity_vision_support()
    {
        $this->assertTrue($this->entity(EntityEnum::GPT_4O)->supportsVision());
        $this->assertTrue($this->entity(EntityEnum::CLAUDE_3_5_SONNET)->supportsVision());
        $this->assertTrue($this->entity(EntityEnum::GEMINI_1_5_PRO)->supportsVision());
        $this->assertFalse($this->entity(EntityEnum::GPT_3_5_TURBO)->supportsVision());
    }

    public function test_entity_calculation_methods()
    {
        $this->assertEquals('words', $this->entity(EntityEnum::GPT_4O)->calculationMethod());
        $this->assertEquals('images', $this->entity(EntityEnum::DALL_E_3)->calculationMethod());
        $this->assertEquals('minutes', $this->entity(EntityEnum::WHISPER_1)->calculationMethod());
        $this->assertEquals('videos', $this->entity(EntityEnum::FAL_STABLE_VIDEO)->calculationMethod());
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
