# Pricing Audit And Developer UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add package-level commands and tests that let Laravel apps audit AI pricing, simulate credit charges, verify billing guards, and bootstrap tools/skills with less friction.

**Architecture:** Keep pricing math in a reusable service that wraps `CreditManager` and `AIRequest` instead of duplicating formulas in console commands. Commands format the service output as tables or JSON. Tests cover command output and critical billing guard behavior without making live provider calls.

**Tech Stack:** Laravel console commands, PHPUnit, existing `CreditManager`, `AIRequest`, `EngineEnum`, `EntityEnum`, and agent scaffold command patterns.

---

### Task 1: Pricing Audit And Simulation Tests

**Files:**
- Create: `tests/Unit/Console/Commands/PricingCommandTest.php`
- Modify: `src/AIEngineServiceProvider.php`

- [ ] **Step 1: Write failing command tests**

Add tests that call `ai:pricing-audit --json` and `ai:pricing-simulate --json` and assert provider rates, warnings, and FAL input image totals are included.

- [ ] **Step 2: Run the new tests and verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Console/Commands/PricingCommandTest.php`
Expected: command-not-found failures.

### Task 2: Pricing Breakdown Service And Commands

**Files:**
- Create: `src/Services/Billing/PricingInspectionService.php`
- Create: `src/Console/Commands/PricingAuditCommand.php`
- Create: `src/Console/Commands/PricingSimulateCommand.php`
- Modify: `src/AIEngineServiceProvider.php`

- [ ] **Step 1: Implement reusable pricing inspection**

The service returns normalized arrays for configured engine rates, additional input rates, and a simulation breakdown using `CreditManager::calculateCredits()`.

- [ ] **Step 2: Implement console commands**

`ai:pricing-audit` lists rates and warnings. `ai:pricing-simulate` accepts engine, model, prompt, and JSON parameters, then prints base engine credits, additional input credits, engine rate, and final credits.

- [ ] **Step 3: Run pricing command tests**

Run: `php vendor/bin/phpunit tests/Unit/Console/Commands/PricingCommandTest.php`
Expected: pass.

### Task 3: Billing Guard Coverage

**Files:**
- Modify: `tests/Unit/Services/Media/MediaCreditAccountingTest.php`
- Modify: `tests/Unit/Services/Fal/FalAsyncVideoServiceTest.php`

- [ ] **Step 1: Add focused guard assertions where missing**

Extend tests to cover preflight insufficient credit checks and failed-provider non-deduction for media/FAL async paths where existing coverage is incomplete.

- [ ] **Step 2: Run affected billing tests**

Run: `php vendor/bin/phpunit tests/Unit/Services/Media/MediaCreditAccountingTest.php tests/Unit/Services/Fal/FalAsyncVideoServiceTest.php`
Expected: pass.

### Task 4: Tool And Skill Developer UX

**Files:**
- Modify: `src/Console/Commands/AgentManifestDoctorCommand.php`
- Modify: `docs/agent-skills.mdx`

- [ ] **Step 1: Add command output that helps developers see available tools and skills**

Enhance existing doctor output rather than creating duplicate commands.

- [ ] **Step 2: Document the recommended flow**

Show how to scaffold a tool/agent, discover skills, run manifest doctor, and run pricing simulation before live calls.

### Task 5: Verification And Commit

**Files:**
- All changed files

- [ ] **Step 1: Run syntax checks**

Run `php -l` on every added/modified PHP file.

- [ ] **Step 2: Run affected tests**

Run command, credit, media, FAL, and docs tests.

- [ ] **Step 3: Run full suite**

Run: `php vendor/bin/phpunit`
Expected: pass or only existing intentional skips.

- [ ] **Step 4: Commit**

Commit with message: `Add pricing audit and simulation tooling`.
