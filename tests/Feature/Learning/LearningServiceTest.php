<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Learning;

use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AILearnedItem;
use LaravelAIEngine\Models\AILearnSource;
use LaravelAIEngine\Models\AIVectorStoreDocument;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Learning\LearningService;
use LaravelAIEngine\Tests\TestCase;

class LearningServiceTest extends TestCase
{
    public function test_it_ingests_text_into_scoped_learned_items(): void
    {
        $result = app(LearningService::class)->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'BMW M pages use black backgrounds, white text, red accents, sharp cards, and performance-focused product copy.',
            type: 'design',
            title: 'BMW M style notes',
            tenantId: 'tenant-1',
            workspaceId: 'workspace-1',
            metadata: ['origin' => 'test']
        ));

        $this->assertSame('design', $result->source->type);
        $this->assertSame('tenant-1', $result->source->tenantId);
        $this->assertGreaterThan(0, $result->itemsCount);

        $this->assertDatabaseHas('ai_learn_sources', [
            'source_id' => $result->source->sourceId,
            'type' => 'design',
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
        ]);

        $this->assertGreaterThan(0, AILearnedItem::query()->count());
    }

    public function test_it_searches_only_authorized_scope(): void
    {
        $service = app(LearningService::class);

        $service->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Invoices require customer email, due date, tax rules, and line item totals before confirmation.',
            type: 'workflow',
            title: 'Invoice workflow',
            tenantId: 'tenant-1',
            workspaceId: 'workspace-1'
        ));

        $service->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Landing pages use editorial photography and serif headings.',
            type: 'design',
            title: 'Other tenant style',
            tenantId: 'tenant-2',
            workspaceId: 'workspace-2'
        ));

        $matches = $service->search('how should invoice confirmation work?', [
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
        ]);

        $this->assertNotEmpty($matches);
        $this->assertSame('tenant-1', $matches[0]->source->tenantId);
        $this->assertStringContainsString('Invoices require customer email', $matches[0]->item->content);
    }

    public function test_it_can_index_learned_source_into_vector_store_registry(): void
    {
        $result = app(LearningService::class)->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Support replies should be concise, calm, and include one clear next action.',
            type: 'reply_style',
            title: 'Support reply style',
            workspaceId: 'workspace-1',
            shouldIndex: true
        ));

        $source = AILearnSource::query()->where('source_id', $result->source->sourceId)->firstOrFail();

        $this->assertNotNull($source->vector_store_id);
        $this->assertNotNull($source->indexed_at);
        $this->assertDatabaseCount('ai_vector_stores', 1);
        $this->assertDatabaseCount('ai_vector_store_documents', 1);
        $metadata = AIVectorStoreDocument::query()->firstOrFail()->metadata;
        $this->assertArrayNotHasKey('content', $metadata);
        $this->assertArrayNotHasKey('content_preview', $metadata);
    }

    public function test_learning_ingest_tool_is_opt_in_for_agents(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertFalse($registry->has('learn_source'));
        $this->assertTrue($registry->has('search_learned_context'));

        config()->set('ai-engine.learning.tools.agent_ingest_enabled', true);
        $this->app->forgetInstance(ToolRegistry::class);

        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->has('learn_source'));
    }

    public function test_scoped_search_does_not_include_global_learned_items_by_default(): void
    {
        $service = app(LearningService::class);

        $service->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Global invoice design rule should not leak into scoped workspaces.',
            type: 'design',
            title: 'Global design rule'
        ));

        $service->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Tenant invoice design rule uses compact confirmation tables.',
            type: 'design',
            title: 'Tenant design rule',
            tenantId: 'tenant-1',
            workspaceId: 'workspace-1'
        ));

        $matches = $service->search('invoice design rule', [
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
        ], type: 'design');

        $this->assertNotEmpty($matches);
        $this->assertSame('Tenant design rule', $matches[0]->source->title);
        $this->assertNotContains('Global design rule', array_map(
            static fn ($match): ?string => $match->source->title,
            $matches
        ));
    }

    public function test_learned_context_tool_falls_back_when_specific_scope_has_no_relevant_matches(): void
    {
        $service = app(LearningService::class);

        $service->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Payroll approvals require manager review and signed timesheets.',
            type: 'workflow',
            title: 'Session workflow noise',
            userId: 'designer-1',
            tenantId: 'tenant-1',
            workspaceId: 'workspace-1',
            sessionId: 'chat-1'
        ));

        $service->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Premium automotive hero pages use black contrast, red accent CTAs, sharp cards, and performance metrics.',
            type: 'design',
            title: 'Workspace automotive design',
            tenantId: 'tenant-1',
            workspaceId: 'workspace-1'
        ));

        $this->assertSame([], $service->search('automotive hero red accent', [
            'user_id' => 'designer-1',
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
            'session_id' => 'chat-1',
        ], type: 'design'));

        $tool = app(ToolRegistry::class)->get('search_learned_context');
        $this->assertNotNull($tool);

        $result = $tool->execute([
            'query' => 'automotive hero red accent',
            'type' => 'design',
            'limit' => 2,
        ], new UnifiedActionContext(
            sessionId: 'chat-1',
            userId: 'designer-1',
            metadata: [
                'tenant_id' => 'tenant-1',
                'workspace_id' => 'workspace-1',
            ]
        ));

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->data);
        $this->assertSame('Workspace automotive design', $result->data[0]['source']['title']);
    }

    public function test_learning_ingest_rejects_sources_over_configured_size(): void
    {
        config()->set('ai-engine.learning.max_content_bytes', 16);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the configured size limit');

        app(LearningService::class)->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: str_repeat('large ', 20),
            type: 'design',
            title: 'Too large'
        ));
    }
}
