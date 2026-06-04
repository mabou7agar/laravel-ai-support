<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseBuilderService;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * Guards the opt-in `ai-engine.graph.require_access_scope` hardening: when enabled,
 * a graph query that resolves to NO user identity must match nothing instead of
 * leaking the entire (multi-tenant) graph.
 */
class GraphAccessScopeTest extends UnitTestCase
{
    private function accessPredicate(): string
    {
        $service = new Neo4jRetrievalService();
        $method = new \ReflectionMethod($service, 'accessPredicate');
        $method->setAccessible(true);

        return (string) $method->invoke($service, 'e');
    }

    public function test_retrieval_access_predicate_fails_open_by_default(): void
    {
        config()->set('ai-engine.graph.require_access_scope', false);

        $predicate = $this->accessPredicate();

        // Default (single-tenant/system) behaviour: an unscoped query returns everything.
        $this->assertStringContainsString('ELSE true', $predicate);
        $this->assertStringNotContainsString('ELSE false', $predicate);
    }

    public function test_retrieval_access_predicate_fails_closed_when_scope_required(): void
    {
        config()->set('ai-engine.graph.require_access_scope', true);

        $predicate = $this->accessPredicate();

        // Hardened: an unscoped query (no canonical_user_id / user_email_normalized) denies all.
        $this->assertStringContainsString('ELSE false', $predicate);
        $this->assertStringNotContainsString('ELSE true', $predicate);
        // The scoped branches are untouched — a real user still resolves via CAN_ACCESS.
        $this->assertStringContainsString('CAN_ACCESS', $predicate);
    }

    private function captureSnapshotStatement(): string
    {
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');

        $captured = '';
        Http::fake(function ($request) use (&$captured) {
            $captured = (string) ($request->data()['statement'] ?? '');

            // Return an empty (but successful) result set so no rows are processed.
            return Http::response(['data' => ['fields' => [], 'values' => []]], 202);
        });

        app(GraphKnowledgeBaseBuilderService::class)->buildEntitySnapshots([], 5);

        return $captured;
    }

    public function test_snapshot_builder_includes_unscoped_clause_by_default(): void
    {
        config()->set('ai-engine.graph.require_access_scope', false);

        $statement = $this->captureSnapshotStatement();

        $this->assertStringContainsString('$canonical_user_id IS NULL AND $user_email_normalized IS NULL', $statement);
    }

    public function test_snapshot_builder_drops_unscoped_clause_when_scope_required(): void
    {
        config()->set('ai-engine.graph.require_access_scope', true);

        $statement = $this->captureSnapshotStatement();

        // The NULL-NULL escape hatch is gone, so an unscoped build matches no entities...
        $this->assertStringNotContainsString('IS NULL AND $user_email_normalized IS NULL', $statement);
        // ...while the scoped CAN_ACCESS predicates remain intact.
        $this->assertStringContainsString('CAN_ACCESS', $statement);
    }
}
