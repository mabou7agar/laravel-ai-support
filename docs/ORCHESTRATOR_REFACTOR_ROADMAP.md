# Orchestrator Refactor Roadmap

## Goal
Reduce behavior bugs and overengineering without rewriting stable provider/driver infrastructure.

## Current Status
- Phase 1 behavior guardrails implemented.
- Phase 2 started with service extraction in active orchestration path:
  - `IntentClassifierService`
  - `DecisionPolicyService`
  - `FollowUpStateService`
- Additional Phase 2 extraction completed:
  - `OrchestratorPromptBuilder`
  - `OrchestratorDecisionParser`
  - `UserProfileResolver`
  - `ToolExecutionCoordinator`
  - `CollectorExecutionCoordinator`
  - `NodeRoutingCoordinator`
  - `RoutedSessionPolicyService`
  - `FollowUpDecisionAIService`
  - `PositionalReferenceAIService`
  - `OptionSelectionCoordinator`
  - `PositionalReferenceCoordinator`
  - `AgentPolicyService` (config-driven messages/policy)
- Config simplification pass:
  - Removed redundant per-feature model/engine keys from defaults
  - Added `workflow_directories` key (used by WorkflowDiscoveryService)
  - Added minimal config guide (`docs/AGENT_CONFIG_MINIMAL.md`)
- Hardcoded `\App\Models\User` and entity model assumptions removed from `MinimalAIOrchestrator` path and replaced with config/state resolution.

## Phase 1: Stabilize User Behavior (1-2 sprints)
- Add regression tests for high-frequency loops:
  - `list invoices -> follow-up question -> no forced re-list`
  - numbered selection and positional references
  - routed node follow-up and context shift
- Add deterministic guardrails in `MinimalAIOrchestrator`:
  - follow-up intent guard
  - stricter positional parsing
  - stale context cleanup
- Add branch-level telemetry:
  - chosen action
  - override reason
  - context keys used for decision

Exit criteria:
- Repetition defect rate reduced by at least 80% in captured scenarios.
- No regression in option selection and routing scenarios.

## Phase 2: Split Orchestrator Responsibilities (2-3 sprints)
- Extract planner logic from `MinimalAIOrchestrator` into:
  - `IntentClassifierService`
  - `DecisionPolicyService`
  - `FollowUpStateService`
- Keep existing orchestrator as a thin coordinator.
- Move prompt construction/parsing into dedicated classes.
- Preserve existing public behavior behind feature flag:
  - `AI_ENGINE_ORCHESTRATOR_V2=true`

Exit criteria:
- `MinimalAIOrchestrator` reduced to orchestration only.
- New planner services have direct unit tests.

## Phase 3: Refactor RAG Decision Layer (2-3 sprints)
- Split `IntelligentRAGService` into:
  - query analysis
  - retrieval execution
  - aggregate response generator
  - source/metadata formatter
- Remove duplicated decision paths between orchestrator and RAG.
- Introduce contract tests for list/follow-up/summarize flows.

Exit criteria:
- RAG follow-up path reuses prior entity context by default.
- Aggregate and list responses remain backward compatible.

## Phase 4: Cross-Node Tool Dependency Planning (2 sprints)
- Introduce a tool dependency graph service:
  - tool metadata
  - prerequisites
  - execution order
- Integrate with action execution pipeline and node routing.
- Add fallback policy when dependent node is unavailable.

Exit criteria:
- Multi-node dependent tools execute deterministically with traceable state.

## Guardrails During Refactor
- Keep drivers, node auth/circuit breaker, credit/rate/cache modules unchanged unless required.
- Every extracted component must have focused tests before feature flag rollout.
- Do not remove legacy path until parity checks pass against baseline scenarios.
