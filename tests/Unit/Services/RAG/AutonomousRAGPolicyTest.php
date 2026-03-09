<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use PHPUnit\Framework\TestCase;

class AutonomousRAGPolicyTest extends TestCase
{
    public function test_policy_exposes_stable_defaults(): void
    {
        $policy = new AutonomousRAGPolicy();

        $this->assertSame(10, $policy->itemsPerPage());
        $this->assertSame(30, $policy->queryStateTtlMinutes());
        $this->assertSame(6, $policy->conversationSummaryMessageLimit());
        $this->assertSame(200, $policy->conversationSummaryExcerptLimit());
    }

    public function test_policy_normalizes_unknown_aggregate_operations(): void
    {
        $policy = new AutonomousRAGPolicy();

        $this->assertSame('sum', $policy->normalizeAggregateOperation('median'));
        $this->assertSame('avg', $policy->normalizeAggregateOperation('AVG'));
    }

    public function test_policy_language_mode_defaults_to_hybrid_without_config(): void
    {
        $policy = new AutonomousRAGPolicy();

        $this->assertSame('hybrid', $policy->decisionLanguageMode());
    }
}
