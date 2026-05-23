<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Learning;

use LaravelAIEngine\Contracts\Learning\LearningSourceAdapterInterface;
use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AILearnedItem;
use LaravelAIEngine\Models\AILearnSource;
use LaravelAIEngine\Repositories\LearningRepository;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Learning\LearningExtractorService;
use LaravelAIEngine\Services\Learning\LearningService;
use LaravelAIEngine\Services\Learning\LearningVectorIndexer;
use LaravelAIEngine\Tests\TestCase;

class DesignLearningScenarioTest extends TestCase
{
    public function test_design_learning_scenario_ingests_indexes_and_retrieves_workspace_guidance_for_agent(): void
    {
        $service = $this->fakeGetDesignLearningService();
        $this->app->instance(LearningService::class, $service);

        $result = $service->ingest(new LearningSourceRequest(
            sourceType: 'getdesign_slug',
            source: 'bmw-m',
            type: 'design',
            adapter: 'getdesign',
            tenantId: 'tenant-design',
            workspaceId: 'workspace-bmw',
            shouldIndex: true,
            vectorStoreName: 'Design Examples'
        ));

        $this->assertSame('design', $result->source->type);
        $this->assertSame('getdesign', $result->source->adapter);
        $this->assertSame('workspace-bmw', $result->source->workspaceId);
        $this->assertGreaterThanOrEqual(4, $result->itemsCount);

        $source = AILearnSource::query()->where('source_id', $result->source->sourceId)->firstOrFail();
        $this->assertNotNull($source->vector_store_id);
        $this->assertNotNull($source->indexed_at);
        $this->assertDatabaseCount('ai_vector_stores', 1);
        $this->assertDatabaseCount('ai_vector_store_documents', 1);
        $this->assertGreaterThanOrEqual(4, AILearnedItem::query()->count());

        $matches = $service->search('create a premium automotive hero with red accent CTAs and sharp performance cards', [
            'tenant_id' => 'tenant-design',
            'workspace_id' => 'workspace-bmw',
        ], limit: 3, type: 'design');

        $this->assertNotEmpty($matches);
        $combined = implode("\n", array_map(static fn ($match): string => $match->item->content, $matches));
        $this->assertStringContainsString('red accent', mb_strtolower($combined));
        $this->assertStringContainsString('sharp', mb_strtolower($combined));

        $otherWorkspaceMatches = $service->search('premium automotive hero red accent', [
            'tenant_id' => 'tenant-design',
            'workspace_id' => 'workspace-other',
        ], limit: 3, type: 'design');

        $this->assertSame([], $otherWorkspaceMatches);

        $tool = app(ToolRegistry::class)->get('search_learned_context');
        $this->assertNotNull($tool);

        $toolResult = $tool->execute([
            'query' => 'Which design rules should guide a premium automotive landing page?',
            'type' => 'design',
            'limit' => 2,
        ], new UnifiedActionContext(
            sessionId: 'design-session',
            userId: 'designer-1',
            metadata: [
                'tenant_id' => 'tenant-design',
                'workspace_id' => 'workspace-bmw',
            ]
        ));

        $this->assertTrue($toolResult->success);
        $this->assertNotEmpty($toolResult->data);
        $this->assertSame('design', $toolResult->data[0]['source']['type']);
        $this->assertStringContainsString('premium automotive', mb_strtolower($toolResult->data[0]['item']['content']));
    }

    protected function fakeGetDesignLearningService(): LearningService
    {
        $adapter = new class implements LearningSourceAdapterInterface {
            public function supports(LearningSourceRequest $request): bool
            {
                return $request->adapter === 'getdesign';
            }

            public function fetch(LearningSourceRequest $request): LearningSourcePayload
            {
                return new LearningSourcePayload(
                    content: <<<'MD'
# BMW M Design System

## Visual Theme
Use a premium automotive visual language with black backgrounds, white text, and red accent CTAs. Keep the first viewport focused on motion, precision, and performance.

## Components
Hero sections should use large product imagery, tight navigation, red primary buttons, and sharp performance cards. Do not use soft rounded marketing cards.

## Typography
Use compact uppercase labels, confident headings, and concise copy. Body copy should stay direct and technical.

## Layout Rules
Keep sections dense, high contrast, and component driven. Use repeated metric strips, model cards, and comparison rows for scanning.
MD,
                    title: 'BMW M Design System',
                    metadata: [
                        'adapter' => 'getdesign',
                        'source_type' => 'getdesign_slug',
                        'slug' => $request->source,
                    ],
                );
            }
        };

        return new LearningService(
            app(LearningRepository::class),
            app(LearningExtractorService::class),
            app(LearningVectorIndexer::class),
            [$adapter]
        );
    }
}
