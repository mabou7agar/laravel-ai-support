<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\SubAgents;

use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;
use LaravelAIEngine\Services\Agent\SubAgents\EmptySkillRegistry;
use LaravelAIEngine\Tests\TestCase;

/**
 * EmptySkillRegistry is what stops a domain sub-agent from re-matching a global skill (e.g. an
 * "invoice" agent matching the invoice-create skill) and then getting trapped by that skill's
 * required-final-tool guard on a read-only question.
 */
class EmptySkillRegistryTest extends TestCase
{
    public function test_exposes_no_skills(): void
    {
        $registry = new EmptySkillRegistry();

        $this->assertSame([], $registry->skills());
        $this->assertSame([], $registry->capabilityDocuments());
        $this->assertSame([], $registry->providers());
    }

    public function test_neutralizes_skill_matching_so_a_domain_agent_can_finalize(): void
    {
        // An action-verb message would otherwise match a global skill and trigger the
        // required-final-tool guard; with no skills it matches nothing, so the planner finalizes.
        $matcher = new AiNativeSkillMatcher(new EmptySkillRegistry());

        $this->assertFalse($matcher->messageMatchesSkill('create an invoice for Apollo Labs', []));
    }
}
