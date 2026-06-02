<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentSkillProvider;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\RunSkillTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Headline "the whole thing works" end-to-end test.
 *
 * One realistic multi-turn conversation runs through the REAL chat entry point
 * (ChatService::processMessage -> real LaravelAgentRuntime -> real LaravelAgentProcessor
 * -> real RoutingPipeline -> real AgentExecutionDispatcher -> real AgentActionExecutionService
 * -> real ToolRegistry / AgentConversationService). Only the leaf AI/IO collaborators are
 * faked, scripted per turn, so no real network or model call ever happens:
 *
 *   - IntentRouter          -> mocked (decides the use_tool / conversational routes)
 *   - AIEngineService       -> mocked (conversational text generation)
 *   - RAGDecisionEngine     -> mocked (knowledge / search_rag path)
 *   - AiNativeRuntime       -> mocked (skill execution behind RunSkillTool)
 *
 * Turns exercised across a SINGLE session:
 *   Turn 1 — greeting / conversational reply; transcript begins.
 *   Turn 2 — request routes to a registered TOOL (use_tool); the tool actually executes and
 *            the result + routing metadata come back; response suggestions surface the tool.
 *   Turn 3 — context-dependent follow-up; earlier turns are hydrated into the conversational
 *            prompt (memory carried forward across turns).
 *   Turn 4 — a knowledge question that heuristically routes to search_rag (RAG path mocked).
 *   Turn 5 — a message that matches a registered SKILL; the skill runs through run_skill.
 *
 * At the end the in-conversation transcript is asserted to reflect every turn in order.
 */
class FullConversationE2ETest extends TestCase
{
    private const SESSION = 'fullconv-session';

    /** @var int */
    private $userId = 4242;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic heuristic routing (no AI intent layer in the classifier).
        config()->set('ai-agent.intent_understanding.mode', 'heuristic');

        // Conversation memory on so prior-turn context is hydrated into the chat prompt.
        config()->set('ai-agent.conversation_memory.enabled', true);
        config()->set('ai-agent.conversation_memory.semantic.enabled', false);

        // Register our skill end-to-end through the provider config the registry reads.
        config()->set('ai-agent.skills.enabled', true);
        config()->set('ai-agent.skills.prefer_deterministic_matches', true);
        // Deterministic trigger matching only: no AI fallback for skill intent matching, so
        // turns 1-4 never make a spurious AI call through the skill match stage.
        config()->set('ai-agent.skills.intent_matching.enabled', false);
        config()->set('ai-agent.skill_providers', [
            'fullconv' => FullConvSkillProvider::class,
        ]);

        // Make sure registry/pipeline singletons pick up the skill provider config above.
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\AgentSkillMatcher::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Routing\Stages\AgentSkillMatchStage::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Routing\RoutingPipeline::class);
    }

    public function test_realistic_multi_turn_conversation_drives_the_full_orchestrator_stack(): void
    {
        // -----------------------------------------------------------------
        // Fakes for the leaf AI/IO collaborators (scripted per turn).
        // -----------------------------------------------------------------
        $ai = Mockery::mock(AIEngineService::class);
        $intentRouter = Mockery::mock(IntentRouter::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $native = Mockery::mock(AiNativeRuntime::class);

        // Registered tool: exercised by turn 2 (use_tool route).
        $invoiceTool = new FullConvInvoiceTool();
        /** @var ToolRegistry $registry */
        $registry = $this->app->make(ToolRegistry::class);
        $registry->register($invoiceTool->getName(), $invoiceTool);

        // run_skill must be backed by a RunSkillTool whose AiNativeRuntime is mocked so the
        // turn-5 skill executes entirely in-memory (no real AI). Register it on the same
        // registry the dispatcher resolves.
        $registry->register('run_skill', new RunSkillTool($native));

        // Bind the mocked leaf services so the real runtime/pipeline resolve them.
        $this->app->instance(IntentRouter::class, $intentRouter);
        $this->app->instance(AIEngineService::class, $ai);
        $this->app->instance(RAGPipelineContract::class, $ragPipeline);
        $this->app->instance(AiNativeRuntime::class, $native);

        // The conversation service + dispatcher capture their RAG/AI collaborators at
        // construction; forget the cached singletons so the bound mocks above are used.
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\AgentConversationService::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class);
        $this->app->forgetInstance(ChatService::class);
        $this->app->forgetInstance(\LaravelAIEngine\Contracts\AgentRuntimeContract::class);

        // In-test transcript store: records every turn and replays history per turn so the
        // "memory carried across turns" assertion is deterministic and DB-backend agnostic.
        $transcript = new FullConvTranscriptStub();
        $this->app->instance(ConversationTranscriptService::class, $transcript);

        // Build the REAL chat service on top of the REAL agent runtime (only leaves faked).
        $chat = $this->app->make(ChatService::class);

        $commonOptions = [
            'response_suggestions' => true,
        ];

        // =====================================================================
        // TURN 1 — greeting / conversational reply.
        // "hello" is classified conversational and bypasses the IntentRouter entirely.
        // =====================================================================
        $ai->shouldReceive('generate')
            ->once()
            ->ordered()
            ->with(Mockery::on(fn (AIRequest $req): bool => str_contains($req->getPrompt(), 'hello')))
            ->andReturn(AIResponse::success('Hi there! How can I help you today?', 'openai', 'gpt-4o-mini'));

        $turn1 = $chat->processMessage(
            message: 'hello',
            sessionId: self::SESSION,
            useMemory: true,
            userId: $this->userId,
            extraOptions: $commonOptions
        );

        $this->assertTrue($turn1->success);
        $this->assertSame('Hi there! How can I help you today?', $turn1->getContent());
        $this->assertSame(
            RoutingDecisionAction::CONVERSATIONAL,
            $turn1->getMetadata()['route_explanation']['action'] ?? null,
            'Turn 1 should resolve conversationally.'
        );

        // =====================================================================
        // TURN 2 — request routes to a registered TOOL and actually executes.
        // "Create ..." is classified action_request and reaches the (mocked) IntentRouter.
        // =====================================================================
        $intentRouter->shouldReceive('route')
            ->once()
            ->ordered()
            ->with('Create an invoice for Acme for 250.', Mockery::type(UnifiedActionContext::class), Mockery::type('array'))
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'fullconv_create_invoice',
                'params' => ['customer' => 'Acme', 'amount' => 250],
                'reasoning' => 'invoice creation tool',
                'decision_source' => 'router_ai',
            ]);

        $turn2 = $chat->processMessage(
            message: 'Create an invoice for Acme for 250.',
            sessionId: self::SESSION,
            useMemory: true,
            userId: $this->userId,
            useRag: false,
            extraOptions: $commonOptions
        );

        // The tool actually ran exactly once with the routed params.
        $this->assertSame(1, $invoiceTool->executions, 'Registered tool execute() must run exactly once.');
        $this->assertSame(['customer' => 'Acme', 'amount' => 250], $invoiceTool->receivedParameters);

        // The tool result + routing metadata are carried back on the response.
        $this->assertTrue($turn2->success);
        $this->assertSame('Invoice INV-FULLCONV-1 created for Acme.', $turn2->getContent());
        $this->assertSame('INV-FULLCONV-1', $turn2->getMetadata()['runtime_data']['invoice_id'] ?? null);
        $this->assertSame('fullconv_create_invoice', $turn2->getMetadata()['tool_name'] ?? null);
        $this->assertSame(
            RoutingDecisionAction::USE_TOOL,
            $turn2->getMetadata()['route_explanation']['action'] ?? null,
            'Turn 2 should route to use_tool.'
        );

        // Response suggestions enabled throughout: the registered invoice tool surfaces here.
        $suggestions = $turn2->getMetadata()['suggestions'] ?? [];
        $this->assertIsArray($suggestions);
        $suggestionIds = array_map(static fn (array $s): string => (string) ($s['id'] ?? ''), $suggestions);
        $this->assertContains(
            'fullconv_create_invoice',
            $suggestionIds,
            'The registered tool should surface as a response suggestion.'
        );

        // =====================================================================
        // TURN 3 — context-dependent follow-up; prior turns hydrated into the prompt.
        // Route conversationally so AgentConversationService builds the history-bearing prompt.
        // =====================================================================
        $capturedPrompt = null;
        $intentRouter->shouldReceive('route')
            ->once()
            ->ordered()
            ->with('and what amount did we use for that invoice?', Mockery::type(UnifiedActionContext::class), Mockery::type('array'))
            ->andReturn([
                'action' => 'conversational',
                'reasoning' => 'follow-up chat about prior invoice',
                'decision_source' => 'router_ai',
            ]);

        $ai->shouldReceive('generate')
            ->once()
            ->ordered()
            ->with(Mockery::on(function (AIRequest $req) use (&$capturedPrompt): bool {
                $capturedPrompt = $req->getPrompt();

                // The earlier invoice turn must have been hydrated into this turn's prompt.
                return str_contains($capturedPrompt, 'Create an invoice for Acme for 250.')
                    && str_contains($capturedPrompt, 'and what amount did we use for that invoice?');
            }))
            ->andReturn(AIResponse::success('The invoice for Acme used an amount of 250.', 'openai', 'gpt-4o-mini'));

        $turn3 = $chat->processMessage(
            message: 'and what amount did we use for that invoice?',
            sessionId: self::SESSION,
            useMemory: true,
            userId: $this->userId,
            useRag: false,
            extraOptions: $commonOptions
        );

        $this->assertTrue($turn3->success);
        $this->assertSame('The invoice for Acme used an amount of 250.', $turn3->getContent());
        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString(
            'Create an invoice for Acme for 250.',
            (string) $capturedPrompt,
            'Turn 3 prompt must include earlier conversation history (memory carried forward).'
        );

        // =====================================================================
        // TURN 4 — knowledge question routes to search_rag (RAG path mocked).
        // "what changed ..." is a strong semantic-retrieval pattern -> SEARCH_RAG heuristically,
        // bypassing the IntentRouter.
        // =====================================================================
        $ragPipeline->shouldReceive('answer')
            ->once()
            ->ordered()
            ->withArgs(function (string $query, array $options, $userId): bool {
                return $query === 'what changed for Acme this week?';
            })
            ->andReturn(AgentResponse::conversational(
                message: 'Acme had two invoices issued and one payment received this week.',
                context: new UnifiedActionContext(self::SESSION, $this->userId),
                metadata: ['context_count' => 3, 'rag_enabled' => true]
            ));

        $turn4 = $chat->processMessage(
            message: 'what changed for Acme this week?',
            sessionId: self::SESSION,
            useMemory: true,
            userId: $this->userId,
            useRag: true,
            extraOptions: $commonOptions
        );

        $this->assertTrue($turn4->success);
        $this->assertSame('Acme had two invoices issued and one payment received this week.', $turn4->getContent());
        $this->assertSame(
            RoutingDecisionAction::SEARCH_RAG,
            $turn4->getMetadata()['route_explanation']['action'] ?? null,
            'Turn 4 should route to search_rag.'
        );

        // =====================================================================
        // TURN 5 — message matches a registered SKILL and the skill runs.
        // The skill match stage produces a run_skill use_tool decision; RunSkillTool executes
        // it through the mocked AiNativeRuntime.
        // =====================================================================
        $native->shouldReceive('process')
            ->once()
            ->ordered()
            ->with(
                'translate this note to french',
                Mockery::type(UnifiedActionContext::class),
                Mockery::on(static fn (array $o): bool => ($o['skill_id'] ?? null) === 'fullconv_translate'
                    && ($o['runtime_scope'] ?? null) === 'skill')
            )
            ->andReturn(new AgentResponse(
                success: true,
                message: 'Translated to French: ceci est une note.',
                context: new UnifiedActionContext(self::SESSION, $this->userId),
                metadata: ['executed_tool' => 'fullconv_translate_tool'],
                isComplete: true
            ));

        $turn5 = $chat->processMessage(
            message: 'translate this note to french',
            sessionId: self::SESSION,
            useMemory: true,
            userId: $this->userId,
            useRag: false,
            extraOptions: $commonOptions
        );

        $this->assertTrue($turn5->success);
        $this->assertSame('Translated to French: ceci est une note.', $turn5->getContent());
        $this->assertSame(
            'fullconv_translate_tool',
            $turn5->getMetadata()['executed_tool'] ?? null,
            'Turn 5 should have executed the matched skill.'
        );

        // =====================================================================
        // FINAL — the transcript reflects all five turns, in order.
        // =====================================================================
        $this->assertCount(5, $transcript->turns, 'All five turns should be persisted.');

        $this->assertSame([
            'hello',
            'Create an invoice for Acme for 250.',
            'and what amount did we use for that invoice?',
            'what changed for Acme this week?',
            'translate this note to french',
        ], array_column($transcript->turns, 'user'), 'User messages must be persisted in order.');

        $this->assertSame([
            'Hi there! How can I help you today?',
            'Invoice INV-FULLCONV-1 created for Acme.',
            'The invoice for Acme used an amount of 250.',
            'Acme had two invoices issued and one payment received this week.',
            'Translated to French: ceci est une note.',
        ], array_column($transcript->turns, 'assistant'), 'Assistant replies must be persisted in order.');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}

/**
 * Registered tool fixture (turn 2). Records executions so we can prove it actually ran.
 */
class FullConvInvoiceTool extends AgentTool
{
    public int $executions = 0;

    /** @var array<string, mixed> */
    public array $receivedParameters = [];

    public function getName(): string
    {
        return 'fullconv_create_invoice';
    }

    public function getDescription(): string
    {
        return 'Create an invoice for a customer for a given amount.';
    }

    public function getParameters(): array
    {
        return [
            'customer' => ['type' => 'string', 'required' => true, 'description' => 'Customer name'],
            'amount' => ['type' => 'number', 'required' => false, 'description' => 'Invoice amount'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $this->executions++;
        $this->receivedParameters = $parameters;

        $customer = (string) ($parameters['customer'] ?? 'unknown');

        return ActionResult::success(
            message: "Invoice INV-FULLCONV-1 created for {$customer}.",
            data: [
                'invoice_id' => 'INV-FULLCONV-1',
                'customer' => $customer,
                'amount' => $parameters['amount'] ?? null,
            ],
            metadata: ['agent_strategy' => 'tool']
        );
    }
}

/**
 * Skill provider fixture (turn 5). Registered through ai-agent.skill_providers config.
 */
class FullConvSkillProvider implements AgentSkillProvider
{
    public function skills(): iterable
    {
        yield new AgentSkillDefinition(
            id: 'fullconv_translate',
            name: 'Translate Note',
            description: 'Translate a note into another language using the translation tool.',
            triggers: ['translate'],
            tools: ['fullconv_translate_tool'],
            metadata: [
                'planner' => 'ai_native',
                'target_json' => ['language' => null],
                'final_tool' => 'fullconv_translate_tool',
            ],
            enabled: true
        );
    }
}

/**
 * In-test transcript store. Records each turn and replays history so the orchestrator
 * hydrates prior turns into subsequent prompts deterministically (no vector/DB backend).
 */
class FullConvTranscriptStub extends ConversationTranscriptService
{
    /** @var array<int, array{user:string,assistant:string}> */
    public array $turns = [];

    /** @var array<int, array{role:string,content:string}> */
    private array $history = [];

    public function __construct()
    {
        // Intentionally bypass the parent constructor's dependencies.
    }

    public function getOrCreateConversation(
        string $sessionId,
        string|int|null $userId = null,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini'
    ): string {
        return 'fullconv-conversation';
    }

    public function getConversationHistory(string $sessionId, int $limit = 50, string|int|null $userId = null): array
    {
        return array_slice($this->history, -$limit);
    }

    public function saveMessages(
        string $conversationId,
        string $userMessage,
        AIResponse $aiResponse
    ): void {
        $this->turns[] = [
            'user' => $userMessage,
            'assistant' => $aiResponse->getContent(),
        ];

        $this->history[] = ['role' => 'user', 'content' => $userMessage];
        $this->history[] = ['role' => 'assistant', 'content' => $aiResponse->getContent()];
    }
}
