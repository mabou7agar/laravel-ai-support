<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Memory;

use LaravelAIEngine\Contracts\ConversationEntityMemory;
use LaravelAIEngine\DTOs\ConversationEntityReference;
use LaravelAIEngine\Tests\UnitTestCase;

final class CacheConversationEntityMemoryTest extends UnitTestCase
{
    public function test_it_tracks_focus_and_isolates_tenant_scopes(): void
    {
        $memory = app(ConversationEntityMemory::class);
        $tenantOne = ['tenant_id' => 'tenant-1', 'user_id' => '7'];
        $tenantTwo = ['tenant_id' => 'tenant-2', 'user_id' => '7'];

        $memory->remember('thread-1', new ConversationEntityReference('course', '10', 'First'), $tenantOne);
        $memory->remember('thread-1', new ConversationEntityReference('course', '11', 'Second'), $tenantOne);

        self::assertSame('11', $memory->focus('thread-1', 'course', $tenantOne)?->id);
        self::assertSame(['11', '10'], array_map(
            static fn (ConversationEntityReference $reference): string => $reference->id,
            $memory->recent('thread-1', 'course', 10, $tenantOne),
        ));
        self::assertNull($memory->focus('thread-1', 'course', $tenantTwo));
    }

    public function test_remembering_the_same_entity_moves_it_to_focus_without_duplication(): void
    {
        $memory = app(ConversationEntityMemory::class);
        $scope = ['tenant_id' => 'tenant-1'];

        $memory->remember('thread-2', new ConversationEntityReference('course', '10', 'Old'), $scope);
        $memory->remember('thread-2', new ConversationEntityReference('lesson', '20', 'Lesson'), $scope);
        $memory->remember('thread-2', new ConversationEntityReference('course', '10', 'Updated'), $scope);

        self::assertCount(2, $memory->recent('thread-2', null, 10, $scope));
        self::assertSame('Updated', $memory->focus('thread-2', 'course', $scope)?->label);
    }
}
