# AI Native Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the package route chat, skills, tools, memory, RAG, and actions through an AI-native tool-calling loop instead of package-coded business workflows.

**Architecture:** Skills and tools become capability context. The AI chooses whether to answer, ask, call a tool, use RAG, or continue a pending action. The package keeps only the safety kernel: validation, write confirmation, scope, audit metadata, trusted tool-result authority, persistence, and limits.

**Tech Stack:** Laravel PHP services, existing `AIEngineService`, `AgentTool`/`ToolRegistry`, `AgentSkillRegistry`, `UnifiedActionContext`, PHPUnit.

---

### Task 1: AI-Native Runtime Vertical Slice

**Files:**
- Create: `src/Services/Agent/AiNative/AiNativeRuntime.php`
- Create: `src/Services/Agent/AiNative/AiNativePromptBuilder.php`
- Create: `src/Services/Agent/AiNative/AiNativeResponseParser.php`
- Create: `src/Services/Agent/AiNative/ToolResultAuthorityService.php`
- Modify: `src/Support/Providers/AgentServiceRegistrar.php`
- Test: `tests/Unit/Services/Agent/AiNativeRuntimeTest.php`

- [x] **Step 1: Write failing tests**

Add tests that verify:
- the AI can call a registered read tool directly;
- write tools pause with structured confirmation metadata;
- after confirmation, the pending write tool executes;
- relation ID fields from AI output are removed unless a prior successful tool result produced the same value.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
"/Users/abou7agar/Library/Application Support/Herd/bin/php" vendor/bin/phpunit tests/Unit/Services/Agent/AiNativeRuntimeTest.php
```

Expected: fails because `AiNativeRuntime` does not exist.

- [x] **Step 3: Implement minimal runtime**

Implement a bounded JSON loop:
- action `tool_call`: validate/execute read tool, or pause write tool;
- action `ask_user`: return `AgentResponse::needsUserInput`;
- action `final`: return `AgentResponse::success`/`conversational`;
- state key: `ai_native` in context metadata;
- max steps from `ai-agent.ai_native.max_steps`.

- [x] **Step 4: Run focused tests**

Run:

```bash
"/Users/abou7agar/Library/Application Support/Herd/bin/php" vendor/bin/phpunit tests/Unit/Services/Agent/AiNativeRuntimeTest.php
```

Expected: pass.

### Task 2: Route Chat Through AI-Native Runtime

**Files:**
- Modify: `src/Services/Agent/Runtime/LaravelAgentProcessor.php`
- Modify: `config/ai-agent.php`
- Test: `tests/Unit/Services/Agent/AiNativeProcessorRoutingTest.php`

- [x] **Step 1: Write failing test**

Verify `LaravelAgentProcessor` calls `AiNativeRuntime` when `ai-agent.ai_native.enabled=true`.

- [x] **Step 2: Implement AI-native runtime switch**

Add:

```php
'ai_native' => [
    'enabled' => env('AI_AGENT_AI_NATIVE_ENABLED', true),
    'max_steps' => env('AI_AGENT_AI_NATIVE_MAX_STEPS', 6),
],
```

Processor should use AI-native unless `force_rag`, routed node continuation, or an explicit goal/sub-agent path is requested.

### Task 3: Replace Package-Coded Skill Planner

**Files:**
- Modify: `src/Services/Agent/Tools/RunSkillTool.php`
- Modify: `src/Services/Agent/AgentSkillExecutionPlanner.php`
- Test: `tests/Unit/Services/Agent/Tools/RunSkillToolAiNativeTest.php`

- [x] **Step 1: Make `run_skill` delegate to AI-native runtime by default**

When `ai-agent.ai_native.skills=true`, `RunSkillTool` should pass the selected skill as context to `AiNativeRuntime`.

- [x] **Step 2: Keep AI-native tests explicit**

Tests should verify AI-native skill flow.

### Task 4: Docs And Cleanup

**Files:**
- Modify: `docs/agent-skills.mdx`
- Modify: `docs/orchestration-v2.mdx`
- Modify: `docs/quickstart-chat-actions.mdx`
- Modify: `CHANGELOG.md`

- [x] **Step 1: Document the package-wide rule**

Document:

```text
AI decides intent, plan, tool use, RAG use, follow-up questions, and continuation.
The package enforces validation, scope, approvals, audit, persistence, credits, and provider safety.
```

- [x] **Step 2: Remove superseded package-coded workflow services**

Document AI-native skills plus concrete tools as the recommended path, and remove old action-draft planners from the built-in runtime.

### Task 5: Verification

Run:

```bash
"/Users/abou7agar/Library/Application Support/Herd/bin/php" vendor/bin/phpunit tests/Unit/Services/Agent/AiNativeRuntimeTest.php
"/Users/abou7agar/Library/Application Support/Herd/bin/php" vendor/bin/phpunit tests/Unit/Services/Agent/Tools/RunSkillToolAiNativeTest.php tests/Feature/AgentRunContextRestoreTest.php
"/Users/abou7agar/Library/Application Support/Herd/bin/php" vendor/bin/phpunit
```

Then run a live demo invoice flow through chat and verify the invoice row and item rows are created only after confirmation.

Status:

- [x] Focused AI-native runtime, processor, prompt builder, and `run_skill` tests passed.
- [x] Full package suite passed after implementation: 1295 tests, 5784 assertions.
- [x] Live chat verified AI-native no longer accepts fake `Done` completions, persists tool results across turns, creates missing customer/product records only after confirmation, and blocks invoice creation until products are resolved.
- [ ] Live chat invoice UX still needs a follow-up refinement so the model consistently searches customer/product records before asking conservative clarification questions and completes the final invoice in fewer turns.
