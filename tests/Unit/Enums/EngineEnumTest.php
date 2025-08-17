<?php

namespace LaravelAIEngine\Tests\Unit\Enums;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;

class EngineEnumTest extends TestCase
{
    public function test_engine_enum_cases_exist()
    {
        $expectedEngines = [
            'openai',
            'anthropic',
            'gemini',
            'stable_diffusion',
            'eleven_labs',
            'fal_ai',
            'deepseek',
            'perplexity',
            'midjourney',
            'azure',
            'serper',
            'plagiarism_check',
            'unsplash',
            'pexels',
        ];

        $actualEngines = array_map(fn($case) => $case->value, EngineEnum::cases());

        foreach ($expectedEngines as $engine) {
            $this->assertContains($engine, $actualEngines, "Engine '{$engine}' should exist in EngineEnum");
        }
    }

    public function test_engine_driver_class_mapping()
    {
        $this->assertEquals(
            OpenAIEngineDriver::class,
            EngineEnum::OPENAI->driverClass()
        );

        $this->assertEquals(
            AnthropicEngineDriver::class,
            EngineEnum::ANTHROPIC->driverClass()
        );
    }

    public function test_engine_capabilities()
    {
        $openaiCapabilities = EngineEnum::OPENAI->capabilities();
        
        $this->assertIsArray($openaiCapabilities);
        $this->assertContains('text', $openaiCapabilities);
        $this->assertContains('images', $openaiCapabilities);
        $this->assertContains('audio', $openaiCapabilities);
    }

    public function test_engine_default_models()
    {
        $openaiModels = EngineEnum::OPENAI->getDefaultModels();
        
        $this->assertIsArray($openaiModels);
        $this->assertNotEmpty($openaiModels);
    }

    public function test_engine_from_string()
    {
        $engine = EngineEnum::from('openai');
        $this->assertEquals(EngineEnum::OPENAI, $engine);
    }

    public function test_engine_try_from_invalid()
    {
        $engine = EngineEnum::tryFrom('invalid_engine');
        $this->assertNull($engine);
    }

    public function test_all_engines_have_driver_classes()
    {
        foreach (EngineEnum::cases() as $engine) {
            $driverClass = $engine->driverClass();
            $this->assertNotEmpty($driverClass, "Engine {$engine->value} should have a driver class");
            $this->assertTrue(
                class_exists($driverClass) || interface_exists($driverClass),
                "Driver class {$driverClass} should exist for engine {$engine->value}"
            );
        }
    }
}
