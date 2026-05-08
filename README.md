# Laravel AI Engine

Laravel AI Engine is a Laravel package for AI chat orchestration, deterministic tool execution, GraphRAG/RAG, and node federation across multiple Laravel apps.

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
- central Neo4j graph sync and read path
- planner-driven graph retrieval with query-kind-aware traversal templates
- scoped graph knowledge-base acceleration (plan cache, result cache, entity snapshots)
- host-app background KB build workflow
- host-app capability memory primitives for semantic tool/action/module routing
- compacted agent conversation memory for long chat sessions

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

## Runtime Architecture

- `Engine` / `AIEngine` facade and `app('ai-engine')` resolve to `UnifiedEngineManager`
- `UnifiedEngineManager` is the public fluent entrypoint
- `AIEngineService` is the direct typed execution API for internal services and explicit `AIRequest` flows
- `DriverRegistry` is the single driver construction path

## Breaking Upgrade Note

The legacy `AIEngineManager` and `EngineBuilder` classes were removed. If your application instantiated or type-hinted them directly, migrate to:

- `LaravelAIEngine\\Services\\UnifiedEngineManager` for fluent facade-style usage
- `LaravelAIEngine\\Services\\AIEngineService` for direct request execution
- `LaravelAIEngine\\Services\\EngineProxy` as the fluent builder returned by `engine()` / `model()`

Reference-pack upgrade note:

- `selected_looks` with more than one item now defaults to `strict_selected_set`
- `look_id` without an explicit mode now defaults to `guided`
- `guided` starts from your app-selected look, then can continue into vendor-generated variants
- use `look_mode=strict_stored` if you need deterministic production references from one approved stored look
- use `look_mode=strict_selected_set` if one pack must cover multiple approved stored looks in exact order
- `strict_stored_looks=true` is supported as a shorthand for strict production mode

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

For central GraphRAG with Neo4j:

```env
AI_ENGINE_GRAPH_ENABLED=true
AI_ENGINE_GRAPH_BACKEND=neo4j
AI_ENGINE_GRAPH_READS_PREFER_CENTRAL=true
AI_ENGINE_GRAPH_KB_ENABLED=true

AI_ENGINE_NEO4J_URL=http://localhost:7474
AI_ENGINE_NEO4J_DATABASE=neo4j
AI_ENGINE_NEO4J_USERNAME=neo4j
AI_ENGINE_NEO4J_PASSWORD=secret
AI_ENGINE_NEO4J_CHUNK_VECTOR_INDEX=chunk_embedding_index
AI_ENGINE_NEO4J_CHUNK_VECTOR_PROPERTY=embedding
AI_ENGINE_NEO4J_SHARED_DEPLOYMENT=false
AI_ENGINE_NEO4J_VECTOR_NAMING_STRATEGY=static
AI_ENGINE_NEO4J_VECTOR_NODE_SLUG=
AI_ENGINE_NEO4J_VECTOR_TENANT_KEY=
AI_ENGINE_GRAPH_ONTOLOGY_PACKS=project_management,messaging
```

Fresh installs are now Neo4j-first by default. If Neo4j is not fully configured, runtime read-path resolution falls back to the configured vector driver, which remains `qdrant` by default.

For shared Neo4j clusters, prefer a dedicated vector slot per app or tenant:

```env
AI_ENGINE_NEO4J_SHARED_DEPLOYMENT=true
AI_ENGINE_NEO4J_VECTOR_NAMING_STRATEGY=node
AI_ENGINE_NEO4J_VECTOR_NODE_SLUG=billing_app
AI_ENGINE_NEO4J_CHUNK_VECTOR_INDEX=chunk_embedding_index
AI_ENGINE_NEO4J_CHUNK_VECTOR_PROPERTY=embedding
```

That produces names like `chunk_embedding_index_billing_app` and `embedding_billing_app` so you do not collide with other apps on the same Neo4j database.

## High-Value Commands

### Diagnostics

```bash
php artisan ai-engine:test-package
php artisan ai-engine:test-everything
php artisan ai-engine:test-everything --profile=graph
php artisan ai-engine:test-everything --profile=all --root-path=/path/to/root/app
php artisan ai-engine:backend-status
php artisan ai-engine:model-status "App\\Models\\Project"
php artisan ai-engine:test-real-agent --script=followup --json
php artisan ai-engine:infra-health
```

`ai-engine:test-everything` is the umbrella validation command:

- `safe`: package graph and chat slices, plus root mocked chat route when available
- `graph`: safe plus package live Neo4j graph checks
- `full`: graph plus root-app live graph/chat tests
- `all`: full plus billed provider live matrix

`ai-engine:backend-status` shows the effective read backend and whether Neo4j is active or falling back.

`ai-engine:model-status "App\\Models\\Project"` shows whether a model is ready for indexing, graph publishing, and chat retrieval. Use `--id=<record>` to inspect a real row instead of a blank instance, which is useful when a model only becomes indexable after required attributes are populated.

### Federation (Safe Workflow)

```bash
php artisan ai-engine:node-list
php artisan ai-engine:node-ping --all
php artisan ai-engine:nodes-sync --file=config/ai-engine-nodes.json
php artisan ai-engine:nodes-sync --file=config/ai-engine-nodes.json --autofix
php artisan ai-engine:nodes-sync --file=config/ai-engine-nodes.json --apply --prune --ping --force
php artisan ai-engine:node-cleanup --status=error --days=0 --apply --force
```

### Neo4j GraphRAG and Knowledge Base

```bash
php artisan ai-engine:neo4j-init
php artisan ai-engine:neo4j-sync --fresh
php artisan ai-engine:neo4j-stats
php artisan ai-engine:neo4j-diagnose
php artisan ai-engine:neo4j-repair --apply
php artisan ai-engine:neo4j-drift --repair --prune
php artisan ai-engine:neo4j-benchmark "who owns Apollo?" --iterations=5
php artisan ai-engine:neo4j-index-benchmark "App\\Models\\Project" --limit=10
php artisan ai-engine:neo4j-load-benchmark --profile=steady
php artisan ai-engine:neo4j-load-benchmark --mode=mixed --iterations=50 --concurrency=4
php artisan ai-engine:chat-benchmark "What changed for Apollo?" --iterations=3
php artisan ai-engine:benchmark-history --type=retrieval --limit=10
php artisan ai-engine:graph-ranking-feedback relationship
php artisan ai-engine:neo4j-kb-warm --from-profiles --canonical-user-id=1
php artisan ai-engine:neo4j-kb-build --profiles-limit=25 --entity-limit=25
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

## Agent Capability Memory

Capability memory stores what an agent can do, not business records. Use it when a host app needs semantic routing over available tools, CRUD actions, modules, relations, and query surfaces before deciding whether to call deterministic tools, RAG, or the LLM.

Package-owned primitives:

- `LaravelAIEngine\Contracts\AgentCapabilityProvider`
- `LaravelAIEngine\DTOs\AgentCapabilityDocument`
- `LaravelAIEngine\Services\Agent\AgentCapabilityRegistry`

Host apps own the domain provider and vector sync command. A typical provider reads app-specific registries such as actions, model catalogs, and tool configs, then returns compact capability documents:

```php
use LaravelAIEngine\Contracts\AgentCapabilityProvider;
use LaravelAIEngine\DTOs\AgentCapabilityDocument;

class BusinessCapabilityProvider implements AgentCapabilityProvider
{
    public function capabilities(): iterable
    {
        yield new AgentCapabilityDocument(
            id: 'business_action:create_invoice',
            text: 'Create invoice. Requires customer, invoice date, due date, and line items. Use prepare then execute after confirmation.',
            payload: [
                'type' => 'agent_capability',
                'capability_type' => 'business_action',
                'action_id' => 'create_invoice',
                'tools' => ['prepare_business_action', 'execute_business_action'],
            ],
            metadata: [
                'model_class' => 'agent_capability',
                'model_id' => 'business_action:create_invoice',
            ]
        );
    }
}
```

Register providers in the host app:

```php
'capability_providers' => [
    'business' => \App\AI\Capabilities\BusinessCapabilityProvider::class,
],
```

Then the host app can sync `AgentCapabilityRegistry::documents()` to Qdrant, Neo4j, Redis, or any other memory layer using its own command/service. Keep domain knowledge in the app provider; keep reusable contracts and registry behavior in this package.

## Business Action Framework

For app-wide CRUD/workflow actions, the package owns the reusable action framework and the host app owns domain services, permissions, DTOs, and database writes.

Package contracts:

- `LaravelAIEngine\Contracts\BusinessActionDefinitionProvider`
- `LaravelAIEngine\Contracts\BusinessActionExecutor`
- `LaravelAIEngine\Contracts\BusinessActionRelationResolver`
- `LaravelAIEngine\Contracts\ConversationMemory`
- `LaravelAIEngine\Contracts\AgentCapabilityProvider`

Package services:

- `LaravelAIEngine\Services\BusinessActions\BusinessActionRegistry`
- `LaravelAIEngine\Services\BusinessActions\BusinessActionOrchestrator`
- `LaravelAIEngine\Services\Actions\ActionIntakeCoordinator`
- `LaravelAIEngine\Services\Memory\CacheConversationMemory`

Register static definitions, provider classes, and relation resolvers in the host app:

```php
'business_actions' => [
    'create_invoice' => [
        'module' => 'sales',
        'operation' => 'create',
        'required' => ['customer_id', 'items'],
        'executor' => \App\AI\Actions\CreateInvoiceExecutor::class,
    ],
],

'business_action_providers' => [
    \App\AI\Actions\SalesActionProvider::class,
],

'business_action_relation_resolvers' => [
    \App\AI\Actions\BusinessRelationResolver::class,
],
```

`BusinessActionDefinitionProvider` publishes action definitions. `BusinessActionExecutor` prepares and executes one action through app services. `BusinessActionRelationResolver` resolves or creates related records around prepare/execute. `ConversationMemory` lets package flows store pending payloads without hardcoding a storage backend.

## Action Payload Extraction

The package provides `LaravelAIEngine\Services\Actions\ActionPayloadExtractor` for the reusable AI part of action intake. It converts the latest user turn, the current draft payload, and recent conversation history into a structured payload patch using the action's `parameters` schema.

Host apps still own the domain-specific parts: action definitions, permissions, validation, relation resolution, confirmation, and database writes.

For multi-turn action intake, use `LaravelAIEngine\Services\Actions\ActionIntakeCoordinator`. It combines payload extraction, conversation-memory-backed intake payloads, draft payloads, prepare callbacks, execute-after-confirm callbacks, and relation-review hooks. Host apps pass callbacks for domain-specific merge, prepare, execute, and relation lookup behavior.

```php
use LaravelAIEngine\Services\Actions\ActionPayloadExtractor;

$patch = app(ActionPayloadExtractor::class)->extract(
    action: $actionDefinition,
    message: '5 Macbook Pro and 4 iPhone',
    currentPayload: $draftPayload,
    recentHistory: $history,
    options: [
        'instructions' => 'For invoices, split natural item phrases into line items.',
    ],
);
```

Relevant environment settings:

```env
AI_AGENT_ACTION_PAYLOAD_EXTRACTION_ENABLED=true
AI_AGENT_ACTION_PAYLOAD_EXTRACTION_MODEL=gpt-4o
AI_AGENT_ACTION_PAYLOAD_EXTRACTION_MAX_TOKENS=1400
AI_AGENT_ACTION_PAYLOAD_EXTRACTION_TEMPERATURE=0.1
```

## Agent Conversation Context Compaction

Agent chat history is compacted before persistence and prompt construction so long sessions keep useful context without sending every old turn back to the model. The package keeps recent messages verbatim, folds older messages into `metadata.conversation_summary`, and reuses that summary in conversational prompts, intent routing, and autonomous RAG context.

Default settings are conservative:

```env
AI_AGENT_CONTEXT_COMPACTION_ENABLED=true
AI_AGENT_CONTEXT_MAX_MESSAGES=12
AI_AGENT_CONTEXT_KEEP_RECENT_MESSAGES=6
AI_AGENT_CONTEXT_MAX_MESSAGE_CHARS=2000
AI_AGENT_CONTEXT_MAX_TOTAL_CHARS=12000
AI_AGENT_CONTEXT_MAX_SUMMARY_CHARS=4000
AI_AGENT_CONTEXT_SUMMARY_MESSAGE_CHARS=240
```

This memory is for conversation state only. Business records and capability documents should still be indexed through the host app's RAG, graph, or capability-memory sync pipeline.

## Search Document and Graph Contracts

Legacy compatibility methods:

- `getVectorContent()`
- `getVectorMetadata()`
- `toRAGContent()`

Preferred contracts for new work:

- `toSearchDocument()`
- `toGraphObject()`
- `getGraphRelations()`
- `getAccessScope()`
- `toRAGSummary()`
- `toRAGDetail()`
- `toRAGListPreview(?string $locale = null)`

## Ontology Packs and Live Provider Matrices

You can enable built-in ontology packs to bias relation inference toward your app domain:

```env
AI_ENGINE_GRAPH_ONTOLOGY_PACKS=project_management,messaging,crm
```

Current packs:

- `project_management`
- `messaging`
- `support`
- `crm`
- `commerce`

For broader billed live coverage in CI or scheduled validation, provide provider matrices:

```env
AI_ENGINE_LIVE_TEXT_PROVIDER_MATRIX=openai:gpt-4o-mini,openrouter:openai/gpt-4o-mini
AI_ENGINE_LIVE_AGENT_PROVIDER_MATRIX=openai:gpt-4o-mini
AI_ENGINE_LIVE_IMAGE_PROVIDER_MATRIX=openai:dall-e-3
AI_ENGINE_LIVE_VIDEO_PROVIDER_MATRIX=fal_ai:bytedance/seedance-2.0/text-to-video
AI_ENGINE_LIVE_TTS_PROVIDER_MATRIX=eleven_labs:eleven_multilingual_v2
AI_ENGINE_LIVE_TRANSCRIBE_PROVIDER_MATRIX=openai:whisper-1
```

Graph retrieval now prefers matched chunk context plus `entity_ref` and `object` payloads for follow-ups and UI reuse.

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

For consistent TTS per saved character, store `voice_id` and optional ElevenLabs voice settings when you save the character, then call `/api/v1/ai/generate/tts` with `use_character` or `use_last_character`.

Authenticated calls are credit-enforced (same policy as chat/RAG), including image/audio endpoints.

When direct requests omit `engine`, the package can resolve the provider from the requested model and configured availability. By default it prefers the model's native provider first, then OpenRouter-compatible fallbacks. Tune this with `AI_ENGINE_REQUEST_PROVIDER_PRIORITY`.

For text generation you can also omit both `engine` and `model` and send a simple preference like `cost`, `speed`, `performance`, or `quality`. The package resolves a suitable model/provider first, then applies the normal credit checks against the final route.

Toggle/prefix with env:

```env
AI_ENGINE_GENERATE_API_ENABLED=true
AI_ENGINE_GENERATE_API_PREFIX=api/v1/ai/generate
AI_ENGINE_REQUEST_PROVIDER_PRIORITY=native;openrouter;anthropic;gemini;deepseek;ollama
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
5. `guides/capability-memory`
6. `guides/graph-relation-modeling`
7. `guides/knowledge-base-security`
8. `guides/direct-generation-recipes`
9. `guides/entity-list-preview-ux`
10. `guides/data-collectors`
11. `guides/rag-indexing`
12. `guides/graph-rag-neo4j`
13. `guides/end-to-end-graph-walkthrough`
14. `guides/copy-paste-playbooks`
15. `guides/multi-app-federation`
16. `guides/neo4j-ops-runbook`
17. `guides/policy-learning`
18. `guides/testing-playbook`
19. `guides/troubleshooting`

## Upgrading Existing Installs

If config was published before recent refactors, refresh it:

```bash
php artisan vendor:publish --tag=ai-engine-config --force
php artisan optimize:clear
```

See `docs-site/reference/upgrade.mdx` for the upgrade checklist and removed-class migration notes.

For central graph migration and operations, use:

- `docs-site/reference/qdrant-to-neo4j-migration.mdx`
- `docs-site/guides/neo4j-ops-runbook.mdx`
- `docs-site/guides/knowledge-base-security.mdx`

## License

MIT
