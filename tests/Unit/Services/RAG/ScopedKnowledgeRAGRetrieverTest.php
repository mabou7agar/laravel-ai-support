<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Contracts\KnowledgeSourceProvider;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\Enums\KnowledgeScope;
use LaravelAIEngine\Services\RAG\RAGRetriever;
use LaravelAIEngine\Tests\UnitTestCase;

final class ScopedKnowledgeRAGRetrieverTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-agent.assistant.knowledge_sources', [
            MultiScopeKnowledgeSourceProvider::class,
        ]);
        config()->set('ai-agent.assistant.knowledge_index.rag_enabled', true);
    }

    public function test_registered_knowledge_sources_are_connected_to_the_standard_rag_retriever(): void
    {
        $sources = app(RAGRetriever::class)->retrieve(
            ['shared deployment guide'],
            [],
            ['retrievers' => ['scoped_knowledge']],
        );

        self::assertCount(1, $sources);
        self::assertSame('global', $sources[0]->id);
        self::assertSame('scoped_knowledge', $sources[0]->type);
        self::assertSame('global_shared', $sources[0]->metadata['knowledge_scope']);
    }

    public function test_tenant_user_and_subscription_documents_are_filtered_before_indexing(): void
    {
        $retriever = app(RAGRetriever::class);

        $tenantOne = $retriever->retrieve(
            ['tenant operations'],
            [],
            [
                'retrievers' => ['scoped_knowledge'],
                'tenant_id' => 'tenant-1',
                'subscription_active' => true,
            ],
            'user-1',
        );
        $tenantTwo = $retriever->retrieve(
            ['tenant operations'],
            [],
            [
                'retrievers' => ['scoped_knowledge'],
                'tenant_id' => 'tenant-2',
                'subscription_active' => false,
            ],
            'user-2',
        );

        self::assertSame(
            ['subscription-1', 'tenant-1'],
            array_map(static fn ($source): string => (string) $source->id, $tenantOne),
        );
        self::assertSame(
            ['tenant-2'],
            array_map(static fn ($source): string => (string) $source->id, $tenantTwo),
        );
    }

    public function test_user_private_documents_do_not_cross_user_scope(): void
    {
        $retriever = app(RAGRetriever::class);

        $userOne = $retriever->retrieve(
            ['personal preference'],
            [],
            ['retrievers' => ['scoped_knowledge']],
            'user-1',
        );
        $userTwo = $retriever->retrieve(
            ['personal preference'],
            [],
            ['retrievers' => ['scoped_knowledge']],
            'user-2',
        );

        self::assertSame(['user-1'], array_map(static fn ($source): string => (string) $source->id, $userOne));
        self::assertSame(['user-2'], array_map(static fn ($source): string => (string) $source->id, $userTwo));
    }

    public function test_workspace_private_documents_do_not_cross_workspace_scope(): void
    {
        $retriever = app(RAGRetriever::class);

        $workspaceOne = $retriever->retrieve(
            ['workspace runbook'],
            [],
            [
                'retrievers' => ['scoped_knowledge'],
                'workspace_id' => 'workspace-1',
            ],
        );
        $workspaceTwo = $retriever->retrieve(
            ['workspace runbook'],
            [],
            [
                'retrievers' => ['scoped_knowledge'],
                'workspace_id' => 'workspace-2',
            ],
        );

        self::assertSame(['workspace-1'], array_map(static fn ($source): string => (string) $source->id, $workspaceOne));
        self::assertSame(['workspace-2'], array_map(static fn ($source): string => (string) $source->id, $workspaceTwo));
    }

    public function test_retriever_can_be_disabled_for_backward_compatibility(): void
    {
        config()->set('ai-agent.assistant.knowledge_index.rag_enabled', false);

        $sources = app(RAGRetriever::class)->retrieve(
            ['shared deployment guide'],
            [],
            ['retrievers' => ['scoped_knowledge']],
        );

        self::assertSame([], $sources);
    }
}

final class MultiScopeKnowledgeSourceProvider implements KnowledgeSourceProvider
{
    public function documents(array $context = []): iterable
    {
        yield new ScopedKnowledgeDocument(
            'global',
            'Shared deployment guide',
            KnowledgeScope::GlobalShared,
            metadata: ['title' => 'Deployment'],
        );
        yield new ScopedKnowledgeDocument(
            'tenant-1',
            'Tenant operations handbook',
            KnowledgeScope::TenantPrivate,
            tenantId: 'tenant-1',
        );
        yield new ScopedKnowledgeDocument(
            'tenant-2',
            'Tenant operations handbook',
            KnowledgeScope::TenantPrivate,
            tenantId: 'tenant-2',
        );
        yield new ScopedKnowledgeDocument(
            'user-1',
            'Personal preference profile',
            KnowledgeScope::UserPrivate,
            userId: 'user-1',
        );
        yield new ScopedKnowledgeDocument(
            'user-2',
            'Personal preference profile',
            KnowledgeScope::UserPrivate,
            userId: 'user-2',
        );
        yield new ScopedKnowledgeDocument(
            'subscription-1',
            'Tenant operations subscription notes',
            KnowledgeScope::SubscriptionLimited,
            tenantId: 'tenant-1',
        );
        yield new ScopedKnowledgeDocument(
            'workspace-1',
            'Workspace runbook',
            KnowledgeScope::WorkspacePrivate,
            workspaceId: 'workspace-1',
        );
        yield new ScopedKnowledgeDocument(
            'workspace-2',
            'Workspace runbook',
            KnowledgeScope::WorkspacePrivate,
            workspaceId: 'workspace-2',
        );
    }
}
