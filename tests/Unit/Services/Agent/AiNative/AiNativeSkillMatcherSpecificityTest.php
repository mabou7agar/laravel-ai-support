<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativeSkillMatcherSpecificityTest extends UnitTestCase
{
    private function matcher(array $skills): AiNativeSkillMatcher
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn($skills);

        return new AiNativeSkillMatcher($registry);
    }

    public function test_the_most_specific_trigger_wins_regardless_of_registration_order(): void
    {
        $matcher = $this->matcher([
            new AgentSkillDefinition(id: 'sales_module', name: 'Sales', description: 'Sales records', triggers: ['sales invoice', 'sales']),
            new AgentSkillDefinition(id: 'invoice_create', name: 'Create invoice', description: 'Create invoices', triggers: ['create sales invoice']),
        ]);

        $this->assertSame('invoice_create', $matcher->matchedSkillId('Please create a sales invoice for Acme', []));
        $this->assertSame('sales_module', $matcher->matchedSkillId('how many sales invoices do I have', []));
        $this->assertNull($matcher->matchedSkillId('what is the weather', []));
    }

    public function test_equal_specificity_keeps_registration_order(): void
    {
        $matcher = $this->matcher([
            new AgentSkillDefinition(id: 'first', name: 'First', description: 'x', triggers: ['report']),
            new AgentSkillDefinition(id: 'second', name: 'Second', description: 'y', triggers: ['report']),
        ]);

        $this->assertSame('first', $matcher->matchedSkillId('build a report', []));
    }

    public function test_a_stopword_only_trigger_does_not_match_every_message(): void
    {
        $matcher = $this->matcher([
            new AgentSkillDefinition(id: 'noise', name: 'Noise', description: 'x', triggers: ['the']),
        ]);

        $this->assertNull($matcher->matchedSkillId('show invoices', []));
    }
}
