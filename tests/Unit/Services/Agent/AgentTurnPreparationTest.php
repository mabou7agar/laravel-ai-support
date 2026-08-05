<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Contracts\AgentRetrievalPolicyContract;
use LaravelAIEngine\Contracts\AgentTurnRouterContract;
use LaravelAIEngine\DTOs\AgentRetrievalDecisionDTO;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentTurnDecisionDTO;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

final class AgentTurnPreparationTest extends UnitTestCase
{
    public function test_default_turn_preparation_is_passthrough_and_host_managed(): void
    {
        $captured = $this->process('hello', []);

        self::assertSame('passthrough', $captured['turn_decision']['route'] ?? null);
        self::assertSame('host_managed', $captured['turn_decision']['retrieval_mode'] ?? null);
        self::assertSame('host_managed', $captured['retrieval_decision']['status'] ?? null);
        self::assertSame('host_managed', $captured['retrieval_decision']['mode'] ?? null);
    }

    public function test_precomputed_host_decisions_are_preserved(): void
    {
        $captured = $this->process('build a page', [
            'turn_decision' => [
                'route' => 'page_build',
                'confidence' => 0.99,
                'retrieval_mode' => 'domain_catalog',
                'reason' => 'Catalog composition requested.',
                'requested_capabilities' => ['catalog.compose'],
            ],
            'retrieval_decision' => [
                'status' => 'required',
                'mode' => 'domain_catalog',
                'reason' => 'Catalog composition requested.',
                'required' => true,
            ],
        ]);

        self::assertSame('page_build', $captured['turn_decision']['route'] ?? null);
        self::assertSame(['catalog.compose'], $captured['turn_decision']['requested_capabilities'] ?? null);
        self::assertSame('required', $captured['retrieval_decision']['status'] ?? null);
        self::assertTrue($captured['retrieval_decision']['required'] ?? false);
    }

    public function test_router_and_retrieval_failures_preserve_host_managed_behavior(): void
    {
        $router = new class implements AgentTurnRouterContract
        {
            public function route(
                string $message,
                UnifiedActionContext $context,
                array $options = [],
            ): AgentTurnDecisionDTO {
                throw new \RuntimeException('Router unavailable.');
            }
        };
        $retrieval = new class implements AgentRetrievalPolicyContract
        {
            public function decide(
                AgentTurnDecisionDTO $turn,
                UnifiedActionContext $context,
                array $options = [],
            ): AgentRetrievalDecisionDTO {
                throw new \RuntimeException('Retriever unavailable.');
            }
        };

        $captured = $this->process('continue safely', [], $router, $retrieval);

        self::assertSame('passthrough', $captured['turn_decision']['route'] ?? null);
        self::assertSame('host_managed', $captured['retrieval_decision']['status'] ?? null);
        self::assertStringContainsString(
            'unavailable',
            (string) ($captured['turn_decision']['reason'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function process(
        string $message,
        array $options,
        ?AgentTurnRouterContract $router = null,
        ?AgentRetrievalPolicyContract $retrieval = null,
    ): array {
        $context = new UnifiedActionContext('turn-preparation', 42);
        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->andReturn($context);

        $captured = [];
        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (
                string $runtimeMessage,
                UnifiedActionContext $runtimeContext,
                array $runtimeOptions,
            ) use (&$captured): AgentResponse {
                $captured = $runtimeOptions;

                return AgentResponse::conversational('Prepared.', $runtimeContext);
            });

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $finalizer,
            null,
            Mockery::mock(AgentExecutionDispatcher::class),
            $native,
            null,
            $router,
            $retrieval,
        );
        $processor->process($message, 'turn-preparation', 42, $options);

        return $captured;
    }
}
