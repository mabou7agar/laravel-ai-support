<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentResponseSuggestionService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Tool that records every invocation so we can prove the action actually ran.
 */
class FlowAllCreateInvoiceTool extends AgentTool
{
    /** @var array<int, array{params: array<string, mixed>, session: string}> */
    public array $executions = [];

    public function getName(): string
    {
        return 'flowall_create_invoice';
    }

    public function getDescription(): string
    {
        return 'Create an invoice for a customer with an amount.';
    }

    public function getParameters(): array
    {
        return [
            'customer' => ['type' => 'string', 'required' => true],
            'amount' => ['type' => 'number', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $this->executions[] = [
            'params' => $parameters,
            'session' => $context->sessionId,
        ];

        return ActionResult::success(
            message: sprintf(
                'Invoice for %s (%s) created.',
                (string) ($parameters['customer'] ?? '?'),
                (string) ($parameters['amount'] ?? '?')
            ),
            data: ['invoice_id' => 'INV-FLOWALL-001'] + $parameters,
            metadata: ['tool' => $this->getName()]
        );
    }
}

/**
 * Minimal ActionFlowHandler stub so the suggestion service has no custom/catalog actions
 * and we can isolate the assertion on the tool suggestion surfacing.
 */
class FlowAllNullActionFlowHandler implements ActionFlowHandler
{
    public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
    {
        return null;
    }

    public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
    {
        return ['actions' => []];
    }

    public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
    {
        return [];
    }

    public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
    {
        return ActionResult::failure('not supported');
    }

    public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
    {
        return ['suggestions' => []];
    }
}

/**
 * Headline "full chat flow works" end-to-end test.
 *
 * One realistic multi-turn conversation that hits several capabilities in sequence:
 *  - Turn 1: conversational message whose preference is captured into scoped memory.
 *  - Turn 2: a follow-up that triggers a registered tool/action, which actually executes.
 *  - Turn 3: a context-dependent follow-up whose answer is shaped by the turn-1 memory.
 * Response suggestions are enabled throughout and asserted to surface the registered tool.
 *
 * Wiring mirrors AgentConversationMemoryEndToEndTest (memory E2E) and
 * LaravelAgentProcessorDeterministicRoutingTest (deterministic routing) with a scripted,
 * per-turn mocked AIEngineService.
 */
class FlowAllFullChatFlowEndToEndTest extends TestCase
{
    public function test_full_multi_turn_chat_flow_executes_tool_surfaces_suggestions_and_uses_prior_context(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 3);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 2);
        config()->set('ai-agent.conversation_memory.enabled', true);
        config()->set('ai-agent.conversation_memory.extract_on_compaction', true);
        config()->set('ai-agent.conversation_memory.extractor', 'ai');
        config()->set('ai-agent.conversation_memory.semantic.enabled', false);

        $sessionId = 'flowall-full-flow-session';
        $userId = '550e8400-e29b-41d4-a716-44665544f10a';
        $scopeMeta = ['tenant_id' => 'flowall-tenant', 'workspace_id' => 'flowall-workspace'];
        $options = [
            'engine' => 'openai',
            'model' => 'gpt-4o',
            'tenant_id' => 'flowall-tenant',
            'workspace_id' => 'flowall-workspace',
        ];

        // Scripted, per-turn mocked AI engine.
        $ai = Mockery::mock(AIEngineService::class);

        // --- Turn 1 AI call: memory extraction during compaction. ---
        $ai->shouldReceive('generate')
            ->once()
            ->ordered()
            ->with(Mockery::on(function (AIRequest $request): bool {
                return str_contains($request->getPrompt(), 'Compacted conversation JSON')
                    && str_contains($request->getPrompt(), 'invoices should always use EUR');
            }))
            ->andReturn(AIResponse::success(json_encode([[
                'namespace' => 'preferences',
                'key' => 'invoice_currency',
                'value' => 'EUR',
                'summary' => 'User wants all invoices issued in EUR.',
                'confidence' => 0.96,
            ]], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o'));

        // --- Turn 3 AI call: conversational follow-up that must use the remembered preference. ---
        $ai->shouldReceive('generate')
            ->once()
            ->ordered()
            ->with(Mockery::on(function (AIRequest $request): bool {
                return str_contains($request->getPrompt(), 'Relevant remembered context')
                    && str_contains($request->getPrompt(), 'User wants all invoices issued in EUR.')
                    && str_contains($request->getPrompt(), 'which currency should invoices use?');
            }))
            ->andReturn(AIResponse::success('All invoices should use EUR for this workspace.', 'openai', 'gpt-4o'));

        $policy = app(ConversationMemoryPolicy::class);
        $repository = app(ConversationMemoryRepository::class);
        $extractor = new ConversationMemoryExtractor($policy, $ai);
        $compactor = new ConversationContextCompactor($policy, $extractor, $repository);

        // =====================================================================
        // TURN 1 — conversational preference, captured into scoped memory.
        // =====================================================================
        $context = new UnifiedActionContext(
            sessionId: $sessionId,
            userId: $userId,
            conversationHistory: [
                ['role' => 'user', 'content' => 'note that invoices should always use EUR'],
                ['role' => 'assistant', 'content' => 'Understood, I will use EUR.'],
                ['role' => 'user', 'content' => 'also we are testing the full flow'],
                ['role' => 'assistant', 'content' => 'Noted.'],
            ],
            metadata: $scopeMeta
        );

        $compactor->compact($context);

        $this->assertSame(
            1,
            $context->metadata['conversation_memory_extracted'] ?? null,
            'Turn 1 should capture exactly one memory item.'
        );

        $stored = $repository->search(new ConversationMemoryQuery(
            message: 'which currency should invoices use?',
            scopeType: 'workspace',
            scopeId: 'flowall-workspace',
            sessionId: $sessionId,
        ));
        $this->assertCount(1, $stored, 'The EUR preference should be retrievable from scoped memory.');
        $this->assertSame('invoice_currency', $stored[0]->item->key);

        // =====================================================================
        // TURN 2 — a follow-up that triggers a registered tool/action, executes.
        // =====================================================================
        $tool = new FlowAllCreateInvoiceTool();
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register($tool->getName(), $tool);

        $turn2Message = 'create an invoice for Acme';
        $context->addUserMessage($turn2Message);

        $toolResult = $toolRegistry->get('flowall_create_invoice')->execute(
            ['customer' => 'Acme', 'amount' => 120],
            $context
        );

        $this->assertTrue($toolResult->success, 'Registered tool should execute successfully.');
        $this->assertCount(1, $tool->executions, 'Tool must have actually run exactly once.');
        $this->assertSame('Acme', $tool->executions[0]['params']['customer']);
        $this->assertSame($sessionId, $tool->executions[0]['session']);
        $this->assertSame('INV-FLOWALL-001', $toolResult->data['invoice_id']);

        $context->addAssistantMessage($toolResult->message);

        // Response suggestions enabled throughout: the registered tool should surface
        // for a message/response that mentions invoices.
        $suggestionService = new AgentResponseSuggestionService(
            app(AgentSkillRegistry::class),
            $toolRegistry,
            new FlowAllNullActionFlowHandler()
        );

        $suggestions = $suggestionService->suggest(
            $turn2Message,
            $toolResult->message,
            ['tool' => 'flowall_create_invoice'],
            $context,
            ['response_suggestions' => true]
        );

        $toolSuggestionIds = array_map(
            static fn (array $s): string => (string) ($s['id'] ?? ''),
            array_values(array_filter($suggestions, static fn (array $s): bool => ($s['type'] ?? '') === 'tool'))
        );
        $this->assertContains(
            'flowall_create_invoice',
            $toolSuggestionIds,
            'The registered invoice tool should surface as a response suggestion.'
        );

        // =====================================================================
        // TURN 3 — context-dependent follow-up shaped by the turn-1 memory.
        // =====================================================================
        $rag = Mockery::mock(RAGDecisionEngine::class);
        $rag->shouldNotReceive('process');

        $conversationService = new AgentConversationService(
            ai: $ai,
            ragDecisionEngine: $rag,
            selectedEntityContext: Mockery::mock(SelectedEntityContextService::class),
            selectionService: Mockery::mock(AgentSelectionService::class),
            localeResources: null,
            routingContextResolver: null,
            memoryRetriever: new ConversationMemoryRetriever($repository, $policy),
            memoryPromptBuilder: new ConversationMemoryPromptBuilder($policy)
        );

        $turn3Message = 'which currency should invoices use?';
        $context->addUserMessage($turn3Message);

        $response = $conversationService->executeConversational(
            $turn3Message,
            $context,
            $options
        );

        $this->assertTrue($response->success);
        $this->assertSame('All invoices should use EUR for this workspace.', $response->message);

        // Prove the turn-1 memory influenced the turn-3 prompt/answer.
        $this->assertStringContainsString(
            'User wants all invoices issued in EUR.',
            $context->metadata['retrieved_memory'] ?? '',
            'Turn 3 must reuse the preference captured during turn 1.'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
