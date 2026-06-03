# ADR 0001 — Fate of the classic routing path

Status: **Accepted — Option B (removed)**
Date: 2026-06-03
Context: after making AiNative the default execution path for every turn (only `routed_to_node` federation continuations bypass it), the classic routing path was dormant. This ADR decided whether to keep it as a supported fallback or remove it.

## Decision & outcome

**Option B was chosen and executed.** AiNative is now the sole router; the classic routing brain was deleted.

- `LaravelAgentProcessor` collapsed to always-AiNative (constructor 12 → 5 params); only an active `routed_to_node` continuation runs before AiNative.
- Deleted: `IntentRouter`, `Services/Agent/Routing/` (`RoutingPipeline` + 8 stages), the `BenchmarkOrchestrationV2Command` / `EvaluateRoutingFixturesCommand` console commands, and the `routing_pipeline.stages` config block.
- Kept (shared infra): `AgentExecutionDispatcher`, `AgentConversationService::executeSearchRAG`, `RAGDecisionEngine`, `GoalAgentService`, `MessageRoutingClassifier`, `RoutingContextResolver`, `AgentPlanner` — all reachable from AiNative tools and/or the federation continuation path.
- **Federation path-1 preserved** via a new `route_to_node` AiNative tool (federation package): the planner can explicitly route a turn to a remote node, reusing `NodeSessionManager::routeToNode`. Path-2 (RAG model-router) was already reachable via `search_knowledge`.
- `ai-agent.ai_native.enabled` is now vestigial (AiNative is unconditional); kept for config back-compat, no longer read.

Follow-up cleanup (done): the dead `USE_TOOL`/`HANDLE_SELECTION` dispatcher arms + `executeTool`/`executeSelection`, the orphaned `AgentActionExecutionService` (379 LOC), and the `RoutingStageContract` + scaffold `routing-stage` artifact type were all removed once the classic decision producers were gone.

**Structured-query consolidation — investigated, intentionally NOT done.** "Make `data_query` the only SQL home" cannot be completed without regressing live behavior. `RAGDecisionEngine`'s db-arms (`db_query`/`db_count`/`db_aggregate`/`db_query_next`) are reached whenever `selected_entity`, `selected_entity_context`, `allow_rag_exit_to_orchestrator`, or `structured_query` is present — i.e. entity-selection follow-ups and explicit structured queries on the **federation-fallback** and **sub-agent RAG** paths (not the AiNative planner). That SQL is federation-aware (remote-node routing) and paginated; the `data_query` tool is local + simple. Removing the db-arms breaks those paths; making either delegate to the other loses node-routing/pagination. The original concern — two SQL routes confusing the *AiNative planner* — is already moot, because the planner only sees the `data_query` and `search_knowledge` tools, never `RAGDecisionEngine`'s internals. So the two SQL paths are parallel capabilities for different execution contexts, kept as-is.

---

## 1. The reframe — it is NOT "keep vs delete classic"

Investigation shows "classic" is two very different things that got lumped together. Separating them is the whole decision.

### 1a. Shared infrastructure — stays regardless of the decision

These were historically "classic" but are now **load-bearing for AiNative and/or federation**. None of them can be removed.

| Component | Why it must stay |
| --- | --- |
| `AgentConversationService::executeSearchRAG` | Called by the AiNative `search_knowledge` tool, by federation's degraded-RAG fallback, and by `ConversationalSubAgentHandler`. |
| `RAGDecisionEngine` | Sits behind `executeSearchRAG` (vector/db_query/db_count/db_aggregate router). |
| `AgentExecutionDispatcher` (SEARCH_RAG, CONVERSATIONAL arms) | Federation's `ContinueNode`/`RouteToNode` handlers are built with `app(AgentExecutionDispatcher::class)`; the processor's `routed_to_node` fallback dispatches `SEARCH_RAG` through it. |
| `GoalAgentService` | Called by the AiNative `run_sub_agent` tool (`RunSubAgentTool` → `GoalAgentService::execute`). |

**Consequence:** even an aggressive "remove classic" keeps the dispatcher + conversation + RAG + goal-agent services. The scary shared parts are not on the table.

### 1b. The actual dormant dead-weight — the classic *routing brain*

Only these are reachable **solely** when `ai_native.enabled = false` (or via two dev-only console commands):

| Component | LOC | Only reachable via |
| --- | --- | --- |
| `RoutingPipeline` + 8 stages (`src/Services/Agent/Routing/**`) | ~1273 | `LaravelAgentProcessor::routeThroughPipeline()` |
| `IntentRouter` | 883 | `AIRouterStage` (a pipeline stage) + `routeThroughPipeline()` |
| `routeThroughPipeline()` + the dispatcher's `USE_TOOL` / `HANDLE_SELECTION` arms | small | classic routing decisions, which only the pipeline/IntentRouter emit |
| `BenchmarkOrchestrationV2Command`, `EvaluateRoutingFixturesCommand` | — | manual dev invocation |

**~2150 LOC of routing brain** is the real subject of this decision. In production with `ai_native.enabled = true` (the default), none of it runs.

---

## 2. The problem with the status quo

It is in the worst quadrant: **not committed-to, not removed.**

- Every change to the hot path must be reasoned about against two execution models.
- The fallback is essentially never exercised in production, so it will bit-rot — and a fallback that silently stops working is worse than none.
- New contributors can't tell which path is canonical.

A decision — either direction — is strictly better than the limbo.

---

## 3. Option A — Keep classic as a *genuinely supported* degraded mode

Make the dormant path a real, proven fallback rather than dead code.

**Plan**
1. Add a CI matrix dimension `ai_native: ['on','off']` (mirrors the existing `federation` dimension). The `off` job runs a representative end-to-end flow (chat → tool → RAG → sub-agent) with `ai-agent.ai_native.enabled=false`, proving the classic brain still works.
2. Add ~3 focused integration tests that pin classic behavior for the flows AiNative now owns (force_rag, goal_agent, a tool call) so regressions surface.
3. Document in the README/agent docs: "AiNative is canonical; the classic pipeline is a supported fallback for `ai_native.enabled=false` and is covered by the `ai_native:off` CI job."
4. Keep `IntentRouter` + `RoutingPipeline`.

**Cost:** ~1 extra CI job (+~1.5 min) and a handful of tests, forever. Keeps ~2150 LOC alive.
**Buys:** a real escape hatch if AiNative regresses or for latency/cost-sensitive deployments that prefer deterministic routing.

**Choose A if:** you foresee real deployments running `ai_native.enabled=false`, or you're not yet confident enough in AiNative to have no fallback.

---

## 4. Option B — Deprecate and remove the classic routing brain

Delete `RoutingPipeline` + stages + `IntentRouter`; keep all shared infra (§1a). AiNative becomes the only router; federation keeps working because it depends on the dispatcher, not the routing brain.

**Plan (phased, each phase independently green)**
1. **Deprecate (one release):** mark `ai-agent.ai_native.enabled` as deprecated when set to `false`; log a deprecation warning; announce in CHANGELOG. No behavior change yet.
2. **Prove independence:** confirm nothing but `routeThroughPipeline()` + the two dev commands reaches `RoutingPipeline`/`IntentRouter` (already verified). Confirm federation's `routed_to_node` fallback uses only `AgentExecutionDispatcher` + `executeSearchRAG` (verified) — not the routing brain.
3. **Collapse the processor:** make `dispatchProcess()` always use AiNative (drop the `shouldUseGoalAgent`-when-disabled branch and `routeThroughPipeline()`); keep the `routed_to_node` branch untouched.
4. **Remove:** delete `src/Services/Agent/Routing/**`, `IntentRouter`, the dispatcher's `USE_TOOL`/`HANDLE_SELECTION` arms (if nothing else emits them), and the two benchmark/eval commands (or repoint them at AiNative). Delete the now-dead routing tests; migrate any still-relevant assertions onto AiNative.
5. **Config cleanup:** remove `ai_native.enabled` (or keep as a hard error if `false`).

**Cost:** a real removal effort + test migration; loses the deterministic fallback.
**Buys:** ~2150 fewer LOC, one execution model, no limbo, simpler onboarding.

**Choose B if:** no real deployment will run with AiNative off, and you're willing to make AiNative the sole router (with federation as the only alternate path).

---

## 5. Recommendation

**Option A now, with a tripwire toward B.** Rationale:

- AiNative is young as the *sole* hot path. Keeping a *proven* fallback (not dead code) is cheap insurance — but only if §3 step 1 (the `ai_native:off` CI job) is done, which converts the limbo into a real safety net.
- Revisit in ~2 release cycles: if telemetry shows no one runs `ai_native.enabled=false` and AiNative has been stable, execute Option B.
- The **structured-query "overlap"** (`RAGDecisionEngine` db-arms vs the `data_query` tool) was investigated in depth (design panel + adversarial review) and is the **accepted terminal design — they do NOT converge**. See the "Structured-query consolidation — investigated, intentionally NOT done" note above and the **Structured-query boundary** section below.

The decision that must NOT be made is "leave it exactly as-is." The `ai_native:off` CI job is the minimum next action under either path.

---

## 6. Open questions for the owner

1. ~~Will any real deployment run `ai_native.enabled=false`?~~ **Resolved: moot** — Option B was executed; the classic path is gone and `ai_native.enabled` is now a deprecated no-op.
2. ~~Keep the deterministic-routing benchmark commands?~~ **Resolved: removed** in the Phase-3 cleanup (`BenchmarkOrchestrationV2Command` / `EvaluateRoutingFixturesCommand` deleted).
3. ~~Should structured queries live in `RAGDecisionEngine` or the `data_query` tool?~~ **Resolved: both, intentionally** — `DataQueryTool` is the local, AI-free AiNative arm (fail-closed-by-default scoping, opt-out via `data_query.require_scope=false` or a per-model `public` flag); `RAGStructuredDataService` is the federation-aware, paginated RAG engine. See the Structured-query boundary section.

---

## 7. Structured-query boundary (resolved)

The `data_query` tool and `RAGStructuredDataService` both answer count/list questions but are **deliberately separate** — a design panel + adversarial review confirmed the only byte-identical code is `(clone $builder)->count()`, while everything load-bearing differs by design. Four verified discriminators:

| Axis | `DataQueryTool` (AiNative arm) | `RAGStructuredDataService` (RAG engine) |
| --- | --- | --- |
| **Dependency footprint** | one optional nullable dep (`RAGCollectionDiscovery`) — lightweight, AI-free, self-contained | 7 collaborators + a 4-callable dependency map (model metadata, scope guard, aggregate, locale, summary, graph, state) |
| **Scope default** | **fail-closed** — `data_query.require_scope` (default true) refuses a query when no user/workspace/tenant scope applies; opt out per-model with `public => true` | **fail-closed** — `RAGModelScopeGuard` (`require_structured_scope` default true) blocks unscoped models (`scope_blocked`) |
| **Federation** | none | emits `should_route_to_node` from local-SQL/`isMissingTableException` failures — the **sole** remote-node handoff signal, then routed + paginated |
| **Reachability** | the AiNative planner sees only `data_query` + `search_knowledge` | reached only when `AgentConversationService::shouldUseRagPipeline()` is false (entity selection / structured_query / exit-to-orchestrator on the RAG-agent + federation-fallback paths) |

Consolidating either direction loses capability (delegating the tool to the service was **refuted**: it would hardcode the `status` column, dead the configurable `scope_columns`, couple the lightweight tool to the whole RAG metadata stack, and replace the tool's own scope model). They are kept as two arms; `tests/Unit/Services/Structured/StructuredQueryParityTest.php` is an anti-divergence tripwire that pins both engines to the same numeric count/list result for identical scoped data, so they can't silently drift.

> Note: the tool's scope is now fail-closed-by-default (`data_query.require_scope`), aligning its security posture with the RAG engine while keeping its lightweight, dependency-free design.
