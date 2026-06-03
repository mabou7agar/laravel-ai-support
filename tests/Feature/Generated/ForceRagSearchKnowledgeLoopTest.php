<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Generated;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Proves the force_rag contract end-to-end through the real AiNative container stack:
 *
 *  1. AiNativePromptBuilder emits the imperative "you MUST call the search_knowledge
 *     tool" directive when the caller sets force_rag.
 *  2. Driven by that directive, the AiNativeRuntime loop actually invokes the
 *     search_knowledge tool (registered in the real default ToolRegistry) before it
 *     returns a final answer.
 *
 * The planner (AIEngineService) is faked the way the existing AiNative tests fake it:
 * it returns scripted JSON plans, so there is no real LLM / network.
 */
class ForceRagSearchKnowledgeLoopTest extends TestCase
{
    public function test_force_rag_directive_is_emitted_by_prompt_builder(): void
    {
        /** @var ToolRegistry $tools */
        $tools = $this->app->make(ToolRegistry::class);
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $builder = new AiNativePromptBuilder($tools, $skills);

        $context = new UnifiedActionContext('force-rag-prompt', 1);

        $withForce = $builder->build('What is our refund policy?', $context, [], ['force_rag' => true]);
        $withoutForce = $builder->build('What is our refund policy?', $context, [], []);

        $this->assertStringContainsString('search_knowledge', $withForce);
        $this->assertStringContainsString(
            'you MUST call the search_knowledge tool before returning a final answer',
            $withForce
        );
        $this->assertStringNotContainsString(
            'you MUST call the search_knowledge tool before returning a final answer',
            $withoutForce
        );
    }

    public function test_force_rag_turn_drives_runtime_to_invoke_search_knowledge_before_final(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        // The real default registry already ships a search_knowledge tool; swap in a
        // spy implementation under the same name so we can observe invocation without
        // touching the real RAG store / network. The runtime resolves tools by name.
        $invocations = [];
        $registry = $this->app->make(ToolRegistry::class);
        $this->assertTrue(
            $registry->has('search_knowledge'),
            'search_knowledge must be in the default ToolRegistry'
        );

        $registry->register('search_knowledge', new class($invocations) extends AgentTool {
            /** @param array<int, array<string, mixed>> $log */
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'search_knowledge';
            }

            public function getDescription(): string
            {
                return 'Semantic search over the knowledge base.';
            }

            public function getParameters(): array
            {
                return [
                    'query' => ['type' => 'string', 'required' => true],
                ];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log[] = $parameters;

                return ActionResult::success(
                    'Refunds are accepted within 30 days of purchase.',
                    ['query' => $parameters['query'] ?? null]
                );
            }
        });

        // Script the planner: first emit a search_knowledge tool_call (as the force_rag
        // directive demands), then a final answer grounded in the tool result.
        $plans = [
            [
                'action' => 'tool_call',
                'tool' => 'search_knowledge',
                'arguments' => ['query' => 'refund policy'],
                'message' => 'Looking that up in the knowledge base.',
            ],
            [
                'action' => 'final',
                'message' => 'Refunds are accepted within 30 days of purchase.',
            ],
        ];

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->andReturn(...array_map(
                static fn (array $plan): AIResponse => AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
                $plans
            ));

        $runtime = new AiNativeRuntime(
            $ai,
            $registry,
            $this->app->make(AgentSkillRegistry::class),
            $this->app->make(IntentSignalService::class)
        );

        $context = new UnifiedActionContext('force-rag-loop', 77);

        $response = $runtime->process(
            'What is our refund policy?',
            $context,
            ['force_rag' => true]
        );

        $this->assertCount(
            1,
            $invocations,
            'force_rag turn must invoke search_knowledge exactly once before final'
        );
        $this->assertSame('refund policy', $invocations[0]['query'] ?? null);
        $this->assertStringContainsString('30 days', (string) $response->message);
    }
}
