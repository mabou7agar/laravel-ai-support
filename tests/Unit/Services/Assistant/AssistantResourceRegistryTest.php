<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Assistant;

use LaravelAIEngine\Contracts\AssistantResourceProvider;
use LaravelAIEngine\DTOs\AssistantResourceItem;
use LaravelAIEngine\DTOs\AssistantResourceQuery;
use LaravelAIEngine\DTOs\AssistantResourceResult;
use LaravelAIEngine\Services\Assistant\AssistantResourceRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

final class AssistantResourceRegistryTest extends UnitTestCase
{
    public function test_it_aggregates_supported_host_resources_and_deduplicates_items(): void
    {
        config()->set('ai-agent.assistant.resource_providers', [
            TestCourseResourceProvider::class,
            DuplicateCourseResourceProvider::class,
        ]);

        $result = app(AssistantResourceRegistry::class)->search(
            new AssistantResourceQuery('testing', 'course', 8, 'en', ['tenant_id' => '1']),
        );

        self::assertCount(1, $result->items);
        self::assertSame('course', $result->items[0]->type);
        self::assertSame('Testing Course', $result->items[0]->title);
        self::assertCount(2, $result->metadata['providers']);
    }

    public function test_it_filters_private_resources_outside_the_trusted_scope(): void
    {
        config()->set('ai-agent.assistant.resource_providers', [
            TestCourseResourceProvider::class,
        ]);

        $result = app(AssistantResourceRegistry::class)->search(
            new AssistantResourceQuery('testing', 'course', 8, 'en', ['tenant_id' => '2']),
        );

        self::assertSame([], $result->items);
    }
}

final class TestCourseResourceProvider implements AssistantResourceProvider
{
    public function supports(AssistantResourceQuery $query): bool
    {
        return $query->type === 'course';
    }

    public function search(AssistantResourceQuery $query): AssistantResourceResult
    {
        return new AssistantResourceResult([
            new AssistantResourceItem('1', 'course', 'Testing Course', tenantId: '1'),
        ]);
    }
}

final class DuplicateCourseResourceProvider implements AssistantResourceProvider
{
    public function supports(AssistantResourceQuery $query): bool
    {
        return true;
    }

    public function search(AssistantResourceQuery $query): AssistantResourceResult
    {
        return new AssistantResourceResult([
            new AssistantResourceItem('1', 'course', 'Testing Course', tenantId: '1'),
        ]);
    }
}
