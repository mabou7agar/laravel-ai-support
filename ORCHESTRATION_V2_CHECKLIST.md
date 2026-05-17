# Orchestration V2 Refactor Checklist

Goal: replace the current over-coupled agent orchestration flow with a simpler, explicit, traceable runtime. This is allowed to be breaking because the package is not widely adopted yet.

## Principles

- [x] Reuse existing package systems before creating new classes, tables, config, or commands.
- [x] Prefer one clear decision pipeline over nested conditional orchestration.
- [x] Keep execution separate from routing decisions.
- [x] Make every route decision observable and testable.
- [x] Keep RAG under RAG-only services; do not mix RAG with agent orchestration.
- [x] Keep Laravel as the default runtime.
- [x] Support LangGraph as a first-class optional runtime for complex durable workflows.
- [x] Do not require LangGraph for normal package installation or simple Laravel app workflows.
- [x] Remove compatibility wrappers when they preserve bad behavior.

## Target Architecture

```text
AgentRuntime
  -> AgentRunStore
  -> RoutingPipeline
  -> ExecutionDispatcher
  -> Step/audit/artifact/approval services
  -> ResponseFinalizer
```

```text
AgentRuntimeContract
  -> LaravelAgentRuntime
  -> LangGraphAgentRuntime
```

## Phase 1: Runtime Boundary

- [x] Declare this work as a breaking `v2.0` orchestration refactor.
- [x] Create a removed/renamed classes list before deleting old code.
- [x] Create `AgentRuntimeContract`.
- [x] Create `LaravelAgentRuntime`.
- [x] Move public orchestration entrypoint from `AgentOrchestrator::process()` into the runtime.
- [x] Keep `AgentOrchestrator` only temporarily as a thin wrapper or delete it if call sites are updated in the same pass.
- [x] Add runtime config:
  - [x] `ai-agent.runtime.default = laravel`
  - [x] `ai-agent.runtime.langgraph.enabled = false`
  - [x] `ai-agent.runtime.langgraph.base_url = null`
  - [x] `ai-agent.runtime.langgraph.timeout = 120`
  - [x] `ai-agent.runtime.langgraph.fallback_to_laravel = true`
- [x] Add feature flag for v2 runtime rollout.
- [x] Add feature flag for v2 RAG pipeline rollout.
- [x] Add feature flag for LangGraph runtime rollout.
- [x] Update service registration to resolve the configured runtime.
- [x] Add tests proving the runtime can process conversational, RAG, tool, and sub-agent requests.
- [x] Add tests proving runtime selection can choose Laravel or LangGraph by config.
- [x] Add runtime capability discovery service.
- [x] Expose runtime capabilities for:
  - [x] streaming
  - [x] interrupts
  - [x] tools
  - [x] artifacts
  - [x] human approvals
  - [x] sub-agents
  - [x] remote callbacks

Required contracts:

- [x] `AgentRuntimeContract`
- [x] `RoutingStageContract`
- [x] `ExecutionHandlerContract`
- [x] `RAGPipelineContract`
- [x] `AgentRunRepositoryContract` only if the existing repository pattern cannot cover agent runs cleanly.
- [x] `AgentRunStepRepositoryContract` only if the existing repository pattern cannot cover run steps cleanly.

## Phase 2: Routing Decision Model

- [x] Create `RoutingDecision` DTO.
- [x] Create `RoutingDecisionAction` enum or constants.
- [x] Create `RoutingDecisionSource` enum or constants.
- [x] Require every decision to include:
  - [x] action
  - [x] source
  - [x] confidence
  - [x] reason
  - [x] payload
  - [x] metadata
- [x] Add `RoutingTrace` DTO for full decision history.
- [x] Persist trace into response metadata and agent run steps.

Allowed actions:

- [x] `continue_run`
- [x] `continue_collector`
- [x] `continue_node`
- [x] `search_rag`
- [x] `use_tool`
- [x] `run_sub_agent`
- [x] `start_collector`
- [x] `route_to_node`
- [x] `conversational`
- [x] `need_user_input`
- [x] `fail`

## Phase 3: Routing Pipeline

- [x] Create `RoutingStageContract`.
- [x] Create `RoutingPipeline`.
- [x] Stages must only return a decision or abstain.
- [x] Stages must not execute tools, RAG, collectors, or node routing.
- [x] Add stage priority/order config.

Initial stages:

- [x] `ActiveRunContinuationStage`
- [x] `ExplicitModeStage`
- [x] `SelectionReferenceStage`
- [x] `DeterministicCommandStage`
- [x] `MessageClassificationStage`
- [x] `AIRouterStage`
- [x] `FallbackConversationalStage`

Conflict handling:

- [x] Stop on first high-confidence decision.
- [x] Record lower-confidence matches in trace.
- [x] Add debug metadata showing why skipped stages did not win.
- [x] Add tests for conflicting messages such as option selection plus structured query.

## Phase 4: Execution Dispatcher

- [x] Create `AgentExecutionDispatcher`.
- [x] Dispatcher is the only class allowed to execute decisions.
- [x] Move execution branches out of `AgentOrchestrator`.
- [x] Add handlers for:
  - [x] conversational response
  - [x] RAG search
  - [x] tool execution
  - [x] sub-agent execution
  - [x] collector start/continue
  - [x] node route/continue
  - [x] approval-required pause
  - [x] failure response
- [x] Add one test per handler.
- [x] Add one integration test proving a pipeline decision maps to the expected handler.

## Phase 5: Agent Run Persistence

- [x] Create `ai_agent_runs` migration.
- [x] Create `ai_agent_run_steps` migration.
- [x] Create `AgentRun` model.
- [x] Create `AgentRunStep` model.
- [x] Create `AgentRunRepository`.
- [x] Create `AgentRunStepRepository`.
- [x] Store:
  - [x] session id
  - [x] user id
  - [x] tenant id / workspace id when available
  - [x] runtime
  - [x] status
  - [x] schema version
  - [x] input
  - [x] final response
  - [x] current step
  - [x] routing trace
  - [x] failure reason
- [x] Link provider tool runs, approvals, and artifacts to agent run steps where possible.

Run statuses:

- [x] `pending`
- [x] `running`
- [x] `waiting_approval`
- [x] `waiting_input`
- [x] `completed`
- [x] `failed`
- [x] `cancelled`
- [x] `expired`

Run safety:

- [x] Add session/run lock before mutating active runs using Laravel cache locks.
- [x] Add tenant-aware run locks by reusing existing tenant resolver/scope metadata.
- [x] Reuse existing action idempotency behavior where decisions execute `ActionOrchestrator`.
- [x] Add idempotency keys only for new agent/tool/provider continuations not already covered by action memory.
- [x] Add duplicate-message protection for the same session.
- [x] Add stuck-run recovery command.
- [x] Add expired-run cleanup command.

Async execution:

- [x] Add queued agent run job.
- [x] Add queued run continuation job.
- [x] Add retry policy.
- [x] Add timeout policy.
- [x] Add max-step limit per run.
- [x] Add max-cost/token guard per run.
- [x] Add chaos tests for interrupted queue workers.
- [x] Add chaos tests for duplicated continuation jobs.
- [x] Add chaos tests for duplicated provider webhooks.
- [x] Add chaos tests for LangGraph downtime.

Retention and privacy:

- [x] Add run retention config.
- [x] Add step retention config.
- [x] Add trace retention config.
- [x] Add artifact retention config.
- [x] Add config to redact prompts before storage.
- [x] Add config to redact responses before storage.
- [x] Add config to disable storing raw model/provider payloads.
- [x] Add retention cleanup command.

Recovery:

- [x] Add failed-step replay support.
- [x] Add resume-from-step support where safe.
- [x] Add mark-run-manually-resolved support.
- [x] Add recovery audit events.

Budgets:

- [x] Reuse `CreditManager` for credit checks, deduction, entity credit ledgers, custom owner resolvers, and lifecycle handlers.
- [x] Reuse existing credit accumulation for per-run usage totals.
- [x] Extend `CreditManager` or add a thin adapter only if run-level budget checks cannot be expressed through existing credits.
- [x] Reuse `RateLimitManager` for per-engine/user request limits.
- [x] Add per-run token/cost ceiling as runtime policy metadata, backed by existing credit/cost accounting.
- [x] Add per-user budget behavior through existing credit owner resolver where possible.
- [x] Add per-tenant budget behavior through existing credit owner resolver/lifecycle handler where possible.
- [x] Stop or pause run when existing credit/rate/budget policy is exceeded.
- [x] Add budget-exceeded response behavior.

## Phase 6: RAG Cleanup

- [x] Delete `RAGService` as the public brain or reduce it to a temporary facade.
- [x] Create `RAGPipeline`.
- [x] Create `RAGQueryAnalyzer`.
- [x] Create `RAGCollectionResolver`.
- [x] Create `RAGRetriever`.
- [x] Create `RAGContextBuilder`.
- [x] Create `RAGPromptBuilder`.
- [x] Create `RAGResponseGenerator`.
- [x] Move vector retrieval into `Retrievers/VectorRAGRetriever`.
- [x] Move graph retrieval into `Retrievers/GraphRAGRetriever`.
- [x] Move Neo4j + Qdrant merge logic into `Retrievers/HybridRAGRetriever`.
- [x] Keep `RAG` prefix only for retrieval augmented generation.
- [x] Remove `AutonomousRAGAgent` naming if it is actually agent orchestration.

RAG source and citation model:

- [x] Create `RAGSource` DTO.
- [x] Create `RAGCitation` DTO.
- [x] Normalize vector citations.
- [x] Normalize graph citations.
- [x] Normalize hybrid citations.
- [x] Normalize SQL aggregate sources.
- [x] Normalize provider file-search citations.
- [x] Ensure citations can be rendered in API responses and admin run details.

## Phase 7: Tool, Sub-Agent, And Skill Links

- [x] Keep `ToolRegistry` as the source of available tools.
- [x] Keep `SubAgentRegistry` as the source of available sub-agents.
- [x] Move `run_sub_agent` into dispatcher handling when possible.
- [x] Ensure tool-backed sub-agents are still supported.
- [x] Ensure skills can link to tools and sub-agents through explicit metadata.
- [x] Keep `ai-engine:test-orchestration`, but update it for the new runtime graph.
- [x] Add graph complexity checks against runtime stages, tools, skills, and sub-agents.

## Phase 8: Approval, Audit, And Artifacts

- [x] Reuse provider tool approval services for risky agent actions.
- [x] Add agent-level approval decisions when a full step needs user confirmation.
- [x] Reuse hosted artifact lifecycle for generated files.
- [x] Ensure every tool/sub-agent/provider call writes an audit event.
- [x] Add policy hooks before execution, not during routing.
- [x] Ensure provider tool continuation resumes the same agent run step.
- [x] Ensure code interpreter, MCP, computer-use, hosted tool, and generated-file continuations link to the same run.
- [x] Add approval expiry behavior.
- [x] Add rejected-approval behavior.
- [x] Add approval edit/resume payload behavior.

Security policy:

- [x] Add runtime-level authorization before execution.
- [x] Reuse `vector-access-control.php`, `TenantResolverInterface`, and `MultiTenantVectorService` for tenant/workspace scope where applicable.
- [x] Add tenant/workspace scope checks before execution only where current vector/action/provider policies do not already enforce scope.
- [x] Add tool allow/deny policy.
- [x] Add sub-agent allow/deny policy.
- [x] Add RAG collection allow/deny policy.
- [x] Add node-routing allow/deny policy.
- [x] Add payload sanitization before sending data to LangGraph.
- [x] Add sensitive field redaction in traces and audit events.
- [x] Add LangGraph callback signature/token authentication.
- [x] Reuse `ProviderToolPolicyService` for hosted/provider tool approval and risk policy.
- [x] Reuse existing prompt policy services where routing/decision prompts need policy checks.
- [x] Add tests for blocked tools, blocked sub-agents, blocked collections, and blocked remote runtime calls.
- [x] Add tests for tenant-isolated runs, tools, RAG collections, and LangGraph calls.

Observability:

- [x] Add trace id to every agent response.
- [x] Add run id to every agent response.
- [x] Add step id to tool/provider/sub-agent metadata.
- [x] Add OpenTelemetry-compatible span names and attributes.
- [x] Capture per-step duration.
- [x] Capture provider model, tokens, and cost by reusing existing response usage and credit accounting fields.
- [x] Capture RAG result counts and source types.
- [x] Add debug route explanation data to response metadata.
- [x] Add admin/API endpoint for run trace details.
- [x] Reuse `ProviderToolAuditService` and provider tool audit events for hosted/provider tool steps.
- [x] Reuse existing action audit logger contract for app action steps where possible.

Streaming:

- [x] Add native Laravel runtime event stream.
- [x] Stream routing decisions.
- [x] Stream RAG retrieval events.
- [x] Stream tool start/progress/finish events.
- [x] Stream provider tool approval requests.
- [x] Stream sub-agent start/progress/finish events.
- [x] Stream final response tokens when provider supports streaming.
- [x] Add non-streaming fallback behavior.

Streaming event contract:

- [x] `run.started`
- [x] `routing.stage_started`
- [x] `routing.stage_abstained`
- [x] `routing.decided`
- [x] `rag.started`
- [x] `rag.sources_found`
- [x] `rag.completed`
- [x] `tool.started`
- [x] `tool.progress`
- [x] `tool.completed`
- [x] `tool.failed`
- [x] `sub_agent.started`
- [x] `sub_agent.completed`
- [x] `approval.required`
- [x] `approval.resolved`
- [x] `artifact.created`
- [x] `run.completed`
- [x] `run.failed`
- [x] `run.cancelled`
- [x] `run.expired`

## Phase 9: LangGraph Optional Runtime

- [x] Create `LangGraphAgentRuntime`.
- [x] Add HTTP client for a LangGraph service.
- [x] Create `LangGraphRuntimeClient`.
- [x] Create `LangGraphRunMapper`.
- [x] Create `LangGraphInterruptMapper`.
- [x] Create `LangGraphEventMapper`.
- [x] Map Laravel agent run id to LangGraph thread id.
- [x] Map Laravel user/session context into LangGraph input state.
- [x] Map Laravel tools into LangGraph tool descriptors or remote callbacks.
- [x] Map Laravel RAG pipeline as a callable LangGraph tool.
- [x] Map Laravel sub-agents into LangGraph nodes where possible.
- [x] Map LangGraph interrupts to Laravel approvals.
- [x] Map LangGraph events to `ai_agent_run_steps`.
- [x] Map LangGraph generated files to hosted artifacts.
- [x] Add timeout, retry, and fallback-to-Laravel behavior.
- [x] Add queue-safe continuation for LangGraph runs.
- [x] Add cancellation support.
- [x] Add health check for LangGraph service availability.
- [x] Add signature/token authentication between Laravel and LangGraph service.
- [x] Add tenant/workspace scope propagation to LangGraph.
- [x] Add LangGraph capability check before dispatching a run.
- [x] Keep LangGraph disabled by default.
- [x] Document required deployment shape for LangGraph service.

LangGraph should be used for:

- [x] Long-running workflows that need checkpoint/resume.
- [x] Multi-agent graphs with branching.
- [x] Human-in-the-loop interrupt and resume.
- [x] Complex research/build/review loops.
- [x] Workflows that benefit from graph visualization/debugging.

LangGraph should not be used for:

- [x] Simple chat.
- [x] Simple RAG.
- [x] Single tool calls.
- [x] Basic collector flows.
- [x] Apps that cannot run a sidecar service.

LangGraph service contract:

- [x] `POST /runs` starts a graph run.
- [x] `GET /runs/{id}` returns run status.
- [x] `POST /runs/{id}/resume` resumes after approval/input.
- [x] `POST /runs/{id}/cancel` cancels a run.
- [x] `GET /runs/{id}/events` streams or lists events.
- [x] `GET /health` reports runtime health.

LangGraph response mapping:

- [x] `completed` -> Laravel `AgentResponse::success`.
- [x] `interrupted` -> Laravel approval or needs-user-input response.
- [x] `failed` -> Laravel failure response with run trace.
- [x] `cancelled` -> Laravel failure/cancelled response.
- [x] `running` -> queued or pending response.

LangGraph tests:

- [x] Add mock LangGraph HTTP server tests.
- [x] Test start run.
- [x] Test resume interrupted run.
- [x] Test cancelled run.
- [x] Test failed run.
- [x] Test event mapping.
- [x] Test fallback-to-Laravel when LangGraph is unavailable.

## Phase 10: Admin UI And API

- [x] Add agent runs list screen.
- [x] Add agent run detail screen.
- [x] Add step timeline.
- [x] Add routing trace panel.
- [x] Add RAG sources/citations panel.
- [x] Add provider tool approvals panel.
- [x] Add hosted artifacts panel.
- [x] Add retry action.
- [x] Add cancel action.
- [x] Add resume action.
- [x] Add API endpoints for runs, steps, approvals, artifacts, and trace.
- [x] Add Bruno requests for new API endpoints.
- [x] Run curl checks for new API endpoints in a Laravel app.
- [x] Add runtime capability inspection endpoint.
- [x] Add budget/credit usage display using existing `CreditManager` and usage data.
- [x] Add retention/redaction settings docs or admin hints.

## Phase 11: Developer Experience

- [x] Add artisan command to inspect runtime config.
- [x] Add artisan command to validate runtime/tool config.
- [x] Extend config validator to validate routing stage config after stages exist.
- [x] Add artisan command to inspect runtime capabilities.
- [x] Extend existing `ScaffoldAgentArtifactCommand` instead of creating unrelated scaffolding commands where possible.
- [x] Add scaffold type for routing stages.
- [x] Add scaffold type for execution handlers.
- [x] Add scaffold type for custom runtimes.
- [x] Keep existing `ai-engine:make-tool` behavior and extend templates only if needed.
- [x] Add artisan command to replay failed run steps.
- [x] Add docs example for creating a custom routing stage.
- [x] Add docs example for creating a custom execution handler.
- [x] Add docs example for creating a custom runtime.
- [x] Add docs example for creating a custom tool with approval policy.

## Phase 12: Existing Service Enhancements

Enhance existing services first. Add new services only when these cannot reasonably own the behavior.

`CreditManager`:

- [x] Add run-level budget helpers.
- [x] Add `startRunBudget($runId, $ownerId, array $limits)`.
- [x] Add `recordRunUsage($runId, AIResponse|array $usage)`.
- [x] Add `remainingRunBudget($runId)`.
- [x] Add `assertRunBudgetAvailable($runId)`.
- [x] Reuse entity credit ledger support.
- [x] Reuse custom owner/user resolver support.
- [x] Reuse lifecycle handler support.
- [x] Add tests for run budget with scalar credits.
- [x] Add tests for run budget with entity credits.
- [x] Add tests for run budget with custom lifecycle handler.

`RateLimitManager`:

- [x] Add scoped rate limit keys.
- [x] Add runtime scope.
- [x] Add run scope.
- [x] Add tenant/workspace scope.
- [x] Add tool/provider scope.
- [x] Keep existing engine/user behavior intact.
- [x] Add tests for mixed engine/user/run/tenant keys.

`ProviderToolPolicyService`:

- [x] Expand policy checks for reusable execution risk.
- [x] Add tool allow/deny checks.
- [x] Add sub-agent allow/deny checks.
- [x] Add runtime allow/deny checks.
- [x] Add approval requirement by risk level.
- [x] Add payload sensitivity detection.
- [x] Keep provider-tool approval behavior intact.
- [x] Add wrapper service only if broader execution policy would make this class unclear.

`ProviderToolAuditService`:

- [x] Allow audit events to include `agent_run_id`.
- [x] Allow audit events to include `agent_step_id`.
- [x] Allow audit events to include `runtime`.
- [x] Allow audit events to include `decision_source`.
- [x] Allow audit events to include `trace_id`.
- [x] Reuse existing provider tool audit table where possible.
- [x] Add generic agent audit table only if provider table shape blocks clean agent auditing.

`HostedArtifactService`:

- [x] Add generic artifact owner support.
- [x] Support owner type `provider_tool_run`.
- [x] Support owner type `agent_run`.
- [x] Support owner type `agent_step`.
- [x] Add artifact source metadata.
- [x] Support source `code_interpreter`.
- [x] Support source `fal`.
- [x] Support source `image_generation`.
- [x] Support source `video_generation`.
- [x] Support source `langgraph`.
- [x] Support source `manual_upload`.
- [x] Add retention/expiry handling.

`MultiTenantVectorService`:

- [x] Add `currentScope(): array`.
- [x] Add `scopeKey(): string`.
- [x] Add `applyScopeToMetadata(array $metadata): array`.
- [x] Reuse same scope shape for vector indexing.
- [x] Reuse same scope shape for RAG retrieval.
- [x] Reuse same scope shape for agent runs.
- [x] Reuse same scope shape for LangGraph payloads.

`ActionOrchestrator`:

- [x] Add `canExecute($actionId, ?UnifiedActionContext $context = null)`.
- [x] Add `requiresConfirmation($actionId, array $payload, ?UnifiedActionContext $context = null)`.
- [x] Expose idempotency behavior through a public method or small collaborator.
- [x] Return structured execution metadata for agent run steps.
- [x] Keep existing action prepare/execute API intact unless v2 removes it deliberately.

`ScaffoldAgentArtifactCommand`:

- [x] Add scaffold type `routing-stage`.
- [x] Add scaffold type `execution-handler`.
- [x] Add scaffold type `runtime`.
- [x] Add scaffold type `rag-retriever`.
- [x] Add scaffold type `policy`.
- [x] Reuse existing manifest registration behavior where applicable.
- [x] Keep `ai-engine:make-tool` behavior intact.

RAG services:

- [x] Avoid adding more behavior to broad RAG chat services.
- [x] Move behavior into the new RAG pipeline services.
- [x] Delete the old RAG facade once call sites are migrated.

`AgentOrchestrationInspector`:

- [x] Validate runtime config.
- [x] Validate routing stages.
- [x] Validate dispatcher handlers.
- [x] Validate tool/sub-agent/skill graph.
- [x] Validate LangGraph config when enabled.
- [x] Validate runtime capability compatibility.
- [x] Report complexity for runtime stages, tools, skills, sub-agents, and runtimes.
- [x] Report missing links with actionable subjects.

## Phase 13: Demo App Fixtures And Live Validation

- [x] Add small demo app tables for orchestration tests if they do not already exist.
- [x] Add demo `projects` table.
- [x] Add demo `tasks` table.
- [x] Add demo `customers` table.
- [x] Add demo `invoices` table.
- [x] Add demo `documents` table for RAG/file-search scenarios.
- [x] Add demo pivot/relationship table for graph traversal scenarios.
- [x] Add demo seeders with realistic records.
- [x] Add demo seeders for vectorizable records.
- [x] Add demo seeders for graph-linked records.
- [x] Add demo seeders for action/tool execution records.
- [x] Add demo seeders for tenant/workspace scoped records.
- [x] Add demo seeders for credit-limited users.
- [x] Add demo seeders for approval-required tool runs.
- [x] Add demo app factories where package tests need generated data.
- [x] Add demo app route/API smoke tests for runtime endpoints.
- [x] Add demo app command to run all orchestration v2 live checks.
- [x] Ensure demo fixtures stay generic and do not leak project-specific assumptions into package core.
- [x] Use demo app fixtures to test every public runtime path end to end.
- [x] Use demo app fixtures to test every routing stage with real models.
- [x] Use demo app fixtures to test every execution handler with real records.
- [x] Use demo app fixtures to test RAG/vector/graph/hybrid retrieval with real records.
- [x] Use demo app fixtures to test credits, rate limits, tenant scope, approvals, audit, and artifacts together.

## Phase 14: Evaluation And Regression Tests

- [x] Add focused unit tests for every routing stage.
- [x] Add dispatcher tests for every action.
- [x] Add integration tests for full flows:
  - [x] conversational
  - [x] vector RAG
  - [x] graph RAG
  - [x] hybrid Neo4j + Qdrant RAG
  - [x] tool execution
  - [x] sub-agent execution
  - [x] approval pause and resume
  - [x] node routing fallback
- [x] Add `ai-engine:test-orchestration-v2` or update the existing command.
- [x] Add golden routing fixtures.
- [x] Add synthetic conversation fixtures for conflict cases.
- [x] Add RAG quality fixtures.
- [x] Add tool safety fixtures.
- [x] Add approval/resume fixtures.
- [x] Add LangGraph mock-runtime fixtures.
- [x] Add test command for evaluating routing fixtures.
- [x] Add test command for evaluating RAG fixtures.
- [x] Add compatibility smoke tests against the real Laravel demo app.
- [x] Add performance benchmark for the classifier baseline before deleting old runtime paths.
- [x] Add performance benchmark for v2 runtime routing.
- [x] Add performance benchmark for v2 RAG pipeline.
- [x] Add benchmark threshold report, not hard failures by default.
- [x] Run focused test groups.
- [x] Run full PHPUnit suite.

## Phase 15: Migration And Docs

- [x] Add v2 upgrade guide.
- [x] Add migration notes for removed classes.
- [x] Add migration notes for removed config keys.
- [x] Add migration notes for renamed commands.
- [x] Add old-to-new orchestration flow comparison.
- [x] Add docs for `AgentRuntimeContract`.
- [x] Add docs for routing stages.
- [x] Add docs for execution handlers.
- [x] Add docs for RAG pipeline.
- [x] Add docs for LangGraph runtime setup.
- [x] Add docs for streaming events.
- [x] Add docs for agent run statuses.
- [x] Add docs for approval/resume flows.
- [x] Add docs for tenancy/scope.
- [x] Add docs for retention/redaction.
- [x] Add docs for budgets.
- [x] Add docs for runtime capability discovery.
- [x] Add docs for recovery and replay.
- [x] Add docs for feature flags and rollout strategy.
- [x] Add docs for demo app fixtures and live validation commands.
- [x] Update docs for the breaking v2 flow.
- [x] Remove stale docs that describe the old orchestrator behavior.

## Removal Candidates

- [x] Remove nested routing logic from `AgentOrchestrator`.
- [x] Remove magic reroute flags such as `skip_ai_decision` where possible.
- [x] Remove duplicated RAG decision logic from chat/orchestration layers.
- [x] Remove broad "intelligent" service names that hide responsibilities.
- [x] Remove compatibility aliases for deleted commands/classes if not needed.

## Done Criteria

- [x] One message produces one final routing decision.
- [x] All candidate decisions are visible in trace metadata.
- [x] Routing stages do not execute side effects.
- [x] Dispatcher owns all execution.
- [x] RAG is a separate pipeline behind a `search_rag` decision.
- [x] Agent runs are persisted and resumable.
- [x] Agent runs are tenant/workspace scoped when the app provides scope data.
- [x] Session/run locking prevents concurrent mutation conflicts.
- [x] Queued long-running runs can retry, timeout, expire, and recover.
- [x] Retention and redaction settings control stored run data.
- [x] Schema versioning protects stored payload upgrades.
- [x] Runtime capability discovery prevents unsupported dispatch.
- [x] Cost/token budgets can stop or pause runs.
- [x] Failed steps can be replayed or manually resolved.
- [x] Feature flags can enable/disable v2 runtime, v2 RAG, and LangGraph independently.
- [x] Config validator catches invalid runtime/tool setup.
- [x] Config validator catches invalid stage setup after stages exist.
- [x] Demo app fixtures validate every public runtime path.
- [x] Compatibility smoke tests pass against the demo Laravel app.
- [x] Performance benchmark reports before/after routing and RAG results.
- [x] Chaos tests cover queue interruption, duplicate continuation, duplicate webhook, and LangGraph downtime.
- [x] Streaming and non-streaming paths both work.
- [x] Streaming events follow a stable event-name contract.
- [x] Security policies run before every execution.
- [x] OpenTelemetry-compatible trace data is emitted or stored.
- [x] RAG citations use one normalized source model.
- [x] Provider tool continuations resume the same agent run step.
- [x] Tool approvals and artifacts are linked to run steps.
- [x] Admin UI can inspect, retry, cancel, and resume runs.
- [x] LangGraph can be enabled without changing Laravel runtime code.
- [x] LangGraph failures can fall back to Laravel when configured.
- [x] Existing credit, rate limit, tenant, policy, audit, artifact, action, and scaffold services are reused or explicitly enhanced.
- [x] v2 upgrade docs cover removed classes, commands, and config keys.
- [x] Full test suite passes.
- [x] Docs explain the new v2 runtime clearly.

## Phase 16: Package Hardening And Simplification

These points address the remaining weak spots after the v2 runtime and provider expansion work.

- [x] Split generate API validation into dedicated Form Request classes.
- [x] Move generate API request-to-DTO mapping and provider defaults into focused services where practical.
- [x] Split broad RAG chat responsibilities into focused retrieval, prompt, answer, and citation collaborators.
- [x] Keep Skills + Tools + Actions as the primary public workflow model; keep collectors as internal/legacy helpers unless explicitly enabled.
- [x] Add stricter skill planner JSON schema validation before executing planner decisions.
- [x] Add skill planner decision trace metadata to all `run_skill` results.
- [x] Enforce execution policy in `RunSkillTool` before executing declared tools.
- [x] Add media provider routing guards for missing credentials, disabled providers, local-only constraints, and recent provider failures.
- [x] Keep enums for stable aliases only and document dynamic `ai_models` catalog as the preferred model expansion path.
- [x] Add tests for the hardening behavior before checking each point.

## Phase 17: Tool Developer Experience

These points make tool creation easier without removing the existing low-level `AgentTool` contract.

- [x] Add `SimpleAgentTool` for metadata-property tools that only implement `handle()`.
- [x] Add `ActionBackedTool` for thin wrappers around registered action flows.
- [x] Enhance model-backed lookup/upsert tools so host apps can define them with properties instead of boilerplate methods.
- [x] Generate simple, model-backed, and action-backed tool templates from `ai-engine:make-tool`.
- [x] Add `ai-engine:tools:test` to execute a registered tool with a JSON payload.
- [x] Document when to use simple tools, model-backed tools, action-backed tools, and skill orchestration.
- [x] Add focused tests for the easier tool API, scaffolds, and command behavior.

## Phase 18: Provider Parity And Release Guardrails

These points cover the remaining post-release audit gaps that make the package safer in real applications.

- [x] Keep native enum cases while preserving stable string aliases used by existing package internals and tests.
- [x] Add OpenAI hosted shell, apply-patch, and provider skill tool descriptors to the provider tool mapper.
- [x] Add Gemini code execution tool mapping through the existing `CodeInterpreter` provider tool.
- [x] Add provider tool classes for hosted shell, apply patch, and provider skills.
- [x] Add Gemini native TTS models and route Gemini TTS through `generateContent` audio responses instead of legacy predict payloads.
- [x] Store Gemini inline PCM audio as downloadable WAV media with voice/audio metadata.
- [x] Extend realtime session descriptors with audio formats, turn detection, temperature, token caps, and provider-specific config casing.
- [x] Harden hosted artifact persistence with URL scheme checks, private-host blocking, extension allow-lists, and MIME allow-lists.
- [x] Add config defaults for hosted artifact max bytes, allowed MIME types, allowed extensions, and private URL blocking.
- [x] Verify focused provider-tool, realtime, Gemini media, hosted-artifact, and enum tests before checking these items.
