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

Deferred: structured-query consolidation (§4 below). Dead dispatcher arms (`USE_TOOL`/`HANDLE_SELECTION`) were left in place (harmless, still covered by dispatcher tests).

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
- Independently of A/B, address the **structured-query overlap** (`RAGDecisionEngine.db_query` vs the `data_query` tool) — that smell exists in both worlds and should converge on one home.

The decision that must NOT be made is "leave it exactly as-is." The `ai_native:off` CI job is the minimum next action under either path.

---

## 6. Open questions for the owner

1. Will any real deployment run `ai_native.enabled=false`? (If definitively no → go straight to B.)
2. Do you want the deterministic-routing benchmark commands kept as a quality signal, or removed?
3. Should structured queries live in `RAGDecisionEngine` or the `data_query` tool? (Pick one before either A or B.)
