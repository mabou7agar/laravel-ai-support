<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingTrace;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * L5 — a selected entity merged during a FAILED routing-pipeline attempt must not
 * bias the heuristic fallback. The catch block must unset selected_entity /
 * selected_entity_context before delegating to heuristicRoute().
 */
class LaravelAgentProcessorRoutingDedupTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_failed_pipeline_attempt_does_not_bias_heuristic_fallback_with_stale_entity(): void
    {
        $context = new UnifiedActionContext('session-dedup-bias', 7);
        // A selected entity is present at the moment the pipeline begins its attempt.
        $context->metadata['selected_entity_context'] = [
            'entity_id' => 42,
            'entity_type' => 'email',
        ];

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->andReturn($context);
        $contextManager->shouldReceive('save')->andReturnNull();

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')->andReturn([
            'action' => 'conversational',
            'reasoning' => 'fallback',
            'decision_source' => 'fallback',
        ]);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        // Capture the options the heuristic fallback ultimately dispatches with.
        $captured = ['options' => null];
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturnUsing(function (RoutingDecision $d, string $m, UnifiedActionContext $c, array $options) use (&$captured) {
                $captured['options'] = $options;

                return AgentResponse::conversational(message: 'ok', context: $c);
            });

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $r) => $r);

        // Pipeline that consumes the selected entity (clearing it from the context so it
        // is now stale) and then fails — exactly the partial-failed-attempt scenario.
        $pipeline = new class extends RoutingPipeline {
            public function decide(string $message, UnifiedActionContext $context, array $options = []): RoutingTrace
            {
                unset($context->metadata['selected_entity_context']);

                throw new \RuntimeException('Simulated pipeline failure mid-attempt');
            }
        };

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher,
            $pipeline
        );

        $response = $processor->process('how are things going today', 'session-dedup-bias', 7, [
            'use_rag' => false,
        ]);

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertNotNull($captured['options'], 'The heuristic fallback must have dispatched.');
        $this->assertArrayNotHasKey(
            'selected_entity',
            $captured['options'],
            'The stale selected_entity from the failed pipeline attempt must not bias the fallback.'
        );
        $this->assertArrayNotHasKey(
            'selected_entity_context',
            $captured['options'],
            'The stale selected_entity_context from the failed pipeline attempt must not bias the fallback.'
        );
    }
}
