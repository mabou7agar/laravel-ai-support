# Laravel AI Engine

Laravel AI Engine is a Laravel package for AI chat orchestration, deterministic tool execution, RAG, and node federation across multiple Laravel apps.

## Status (March 2026)

Current codebase includes:

- modular orchestrator (`IntentRouter`, `AgentPlanner`, action execution, response finalizer)
- autonomous RAG split into focused services (decision, execution, context/state, policy, feedback, structured data)
- deterministic node routing via ownership and manifest metadata (no AI-only node guessing)
- standardized response envelope (`success`, `message`, `data`, `error`, `meta`)
- localization stack (locale middleware, lexicons, prompt templates)
- prompt policy learning with DB-backed feedback events and policy versions
- infrastructure hardening (remote migration guard, Qdrant self-check, startup health gate)
- admin UI with user/email/IP access controls

## Compatibility

- package: `m-tech-stack/laravel-ai-engine`
- PHP: `^8.1`
- Laravel: `8.x | 9.x | 10.x | 11.x | 12.x`
- Guzzle: `^7.0`
- OpenAI PHP client: `^0.8 | ^0.9 | ^0.10`
- Symfony HTTP client: `^5.4 | ^6.0 | ^7.0`

Source of truth: `composer.json`.

## Install

```bash
composer require m-tech-stack/laravel-ai-engine
php artisan vendor:publish --tag=ai-engine-config
php artisan vendor:publish --tag=ai-engine-migrations
php artisan migrate
```

## Minimal Production Baseline

```env
AI_ENGINE_DEFAULT=openai
AI_ENGINE_DEFAULT_MODEL=gpt-4o
AI_ORCHESTRATION_MODEL=gpt-4o-mini
OPENAI_API_KEY=your_key

AI_ENGINE_STANDARDIZE_API_RESPONSES=true
AI_ENGINE_API_RESPONSE_PRESERVE_LEGACY=true

AI_ENGINE_INJECT_USER_CONTEXT=true
AI_ENGINE_LOCALIZATION_ENABLED=true
AI_ENGINE_SUPPORTED_LOCALES=en,ar
AI_ENGINE_FALLBACK_LOCALE=en

AI_ENGINE_REMOTE_NODE_MIGRATION_GUARD=true
AI_ENGINE_QDRANT_SELF_CHECK_ENABLED=true
AI_ENGINE_STARTUP_HEALTH_GATE_ENABLED=true
```

For multi-app federation:

```env
AI_ENGINE_NODES_ENABLED=true
AI_ENGINE_IS_MASTER=true
AI_ENGINE_NODE_JWT_SECRET=change_me
```

## High-Value Commands

### Diagnostics

```bash
php artisan ai-engine:test-package
php artisan ai-engine:test-real-agent --script=followup --json
php artisan ai-engine:infra-health
```

### Federation (Safe Workflow)

```bash
php artisan ai-engine:node-list
php artisan ai-engine:node-ping --all
php artisan ai-engine:nodes-sync --file=config/ai-engine-nodes.json
php artisan ai-engine:nodes-sync --file=config/ai-engine-nodes.json --autofix
php artisan ai-engine:nodes-sync --file=config/ai-engine-nodes.json --apply --prune --ping --force
php artisan ai-engine:node-cleanup --status=error --days=0 --apply --force
```

### Prompt Policy Learning (Policy-Level)

```bash
php artisan ai-engine:decision-feedback:report
php artisan ai-engine:decision-policy:evaluate --window-hours=48
php artisan ai-engine:decision-policy:create v2 --activate
php artisan ai-engine:decision-policy:activate 2
```

## Entity List UX (Important)

List responses are model-driven:

- implement `toRAGListPreview(?string $locale = null)` for clean multi-line list cards
- implement `toAISummarySource()` for compact summary cache input

If `toRAGListPreview()` exists, it is preferred over fallback summary rendering in structured list responses.

## Admin UI

Enable:

```env
AI_ENGINE_ENABLE_ADMIN_UI=true
AI_ENGINE_ADMIN_PREFIX=ai-engine/admin
AI_ENGINE_ADMIN_ALLOWED_USER_IDS=1
AI_ENGINE_ADMIN_ALLOWED_EMAILS=admin@example.com
AI_ENGINE_ADMIN_ALLOWED_IPS=127.0.0.1,::1
```

Open: `/ai-engine/admin` (or your configured prefix).

## API Contract

```json
{
  "success": true,
  "message": "Request completed.",
  "data": {},
  "error": null,
  "meta": {}
}
```

Built-in direct generation endpoints:

- `POST /api/v1/ai/generate/text`
- `POST /api/v1/ai/generate/image`
- `POST /api/v1/ai/generate/transcribe`
- `POST /api/v1/ai/generate/tts`

Authenticated calls are credit-enforced (same policy as chat/RAG), including image/audio endpoints.

Toggle/prefix with env:

```env
AI_ENGINE_GENERATE_API_ENABLED=true
AI_ENGINE_GENERATE_API_PREFIX=api/v1/ai/generate
```

Inject your own middleware into package API routes:

```env
AI_ENGINE_API_APPEND_MIDDLEWARE=auth:sanctum
AI_ENGINE_API_GENERATE_MIDDLEWARE=throttle:30,1
# For multiple middlewares, separate with semicolon:
# AI_ENGINE_API_GENERATE_MIDDLEWARE=auth:sanctum;throttle:30,1
# Or use JSON array for exact values:
# AI_ENGINE_API_GENERATE_MIDDLEWARE=["auth:sanctum","throttle:30,1"]
```

## Documentation

Deep docs are in `docs-site` (Mintlify).

Run locally:

```bash
cd docs-site
npx mintlify dev
```

Recommended reading order:

1. `guides/quickstart`
2. `guides/concepts`
3. `guides/single-app-setup`
4. `guides/model-config-tools`
5. `guides/direct-generation-recipes`
6. `guides/entity-list-preview-ux`
7. `guides/data-collectors`
8. `guides/rag-indexing`
9. `guides/copy-paste-playbooks`
10. `guides/multi-app-federation`
11. `guides/policy-learning`
12. `guides/testing-playbook`

## Upgrading Existing Installs

If config was published before recent refactors, refresh it:

```bash
php artisan vendor:publish --tag=ai-engine-config --force
php artisan optimize:clear
```

See `docs-site/reference/upgrade.mdx` for the upgrade checklist.

## License

MIT
