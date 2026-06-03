<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationIntent;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeFinalToolPolicy;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeLookupPolicy;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillPayloadResolver;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillPolicy;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolClassifier;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\RunSkillTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Self-contained coverage for the Skills subsystem: matching, final-tool
 * gating, payload resolution, lookup gating, the run_skill tool, planner
 * branches, and the AgentSkill DTO builder.
 *
 * Collaborators (AgentSkillRegistry / AIEngineService / AiNativeRuntime) are
 * mocked exactly like the existing AgentSkillMatcherTest / RunSkillToolAiNativeTest
 * harness. No real LLM or network calls are made.
 */
class SkillsFlowTest extends UnitTestCase
{
    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<int, AgentSkillDefinition> $skills
     */
    private function skillRegistry(array $skills): AgentSkillRegistry
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn($skills);

        return $registry;
    }

    private function createInvoiceSkill(array $metadataOverrides = []): AgentSkillDefinition
    {
        $metadata = array_replace([
            'planner' => 'ai_native',
            'target_json' => [
                'customer_id' => null,
                'customer_name' => null,
                'customer_email' => null,
                'items' => [],
            ],
            'relations' => [
                [
                    'name' => 'customer',
                    'field' => 'customer_id',
                    'lookup_tool' => 'lookup_customer',
                    'create_tool' => 'create_customer',
                ],
                [
                    'name' => 'product',
                    'field' => 'items.*.product_id',
                    'lookup_tool' => 'lookup_product',
                    'create_tool' => 'create_product',
                ],
            ],
            'final_tool' => 'create_invoice',
        ], $metadataOverrides);

        return new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            tools: ['lookup_customer', 'create_customer', 'lookup_product', 'create_product', 'create_invoice'],
            metadata: $metadata
        );
    }

    private function toolRegistryWithInvoiceTools(): ToolRegistry
    {
        $registry = new ToolRegistry();

        foreach ([
            ['lookup_customer', 'lookup', 'customer', false],
            ['create_customer', 'write', 'customer', true],
            ['lookup_product', 'lookup', 'product', false],
            ['create_product', 'write', 'product', true],
            ['create_invoice', 'write', 'invoice', true],
        ] as [$name, $kind, $entity, $write]) {
            $registry->register($name, $this->stubTool($name, $kind, $entity, $write));
        }

        return $registry;
    }

    private function stubTool(string $name, ?string $kind, ?string $entity, bool $itemsRequired = false): AgentTool
    {
        return new class($name, $kind, $entity, $itemsRequired) extends AgentTool {
            public function __construct(
                private string $toolName,
                private ?string $kind,
                private ?string $entity,
                private bool $itemsRequired
            ) {}

            public function getName(): string
            {
                return $this->toolName;
            }

            public function getDescription(): string
            {
                return 'Stub '.$this->toolName;
            }

            public function getParameters(): array
            {
                if ($this->toolName === 'create_invoice') {
                    return [
                        'customer_id' => ['type' => 'integer', 'required' => false],
                        'items' => ['type' => 'array', 'required' => $this->itemsRequired],
                    ];
                }

                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function getToolKind(): ?string
            {
                return $this->kind;
            }

            public function getEntityType(): ?string
            {
                return $this->entity;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('ok');
            }
        };
    }

    private function skillPolicy(AgentSkillRegistry $skills, ToolRegistry $tools): AiNativeSkillPolicy
    {
        return new AiNativeSkillPolicy($skills, $tools, app(IntentSignalService::class));
    }

    // =====================================================================
    // Scenario: AgentSkillMatcher score tiers + best-score-wins
    // =====================================================================

    public function test_matcher_score_tiers_exact_prefix_word_boundary(): void
    {
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            actions: ['invoices.create']
        );
        $matcher = new AgentSkillMatcher($this->skillRegistry([$skill]));

        $this->assertSame(100, $matcher->match('create invoice')['score']);
        $this->assertSame(90, $matcher->match('create invoice for Acme')['score']);
        $this->assertSame(75, $matcher->match('please create invoice now')['score']);
    }

    public function test_matcher_best_score_wins_across_skills(): void
    {
        $exact = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Invoices.',
            triggers: ['create invoice']
        );
        // Interior word-boundary only (score 75) for the same message.
        $weaker = new AgentSkillDefinition(
            id: 'invoice_report',
            name: 'Invoice Report',
            description: 'Reports.',
            triggers: ['invoice']
        );

        $matcher = new AgentSkillMatcher($this->skillRegistry([$weaker, $exact]));
        $match = $matcher->match('create invoice');

        $this->assertSame('create_invoice', $match['skill']->id);
        $this->assertSame(100, $match['score']);
    }

    public function test_matcher_candidate_triggers_include_name_and_capabilities(): void
    {
        // No explicit triggers; only name + capabilities provide candidates.
        $skill = new AgentSkillDefinition(
            id: 'refund_order',
            name: 'Refund Order',
            description: 'Refunds.',
            triggers: [],
            capabilities: ['issue refund']
        );
        $matcher = new AgentSkillMatcher($this->skillRegistry([$skill]));

        $this->assertSame(100, $matcher->match('refund order')['score']);
        $this->assertSame(100, $matcher->match('issue refund')['score']);
    }

    // =====================================================================
    // Scenario: AgentSkillMatcher AI-intent failure modes
    // =====================================================================

    public function test_match_intent_returns_null_when_generate_throws(): void
    {
        config()->set('ai-agent.skills.intent_matching.min_confidence', 72);
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->with([], false)->andReturn([
            new AgentSkillDefinition('create_invoice', 'Create Invoice', 'Create invoices.', triggers: ['create invoice']),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andThrow(new \RuntimeException('boom'));

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent(
            'handle that paperwork',
            new UnifiedActionContext('intent-throw')
        );

        $this->assertNull($match);
    }

    public function test_match_intent_returns_null_on_unsuccessful_response(): void
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->with([], false)->andReturn([
            new AgentSkillDefinition('create_invoice', 'Create Invoice', 'Create invoices.', triggers: ['create invoice']),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::error('upstream failure', 'openai', 'gpt-4o-mini')
        );

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent(
            'handle that paperwork',
            new UnifiedActionContext('intent-unsuccessful')
        );

        $this->assertNull($match);
    }

    public function test_match_intent_returns_null_on_garbage_json(): void
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->with([], false)->andReturn([
            new AgentSkillDefinition('create_invoice', 'Create Invoice', 'Create invoices.', triggers: ['create invoice']),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('this is not json at all', 'openai', 'gpt-4o-mini')
        );

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent(
            'handle that paperwork',
            new UnifiedActionContext('intent-garbage')
        );

        $this->assertNull($match);
    }

    public function test_match_intent_returns_null_for_skill_id_none_and_null(): void
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->with([], false)->andReturn([
            new AgentSkillDefinition('create_invoice', 'Create Invoice', 'Create invoices.', triggers: ['create invoice']),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->twice()->andReturn(
            AIResponse::success(json_encode(['skill_id' => 'none', 'confidence' => 95]), 'openai', 'gpt-4o-mini'),
            AIResponse::success(json_encode(['skill_id' => 'null', 'confidence' => 95]), 'openai', 'gpt-4o-mini')
        );

        $matcher = new AgentSkillMatcher($registry, $ai);
        $this->assertNull($matcher->matchIntent('do the thing', new UnifiedActionContext('intent-none')));
        $this->assertNull($matcher->matchIntent('do the thing', new UnifiedActionContext('intent-null')));
    }

    public function test_match_intent_disabled_short_circuits_without_calling_ai(): void
    {
        config()->set('ai-agent.skills.intent_matching.enabled', false);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->with([], false)->andReturn([
            new AgentSkillDefinition('create_invoice', 'Create Invoice', 'Create invoices.', triggers: ['create invoice']),
        ]);

        // generate() must never be reached.
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->never();

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent(
            'handle that paperwork',
            new UnifiedActionContext('intent-disabled')
        );

        $this->assertNull($match);
    }

    public function test_match_intent_strips_json_fence_and_rescales_fractional_confidence(): void
    {
        config()->set('ai-agent.skills.intent_matching.enabled', true);
        config()->set('ai-agent.skills.intent_matching.min_confidence', 72);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->with([], false)->andReturn([
            new AgentSkillDefinition('create_invoice', 'Create Invoice', 'Create invoices.', triggers: ['create invoice']),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                "```json\n{\"skill_id\":\"create_invoice\",\"confidence\":0.9,\"reason\":\"ok\"}\n```",
                'openai',
                'gpt-4o-mini'
            )
        );

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent(
            'handle that paperwork',
            new UnifiedActionContext('intent-fenced')
        );

        $this->assertNotNull($match);
        $this->assertSame('create_invoice', $match['skill']->id);
        $this->assertSame(90, $match['score']);
        $this->assertSame('ai_intent', $match['trigger']);
    }

    // =====================================================================
    // Scenario: RunSkillTool reset / fresh_start / message fallback / mapping
    // =====================================================================

    public function test_run_skill_reset_wipes_state_and_seeds_scope(): void
    {
        $context = new UnifiedActionContext('run-skill-reset');
        $context->metadata['ai_native'] = ['stale' => 'value', 'tool_results' => [['tool' => 'x']]];

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::success('done', context: $context));

        $tool = new RunSkillTool($native);
        $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice',
            'reset' => true,
        ], $context);

        $state = $context->metadata['ai_native'];
        $this->assertArrayNotHasKey('stale', $state);
        $this->assertArrayNotHasKey('tool_results', $state);
        $this->assertSame('create_invoice', $state['selected_skill_id']);
        $this->assertSame('skill', $state['runtime_scope']);
    }

    public function test_run_skill_propagates_fresh_start_into_state_and_options(): void
    {
        $context = new UnifiedActionContext('run-skill-fresh');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('create invoice', $context, Mockery::on(
                fn (array $options): bool => ($options['fresh_start'] ?? null) === true
            ))
            ->andReturn(AgentResponse::success('done', context: $context));

        $tool = new RunSkillTool($native);
        $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice',
            'fresh_start' => true,
        ], $context);

        $this->assertTrue($context->metadata['ai_native']['fresh_start']);
    }

    public function test_run_skill_falls_back_to_latest_user_message(): void
    {
        $context = new UnifiedActionContext('run-skill-fallback');
        $context->metadata['latest_user_message'] = 'create invoice from history';

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('create invoice from history', $context, Mockery::any())
            ->andReturn(AgentResponse::success('done', context: $context));

        $tool = new RunSkillTool($native);
        $result = $tool->execute(['skill_id' => 'create_invoice'], $context);

        $this->assertTrue($result->success);
    }

    public function test_run_skill_maps_needs_user_input_response(): void
    {
        $context = new UnifiedActionContext('run-skill-needs-input');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')->once()->andReturn(
            AgentResponse::needsUserInput(
                'What email should I use?',
                ['current_payload' => []],
                null,
                $context,
                null,
                ['customer_email']
            )
        );

        $tool = new RunSkillTool($native);
        $result = $tool->execute(['skill_id' => 'create_invoice', 'message' => 'create invoice'], $context);

        $this->assertTrue($result->requiresUserInput());
        $this->assertSame(['customer_email'], $result->metadata['required_inputs']);
    }

    public function test_run_skill_maps_failure_response(): void
    {
        $context = new UnifiedActionContext('run-skill-failure');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')->once()->andReturn(
            AgentResponse::failure('Could not create invoice.', null, $context)
        );

        $tool = new RunSkillTool($native);
        $result = $tool->execute(['skill_id' => 'create_invoice', 'message' => 'create invoice'], $context);

        $this->assertFalse($result->success);
        $this->assertFalse($result->requiresUserInput());
        // ActionResult::failure() routes the message into the error field.
        $this->assertSame('Could not create invoice.', $result->error);
    }

    // =====================================================================
    // Scenario: AgentSkillExecutionPlanner fresh-skill / tools-without-planner
    // =====================================================================

    public function test_planner_marks_fresh_start_when_request_matches(): void
    {
        $skill = $this->createInvoiceSkill();
        $context = new UnifiedActionContext('planner-fresh');
        $context->metadata['_fresh_skill_request'] = [
            'skill_id' => 'create_invoice',
            'message' => 'create invoice',
        ];

        $plan = (new AgentSkillExecutionPlanner())->plan($skill, 'create invoice', $context);

        $this->assertSame('run_skill', $plan['resource_name']);
        $this->assertTrue($plan['params']['fresh_start']);
        $this->assertTrue($plan['params']['reset']);
    }

    public function test_planner_fresh_start_false_when_request_message_differs(): void
    {
        $skill = $this->createInvoiceSkill();
        $context = new UnifiedActionContext('planner-not-fresh');
        $context->metadata['_fresh_skill_request'] = [
            'skill_id' => 'create_invoice',
            'message' => 'different message',
        ];

        $plan = (new AgentSkillExecutionPlanner())->plan($skill, 'create invoice', $context);

        $this->assertFalse($plan['params']['fresh_start']);
    }

    public function test_planner_falls_back_to_run_skill_without_explicit_planner(): void
    {
        // No 'planner' metadata; actions empty, tools non-empty, target_json present.
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            tools: ['lookup_customer', 'create_invoice'],
            actions: [],
            metadata: ['target_json' => ['customer_name' => null, 'items' => []]]
        );

        $plan = (new AgentSkillExecutionPlanner())->plan(
            $skill,
            'create invoice',
            new UnifiedActionContext('planner-fallback')
        );

        $this->assertSame('use_tool', $plan['action']);
        $this->assertSame('run_skill', $plan['resource_name']);
        $this->assertTrue($plan['params']['reset']);
    }

    // =====================================================================
    // Scenario: AiNativeFinalToolPolicy merges plural + singular final tools
    // =====================================================================

    public function test_final_tool_policy_merges_plural_and_singular_tools(): void
    {
        $skill = $this->createInvoiceSkill([
            'final_tools' => ['submit_invoice'],
            'final_tool' => 'create_invoice',
        ]);
        $skills = $this->skillRegistry([$skill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $signals = app(IntentSignalService::class);
        $confirmation = new AiNativeConfirmationIntent($signals);
        $policy = new AiNativeFinalToolPolicy($skills, $matcher, $confirmation, $this->toolRegistryWithInvoiceTools());

        $tools = $policy->requiredTools('', ['skill_id' => 'create_invoice'], []);

        $this->assertSame(['submit_invoice', 'create_invoice'], $tools);
    }

    public function test_final_tool_requirement_clears_when_any_declared_tool_succeeds(): void
    {
        // Documented contract (docs/agent-skills.mdx): the runtime rejects a final
        // answer "until ONE declared final tool has executed successfully" — i.e. OR
        // semantics across the declared final tools, not all-of-them.
        $skill = $this->createInvoiceSkill([
            'final_tools' => ['submit_invoice'],
            'final_tool' => 'create_invoice',
        ]);
        $skills = $this->skillRegistry([$skill]);
        $tools = $this->toolRegistryWithInvoiceTools();
        $policy = $this->skillPolicy($skills, $tools);

        $options = ['skill_id' => 'create_invoice', 'runtime_scope' => 'skill'];

        // No results yet -> required.
        $this->assertTrue($policy->needsRequiredFinalToolBeforeFinal('create invoice', [], $options));

        // Only success:false results -> still required (false/missing does not satisfy).
        $stateFalse = ['tool_results' => [
            ['tool' => 'submit_invoice', 'result' => ['success' => false]],
            ['tool' => 'create_invoice', 'result' => ['success' => false]],
        ]];
        $this->assertTrue($policy->needsRequiredFinalToolBeforeFinal('create invoice', $stateFalse, $options));

        // ANY one declared final tool succeeding clears the requirement (OR), even
        // though the other declared final tool (create_invoice) never ran.
        $stateOne = ['tool_results' => [
            ['tool' => 'submit_invoice', 'result' => ['success' => true]],
        ]];
        $this->assertFalse($policy->needsRequiredFinalToolBeforeFinal('create invoice', $stateOne, $options));
    }

    // =====================================================================
    // Scenario: requirementApplies approval-message / runtime-feedback branches
    // =====================================================================

    public function test_requirement_applies_via_approval_message_without_active_skill(): void
    {
        $skill = $this->createInvoiceSkill();
        $skills = $this->skillRegistry([$skill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $confirmation = new AiNativeConfirmationIntent(app(IntentSignalService::class));
        $policy = new AiNativeFinalToolPolicy($skills, $matcher, $confirmation, $this->toolRegistryWithInvoiceTools());

        // No skill scope, no active objective, but an approval-style message.
        $this->assertTrue($policy->requirementApplies('yes go ahead', [], []));
    }

    public function test_requirement_applies_via_runtime_feedback_reason(): void
    {
        $skill = $this->createInvoiceSkill();
        $skills = $this->skillRegistry([$skill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $confirmation = new AiNativeConfirmationIntent(app(IntentSignalService::class));
        $policy = new AiNativeFinalToolPolicy($skills, $matcher, $confirmation, $this->toolRegistryWithInvoiceTools());

        $state = ['runtime_feedback' => [
            ['reason' => 'final_tool_required_before_confirmation_question'],
        ]];

        // A neutral, non-approving, non-matching message still applies via feedback.
        $this->assertTrue($policy->requirementApplies('what time is it', $state, []));
    }

    public function test_requirement_applies_false_when_no_evidence(): void
    {
        $skill = $this->createInvoiceSkill();
        $skills = $this->skillRegistry([$skill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $confirmation = new AiNativeConfirmationIntent(app(IntentSignalService::class));
        $policy = new AiNativeFinalToolPolicy($skills, $matcher, $confirmation, $this->toolRegistryWithInvoiceTools());

        // Non-matching, non-approval message, no payload, no tool_results, no feedback.
        $this->assertFalse($policy->requirementApplies('tell me a joke', [], []));
    }

    // =====================================================================
    // Scenario: AiNativeSkillPayloadResolver fromPlan precedence + looksLike
    // =====================================================================

    public function test_payload_resolver_precedence_ladder(): void
    {
        $skill = $this->createInvoiceSkill();
        $skills = $this->skillRegistry([$skill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $resolver = new AiNativeSkillPayloadResolver($skills, $matcher);

        $options = ['skill_id' => 'create_invoice'];

        // current_payload wins over all the others.
        $plan = ['data' => [
            'current_payload' => ['customer_name' => 'A'],
            'draft_payload' => ['customer_name' => 'B'],
            'payload' => ['customer_name' => 'C'],
            'draft' => ['payload' => ['customer_name' => 'D']],
        ]];
        $this->assertSame(['customer_name' => 'A'], $resolver->fromPlan($plan, [], $options));

        // draft.payload returned when only it is set.
        $planDraft = ['data' => ['draft' => ['payload' => ['customer_name' => 'D']]]];
        $this->assertSame(['customer_name' => 'D'], $resolver->fromPlan($planDraft, [], $options));
    }

    public function test_payload_resolver_bare_data_matches_target_json_keys(): void
    {
        $skill = $this->createInvoiceSkill();
        $skills = $this->skillRegistry([$skill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $resolver = new AiNativeSkillPayloadResolver($skills, $matcher);

        // task_frame.active_objective drives selectedSkillIdForActiveTask.
        $state = ['task_frame' => ['active_objective' => 'create_invoice', 'status' => 'working']];
        $plan = ['data' => ['customer_name' => 'Globex']];

        $this->assertSame(['customer_name' => 'Globex'], $resolver->fromPlan($plan, $state, []));
    }

    public function test_payload_resolver_returns_empty_for_empty_or_completed_or_no_target(): void
    {
        $invoiceSkill = $this->createInvoiceSkill();
        $skills = $this->skillRegistry([$invoiceSkill]);
        $matcher = new AiNativeSkillMatcher($skills);
        $resolver = new AiNativeSkillPayloadResolver($skills, $matcher);

        // Empty data.
        $this->assertSame([], $resolver->fromPlan(['data' => []], [], ['skill_id' => 'create_invoice']));

        // Completed task -> selectedSkillId becomes '' -> [].
        $completed = ['task_frame' => ['active_objective' => 'create_invoice', 'status' => 'completed']];
        $this->assertSame([], $resolver->fromPlan(['data' => ['customer_name' => 'X']], $completed, []));

        // Skill with no target_json -> [].
        $noTarget = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            tools: ['create_invoice'],
            metadata: ['planner' => 'ai_native']
        );
        $noTargetResolver = new AiNativeSkillPayloadResolver(
            $this->skillRegistry([$noTarget]),
            new AiNativeSkillMatcher($this->skillRegistry([$noTarget]))
        );
        $this->assertSame([], $noTargetResolver->fromPlan(
            ['data' => ['customer_name' => 'X']],
            [],
            ['skill_id' => 'create_invoice']
        ));
    }

    // =====================================================================
    // Scenario: seedActiveTask lifecycle
    // =====================================================================

    public function test_seed_active_task_clears_completed_and_reseeds_from_trigger(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['task_frame' => ['active_objective' => 'stale_objective', 'status' => 'completed']];
        $policy->seedActiveTask('please create the invoice', $state, []);

        $this->assertSame('create_invoice', $state['task_frame']['active_objective']);
        $this->assertSame('working', $state['task_frame']['status']);
    }

    public function test_seed_active_task_preserves_existing_working_objective(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['task_frame' => ['active_objective' => 'existing_objective', 'status' => 'working']];
        $policy->seedActiveTask('create invoice', $state, []);

        $this->assertSame('existing_objective', $state['task_frame']['active_objective']);
    }

    public function test_seed_active_task_option_override_beats_trigger_scan(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = [];
        $policy->seedActiveTask('totally unrelated text', $state, ['skill_id' => 'forced_skill']);

        $this->assertSame('forced_skill', $state['task_frame']['active_objective']);
    }

    // =====================================================================
    // Scenario: needsNextStepAfterMissingLookup
    // =====================================================================

    public function test_needs_next_step_after_missing_lookup_true_on_not_found(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['tool_results' => [
            ['tool' => 'lookup_customer', 'result' => ['data' => ['found' => false]]],
        ]];

        $this->assertTrue($policy->needsNextStepAfterMissingLookup(
            'create invoice',
            $state,
            [],
            ['data' => []]
        ));
    }

    public function test_needs_next_step_after_missing_lookup_false_cases(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        // Non find/search/lookup tool.
        $stateWrite = ['tool_results' => [
            ['tool' => 'create_invoice', 'result' => ['data' => ['found' => false]]],
        ]];
        $this->assertFalse($policy->needsNextStepAfterMissingLookup('create invoice', $stateWrite, [], ['data' => []]));

        // found === true.
        $stateFound = ['tool_results' => [
            ['tool' => 'lookup_customer', 'result' => ['data' => ['found' => true]]],
        ]];
        $this->assertFalse($policy->needsNextStepAfterMissingLookup('create invoice', $stateFound, [], ['data' => []]));

        // Non-empty plan.data.
        $stateMiss = ['tool_results' => [
            ['tool' => 'lookup_customer', 'result' => ['data' => ['found' => false]]],
        ]];
        $this->assertFalse($policy->needsNextStepAfterMissingLookup('create invoice', $stateMiss, [], ['data' => ['x' => 1]]));

        // Message does not match any skill.
        $this->assertFalse($policy->needsNextStepAfterMissingLookup('hello there', $stateMiss, [], ['data' => []]));
    }

    // =====================================================================
    // Scenario: needsLookupBeforeAsk gauntlet
    // =====================================================================

    public function test_needs_lookup_before_ask_true_when_no_results_yet(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $plan = ['message' => 'What is the customer name?', 'required_inputs' => ['customer_name']];
        $this->assertTrue($policy->needsLookupBeforeAsk('create invoice', [], ['skill_id' => 'create_invoice'], $plan));
    }

    public function test_needs_lookup_before_ask_false_on_continuation_abandoned_feedback(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['runtime_feedback' => [
            ['reason' => 'suggested_tool_continuation_abandoned'],
        ]];
        $plan = ['message' => 'What is the customer name?', 'required_inputs' => ['customer_name']];

        $this->assertFalse($policy->needsLookupBeforeAsk('create invoice', $state, ['skill_id' => 'create_invoice'], $plan));
    }

    public function test_needs_lookup_before_ask_false_on_pending_tool(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['pending_tool' => ['tool' => 'create_invoice']];
        $plan = ['message' => 'What is the customer name?', 'required_inputs' => ['customer_name']];

        $this->assertFalse($policy->needsLookupBeforeAsk('create invoice', $state, ['skill_id' => 'create_invoice'], $plan));
    }

    public function test_needs_lookup_before_ask_false_when_latest_lookup_not_found(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['tool_results' => [
            ['tool' => 'lookup_customer', 'result' => ['data' => ['found' => false]]],
        ]];
        $plan = ['message' => 'What customer email?', 'required_inputs' => ['customer_email']];

        $this->assertFalse($policy->needsLookupBeforeAsk('create invoice', $state, ['skill_id' => 'create_invoice'], $plan));
    }

    public function test_needs_lookup_before_ask_false_on_unused_lookup_feedback(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        // A prior found result for product, but the customer lookup is unused and
        // the runtime has already flagged ask_with_unused_lookup_tools.
        $state = [
            'tool_results' => [
                ['tool' => 'lookup_product', 'result' => ['data' => ['found' => true]]],
            ],
            'runtime_feedback' => [
                ['reason' => 'ask_with_unused_lookup_tools'],
            ],
        ];
        $plan = ['message' => 'What is the customer name?', 'required_inputs' => ['customer_name']];

        $this->assertFalse($policy->needsLookupBeforeAsk('create invoice', $state, ['skill_id' => 'create_invoice'], $plan));
    }

    // =====================================================================
    // Scenario: matchingLookupToolsForWrite
    // =====================================================================

    public function test_matching_lookup_tools_for_write_resolves_skill_lookup_tool(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $tools = $policy->matchingLookupToolsForWrite(
            'create_customer',
            ['task_frame' => ['active_objective' => 'create_invoice', 'status' => 'working']],
            ['skill_id' => 'create_invoice']
        );

        $this->assertSame(['lookup_customer'], $tools);
    }

    public function test_matching_lookup_tools_for_write_empty_without_active_skill(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $this->assertSame([], $policy->matchingLookupToolsForWrite('create_customer', [], []));
    }

    // =====================================================================
    // Scenario: relationCreateNeedsLookupMiss + cross-relation isolation
    // =====================================================================

    public function test_relation_create_needs_lookup_miss_when_no_matching_lookup(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        // create_product is a relation create_tool; no lookup_product miss for "Northwind" yet.
        $this->assertTrue($policy->relationCreateNeedsLookupMiss(
            'create_product',
            ['name' => 'Northwind'],
            ['tool_results' => []],
            ['skill_id' => 'create_invoice']
        ));
    }

    public function test_relation_create_does_not_reuse_other_relation_lookup_miss(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        // A customer lookup-miss for "Northwind" must NOT satisfy the product relation.
        $state = ['tool_results' => [
            [
                'tool' => 'lookup_customer',
                'params' => ['query' => 'Northwind'],
                'result' => ['data' => ['found' => false, 'name' => 'Northwind']],
            ],
        ]];

        $this->assertTrue($policy->relationCreateNeedsLookupMiss(
            'create_product',
            ['name' => 'Northwind'],
            $state,
            ['skill_id' => 'create_invoice']
        ));
    }

    public function test_relation_create_satisfied_by_own_lookup_miss(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        // A product lookup-miss for "Northwind" satisfies the product relation create.
        $state = ['tool_results' => [
            [
                'tool' => 'lookup_product',
                'params' => ['query' => 'Northwind'],
                'result' => ['data' => ['found' => false, 'name' => 'Northwind']],
            ],
        ]];

        $this->assertFalse($policy->relationCreateNeedsLookupMiss(
            'create_product',
            ['name' => 'Northwind'],
            $state,
            ['skill_id' => 'create_invoice']
        ));
    }

    // =====================================================================
    // Scenario: needsFinalToolBeforeAsk early-return guards
    // =====================================================================

    public function test_needs_final_tool_before_ask_true_when_payload_present_and_tool_unsuccessful(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $state = ['task_frame' => ['current_payload' => ['customer_id' => 5, 'items' => [['product_id' => 1]]]]];
        $options = ['skill_id' => 'create_invoice', 'runtime_scope' => 'skill'];

        $this->assertTrue($policy->needsFinalToolBeforeAsk('create invoice', $state, $options, []));
    }

    public function test_needs_final_tool_before_ask_guards(): void
    {
        $policy = $this->skillPolicy(
            $this->skillRegistry([$this->createInvoiceSkill()]),
            $this->toolRegistryWithInvoiceTools()
        );

        $options = ['skill_id' => 'create_invoice', 'runtime_scope' => 'skill'];
        $payload = ['task_frame' => ['current_payload' => ['customer_id' => 5, 'items' => [['product_id' => 1]]]]];

        // pending_tool present -> false.
        $this->assertFalse($policy->needsFinalToolBeforeAsk(
            'create invoice',
            array_merge($payload, ['pending_tool' => ['tool' => 'create_invoice']]),
            $options,
            []
        ));

        // plan.required_inputs non-empty -> false.
        $this->assertFalse($policy->needsFinalToolBeforeAsk(
            'create invoice',
            $payload,
            $options,
            ['required_inputs' => ['customer_email']]
        ));

        // empty current_payload -> false.
        $this->assertFalse($policy->needsFinalToolBeforeAsk(
            'create invoice',
            ['task_frame' => ['current_payload' => []]],
            $options,
            []
        ));

        // final tool already successful -> false.
        $satisfied = ['task_frame' => ['current_payload' => ['customer_id' => 5, 'items' => [['product_id' => 1]]]],
            'tool_results' => [['tool' => 'create_invoice', 'result' => ['success' => true]]]];
        $this->assertFalse($policy->needsFinalToolBeforeAsk('create invoice', $satisfied, $options, []));
    }

    // =====================================================================
    // Scenario: AgentSkill DTO building fallbacks
    // =====================================================================

    public function test_agent_skill_dto_id_name_description_capability_fallbacks(): void
    {
        $named = new CreatePurchaseOrderSkill();
        $definition = $named->definition();

        $this->assertSame('create_purchase_order', $definition->id);
        $this->assertSame('Create Purchase Order', $definition->name);
        $this->assertStringContainsString('Create Purchase Order', $definition->description);
        $this->assertSame(['create_purchase_order'], $definition->capabilities);
        // planner defaults to ai_native and target_json is present.
        $this->assertSame('ai_native', $definition->metadata['planner']);
        $this->assertArrayHasKey('target_json', $definition->metadata);
    }

    public function test_agent_skill_dto_final_tool_class_string_normalizes_to_name(): void
    {
        $skill = new PurchaseOrderWithFinalToolSkill();
        $definition = $skill->definition();

        $this->assertSame('submit_po', $definition->metadata['final_tool']);
    }

    public function test_agent_skill_dto_metadata_prompt_precedence(): void
    {
        $skill = new PurchaseOrderWithPromptSkill();
        $definition = $skill->definition();

        // metadata['prompt'] takes precedence over the prompt property.
        $this->assertSame('From metadata prompt', $definition->metadata['prompt']);
    }
}

/**
 * Named AgentSkill subclasses for DTO-building assertions. Kept in the same file
 * to satisfy the single-file rule; they are local to this test namespace.
 */
class CreatePurchaseOrderSkill extends AgentSkill
{
    public function targetJson(): array
    {
        return ['vendor_name' => null, 'lines' => []];
    }
}

class SubmitPoTool extends AgentTool
{
    public function getName(): string
    {
        return 'submit_po';
    }

    public function getDescription(): string
    {
        return 'Submit a purchase order.';
    }

    public function getParameters(): array
    {
        return ['vendor_id' => ['type' => 'integer', 'required' => true]];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}

class PurchaseOrderWithFinalToolSkill extends AgentSkill
{
    public string $finalTool = SubmitPoTool::class;

    public function targetJson(): array
    {
        return ['vendor_name' => null];
    }
}

class PurchaseOrderWithPromptSkill extends AgentSkill
{
    public string $prompt = 'From property prompt';

    public function targetJson(): array
    {
        return ['vendor_name' => null];
    }

    public function metadata(): array
    {
        return ['prompt' => 'From metadata prompt'];
    }
}
