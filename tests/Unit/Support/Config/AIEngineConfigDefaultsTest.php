<?php

namespace LaravelAIEngine\Tests\Unit\Support\Config;

use LaravelAIEngine\Support\Config\AIEngineConfigDefaults;
use LaravelAIEngine\Tests\UnitTestCase;

class AIEngineConfigDefaultsTest extends UnitTestCase
{
    public function test_package_config_still_exposes_core_defaults(): void
    {
        $this->assertSame('openai', config('ai-engine.default'));
        $this->assertSame(config('ai-engine.default'), config('ai-engine.default_engine'));
        $this->assertTrue(config('ai-engine.engines.openai.models.gpt-4o-mini.enabled'));
        $this->assertSame('HS256', config('ai-engine.nodes.jwt.algorithm'));
        $this->assertSame(10, config('ai-engine.workflow.max_ai_calls'));
    }

    public function test_defaults_class_returns_full_config_tree(): void
    {
        $defaults = AIEngineConfigDefaults::defaults();

        $this->assertIsArray($defaults['engines'] ?? null);
        $this->assertIsArray($defaults['nodes'] ?? null);
        $this->assertIsArray($defaults['workflow'] ?? null);
        $this->assertIsArray($defaults['infrastructure'] ?? null);
        $this->assertSame(true, $defaults['intelligent_rag']['autonomous_mode'] ?? null);
        $this->assertIsArray($defaults['intelligent_rag']['decision'] ?? null);
        $this->assertSame(true, $defaults['intelligent_rag']['decision']['adaptive_feedback']['enabled'] ?? null);
        $this->assertSame(
            ['gpt-5-mini', 'gpt-4o-mini', 'gpt-4o', 'dall-e-3', 'whisper-1'],
            array_keys($defaults['engines']['openai']['models'] ?? [])
        );
        $this->assertSame(
            'https://integrate.api.nvidia.com/v1',
            $defaults['engines']['nvidia_nim']['base_url'] ?? null
        );
        $this->assertSame(
            'nvidia/llama-3.1-nemotron-70b-instruct',
            $defaults['engines']['nvidia_nim']['default_model'] ?? null
        );
    }
}
