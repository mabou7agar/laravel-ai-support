<?php

namespace LaravelAIEngine\Tests\Unit\Support\Config;

use LaravelAIEngine\Support\Config\AIEngineConfigDefaults;
use LaravelAIEngine\Tests\UnitTestCase;

class AIEngineConfigDefaultsTest extends UnitTestCase
{
    public function test_package_config_still_exposes_core_defaults(): void
    {
        $this->assertSame('openai', config('ai-engine.default'));
        $this->assertNull(config('ai-engine.default_engine'));
        $this->assertTrue(config('ai-engine.engines.openai.models.gpt-4o-mini.enabled'));
        $this->assertSame('HS256', config('ai-engine.nodes.jwt.algorithm'));
        $this->assertSame(true, config('ai-engine.provider_tools.approvals.enabled'));
    }

    public function test_defaults_class_returns_full_config_tree(): void
    {
        $defaults = AIEngineConfigDefaults::defaults();

        $this->assertIsArray($defaults['engines'] ?? null);
        $this->assertIsArray($defaults['nodes'] ?? null);
        $this->assertIsArray($defaults['infrastructure'] ?? null);
        $this->assertSame(['computer_use', 'mcp_server', 'code_interpreter'], $defaults['provider_tools']['approvals']['require_for'] ?? null);
        $this->assertSame(true, $defaults['rag']['autonomous_mode'] ?? null);
        $this->assertSame(false, $defaults['rag']['hybrid']['enabled'] ?? null);
        $this->assertSame('vector_then_graph', $defaults['rag']['hybrid']['strategy'] ?? null);
        $this->assertSame('qdrant', $defaults['rag']['hybrid']['vector_driver'] ?? null);
        $this->assertSame('neo4j', $defaults['rag']['hybrid']['graph_driver'] ?? null);
        $this->assertIsArray($defaults['rag']['decision'] ?? null);
        $this->assertSame(true, $defaults['rag']['decision']['adaptive_feedback']['enabled'] ?? null);
        $this->assertSame(
            ['gpt-5-mini', 'gpt-4o-mini', 'gpt-4o', 'gpt-image-1.5', 'gpt-image-1', 'gpt-image-1-mini', 'dall-e-3', 'whisper-1'],
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
