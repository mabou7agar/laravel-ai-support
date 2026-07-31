<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Contracts\ScopedKnowledgeIndex;
use LaravelAIEngine\Contracts\SynchronizesScopedKnowledgeIndex;
use LaravelAIEngine\Tests\UnitTestCase;

final class SyncAssistantKnowledgeIndexCommandTest extends UnitTestCase
{
    public function test_it_rejects_the_non_persistent_default_index(): void
    {
        self::assertSame(1, Artisan::call('ai:assistant-knowledge-index', ['--json' => true]));
        self::assertStringContainsString('is not persistent', Artisan::output());
    }

    public function test_it_runs_for_a_synchronizing_index(): void
    {
        $index = new class implements ScopedKnowledgeIndex, SynchronizesScopedKnowledgeIndex {
            public bool $synced = false;

            public function search(iterable $documents, string $query, int $limit = 8): array
            {
                return [];
            }

            public function sync(iterable $documents, bool $force = false): int
            {
                $this->synced = true;

                return count(is_array($documents) ? $documents : iterator_to_array($documents));
            }
        };
        $this->app->instance(ScopedKnowledgeIndex::class, $index);

        self::assertSame(0, Artisan::call('ai:assistant-knowledge-index', [
            '--force' => true,
            '--json' => true,
        ]));
        self::assertTrue($index->synced);
    }
}
