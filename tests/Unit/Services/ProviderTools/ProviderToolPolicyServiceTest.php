<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\ProviderTools;

use LaravelAIEngine\Services\ProviderTools\ProviderToolPolicyService;
use LaravelAIEngine\Tests\UnitTestCase;

class ProviderToolPolicyServiceTest extends UnitTestCase
{
    public function test_provider_tool_policy_requires_approval_by_risk_threshold(): void
    {
        config()->set('ai-engine.provider_tools.approvals.enabled', true);
        config()->set('ai-engine.provider_tools.approvals.require_for', []);
        config()->set('ai-engine.provider_tools.approvals.require_risk_level_at_or_above', 'medium');
        config()->set('ai-engine.provider_tools.approvals.risk_levels', [
            'web_search' => 'low',
            'mcp_server' => 'medium',
        ]);

        $policy = new ProviderToolPolicyService();

        $this->assertFalse($policy->requiresApproval(['type' => 'web_search']));
        $this->assertTrue($policy->requiresApproval(['type' => 'mcp_server']));
        $this->assertTrue($policy->requiresApproval(['type' => 'computer_use']));
    }

    public function test_provider_tool_policy_detects_sensitive_payloads(): void
    {
        config()->set('ai-engine.provider_tools.approvals.enabled', true);
        config()->set('ai-engine.provider_tools.approvals.require_for', []);
        config()->set('ai-engine.provider_tools.approvals.require_for_sensitive_payloads', true);

        $policy = new ProviderToolPolicyService();
        $sensitivity = $policy->payloadSensitivity([
            'type' => 'web_search',
            'arguments' => [
                'Authorization' => 'Bearer sk-testSecretKeyValue123456',
            ],
        ]);

        $this->assertTrue($sensitivity['sensitive']);
        $this->assertSame('arguments.Authorization', $sensitivity['matches'][0]['path']);
        $this->assertTrue($policy->requiresApproval([
            'type' => 'web_search',
            'arguments' => ['api_key' => 'plain-secret'],
        ]));
    }

    public function test_provider_tool_policy_respects_never_approval_policy(): void
    {
        config()->set('ai-engine.provider_tools.approvals.enabled', true);
        config()->set('ai-engine.provider_tools.approvals.require_risk_level_at_or_above', 'low');

        $policy = new ProviderToolPolicyService();

        $this->assertFalse($policy->requiresApproval([
            'type' => 'computer_use',
            'approval_policy' => 'never',
            'api_key' => 'plain-secret',
        ]));
    }
}
