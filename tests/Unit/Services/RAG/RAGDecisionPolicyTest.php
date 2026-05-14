<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use PHPUnit\Framework\TestCase;

class RAGDecisionPolicyTest extends TestCase
{
    public function test_policy_exposes_stable_defaults(): void
    {
        $policy = new RAGDecisionPolicy();

        $this->assertSame(10, $policy->itemsPerPage());
        $this->assertSame(30, $policy->queryStateTtlMinutes());
        $this->assertSame(6, $policy->conversationSummaryMessageLimit());
        $this->assertSame(200, $policy->conversationSummaryExcerptLimit());
    }

    public function test_policy_normalizes_unknown_aggregate_operations(): void
    {
        $policy = new RAGDecisionPolicy();

        $this->assertSame('sum', $policy->normalizeAggregateOperation('median'));
        $this->assertSame('avg', $policy->normalizeAggregateOperation('AVG'));
        $this->assertSame('summary', $policy->normalizeAggregateOperation('summary'));
    }

    public function test_policy_language_mode_defaults_to_hybrid_without_config(): void
    {
        $policy = new RAGDecisionPolicy();

        $this->assertSame('hybrid', $policy->decisionLanguageMode());
    }
}
