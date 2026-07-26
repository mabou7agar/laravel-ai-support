<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\AIModelCapabilityDetector;
use LaravelAIEngine\Services\ModelCapabilityProfileResolver;
use LaravelAIEngine\Tests\UnitTestCase;

class ModelCapabilityProfileResolverTest extends UnitTestCase
{
    public function test_resolves_host_override_without_model_registry_table(): void
    {
        config()->set('ai-agent.ai_native.model_capabilities', [
            'openrouter' => [
                'vendor/flash' => [
                    'supported_parameters' => ['tools', 'tool_choice', 'reasoning', 'max_tokens'],
                ],
            ],
        ]);

        $profile = (new ModelCapabilityProfileResolver(new AIModelCapabilityDetector()))
            ->resolve('openrouter', 'vendor/flash');

        $this->assertTrue($profile->supportsNativeTools());
        $this->assertTrue($profile->supportsToolChoice());
        $this->assertTrue($profile->supportsReasoning());
        $this->assertFalse($profile->supportsStructuredOutput());
        $this->assertSame('override', $profile->source);
    }

    public function test_request_override_can_disable_inferred_native_tools(): void
    {
        $profile = (new ModelCapabilityProfileResolver(new AIModelCapabilityDetector()))
            ->resolve('openrouter', 'vendor/model', [
                'supported_parameters' => ['tools'],
                'supports_native_tools' => false,
            ]);

        $this->assertFalse($profile->supportsNativeTools());
    }
}
