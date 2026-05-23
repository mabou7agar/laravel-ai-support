# Collector Runtime Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the remaining collector-specific runtime and old node action helpers so AI-native skills plus concrete action-backed tools are the only supported conversational write path.

**Architecture:** Skills describe what the model can do and what data shape is expected. Concrete tools/actions perform Laravel validation, relation resolution, confirmation, idempotency, auditing, and execution. Collector-only state machines, discovery, handlers, DTOs, and public docs are removed instead of kept as a second orchestration system.

**Tech Stack:** Laravel service container, PHPUnit, package config/docs, AI-native agent runtime.

---

### Task 1: Add Cleanup Guard Tests

**Files:**
- Modify: `tests/Unit/Services/LegacyActionCleanupTest.php`

- [x] Add assertions that collector runtime classes, collector DTOs, collector contracts, and old node action helper services no longer exist.
- [x] Run the guard test and verify it fails before deleting production code.

### Task 2: Remove Collector Runtime Wiring

**Files:**
- Modify: `src/Support/Providers/AgentServiceRegistrar.php`
- Modify: `src/Support/Providers/CoreServiceRegistrar.php`
- Modify: `src/Support/Providers/NodeServiceRegistrar.php`
- Modify: agent execution/router/RAG/node services that currently accept collector dependencies.

- [x] Remove collector service bindings.
- [x] Remove collector branches from agent execution and router code.
- [x] Keep RAG and node metadata based on skills, tools, actions, and model metadata only.

### Task 3: Delete Collector Runtime Files

**Files:**
- Delete collector-only DTOs, contracts, services, and tests.
- Update docs so they describe AI-native skill intake and action-backed tools only.

- [x] Delete runtime files after wiring no longer references them.
- [x] Delete tests that only prove removed collector behavior.
- [x] Update docs/config references.

### Task 4: Trim Old Node Action Helpers

**Files:**
- Modify: `src/Support/Providers/NodeServiceRegistrar.php`
- Delete: old action manager/pipeline/intake helpers if no longer referenced.

- [x] Keep `ActionRegistry`, `ActionOrchestrator`, `ActionBackedTool`, relation resolution, audit logging, and generic module action definitions.
- [x] Remove old node helper services that duplicate the current action-backed path.

### Task 5: Verification

- [x] Run focused cleanup tests.
- [x] Run focused AI-native/action/RAG/node tests.
- [x] Run the full PHPUnit suite.
