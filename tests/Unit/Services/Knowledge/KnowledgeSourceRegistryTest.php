<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Knowledge;

use LaravelAIEngine\Contracts\KnowledgeSourceProvider;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\Enums\KnowledgeScope;
use LaravelAIEngine\Services\Knowledge\KnowledgeSourceRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

final class KnowledgeSourceRegistryTest extends UnitTestCase
{
    public function test_default_policy_allows_shared_and_matching_private_documents_only(): void
    {
        config()->set('ai-agent.assistant.knowledge_sources', [TestKnowledgeSourceProvider::class]);

        $documents = app(KnowledgeSourceRegistry::class)->documents([
            'tenant_id' => 'tenant-1',
            'user_id' => 'user-1',
            'subscription_active' => true,
        ]);

        self::assertSame(
            ['global', 'public', 'tenant-1', 'user-1', 'subscription-1'],
            array_map(static fn (ScopedKnowledgeDocument $document): string => $document->id, $documents),
        );
    }
}

final class TestKnowledgeSourceProvider implements KnowledgeSourceProvider
{
    public function documents(array $context = []): iterable
    {
        yield new ScopedKnowledgeDocument('global', 'Guide', KnowledgeScope::GlobalShared);
        yield new ScopedKnowledgeDocument('public', 'Public catalog', KnowledgeScope::TenantPublic, 'tenant-2');
        yield new ScopedKnowledgeDocument('tenant-1', 'Private one', KnowledgeScope::TenantPrivate, 'tenant-1');
        yield new ScopedKnowledgeDocument('tenant-2', 'Private two', KnowledgeScope::TenantPrivate, 'tenant-2');
        yield new ScopedKnowledgeDocument('user-1', 'User one', KnowledgeScope::UserPrivate, userId: 'user-1');
        yield new ScopedKnowledgeDocument('user-2', 'User two', KnowledgeScope::UserPrivate, userId: 'user-2');
        yield new ScopedKnowledgeDocument('subscription-1', 'Subscribed', KnowledgeScope::SubscriptionLimited, 'tenant-1');
    }
}
