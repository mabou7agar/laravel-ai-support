<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use Carbon\CarbonImmutable;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\GenericModelListTool;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\TestCase;

class GenericModelListToolTest extends TestCase
{
    private function tool(string $scope = 'tenant-a'): GenericModelListTool
    {
        return new GenericModelListTool(
            name: 'list_person',
            model: User::class,
            search: ['name', 'email'],
            returns: ['name', 'email'],
            scope: static fn (UnifiedActionContext $context, array $parameters): array => ['password' => $scope],
        );
    }

    private function createUser(string $name, string $email, string $scope = 'tenant-a', int $minute = 0): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $scope,
            'created_at' => CarbonImmutable::parse('2026-01-01 00:00:00')->addMinutes($minute),
        ]);
        $user->save();

        return $user;
    }

    public function test_plain_call_returns_scoped_rows_with_positions_total_and_ids(): void
    {
        $this->createUser('First', 'first@example.com', 'tenant-a', 1);
        $this->createUser('Second', 'second@example.com', 'tenant-a', 2);
        $this->createUser('Other tenant', 'other@example.com', 'tenant-b', 3);

        $result = $this->tool()->execute([], new UnifiedActionContext('list-test'));

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['found']);
        $this->assertSame(2, $result->data['count']);
        $this->assertSame(2, $result->data['total']);
        $this->assertSame([1, 2], array_column($result->data['rows'], 'position'));
        foreach ($result->data['rows'] as $row) {
            $this->assertArrayHasKey('id', $row);
        }
        $this->assertSame('Found 2 people (showing 1-2).', $result->message);
    }

    public function test_limit_is_clamped_and_offset_positions_continue_across_pages(): void
    {
        foreach (range(1, 7) as $index) {
            $this->createUser("Person {$index}", "person{$index}@example.com", 'tenant-a', $index);
        }

        config()->set('ai-engine.agent_tools.list_max_limit', 2);
        $clamped = $this->tool()->execute(['limit' => 99], new UnifiedActionContext('list-test'));
        $this->assertSame(2, $clamped->data['limit']);
        $this->assertCount(2, $clamped->data['rows']);
        $this->assertTrue($clamped->data['has_more']);

        config()->set('ai-engine.agent_tools.list_max_limit', 50);
        $pageTwo = $this->tool()->execute(['limit' => 3, 'offset' => 3], new UnifiedActionContext('list-test'));
        $this->assertSame([4, 5, 6], array_column($pageTwo->data['rows'], 'position'));
        $this->assertSame(7, $pageTwo->data['total']);
        $this->assertTrue($pageTwo->data['has_more']);

        $lastPage = $this->tool()->execute(['limit' => 3, 'offset' => 6], new UnifiedActionContext('list-test'));
        $this->assertSame([7], array_column($lastPage->data['rows'], 'position'));
        $this->assertFalse($lastPage->data['has_more']);
    }

    public function test_empty_result_is_a_successful_not_found_list(): void
    {
        $this->createUser('Other tenant', 'other@example.com', 'tenant-b');

        $result = $this->tool()->execute([], new UnifiedActionContext('list-test'));

        $this->assertTrue($result->success);
        $this->assertFalse($result->data['found']);
        $this->assertSame(0, $result->data['total']);
        $this->assertSame([], $result->data['rows']);
        $this->assertSame('No people found.', $result->message);
    }

    public function test_scope_is_always_applied_and_cannot_be_overridden_by_filters(): void
    {
        $allowed = $this->createUser('Allowed', 'allowed@example.com', 'tenant-a');
        $blocked = $this->createUser('Blocked', 'blocked@example.com', 'tenant-b');

        $result = $this->tool()->execute([], new UnifiedActionContext('list-test'));
        $this->assertSame([$allowed->id], array_column($result->data['rows'], 'id'));
        $this->assertNotContains($blocked->id, array_column($result->data['rows'], 'id'));

        $attemptedOverride = $this->tool()->execute(
            ['filters' => ['password' => 'tenant-b']],
            new UnifiedActionContext('list-test')
        );
        $this->assertSame(0, $attemptedOverride->data['total'], 'A filter must add to, never replace, the tenant scope.');
        $this->assertSame([], $attemptedOverride->data['rows']);
    }

    public function test_query_filters_across_all_searchable_columns(): void
    {
        $this->createUser('Needle Name', 'first@example.com', 'tenant-a', 1);
        $this->createUser('Second Person', 'needle@example.com', 'tenant-a', 2);
        $this->createUser('Unrelated', 'other@example.com', 'tenant-a', 3);

        $result = $this->tool()->execute(['query' => 'needle'], new UnifiedActionContext('list-test'));

        $this->assertSame(2, $result->data['total']);
        $this->assertEqualsCanonicalizing(
            ['Needle Name', 'Second Person'],
            array_column($result->data['rows'], 'name')
        );
    }

    public function test_reports_read_kind_and_unprefixed_entity_type(): void
    {
        $tool = $this->tool();

        $this->assertSame('read', $tool->getToolKind());
        $this->assertSame(['read', 'list'], $tool->getCapabilities());
        $this->assertSame('person', $tool->getEntityType());
        $this->assertSame([], $tool->validate([]));
    }
}
