# ADR 0002 — Adopting the official laravel/ai SDK

Status: **Accepted. Steps 1–3 done; step 4 evaluated, gate stays closed.**
Date: 2026-10-19
Context: Laravel shipped the official `laravel/ai` SDK (v1.0.0 on 2026-09-23, v1.2.3 at the time of writing). It needs PHP ^8.3 and Laravel 12/13. This package supports PHP ^8.1 and Laravel 8–13.

## Decision

Do not replace this package with `laravel/ai`. Adopt it in stages behind the existing runtime boundary, `LaravelAIEngine\Contracts\AgentRuntimeContract`:

1. Make runtime registration extensible, with the built-in runtime staying the default.
2. Add an opt-in `laravel/ai`-backed runtime, gated on `class_exists` and listed only under composer `suggest`.
3. Prove parity on streaming, approval resume, recovery, cancellation, tenant scope and usage accounting.
4. Only then consider making it the default or a requirement.

The split of responsibilities: `laravel/ai` covers provider access, agents, tools and standard retrieval. This package owns persisted run ownership, approval and recovery workflows, execution budgets, tenant-scoped retrieval, billing integration and federation.

## What was built

**Step 1 — registration.** `ai-agent.runtime.drivers` maps a runtime name to an `AgentRuntimeContract` class. `AgentRuntimeManager` resolves entries lazily from the container. `AgentRuntimeConfigValidator` rejects entries that are not contract implementations. The built-in `laravel` and `langgraph` runtimes are always registered.

**Step 2 — the `laravel_ai` runtime.** It lives in `src/Services/Agent/Runtime/LaravelAi/`.

| Piece | Role |
| --- | --- |
| `LaravelAiAgentRuntime` | The `AgentRuntimeContract`. Owns approvals, event mirroring, cancellation, usage metadata and conversation continuity. Never touches SDK types. |
| `LaravelAiGateway` | The seam to the SDK. Binding it replaces the SDK, which is how the parity suite runs on every PHP/Laravel version. |
| `SdkLaravelAiGateway` | The real gateway. Runs `prompt()` or `stream()` on a `laravel/ai` agent and maps responses, stream events, tool calls and pending approvals. |
| `EngineAgent` | Default SDK agent, configured from `ai-agent.runtime.laravel_ai`. Remembers conversations so approvals can resume. Hosts can configure their own agent class instead. |
| `LaravelAiStreamMirror` | Maps SDK stream events onto run events and checks for cancellation between events. |
| `LaravelAiSdk` | The feature gate: registered only when the SDK is installed **and** `ai-agent.runtime.laravel_ai.enabled` is true. |

`laravel/ai` is in composer `suggest` only. Files that implement SDK interfaces (`EngineAgent`, `SdkLaravelAiGateway`) are only autoloaded behind `LaravelAiSdk::installed()`.

**Step 3 — parity evidence.** Two suites:

- `tests/Feature/LaravelAiRuntimeParityTest.php` runs the real `RunAgentJob` / `ContinueAgentRunJob` pipeline against the runtime with a deterministic gateway. It runs on every CI job.
- `tests/Feature/LaravelAiSdkGatewayTest.php` runs the runtime against the real `laravel/ai` SDK, driven by the SDK's own fakes. It is skipped unless the SDK is installed. The `laravel-ai` CI job lifts the harness to Laravel 12, installs the SDK and runs the whole suite.

| Guarantee | Status | Evidence |
| --- | --- | --- |
| Streaming | At parity, and ahead of the built-in runtime, which reports `streaming: false` | Tokens and tool events mirrored in order between `run.started` and `run.completed`; real SDK stream mapped end to end |
| Approval resume | At parity | Tool approval pauses the run as `waiting_approval` with a package approval record; resume via `AgentRunRuntimeControlService` replays `approve` / `reject` into the SDK conversation; works per request on a non-default runtime; real SDK pause and resume through the package control surface |
| Approval safety | Hardened | Resume state comes from the approval record and must belong to the run being resumed. Client-supplied conversation ids or approval keys from another run are ignored |
| Recovery | At parity | Provider failure fails the run, emits `run.failed`, releases the idempotency claim; the queue retry completes without double-counting usage |
| Cancellation | At parity, and fixed for every runtime | Cancelling mid-stream stops consuming the SDK stream; run and step stay `cancelled`; no `run.completed`; tokens already spent are still recorded |
| Tenant scope | At parity | Tenant and workspace reach the runtime; a scope mismatch is rejected before the SDK is called |
| Token usage accounting | At parity | Input, output, cache and reasoning tokens recorded on the run budget across pause and resume |
| Idempotency | At parity | Re-running a completed job does not call the SDK again or emit a second terminal event |
| Conversation continuity | At parity | The next turn in a session continues the same SDK conversation |

### Bugs fixed along the way (all runtimes)

- **Cancelled runs were resurrected.** `RunAgentJob` never checked run status. A job queued or retried after a cancel ran the runtime and overwrote `cancelled` with `completed`, and a cancel during processing was overwritten the same way. The job now skips cancelled or expired runs, and keeps the cancellation if it happens while the runtime works.
- **Per-request runtime selection was misattributed.** `RunAgentJob` recorded the configured default runtime, not the one the request selected, so an approval paused on a non-default runtime resumed on the wrong one. Attribution now follows the selected runtime for registered runtimes. Built-in-only setups keep the historical attribution exactly.

## Step 4 — default or required? Not yet.

The gate stays closed. Making `laravel_ai` the default, or requiring `laravel/ai`, is not justified today:

1. **Requiring the SDK is a breaking change.** It would raise the package floor from PHP ^8.1 and Laravel 8–13 to PHP ^8.3 and Laravel 12–13.
2. **Package tools are not bridged.** The runtime only sees `laravel/ai` Tool classes. The package's tool registry, skills, sub-agents, `search_knowledge` RAG, `data_query` and federation routing are not available to it. The built-in runtime's AiNative planner uses all of them.
3. **Credit billing is not integrated.** Credits are charged per engine request inside `AIEngineService`. The SDK runtime bypasses that service, so `laravel_ai` runs record tokens but deduct no credits, and cost-based budgets see zero cost.
4. **Hidden model calls.** The SDK makes an extra model call to title each new conversation. Its tokens never reach the run budget. Hosts can disable it with `ai.conversations.generate_title = false`.
5. **Capabilities are narrower.** Artifacts and sub-agents report `false`.
6. **Live-provider behaviour is unverified.** Parity is proven against the SDK's fakes, not against real providers.

### Exit criteria to reopen step 4

- A bridge that exposes package `AgentTool`s and skills to the SDK as `laravel/ai` tools, with approval policies preserved.
- Credit billing for SDK turns, pricing SDK token usage through the package's model catalog so credit deduction and cost budgets apply.
- Title-generation usage either attributed to the run or disabled by the runtime.
- A smoke suite against at least one live provider.
- A major version that is allowed to drop PHP < 8.3 and Laravel < 12, if the SDK is to become required rather than default.
