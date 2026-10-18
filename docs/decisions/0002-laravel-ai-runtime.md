# ADR 0002 — Adopting the official laravel/ai SDK

Status: **Accepted. Steps 1–3 done. Step 4: technical gates closed; default/required deferred to a major release.**
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

## Step 4 — default or required?

First evaluation (2026-10-19, at the merge of steps 1–3): **not yet**. Six blockers were found. Five of them were then closed on the same day:

| Blocker | Status | How |
| --- | --- | --- |
| Package tools not bridged | Closed | `PackageAgentTool` exposes package `AgentTool`s to the SDK agent, selected with `ai-agent.runtime.laravel_ai.package_tools`. Calls run through `AiNativeToolExecutor`, so the execution policy applies. The run's tenant and workspace scope reach the tool. Tools that require confirmation become SDK approvals and so package step approvals. Skills (`run_skill`), sub-agents (`run_sub_agent`), `search_knowledge`, `data_query` and federation routing are themselves package tools, so they come across the same way. |
| Credit billing not integrated | Closed | `LaravelAiCreditBiller` prices each turn like an engine call: units × the model's catalog credit index × the engine rate. Units come from the SDK's token usage. Credits are accumulated on the run as `credits_used` and, with `ai-engine.credits.enabled`, deducted from the user after a balance check before the SDK is called. |
| Hidden title-generation calls | Closed | The runtime opens new SDK conversations itself, titled from the first message, so the SDK makes no extra model call. `generate_titles` restores the SDK behaviour. |
| Narrower capabilities | Mostly closed | `sub_agents` is reported when `run_sub_agent` is bridged. `artifacts` is still `false`. |
| Live providers unverified | Closed | `LaravelAiLiveProviderSmokeTest` runs a streamed turn and a confirm-then-approve package tool against a live provider. It passed on OpenRouter with `anthropic/claude-haiku-4.5`. It is opt-in because it spends provider usage. |
| Requiring the SDK is a breaking change | Open, by nature | Requiring `laravel/ai` raises the package floor from PHP ^8.1 and Laravel 8–13 to PHP ^8.3 and Laravel 12–13. |

### Verdict

The runtime is ready for production use **as an opt-in**. It should not become the default or a requirement inside 3.x:

- Making `laravel_ai` the default without requiring the SDK would break every install that has not installed `laravel/ai`.
- Requiring the SDK is a semver-major change. It belongs in a 4.0 release that deliberately drops PHP < 8.3 and Laravel < 12, which is a maintainer decision, not an engineering one.

If 4.0 takes that step, the remaining work is: an `artifacts` mapping for SDK file outputs, a decision about retiring the built-in provider drivers that duplicate the SDK, and running the live smoke test on each provider the project supports.
