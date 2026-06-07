---
name: laravel-ai-engine
description: >-
  Build and extend AI features with the laravel-ai-engine package (m-tech-stack/laravel-ai-engine,
  namespace LaravelAIEngine\). Use when a project depends on this package and the task involves
  creating agent tools or skills, wiring any model/engine (OpenAI, OpenRouter, Anthropic, Gemini,
  fal, Stable Diffusion, etc.), relating records, embeddings/RAG, image or video generation,
  characters/personas & sub-agents, file intake (upload→extract→suggest→pre-fill), tool-context
  scaling, structured data & analytics, memory & conversations, streaming & realtime,
  observability/tracing, billing/credits, graph (Neo4j) RAG, voice/TTS/transcription, provider
  tools & MCP, multi-tenancy & scope, learning, model council, structured collection flows,
  admin/catalog/diagnostics, or federation (nodes) & security. Triggers on files using
  LaravelAIEngine\ classes, config/ai-agent.php, config/ai-engine.php, app/AI/Tools, or app/AI/Skills.
---

# Building with laravel-ai-engine

A map of every capability with its **entry-point class/config** and a minimal, correct recipe.
For exhaustive examples and options, read **`docs/cookbook.mdx`** in this repo — it's the full,
source-grounded guide. Everything below references real `LaravelAIEngine\…` classes.

## Ground rules
- **Verify before you write.** Read the actual class in `src/` (or `docs/cookbook.mdx`) before
  using an API — do not invent methods/config. If a capability doesn't exist, say so.
- **Tools are the unit of action**; **skills** orchestrate multi-step flows over tools; the
  **AiNative runtime** is the planner that calls them.
- **Opt-in & safe by default**: new surfaces are gated by config; data access is fail-closed
  (scope required unless a model is `'public' => true`).
- **Tests**: cover tool/skill behavior deterministically (no live LLM in CI — mock
  `AIEngineService`); keep live model/vision calls to manual runs.

## Creating tools
- Extend `LaravelAIEngine\Services\Agent\Tools\AgentTool` — implement `getName`, `getDescription`,
  `getParameters`, `execute(array $params, UnifiedActionContext $ctx): ActionResult`. Optional:
  `requiresConfirmation()`, `getConfirmationMessage()`, `validate()`, `getToolKind()`.
- For less boilerplate, extend `SimpleAgentTool` (declare `$name`/`$description`/`$parameters`,
  implement `handle()`).
- Register in `config/ai-agent.php` → `'tools' => ['my_tool' => MyTool::class]`.
- **Model CRUD without writing tools**: `AiResource::for(Model::class)->search([...])->writable([...])
  ->identity(['email'])->with(['items'])->detailOnly()->register()` builds
  `find_`/`create_`/`show_` tools. Or declare under `config/ai-agent.php` → `'resources'`.
- Built-ins: `data_query`, `aggregate_data`, `analyze_file`, `search_knowledge`, `find_tools`,
  `run_skill`, `run_sub_agent`.

## Creating skills
- Extend `LaravelAIEngine\Services\Agent\AgentSkill`: set `$id`, `$name`, `$description`,
  `$triggers` (keywords — keep them write-specific; don't add read-ambiguous ones like
  "invoice products"), `$requiredData`, `$capabilities`, and a final tool via
  `$skill->final(CreateXTool::class)->confirmTerms([...])`.
- Skills are discovered/registered via `AgentSkillRegistry`; matched by `AgentSkillMatcher`
  (stopword-stripped keyword match). Run with the `run_skill` tool.
- For a final tool that must link a related record, resolve it deterministically (see Relating).

## Using any model & engine
- Engines live in `config/ai-engine.php` → `engines` (api_key per engine). Default engine:
  `ai-engine.default`; planner model: `ai-engine.orchestration_model`.
- Per request: `app(AIEngineService::class)->generate(new AIRequest(prompt: '…', engine: 'openai',
  model: 'gpt-4o'))`. Use `generateDirect()` to skip failover.
- **Failover**: `ai-engine.error_handling.fallback_engines.<engine>`; keyless engines are skipped
  automatically. Diagnose with `php artisan ai:doctor` (per-engine key health).

## Relating records
- Deterministic find-or-create: `use ResolvesAgentRelations;` then declare `relations()` with
  `['field'=>'customer_id','model'=>Customer::class,'identity'=>['email'],
  'map'=>['email'=>'customer_email','name'=>'customer_name'],'create'=>['name','email']]`, and call
  `$this->resolveRelations($params, $ctx)` in `execute`. Email (identity) is the source of truth.
- Relation-aware detail for the agent: `AiResource::for(Invoice::class)->with(['items'=>[...]])
  ->detailOnly()` → a `show_invoice` returning the record + its line items.

## Embeddings & RAG
- `EmbeddingService` (`embed`, `cosineSimilarity`, fake mode for tests). Vector store via
  `VectorDriverManager`; index/search with `VectorSearchService` (`index`, `indexBatch`,
  `deleteFromIndex`). Add the `Vectorizable` trait to a model (`getVectorContent`/`getVectorMetadata`).
- Agent retrieval is the `search_knowledge` tool; CLI indexing via `php artisan ai:vector-index`.

## Images (generate + understand)
- Generate via an image engine/model on `AIRequest` (Stable Diffusion, fal, DALL·E/gpt-image,
  ComfyUI — see `src/Drivers/`).
- Understand/OCR an uploaded image with `FileAnalysisService` (`analyzeFile` / `extractImageText`,
  gpt-4o vision).

## Video
- The package supports video generation (FAL Seedance/Kling, Replicate WAN) via
  `Services\Media\GenerateVideoService` / `VideoService` and `EntityEnum` cases like
  `FAL_SEEDANCE_2_TEXT_TO_VIDEO`. Submit async + poll, or generate direct. See the cookbook's
  "Generating Video" section.

## Characters, personas & sub-agents
- Declare sub-agents in `config/ai-agent.php` → `sub_agents` (id, capabilities, `handler`:
  `ConversationalSubAgentHandler` or `ToolCallingSubAgentHandler`). The agent delegates via the
  `run_sub_agent` tool / `SubAgentRegistry`. Give a persona via the sub-agent's description/system
  prompt.

## File intake (upload → extract → suggest → pre-fill)
- Enable `ai-engine.file_analysis.enabled`; map document keywords to create actions in
  `keyword_suggestions` (pattern → any `create_*`). The `analyze_file` tool extracts a stored
  upload's text (sandboxed to `base_path`) and suggests the create action.
- `'prefill' => true` adds `StructuredFileExtractor` to pre-fill each suggestion's fields (from the
  create tool's own parameters); images are OCR'd by the vision model first. Generic across entities.

## Scaling tool context
- `ai-agent.ai_native.tool_selection`: `strategy` = `all` | `skill_scoped` | `keyword` | `semantic`,
  `always` (never-trimmed core), `limit`, and `disclosure: progressive` (the `find_tools` meta-tool).
  Reach for embeddings (`semantic`) last; `skill_scoped` is the safe first step.

## Structured data & analytics
- `data_query` answers count/list/status·value·date filters; `aggregate_data` does
  sum/avg/min/max/top/bottom/group_by/count-distinct with metric-aware cross-entity routing.
- Declare models in `config/ai-engine.php` → `data_query.models` with `aggregatable`/`groupable`/
  `metric_aliases`; `'public' => true` for unscoped (catalog) models, otherwise scope is required.

## Memory & conversations
- Transcript history: `ConversationManager` / `ConversationTranscriptService`. Pluggable storage:
  `MemoryManager` (drivers: database/redis/file/mongodb). Durable scoped facts:
  `ConversationMemoryRepository` (+ optional semantic index). Config under `ai-agent.*memory*`
  (e.g. `AI_AGENT_CONVERSATION_MEMORY_ENABLED`, `AI_AGENT_MEMORY_SEMANTIC_ENABLED`).

## Streaming & realtime
- Stream responses (SSE). Realtime voice/tool sessions via `Services\SDK\RealtimeSessionService`
  + `RealtimeToolBrokerService` (route `api/v1/ai/realtime/sessions`). See `realtime-observability.mdx`.

## Observability & tracing
- `Services\SDK\TraceRecorderService`, `ObservabilityExporterService`; events
  `AIRequestStarted`/`AIRequestCompleted`/`AIFailoverTriggered`; run-trace API.

## Billing, credits & pricing
- `CreditManager` (`hasCredits`/`deductCredits`/`calculateCredits`/`accumulate`),
  `InsufficientCreditsException`, per-model `credit_index`. Enable with `ai-engine.credits.enabled`.

## Graph & hybrid (Neo4j) RAG
- `Services\Graph` Neo4j driver + hybrid graph/vector retrieval; models expose
  `getSourceNode()`/`getAccessScope()`/`toGraphObject()`. Fail-closed via `graph.require_access_scope`.

## Voice, TTS & transcription
- `Services\Media\AudioService::transcribe()` / `GenerateAudioService`; TTS engines
  (`eleven_labs`, `google_tts`, local voice). `Transcription` model is polymorphic (`morphTo`);
  add your own `morphMany(Transcription::class, 'transcribable')` — there is no trait.

## Provider tools & MCP
- `Services\ProviderTools` (run/approval/audit services) and `Services\SDK\McpAppToolAdapter` +
  `ProviderToolPayloadMapper`. Config under `ai-engine.provider_tools.*`.

## Multi-tenancy & scope
- `Services\Scope\AIScopeOptionsService` + the `AIScopeResolver` contract; `scope_columns`
  (`user_id`/`workspace_id`/`tenant_id`) flow into data_query, RAG, vector, and memory. Scope is
  fail-closed (unscoped non-`public` access is refused).

## Learning & design generation
- `Services\Learning` (`LearningService`, `LearningExtractorService`, `LearnedDesignGeneratorService`);
  the `search_learned_context` tool; `php artisan` learning commands. See `learning.mdx`.

## Model council
- `api/v1/agent/council` (`ModelCouncilApiController::run`) — multiple models answer/compare.

## Structured collection flows
- `StructuredCollectionDefinition` + `StructuredCollectionSessionService` (multi-turn guided
  collection: `addText`/`addEmail`/`list`, summarize, confirm). See `structured-collection.mdx`.

## Admin, catalog, diagnostics & SDK
- `Services\Catalog\EngineCatalogService` (introspect engines/models), `php artisan ai:doctor` +
  `AgentManifestDoctor`, `Services\Admin`, `Services\SDK` (`sdk-compatibility.mdx`).

## Federation (nodes) & security
- Optional `laravel-ai-engine-federation` package: `AINode`, `NodeAuthService`, the `route_to_node`
  tool, `ai-engine.nodes` config. Security model: fail-closed scope, IDOR protections — see `security.mdx`.

## Full reference
`docs/cookbook.mdx` — the complete, source-grounded guide to all of the above with copy-pasteable
examples and every config flag.
