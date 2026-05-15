<?php

namespace LaravelAIEngine\Tests\Unit\Enums;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;
use LaravelAIEngine\Drivers\NvidiaNim\NvidiaNimEngineDriver;
use LaravelAIEngine\Drivers\Azure\AzureEngineDriver;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekEngineDriver;
use LaravelAIEngine\Drivers\Midjourney\MidjourneyEngineDriver;
use LaravelAIEngine\Drivers\Perplexity\PerplexityEngineDriver;
use LaravelAIEngine\Drivers\PlagiarismCheck\PlagiarismCheckEngineDriver;
use LaravelAIEngine\Drivers\Serper\SerperEngineDriver;
use LaravelAIEngine\Drivers\Unsplash\UnsplashEngineDriver;

class EngineEnumTest extends TestCase
{
    protected function engine(string $value): EngineEnum
    {
        return EngineEnum::from($value);
    }

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
            'nvidia_nim',
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
            $this->engine(EngineEnum::OPENAI)->driverClass()
        );

        $this->assertEquals(
            AnthropicEngineDriver::class,
            $this->engine(EngineEnum::ANTHROPIC)->driverClass()
        );

        $this->assertEquals(
            NvidiaNimEngineDriver::class,
            $this->engine(EngineEnum::NVIDIA_NIM)->driverClass()
        );

        $this->assertEquals(DeepSeekEngineDriver::class, $this->engine(EngineEnum::DEEPSEEK)->driverClass());
        $this->assertEquals(PerplexityEngineDriver::class, $this->engine(EngineEnum::PERPLEXITY)->driverClass());
        $this->assertEquals(MidjourneyEngineDriver::class, $this->engine(EngineEnum::MIDJOURNEY)->driverClass());
        $this->assertEquals(AzureEngineDriver::class, $this->engine(EngineEnum::AZURE)->driverClass());
        $this->assertEquals(SerperEngineDriver::class, $this->engine(EngineEnum::SERPER)->driverClass());
        $this->assertEquals(PlagiarismCheckEngineDriver::class, $this->engine(EngineEnum::PLAGIARISM_CHECK)->driverClass());
        $this->assertEquals(UnsplashEngineDriver::class, $this->engine(EngineEnum::UNSPLASH)->driverClass());
    }

    public function test_engine_capabilities()
    {
        $openaiCapabilities = $this->engine(EngineEnum::OPENAI)->capabilities();
        
        $this->assertIsArray($openaiCapabilities);
        $this->assertContains('text', $openaiCapabilities);
        $this->assertContains('images', $openaiCapabilities);
        $this->assertContains('audio', $openaiCapabilities);
    }

    public function test_engine_default_models()
    {
        $openaiModels = $this->engine(EngineEnum::OPENAI)->getDefaultModels();
        
        $this->assertIsArray($openaiModels);
        $this->assertNotEmpty($openaiModels);
    }

    public function test_engine_from_string()
    {
        $engine = EngineEnum::from('openai');
        $this->assertEquals(EngineEnum::OPENAI, $engine->value);
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
