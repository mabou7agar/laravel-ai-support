<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\Contracts\AssistantResourceProvider;
use LaravelAIEngine\Contracts\ConversationEntityMemory;
use LaravelAIEngine\DTOs\AssistantResourceItem;
use LaravelAIEngine\DTOs\AssistantResourceQuery;
use LaravelAIEngine\DTOs\AssistantResourceResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SearchAssistantResourcesTool;
use LaravelAIEngine\Tests\UnitTestCase;

final class SearchAssistantResourcesToolTest extends UnitTestCase
{
    public function test_it_returns_structured_resources_and_remembers_entity_focus(): void
    {
        config()->set('ai-agent.assistant.resource_providers', [ToolCourseProvider::class]);

        $context = new UnifiedActionContext(
            sessionId: 'session-1',
            userId: '7',
            metadata: ['tenant_id' => 'academy-1', 'locale' => 'ar'],
        );

        $result = app(SearchAssistantResourcesTool::class)->execute([
            'query' => 'اختبارات البرمجيات',
            'type' => 'course',
            'filters' => ['scope' => 'current'],
        ], $context);

        self::assertTrue($result->success);
        self::assertSame('cards', $result->metadata['presentation']);
        self::assertSame('Testing Course', $result->data['items'][0]['title']);

        $focus = app(ConversationEntityMemory::class)->focus(
            'session-1',
            scope: ['user_id' => '7', 'tenant_id' => 'academy-1'],
        );
        self::assertSame('course', $focus?->type);
        self::assertSame('12', $focus?->id);
    }
}

final class ToolCourseProvider implements AssistantResourceProvider
{
    public function supports(AssistantResourceQuery $query): bool
    {
        return $query->type === 'course';
    }

    public function search(AssistantResourceQuery $query): AssistantResourceResult
    {
        \PHPUnit\Framework\Assert::assertSame('current', $query->context['filters']['scope']);

        return new AssistantResourceResult(items: [
            new AssistantResourceItem(
                id: '12',
                type: 'course',
                title: 'Testing Course',
                summary: 'A practical software testing course.',
                url: 'https://academy.test/courses/12',
                tenantId: 'academy-1',
            ),
        ]);
    }
}
