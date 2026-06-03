# System Verification Report — Laravel AI Engine

**Date:** 2026-06-02 · **Baseline:** Full Pest suite green (1470 tests) · **Branch:** `codex/chatflow-trace-cleanup`

Audit covered 8 subsystems. Findings rated serious were adversarially re-verified before inclusion below.

---

## 1. Overall health

| Subsystem | Tests result | works_as_expected | Confirmed findings |
|---|---|---|---|
| orchestration-routing | green | yes | 2 (both best-practice, no fix needed) |
| tools-execution | green | mostly | 2 (1 bug, 1 coverage gap) |
| sub-agents-goal | green | partial | 2 (dependency validation + cycle detection) |
| memory | green | yes | 1 (coverage gap, downgraded to low) |
| rag | green | yes | 2 (coverage gaps) |
| vector | green | **no** | 3 (1 critical, 2 coverage gaps) |
| drivers | green | partial | 4 (1 bug confirmed, 1 bug class, 2 coverage gaps) |
| media | green | partial | ≥4 (2 bugs, 2 coverage gaps) |

The suite passing at 1470 reflects untested paths, not absence of defects. The most serious confirmed defect (vector aggregate) is invisible to the suite because the entire aggregate API and the Pinecone driver path have zero tests.

---

## 2. Confirmed issues by severity

### CRITICAL

**C1 — `getMatchingIds()` missing from PineconeDriver and interface contract**
- **What:** `VectorSearchService::aggregate()` (and `getMatchingIds()`) call `$driver->getMatchingIds()`, but the method exists only on `QdrantDriver`. It is absent from `VectorDriverInterface` and `PineconeDriver`.
- **Impact:** Runtime fatal (undefined method) for any deployment on Pinecone that calls `aggregate/sum/avg/min/max/getMatchingIds`. Not a contract-enforced method, so nothing catches it at boot.
- **Where:** `src/Services/Vector/VectorSearchService.php:620` and `:699` (callers); `src/Services/Vector/VectorDriverInterface.php:1-69` (missing declaration); `src/Drivers/.../PineconeDriver.php` (missing impl); reference impl `QdrantDriver.php:611-661`.
- **Fix:** Declare `getMatchingIds(string $collection, array $filters = []): array` on the interface; implement on PineconeDriver (paginated query, dedupe IDs from `metadata.model_id`/`id`); add a contract test that exercises `aggregate()` against both drivers so the interface gap can't recur.

### HIGH

**H1 — Silent exception swallowing in model-config tool handlers**
- **What:** When a model-config tool handler throws, the `catch (\Exception)` logs but does not return or rethrow; execution falls through to registry lookup then RAG fallback.
- **Impact:** A tool that *exists but failed* is reported as "tool not registered." Implementation bugs are masked; callers can't distinguish not-found from errored.
- **Where:** `src/Services/Agent/AgentActionExecutionService.php:142-147` (fall-through to `:150` registry, `:182` RAG; misleading message at `:157-159`).
- **Fix:** Return `AgentResponse::failure('Tool execution failed: '.$e->getMessage(), ...)` from the catch block. Add test `test_execute_use_tool_returns_failure_when_handler_throws_exception` asserting failure is surfaced and RAG fallback is *not* invoked.

**H2 — Sub-agent plan: undeclared/missing dependencies pass silently**
- **What:** `firstFailedDependency()` only blocks when a dep exists in `$results` and failed. A `depends_on` referencing a non-existent task ID is never detected; the task runs anyway.
- **Impact:** Config typos / missing tasks execute out of intended order with deps unresolved, silently producing wrong results.
- **Where:** `src/Services/Agent/SubAgents/SubAgentExecutionService.php:71-80` (the `isset($results[$id]) && !success` short-circuit at `:74`); no validation in `SubAgentPlanner` or `execute()`.
- **Fix:** Validate all `depends_on` IDs exist in the plan before executing; fail the dependent task with `missing_dependency` metadata. Add `test_goal_agent_fails_when_task_depends_on_nonexistent_task` (GoalAgentServiceTest).

**H3 — Sub-agent plan: no circular / forward-reference detection**
- **What:** No cycle detection. A↔B cycles and forward references both pass because sequential execution + the same weak check never flag them.
- **Impact:** Cyclic or forward-referencing plans "succeed" with logically unresolved dependencies.
- **Where:** `src/Services/Agent/SubAgents/SubAgentExecutionService.php:22-68` (execute loop) + `:71-80` (check).
- **Fix:** Add a pre-execution `validateDependencies()` using Kahn's topological sort to detect cycles, forward refs, and undefined deps; bail before the loop. Add tests for circular, forward-reference, and undefined-dependency cases. (Note H2 and H3 share a root cause — fix together with one validation pass.)

**H4 — Perplexity (and DeepSeek) streaming reads fixed 1024-byte chunks, splitting SSE lines**
- **What:** `generateTextStream()` does `trim($stream->read(1024))` per iteration with no line buffering; SSE events can split across reads or pack multiple per chunk, breaking the `data:`/`json_decode` parse.
- **Impact:** Corrupted/dropped stream chunks in real-time chat. Same bug class confirmed present in DeepSeek; the correct buffered pattern already exists in Grok.
- **Where:** `src/Drivers/Perplexity/PerplexityEngineDriver.php:172` (loop `:170-184`); DeepSeek `:172`. Correct reference: `GrokEngineDriver.php:344-376`.
- **Fix:** Replace fixed-read loop with a buffer that searches for `\n` to extract complete lines before parsing (port Grok's `parseStreamingResponse`). Add SSE streaming tests for both drivers.

**H5 — `VisionService::encodeImage()` unchecked `file_get_contents()` / `mime_content_type()`**
- **What:** Return values not validated. Under `strict_types=1`, `base64_encode(false)` throws `TypeError` (not caught by the `catch(\Exception)` at `:90`) → unhandled crash; a false MIME silently yields a malformed `data:;base64,` URL sent to the Vision API.
- **Impact:** Hard crash on unreadable files; silent data corruption on MIME failure.
- **Where:** `src/Services/Media/VisionService.php:246-247` (used at `:248`, `:250`).
- **Fix:** Throw `InvalidArgumentException` when either returns `false`. Add error-path tests (unreadable file, MIME failure).

**H6 — `DocumentService::extractFromPDF()` temp-file leak on `pdftotext` failure**
- **What:** `tempnam()` creates a file; `unlink()` runs only inside the `returnCode === 0` branch. Non-zero exit leaves an orphan in `sys_get_temp_dir()`.
- **Impact:** Temp-file accumulation on every failed extraction.
- **Where:** `src/Services/Media/DocumentService.php:127-141` (cleanup only at `:139`).
- **Fix:** Wrap in `try/finally` with `@unlink($tempFile)` in `finally`. Add a test for the `pdftotext available but returns non-zero` path.

> Note: `memory` finding (scope_hash dedup) was raised as high but **verification downgraded it to low** — see §5.

---

## 3. Critical coverage gaps (ranked)

1. **Vector aggregate API — entirely untested** (`VectorSearchService.php:613-679`): `aggregate/sum/avg/min/max/countWithFilters/getMatchingIds`. Zero tests; also the only place that would have caught C1. *(highest priority — masks a critical bug)*
2. **Vector parent-lookup filters** (`VectorSearchService.php:70, 723-764`): `applyParentLookupFilters` + model hooks `hasVectorParentLookup`/`resolveParentIdsFromQuery` untested; silent exception-swallow path at `:757-762`.
3. **Driver functional coverage** — 7 drivers have only enum-mapping tests, no functional tests: **Azure, DeepSeek, Midjourney, Perplexity, PlagiarismCheck, Serper, Unsplash**. (Audit's claimed 15 was overstated; 6 of the listed are in fact functionally tested — see §5.)
4. **Grok `stream()`** (`GrokEngineDriver.php:111`): driver advertises `streaming` capability, no streaming test despite correct impl.
5. **RAG `answer_from_context` tool** (`RAGDecisionEngine.php:318-367`): all three paths (fast-path answer, selected-entity fallback, failure) untested.
6. **RAG pipeline-unavailable path** (`RAGDecisionEngine.php:452-454`): `force_rag`/`semantic_retrieval` with null pipeline returns "RAG service not available" — untested.
7. **`ValidateFieldTool`** (`src/Services/Agent/Tools/ValidateFieldTool.php`): public tool, zero tests (currently disabled in `ai-agent.php:451`, but production-ready).
8. **Idempotency reroute stripping** (`LaravelAgentProcessor.php:534-541`): logic is correct but only outer-level idempotency is tested; the `array_diff_key` strip on nested reroute is unverified.
9. **Memory scope_hash** explicit-value/determinism assertion (`ConversationMemoryRepositoryTest.php:92`): low risk (DB unique constraint backstops it) but no explicit hash assertion.

---

## 4. Best-practice observations (themes)

- **Streaming SSE parsing is inconsistent across drivers.** Grok/OpenRouter buffer correctly; Perplexity and DeepSeek use fixed reads. Extract one shared SSE line-buffering helper and route all streaming drivers through it.
- **Errors swallowed without surfacing.** Recurring pattern: catch → log → fall through (tools-execution H1, vector parent-lookup, sub-agent missing deps). Prefer explicit failure responses over silent fallback so callers and tests can observe failures.
- **Interface contracts not enforcing the real surface.** `getMatchingIds` (C1) was used in production but never declared on `VectorDriverInterface`. Audit driver-facing call sites against the interface; add cross-driver contract tests.
- **Unchecked PHP stdlib return values under `strict_types`.** `file_get_contents`, `mime_content_type`, `tempnam`/`exec` cleanup. Validate returns; use `try/finally` for resource cleanup.
- **Capability claims outrun test coverage.** Drivers advertise capabilities (Grok `streaming`) with no test exercising them; aggregate API documented but untested. Tie capability declarations to at least one functional test.

---

## 5. Dismissed / downgraded (do not re-raise)

- **Memory — "scope_hash not validated for dedup" (raised high → verified LOW, partially-confirmed):** The coverage gap is real (no explicit hash-value assertion), but it is *not* a functional vulnerability. `hash()` uses fixed-key `json_encode` + sha256 = deterministic; a DB **unique constraint on (namespace, key_hash, scope_hash)** would surface any divergence as an insert violation, not silent duplication; and the existing `count=1` assertion would catch non-determinism. Action is an optional test assertion, not a fix.
- **Drivers — "15 drivers have zero coverage" (partially-confirmed, count wrong):** 6 of the named drivers DO have functional tests — **CloudflareWorkersAI, ComfyUI, GoogleTTS, HuggingFace, Pexels, Replicate** (MediaProviderDriversTest) and **StableDiffusion** (ImageEditDriversTest). The real zero-functional-coverage set is the **7** listed in §3.3.
- **Orchestration — dedup guard & idempotency stripping:** Both raised as findings but verified **correct, no fix required**. Dedup guard (`LaravelAgentProcessor.php:95-104`) handles the whitespace/empty edge case correctly; reroute idempotency stripping (`:534-541`) is sound. Only a confirming test is suggested (§3.8), not a code change.

---

## 6. Verdict + top 5 next actions

**Verdict:** Core flows are sound and well-tested at the happy-path level (1470 green), but there is **one critical defect** that is invisible to the suite (Pinecone aggregate fatal) plus a cluster of high-severity error-handling and streaming bugs. The common thread is **silent failure + untested error/secondary-driver paths**, not broken core logic. Ship-blocking only if Pinecone aggregate or Perplexity/DeepSeek streaming are in use.

**Top 5 next actions (in order):**
1. **Fix C1** — add `getMatchingIds` to `VectorDriverInterface` + `PineconeDriver`, and add the cross-driver aggregate contract test (closes the critical bug *and* gap §3.1).
2. **Fix the SSE streaming bug (H4)** in Perplexity + DeepSeek via shared buffered line parser; add streaming tests for both plus Grok (§3.4).
3. **Add sub-agent dependency validation (H2+H3)** — one pre-execution Kahn's-sort pass covering undefined deps, cycles, and forward refs; add the three test cases.
4. **Surface tool-handler errors (H1)** — return failure instead of falling through to RAG; add the throwing-handler test.
5. **Harden media I/O (H5+H6)** — validate `encodeImage` returns and wrap PDF temp file in `try/finally`; add the error-path tests.

(Then backfill the remaining coverage gaps: vector parent-lookup, RAG `answer_from_context` + null-pipeline, 7 driver functional tests, `ValidateFieldTool`.)