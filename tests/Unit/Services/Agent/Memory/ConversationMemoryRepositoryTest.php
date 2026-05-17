<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Tests\TestCase;

class ConversationMemoryRepositoryTest extends TestCase
{
    public function test_repository_stores_and_filters_memory_by_user_workspace_and_tenant(): void
    {
        $repo = app(ConversationMemoryRepository::class);

        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'profile',
            'key' => 'preferred_language',
            'value' => 'Arabic',
            'summary' => 'User prefers Arabic replies.',
            'user_id' => '7',
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
            'confidence' => 0.95,
        ]));

        $query = new ConversationMemoryQuery(
            message: 'reply in my preferred language',
            userId: '7',
            tenantId: 'tenant-a',
            workspaceId: 'workspace-a',
            limit: 5,
        );

        $results = $repo->search($query);

        $this->assertCount(1, $results);
        $this->assertSame('preferred_language', $results[0]->item->key);
        $this->assertSame('Arabic', $results[0]->item->value);

        $otherWorkspace = new ConversationMemoryQuery(
            message: 'reply in my preferred language',
            userId: '7',
            tenantId: 'tenant-a',
            workspaceId: 'workspace-b',
            limit: 5,
        );

        $this->assertSame([], $repo->search($otherWorkspace));
    }
}
