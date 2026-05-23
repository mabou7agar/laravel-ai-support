<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Learning;

use LaravelAIEngine\DTOs\LearningIngestionResult;
use LaravelAIEngine\DTOs\LearningSourceRecord;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Services\Learning\LearningBuilder;
use LaravelAIEngine\Services\Learning\LearningService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class LearningBuilderTest extends UnitTestCase
{
    public function test_engine_learn_returns_learning_builder(): void
    {
        $this->assertInstanceOf(LearningBuilder::class, Engine::learn());
    }

    public function test_builder_collects_getdesign_slug_request(): void
    {
        $service = Mockery::mock(LearningService::class);
        $this->app->instance(LearningService::class, $service);

        $service->shouldReceive('ingest')
            ->once()
            ->with(Mockery::on(function ($request): bool {
                return $request->sourceType === 'getdesign_slug'
                    && $request->source === 'bmw-m'
                    && $request->adapter === 'getdesign'
                    && $request->type === 'design'
                    && $request->workspaceId === 'workspace-1'
                    && $request->shouldIndex === true;
            }))
            ->andReturn(new LearningIngestionResult(
                source: new LearningSourceRecord(
                    sourceId: 'learn_src_test',
                    sourceType: 'getdesign_slug',
                    source: 'bmw-m',
                    type: 'design',
                    title: 'BMW M',
                    adapter: 'getdesign',
                    metadata: [],
                    content: '# BMW M',
                    workspaceId: 'workspace-1',
                ),
                itemsCount: 1,
            ));

        $result = Engine::learn()
            ->fromDesignSlug('bmw-m')
            ->type('design')
            ->scope(workspaceId: 'workspace-1')
            ->index()
            ->save();

        $this->assertSame('learn_src_test', $result->source->sourceId);
    }
}
