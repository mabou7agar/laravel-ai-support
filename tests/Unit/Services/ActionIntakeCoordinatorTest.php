<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionIntakeCoordinator;
use LaravelAIEngine\Services\Actions\ActionPayloadExtractor;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ActionIntakeCoordinatorTest extends TestCase
{
    public function test_prepares_action_and_stores_draft_payload(): void
    {
        $context = new UnifiedActionContext('coord-session', 7);
        $extractor = Mockery::mock(ActionPayloadExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn(['name' => 'Demo']);

        $coordinator = new ActionIntakeCoordinator($extractor);

        $response = $coordinator->prepare(
            message: 'create demo',
            context: $context,
            ownerKey: 7,
            scope: 'demo',
            action: ['id' => 'create_demo', 'parameters' => ['name' => ['type' => 'string']]],
            callbacks: [
                'prepare' => fn (array $payload): array => [
                    'success' => true,
                    'message' => 'Draft ready.',
                    'draft' => ['payload' => $payload],
                ],
            ],
            options: ['prepared_strategy' => 'demo_prepare']
        );

        $this->assertSame('demo_prepare', $response->strategy);
        $this->assertSame(['name' => 'Demo'], $coordinator->draftPayload($context, 7, 'demo'));
    }

    public function test_execute_pending_clears_draft_after_success(): void
    {
        $context = new UnifiedActionContext('coord-session-2', 7);
        $coordinator = new ActionIntakeCoordinator(Mockery::mock(ActionPayloadExtractor::class));
        $coordinator->putDraftPayload($context, 7, 'demo', ['name' => 'Demo']);

        $response = $coordinator->executePending(
            context: $context,
            ownerKey: 7,
            scope: 'demo',
            callbacks: [
                'execute' => fn (array $payload): array => [
                    'success' => true,
                    'message' => 'Created ' . $payload['name'],
                ],
            ],
            options: ['executed_strategy' => 'demo_execute']
        );

        $this->assertSame('demo_execute', $response->strategy);
        $this->assertNull($coordinator->draftPayload($context, 7, 'demo'));
    }
}
