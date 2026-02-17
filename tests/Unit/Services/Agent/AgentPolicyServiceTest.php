<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentPolicyService;
use PHPUnit\Framework\TestCase;

class AgentPolicyServiceTest extends TestCase
{
    public function test_returns_destructive_message_when_destructive_intent_detected(): void
    {
        $service = new AgentPolicyService([
            'intents' => [
                'destructive_verbs' => ['delete', 'remove', 'cancel'],
            ],
            'messages' => [
                'no_collector_destructive' => 'Destructive blocked',
                'no_collector_generic' => 'Generic fallback',
            ],
        ]);

        $this->assertSame('Destructive blocked', $service->noCollectorMatchMessage('please delete this invoice'));
    }

    public function test_returns_generic_message_when_no_destructive_intent(): void
    {
        $service = new AgentPolicyService([
            'intents' => [
                'destructive_verbs' => ['delete', 'remove', 'cancel'],
            ],
            'messages' => [
                'no_collector_destructive' => 'Destructive blocked',
                'no_collector_generic' => 'Generic fallback',
            ],
        ]);

        $this->assertSame('Generic fallback', $service->noCollectorMatchMessage('create new invoice'));
    }

    public function test_resume_message_supports_collector_placeholder(): void
    {
        $service = new AgentPolicyService([
            'messages' => [
                'resume_session' => 'Continue :collector now',
            ],
        ]);

        $this->assertSame('Continue invoice now', $service->resumeSessionMessage('invoice'));
    }

    public function test_selected_option_message_supports_placeholders(): void
    {
        $service = new AgentPolicyService([
            'messages' => [
                'selected_option' => 'Option :number => :detail',
            ],
        ]);

        $this->assertSame('Option 2 => Invoice details', $service->selectedOptionMessage(2, 'Invoice details'));
    }

    public function test_collector_unavailable_message_supports_placeholder(): void
    {
        $service = new AgentPolicyService([
            'messages' => [
                'collector_unavailable' => 'Collector missing: :collector',
            ],
        ]);

        $this->assertSame('Collector missing: invoice', $service->collectorUnavailableMessage('invoice'));
    }

    public function test_tool_not_specified_message_is_configurable(): void
    {
        $service = new AgentPolicyService([
            'messages' => [
                'tool_not_specified' => 'Tool name is required',
            ],
        ]);

        $this->assertSame('Tool name is required', $service->toolNotSpecifiedMessage());
    }
}
