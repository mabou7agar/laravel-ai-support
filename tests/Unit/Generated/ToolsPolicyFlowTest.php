<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool;
use LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\SuggestValueTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Self-contained coverage for the "Tools + execution policy" surface:
 *
 *   - AgentExecutionPolicyService: canUseTool / canUseSubAgent / canUseRagCollection
 *     / canRouteToNode allow-deny precedence, wildcard + bare-'*' matching,
 *     empty-name short-circuit,
 *     blockedMessage() format, redactSensitive() recursion / camelCase / suffix
 *     matching / config override, and sanitizePayloadForRuntime() langgraph gate.
 *   - ToolRegistry: primitive register/get/has/all/getToolDefinitions and
 *     discoverFromConfig() config + manifest-override + throwable-swallow +
 *     bad-class-skip + construction-failure-skip behaviour.
 *   - AgentTool: validate() null/empty/multiple-missing edge cases and the full
 *     nine-key toArray() shape (base defaults and overridden), plus the
 *     previewConfirmation/requiresConfirmation/getConfirmationMessage base hooks.
 *   - AI-backed field tools (ExplainFieldTool, SuggestValueTool, SearchOptionsTool):
 *     happy path + try/catch->null->failure, with the AIEngineService mocked.
 *   - AgentToolServiceRegistrar config gating of the singleton ToolRegistry.
 *
 * The AI engine is ALWAYS a Mockery mock of AIEngineService returning a canned
 * AIResponse; no real LLM / network call is made anywhere in this file.
 *
 * Deliberately NOT reproduced here (and why):
 *   - The AiNative mid-loop allow-list block and the AgentExecutionDispatcher /
 *     AgentRuntimeManager cross-surface scenarios require wiring the full ~23-arg
 *     AiNativeRuntime graph (or federated NodeSessionManager/GoalAgentService);
 *     the allow-list policy logic they assert is already exercised directly here
 *     against AgentExecutionPolicyService, so duplicating the heavy graph would
 *     add brittleness without new policy coverage.
 *   - SearchOptionsTool's model-backed (searchInModel) branch depends on a global
 *     schema() helper that the host app supplies but this package does not define,
 *     so only the AI-fallback + empty-options-failure branches are covered.
 */
class ToolsPolicyFlowTest extends UnitTestCase
{
    private function policy(): AgentExecutionPolicyService
    {
        return app(AgentExecutionPolicyService::class);
    }

    private function context(array $runtimeState = []): UnifiedActionContext
    {
        $ctx = new UnifiedActionContext(sessionId: 'tools-policy');
        $ctx->runtimeState = $runtimeState;

        return $ctx;
    }

    /** A captured-prompt mock of the AI engine that returns a canned content string. */
    private function aiReturning(string $content, ?\Closure $capture = null): AIEngineService
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->andReturnUsing(function (AIRequest $request) use ($content, $capture): AIResponse {
                if ($capture !== null) {
                    $capture($request);
                }

                return AIResponse::success($content, 'openai', 'gpt-4o-mini');
            });

        return $ai;
    }

    private function aiThrowing(): AIEngineService
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->andThrow(new \RuntimeException('LLM down'));

        return $ai;
    }

    // ------------------------------------------------------------------
    // PolicyService allow-list precedence, wildcards, empty-name short-circuit
    // ------------------------------------------------------------------

    public function test_policy_tool_allow_deny_precedence_and_empty_name_short_circuit(): void
    {
        $policy = $this->policy();

        // No policy config -> empty deny + empty allow -> everything allowed.
        $this->assertTrue($policy->canUseTool('lookup_customer'));

        // Non-empty allow restricts to glob matches only.
        config()->set('ai-agent.execution_policy.tool_allow', ['create_*']);
        $this->assertTrue($policy->canUseTool('create_invoice'));
        $this->assertFalse($policy->canUseTool('lookup_customer'));

        // Deny wins over a would-be allow match.
        config()->set('ai-agent.execution_policy.tool_deny', ['create_invoice']);
        $this->assertFalse($policy->canUseTool('create_invoice'));

        // Bare '*' in deny blocks everything.
        config()->set('ai-agent.execution_policy.tool_allow', []);
        config()->set('ai-agent.execution_policy.tool_deny', ['*']);
        $this->assertFalse($policy->canUseTool('anything'));

        // Blank / whitespace name short-circuits to allowed before deny/allow consulted.
        $this->assertTrue($policy->canUseTool(''));
        $this->assertTrue($policy->canUseTool('   '));
    }

    // ------------------------------------------------------------------
    // Allow-list variants per resource namespace
    // ------------------------------------------------------------------

    public function test_policy_allow_variants_for_node_rag_sub_agent_and_runtime(): void
    {
        $policy = $this->policy();

        config()->set('ai-agent.execution_policy.node_allow', ['invoice', 'order_*']);
        $this->assertTrue($policy->canRouteToNode('invoice'));
        $this->assertTrue($policy->canRouteToNode('order_detail'));
        $this->assertFalse($policy->canRouteToNode('refund'));

        config()->set('ai-agent.execution_policy.rag_collection_allow', ['public_*']);
        config()->set('ai-agent.execution_policy.rag_collection_deny', ['public_secret']);
        $this->assertTrue($policy->canUseRagCollection('public_docs'));
        $this->assertFalse($policy->canUseRagCollection('public_secret'));

        config()->set('ai-agent.execution_policy.sub_agent_allow', ['billing_*']);
        $this->assertTrue($policy->canUseSubAgent('billing_collector'));
        $this->assertFalse($policy->canUseSubAgent('hr_bot'));

        config()->set('ai-agent.execution_policy.runtime_deny', ['langgraph']);
        $this->assertFalse($policy->canUseRuntime('langgraph'));
        $this->assertTrue($policy->canUseRuntime('laravel'));
    }

    // ------------------------------------------------------------------
    // blockedMessage() format
    // ------------------------------------------------------------------

    public function test_blocked_message_transforms_type_underscores_only(): void
    {
        $policy = $this->policy();

        $this->assertSame(
            'Agent rag collection [private_docs] is blocked by execution policy.',
            $policy->blockedMessage('rag_collection', 'private_docs')
        );
        $this->assertSame(
            'Agent sub agent [billing] is blocked by execution policy.',
            $policy->blockedMessage('sub_agent', 'billing')
        );
        // Underscores inside the NAME are preserved; only the TYPE is transformed.
        $this->assertSame(
            'Agent tool [lookup_customer] is blocked by execution policy.',
            $policy->blockedMessage('tool', 'lookup_customer')
        );
    }

    // ------------------------------------------------------------------
    // redactSensitive()
    // ------------------------------------------------------------------

    public function test_redact_sensitive_defaults_recursion_and_normalization(): void
    {
        $policy = $this->policy();

        // Defaults: password / token / secret / api_key / authorization.
        $out = $policy->redactSensitive([
            'password' => 'p',
            'username' => 'u',
            'nested' => ['api_key' => 'k', 'keep' => 1],
        ]);
        $this->assertSame('[redacted]', $out['password']);
        $this->assertSame('u', $out['username']);
        $this->assertSame('[redacted]', $out['nested']['api_key']);
        $this->assertSame(1, $out['nested']['keep']);

        // camelCase 'apiToken' -> 'api_token' -> '_token' suffix; 'access_token' -> '_token'.
        $out = $policy->redactSensitive(['apiToken' => 'x', 'access_token' => 'y']);
        $this->assertSame('[redacted]', $out['apiToken']);
        $this->assertSame('[redacted]', $out['access_token']);

        // Dash / dot suffix branches after lowercasing ('-secret' / '.secret').
        $out = $policy->redactSensitive(['X-Secret' => 'z', 'config.secret' => 'q']);
        $this->assertSame('[redacted]', $out['X-Secret']);
        $this->assertSame('[redacted]', $out['config.secret']);

        // A sensitive key whose value is an array is replaced wholesale (not recursed).
        $out = $policy->redactSensitive(['secret' => ['deep' => 'v']]);
        $this->assertSame('[redacted]', $out['secret']);
    }

    public function test_redact_sensitive_config_override_replaces_defaults(): void
    {
        $policy = $this->policy();

        config()->set('ai-agent.execution_policy.sensitive_keys', ['custom_*']);

        $out = $policy->redactSensitive(['custom_field' => 'v', 'password' => 'p']);

        // custom_field matched via fnmatch; password NOT redacted (custom list replaces defaults).
        $this->assertSame('[redacted]', $out['custom_field']);
        $this->assertSame('p', $out['password']);
    }

    // ------------------------------------------------------------------
    // sanitizePayloadForRuntime()
    // ------------------------------------------------------------------

    public function test_sanitize_payload_only_redacts_for_langgraph(): void
    {
        $policy = $this->policy();

        $lg = $policy->sanitizePayloadForRuntime('langgraph', ['token' => 'abc', 'keep' => 1]);
        $this->assertSame('[redacted]', $lg['token']);
        $this->assertSame(1, $lg['keep']);

        $laravel = $policy->sanitizePayloadForRuntime('laravel', ['token' => 'abc', 'keep' => 1]);
        $this->assertSame(['token' => 'abc', 'keep' => 1], $laravel);

        $custom = $policy->sanitizePayloadForRuntime('custom_runtime', ['secret' => 's']);
        $this->assertSame(['secret' => 's'], $custom);
    }

    // ------------------------------------------------------------------
    // ToolRegistry::discoverFromConfig()
    // ------------------------------------------------------------------

    public function test_discover_from_config_registers_skips_bad_class_and_logs_construction_failure(): void
    {
        // Neutralise the manifest so only config tools are considered here.
        $manifest = Mockery::mock(AgentManifestService::class);
        $manifest->shouldReceive('tools')->andReturn([]);
        app()->instance(AgentManifestService::class, $manifest);

        config()->set('ai-agent.tools', [
            'validate_field' => ValidateFieldTool::class,
            'ghost' => 'App\\NoSuchClass',
            'broken' => ToolsPolicyUnconstructableTool::class,
        ]);

        $registry = new ToolRegistry();
        $registry->discoverFromConfig();

        // Existing class registered; non-existent class silently skipped (no throw).
        $this->assertTrue($registry->has('validate_field'));
        $this->assertFalse($registry->has('ghost'));
        // Construction failure logged-and-skipped; discovery still completed.
        $this->assertFalse($registry->has('broken'));
    }

    public function test_discover_from_config_manifest_overrides_config_entry(): void
    {
        $manifest = Mockery::mock(AgentManifestService::class);
        $manifest->shouldReceive('tools')->andReturn([
            'validate_field' => ToolsPolicyNamedTool::class,
        ]);
        app()->instance(AgentManifestService::class, $manifest);

        config()->set('ai-agent.tools', ['validate_field' => ValidateFieldTool::class]);

        $registry = new ToolRegistry();
        $registry->discoverFromConfig();

        // array_merge -> manifest entry wins on the name collision.
        $this->assertInstanceOf(ToolsPolicyNamedTool::class, $registry->get('validate_field'));
    }

    public function test_discover_from_config_swallows_manifest_throwable_and_keeps_config_tools(): void
    {
        $manifest = Mockery::mock(AgentManifestService::class);
        $manifest->shouldReceive('tools')->andThrow(new \RuntimeException('manifest boom'));
        app()->instance(AgentManifestService::class, $manifest);

        config()->set('ai-agent.tools', ['validate_field' => ValidateFieldTool::class]);

        $registry = new ToolRegistry();
        $registry->discoverFromConfig(); // must not throw

        $this->assertTrue($registry->has('validate_field'));
    }

    // ------------------------------------------------------------------
    // ToolRegistry primitive API
    // ------------------------------------------------------------------

    public function test_registry_primitive_register_overwrite_miss_all_and_definitions(): void
    {
        $registry = new ToolRegistry();

        $this->assertFalse($registry->has('x'));
        $this->assertNull($registry->get('x'));

        $toolA = new ToolsPolicyNamedTool('x');
        $toolB = new ToolsPolicyNamedTool('x');
        $registry->register('x', $toolA);
        $registry->register('x', $toolB);

        $this->assertSame($toolB, $registry->get('x'));
        $this->assertCount(1, $registry->all());

        $registry->register('y', new ToolsPolicyNamedTool('y'));
        $all = $registry->all();
        $this->assertArrayHasKey('x', $all);
        $this->assertArrayHasKey('y', $all);

        $defs = $registry->getToolDefinitions();
        $this->assertCount(2, $defs);
        $this->assertSame($toolB->toArray(), $defs['x']);
        $this->assertSame('y', $defs['y']['name']);
    }

    // ------------------------------------------------------------------
    // AgentTool::validate() + toArray()
    // ------------------------------------------------------------------

    public function test_agent_tool_validate_edge_cases(): void
    {
        $tool = new ToolsPolicyValidatingTool();

        $this->assertSame(
            ['Missing required parameter: a', 'Missing required parameter: b'],
            $tool->validate([])
        );

        // null AND empty-string both count as missing.
        $this->assertSame(
            ['Missing required parameter: a', 'Missing required parameter: b'],
            $tool->validate(['a' => null, 'b' => ''])
        );

        // 0 is present-and-not-null/'' so passes; non-required c absent is fine.
        $this->assertSame([], $tool->validate(['a' => 'x', 'b' => 0]));
    }

    public function test_agent_tool_to_array_full_shape_overridden_and_default(): void
    {
        $overridden = new ToolsPolicyRichTool();
        $arr = $overridden->toArray();
        $this->assertSame([
            'name',
            'description',
            'parameters',
            'result_schema',
            'capabilities',
            'tool_kind',
            'entity_type',
            'relations',
            'requires_confirmation',
        ], array_keys($arr));
        $this->assertSame(['field' => 'string'], $arr['result_schema']);
        $this->assertSame(['cap'], $arr['capabilities']);
        $this->assertSame('reader', $arr['tool_kind']);
        $this->assertSame('Invoice', $arr['entity_type']);
        $this->assertSame([['name' => 'customer']], $arr['relations']);
        $this->assertTrue($arr['requires_confirmation']);

        $base = new ToolsPolicyNamedTool('plain');
        $arr = $base->toArray();
        $this->assertSame([], $arr['result_schema']);
        $this->assertSame([], $arr['capabilities']);
        $this->assertNull($arr['tool_kind']);
        $this->assertNull($arr['entity_type']);
        $this->assertSame([], $arr['relations']);
        $this->assertFalse($arr['requires_confirmation']);
    }

    public function test_agent_tool_base_optional_hooks_default(): void
    {
        $base = new ToolsPolicyNamedTool('plain');

        $this->assertNull($base->previewConfirmation([], $this->context()));
        $this->assertFalse($base->requiresConfirmation());
        $this->assertNull($base->getConfirmationMessage());
    }

    // ------------------------------------------------------------------
    // ExplainFieldTool
    // ------------------------------------------------------------------

    public function test_explain_field_happy_path_trims_and_folds_optional_params(): void
    {
        $captured = null;
        $ai = $this->aiReturning('  This is the SSN field.  ', function (AIRequest $r) use (&$captured) {
            $captured = $r->prompt;
        });

        $tool = new ExplainFieldTool($ai);
        $result = $tool->execute([
            'field_name' => 'ssn',
            'field_description' => 'Social security number',
            'validation_rules' => 'required|digits:9',
        ], $this->context());

        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('This is the SSN field.', $result->message);
        $this->assertSame('ssn', $result->data['field']);
        $this->assertSame('This is the SSN field.', $result->data['explanation']);

        $this->assertStringContainsString('Social security number', $captured);
        $this->assertStringContainsString('required|digits:9', $captured);
    }

    public function test_explain_field_ai_exception_becomes_failure(): void
    {
        $tool = new ExplainFieldTool($this->aiThrowing());
        $result = $tool->execute(['field_name' => 'ssn'], $this->context());

        $this->assertFalse($result->success);
        $this->assertSame("Could not generate explanation for field 'ssn'", $result->error);
        $this->assertSame('ssn', $result->data['field']);
    }

    // ------------------------------------------------------------------
    // SuggestValueTool
    // ------------------------------------------------------------------

    public function test_suggest_value_folds_scalar_runtime_state_and_succeeds(): void
    {
        $captured = null;
        $ai = $this->aiReturning('  42  ', function (AIRequest $r) use (&$captured) {
            $captured = $r->prompt;
        });

        $tool = new SuggestValueTool($ai);
        $context = $this->context(['customer_name' => 'Ahmed', 'arr' => [1, 2]]);
        $result = $tool->execute(['field_name' => 'discount', 'field_type' => 'number'], $context);

        $this->assertStringContainsString('customer_name: Ahmed', $captured);
        $this->assertStringNotContainsString('- arr:', $captured);

        $this->assertTrue($result->success);
        $this->assertSame("Suggested value for 'discount': 42", $result->message);
        $this->assertSame(['field' => 'discount', 'suggestion' => '42', 'type' => 'number'], $result->data);
    }

    public function test_suggest_value_ai_exception_becomes_failure(): void
    {
        $tool = new SuggestValueTool($this->aiThrowing());
        $result = $tool->execute(['field_name' => 'discount'], $this->context());

        $this->assertFalse($result->success);
        $this->assertSame("Could not generate suggestion for field 'discount'", $result->error);
    }

    // ------------------------------------------------------------------
    // SearchOptionsTool (AI fallback branch only; model branch needs a host
    // schema() helper not defined in this package).
    // ------------------------------------------------------------------

    public function test_search_options_ai_fallback_extracts_json_array(): void
    {
        $ai = $this->aiReturning('Here: ["red","green"] done');

        $tool = new SearchOptionsTool($ai);
        $result = $tool->execute(['field_name' => 'tags', 'query' => 'colors'], $this->context());

        $this->assertTrue($result->success);
        $this->assertSame(['red', 'green'], $result->data['options']);
        $this->assertSame(2, $result->data['count']);
    }

    public function test_search_options_ai_no_json_array_is_failure(): void
    {
        $ai = $this->aiReturning('No options here, sorry.');

        $tool = new SearchOptionsTool($ai);
        $result = $tool->execute(['field_name' => 'tags', 'query' => 'colors'], $this->context());

        $this->assertFalse($result->success);
        $this->assertSame("No options found for field 'tags'", $result->error);
    }

    // ------------------------------------------------------------------
    // AgentToolServiceRegistrar config gating of the singleton ToolRegistry
    // ------------------------------------------------------------------

    private function freshSingletonRegistry(): ToolRegistry
    {
        app()->forgetInstance(ToolRegistry::class);

        return app(ToolRegistry::class);
    }

    public function test_registrar_gate_sub_agent_tool_off(): void
    {
        config()->set('ai-agent.goal_agent.register_sub_agent_tool', false);
        $this->assertFalse($this->freshSingletonRegistry()->has('run_sub_agent'));
    }

    public function test_registrar_gate_data_query_off(): void
    {
        config()->set('ai-engine.data_query.enabled', false);
        $this->assertFalse($this->freshSingletonRegistry()->has('data_query'));
    }

    public function test_registrar_gate_knowledge_tool_off(): void
    {
        config()->set('ai-agent.ai_native.knowledge_tool_enabled', false);
        $this->assertFalse($this->freshSingletonRegistry()->has('search_knowledge'));
    }

    public function test_registrar_gate_learn_source_default_off_and_on(): void
    {
        config()->set('ai-engine.learning.tools.agent_ingest_enabled', false);
        $this->assertFalse($this->freshSingletonRegistry()->has('learn_source'));

        config()->set('ai-engine.learning.tools.agent_ingest_enabled', true);
        $this->assertTrue($this->freshSingletonRegistry()->has('learn_source'));
    }

    public function test_registrar_does_not_overwrite_config_supplied_tool(): void
    {
        // Pre-seed config so discoverFromConfig already registers 'run_sub_agent'
        // under a different class; the !has() guard must keep it.
        config()->set('ai-agent.tools', ['run_sub_agent' => ToolsPolicyNamedTool::class]);
        config()->set('ai-agent.goal_agent.register_sub_agent_tool', true);

        $registry = $this->freshSingletonRegistry();

        $this->assertInstanceOf(ToolsPolicyNamedTool::class, $registry->get('run_sub_agent'));
    }
}

/**
 * A minimal concrete AgentTool whose name is injected. Used for registry
 * primitive tests and as a manifest/config-supplied override class.
 */
class ToolsPolicyNamedTool extends AgentTool
{
    public function __construct(private string $toolName = 'named')
    {
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return 'Named tool.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}

/** Two required params (a, b) and one optional (c) to drive validate() edge cases. */
class ToolsPolicyValidatingTool extends AgentTool
{
    public function getName(): string
    {
        return 'validating';
    }

    public function getDescription(): string
    {
        return 'Validating tool.';
    }

    public function getParameters(): array
    {
        return [
            'a' => ['type' => 'string', 'required' => true],
            'b' => ['type' => 'string', 'required' => true],
            'c' => ['type' => 'string', 'required' => false],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}

/** Overrides every optional hook to assert the full toArray() shape. */
class ToolsPolicyRichTool extends AgentTool
{
    public function getName(): string
    {
        return 'rich';
    }

    public function getDescription(): string
    {
        return 'Rich tool.';
    }

    public function getParameters(): array
    {
        return ['p' => ['type' => 'string', 'required' => false]];
    }

    public function getResultSchema(): array
    {
        return ['field' => 'string'];
    }

    public function getCapabilities(): array
    {
        return ['cap'];
    }

    public function getToolKind(): ?string
    {
        return 'reader';
    }

    public function getEntityType(): ?string
    {
        return 'Invoice';
    }

    public function getRelations(): array
    {
        return [['name' => 'customer']];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}

/**
 * Constructor demands an unresolvable type-hint so app($class) throws during
 * discovery, exercising the construction-failure Log::warning + skip path.
 */
class ToolsPolicyUnconstructableTool extends AgentTool
{
    public function __construct(ToolsPolicyMissingDependency $dependency)
    {
    }

    public function getName(): string
    {
        return 'broken';
    }

    public function getDescription(): string
    {
        return 'Broken tool.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}

/** An interface the container cannot instantiate, forcing a construction failure above. */
interface ToolsPolicyMissingDependency
{
}
