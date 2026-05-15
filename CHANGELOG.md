# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added

- **PexelsEngineDriver** — new image-search driver backed by the Pexels API, with `PEXELS` constant in `EngineEnum` and `PEXELS_SEARCH` support wired through `EntityEnum`.
- **Text-to-speech provider coverage** — OpenAI now supports speech generation models (`gpt-4o-mini-tts`, `tts-1`, `tts-1-hd`) and Google Cloud Text-to-Speech is available through a dedicated `google_tts` driver.
- **HTTP health-check endpoint** — `GET /api/v1/ai/health` returns a structured package health payload consumable by load balancers and uptime monitors.
- **Anthropic model additions to EntityEnum**:
  - `CLAUDE_3_5_SONNET_20241022` (`claude-3-5-sonnet-20241022`) — Oct 2024 snapshot of Claude 3.5 Sonnet.
  - `CLAUDE_3_7_SONNET` (`claude-3-7-sonnet-20250219`) — Claude 3.7 Sonnet (Feb 2025).
  - `CLAUDE_SONNET_4_5` (`claude-sonnet-4-5`) — Claude Sonnet 4.5.
  - `CLAUDE_OPUS_4_5` (`claude-opus-4-5`) — Claude Opus 4.5.
  - `CLAUDE_HAIKU_4_5` (`claude-haiku-4-5-20251001`) — Claude Haiku 4.5.
  - `CLAUDE_SONNET_4_6` (`claude-sonnet-4-6`) — Claude Sonnet 4.6.
  - `CLAUDE_OPUS_4_6` (`claude-opus-4-6`) — Claude Opus 4.6.
- **Google Gemini model additions to EntityEnum**:
  - `GEMINI_2_0_FLASH` (`gemini-2.0-flash`) — Gemini 2.0 Flash.
  - `GEMINI_2_0_FLASH_LITE` (`gemini-2.0-flash-lite`) — Gemini 2.0 Flash Lite.
  - `GEMINI_2_5_PRO` (`gemini-2.5-pro-preview-05-06`) — Gemini 2.5 Pro preview.
  - `GEMINI_2_5_FLASH` (`gemini-2.5-flash-preview-04-17`) — Gemini 2.5 Flash preview.
- **AIEngineFake assertion helper** — added `assertTemperatureUsed()` alongside the existing prompt, image, video, embedding, stream, model, engine, request-count, and last-request assertions.
- **Pest bootstrap** — `pestphp/pest` is declared for Laravel teams that prefer Pest syntax, while the existing PHPUnit suite remains supported.
- **Token estimation profiles** — `TokenCalculator` now detects latin, code, and CJK text profiles and exposes configurable character-per-token ratios through `ai-engine.token_estimation.profiles`.
- **Node and chunking coverage** — added dedicated tests for `NodeHttpClient` header forwarding and `ContentChunker` token-budget behavior.

### Fixed

- **Broken facade auto-alias** — removed the stale `AIEngine` auto-alias that pointed to a non-existent facade class. The supported facade remains `LaravelAIEngine\Facades\Engine`.
- **Duplicate migration timestamps** — `create_ai_job_statuses_table` and `create_credit_packages_table` now use unique migration timestamps, so fresh installs have deterministic ordering.
- **AIEngineFake safe surface** — `AIEngineFake` no longer depends on real engine or memory services and overrides the public manager API used in tests.
- **OpenAI client resolution without a key** — OpenAI-backed services can now be resolved without `OPENAI_API_KEY`; a clear error is thrown only when an OpenAI resource is actually used.
- **Vectorizable save-time API guard** — AI-based field auto-detection is restricted to explicit indexing context, preventing ordinary model saves from triggering unexpected AI calls.
- **`vectorChatStream()` provider streaming** — the method now builds RAG context synchronously, then forwards provider stream chunks through the callback instead of returning one buffered response.

### Changed

- **`EngineEnum` is now a native PHP 8.1 backed enum** while preserving string constants for config and array-key usage.
- **Published config is annotated** instead of being a minimal delegation file, so apps get visible defaults for engines, token estimation, vector settings, cache, credits, and debug toggles.
- **Strict typing is enabled across source files** with `declare(strict_types=1);`.
- **Token calculations no longer use a single hardcoded characters-per-token heuristic** in base drivers, vector chunking, embedding fallback accounting, and vectorization truncation.

### Removed

- **Legacy collector public surface** — removed the standalone data-collector/autonomous-collector HTTP routes, controllers, facades, collector scaffold option, and collector discovery/test commands. New application flows should use agent chat with skills and tools.

### Notes

- `EntityEnum` intentionally remains a dynamic model value object because synced provider catalogs and database-backed models can contain arbitrary provider/model IDs that PHP native enums cannot represent as runtime cases.

---

## [1.5.0] — 2026-04-11

### Added

- **AI Media table** (`ai_media`) — stores provider-generated images, video, and audio outputs with owner polymorphic reference, provider metadata, and expiry tracking.
- **AI Reference Packs** (`ai_reference_packs`) — store curated look/style references for image generation pipelines; supports `strict_stored`, `guided`, `vendor`, and `strict_selected_set` look modes.
- **Video generation service** — `VideoService` wraps FAL AI Kling, Luma, and Seedance 2.0 video generation behind a unified interface.
- **Media embedding service** — `MediaEmbeddingService` extracts visual embeddings from images and video frames for multimodal RAG.
- **`HasMediaEmbeddings` trait** — Eloquent models can use this trait to store and retrieve media embeddings alongside text embeddings.
- **FAL AI model additions**: `FAL_NANO_BANANA_2`, `FAL_NANO_BANANA_2_EDIT`, `FAL_KLING_O3_IMAGE_TO_VIDEO`, `FAL_KLING_O3_REFERENCE_TO_VIDEO`, `FAL_SEEDANCE_2_TEXT_TO_VIDEO`, `FAL_SEEDANCE_2_IMAGE_TO_VIDEO`, `FAL_SEEDANCE_2_REFERENCE_TO_VIDEO`.
- **Gemini generative media models**: `GEMINI_IMAGEN_4`, `GEMINI_IMAGEN_4_FAST`, `GEMINI_VEO_3_1`, `GEMINI_VEO_3_1_FAST`, `GEMINI_LYRIA_002`.

---

## [1.4.0] — 2026-03-06

### Added

- **Prompt policy versioning** (`ai_prompt_policy_versions`) — stores versioned prompt policy documents with hash-based change detection; enables audit trails for regulated use cases.
- **Prompt feedback events** (`ai_prompt_feedback_events`) — records user feedback (thumbs, ratings, corrections) against specific prompt/response pairs.
- **Action metrics** (`ai_action_metrics`) — captures timing, token usage, and outcome data per agent action execution for performance analytics.
- **Entity summaries** (`ai_entity_summaries`) — stores AI-generated summaries of indexed Eloquent models for fast contextual lookups.
- **Policy learning guide** — `docs-site/guides/policy-learning.mdx` documents the feedback-to-policy cycle.
- **Brand Voice Manager** — `BrandVoiceManager` service allows host apps to inject brand-voice context into prompt templates per workspace or tenant.
- **Template Engine** — `TemplateEngine` service handles variable interpolation in prompt templates with Blade-like syntax.

### Changed

- RAG service refactored into dedicated sub-services: decision service, context/state service, execution service, structured data service, and prompt policy/feedback service.

---

## [1.3.0] — 2026-01-13

### Added

- **Data collector configs and states tables** (`data_collector_configs`, `data_collector_states`) — persistent storage for guided and autonomous collector configurations and runtime state.
- **Autonomous collector controller** — HTTP endpoint to trigger, pause, resume, and query autonomous data collectors.
- **DataCollector facade** — `LaravelAIEngine\Facades\DataCollector` for clean host-app integration.
- **Entity credits on users** (`add_entity_credits_to_users`) — migration adds `ai_credits` balance column to the host app's `users` table.
- **Credit lifecycle contracts** — `CreditLifecycleInterface` and `WithCreditInterface` define charge, reserve, refund, and expiry hooks.
- **Expiring credit handler** — `ExpiringCreditHandler` manages time-bounded credit grants with configurable expiry.
- **Credit reservation table** (`ai_credit_reservations`) — optimistic credit reservation for async AI jobs to prevent over-spending.
- **`InsufficientCreditsException`** — thrown when a request exceeds the caller's available credit balance.
- **Rate limiting trait** — `HandlesRateLimiting` trait wraps driver calls with per-user and per-engine rate limit enforcement.
- **Data collector recipes guide** — `docs-site/guides/data-collector-recipes.mdx`.

---

## [1.2.0] — 2025-12-02

### Added

- **Node federation tables** (`ai_nodes`, `ai_node_requests`, `ai_node_search_cache`, `ai_node_circuit_breakers`) — persistent storage for multi-app federation topology.
- **Node description and collections columns** — `description` and `collections` added to `ai_nodes` to describe domain ownership.
- **Autonomous collectors flag** — `autonomous_collectors_enabled` column added to `ai_nodes`.
- **Circuit breaker service** — `CircuitBreakerService` tracks node error rates and opens/closes circuits automatically.
- **Failover manager** — `FailoverManager` with pluggable `FailoverStrategyInterface` for multi-provider fallback.
- **Node ownership resolver** — `NodeOwnershipResolver` routes agent actions to the owning node based on entity domain metadata.
- **Node rate limit middleware** — `NodeRateLimitMiddleware` enforces per-node request quotas.
- **Node auth middleware** — `NodeAuthMiddleware` validates JWT tokens issued between federation nodes.
- **Node dashboard and API controllers** — `NodeDashboardController`, `NodeApiController` expose federation management endpoints.
- **AI request tracking table** (`ai_request_tracking`) — fine-grained per-request lifecycle tracking with start/end times and provider metadata.
- **AI transcriptions table** (`ai_transcriptions`) — stores Whisper and other STT transcription results with speaker metadata.
- **NVIDIA NIM driver** — `NvidiaNimEngineDriver` supports `nvidia/llama-3.1-nemotron-70b-instruct`, `meta/llama-3.1-70b-instruct`, and `meta/llama-3.1-8b-instruct`.
- **Cloudflare Workers AI driver** — `CloudflareWorkersAIEngineDriver` supports FLUX Schnell, Dreamshaper, Whisper, and MeloTTS.
- **HuggingFace driver** — `HuggingFaceEngineDriver` supports FLUX Schnell, Whisper Large v3, and MMS TTS.
- **Replicate driver** — `ReplicateEngineDriver` supports FLUX Schnell and WAN image-to-video.
- **ComfyUI driver** — `ComfyUIEngineDriver` for self-hosted image and video generation workflows.
- **OpenRouter driver** — `OpenRouterEngineDriver` with support for GPT-4o, Claude, Gemini, Llama, Mixtral, Qwen, and DeepSeek models via OpenRouter.
- **Ollama driver** — `OllamaEngineDriver` for local LLM inference; supports Llama 2/3, Mistral, CodeLlama, Phi, Gemma, and others.
- **Node commands** — `ai:node-list`, `ai:node-ping`, `ai:nodes-sync`, `ai:node-cleanup` artisan commands.
- **Infrastructure health service** — `InfrastructureHealthService` aggregates database, cache, queue, and provider connectivity into a single health payload.
- **`ai:infra-health` command** — CLI output of infrastructure health check.
- **Federation guide** — `docs-site/guides/federation.mdx`.
- **Multi-app federation guide** — `docs-site/guides/multi-app-federation.mdx`.

### Changed

- `UnifiedEngineManager`, `EngineProxy`, and `AIEngineService` replace the removed `AIEngineManager` and `EngineBuilder` as the public runtime API.
- Demo routes disabled by default; enabled via `AI_ENGINE_ENABLE_DEMO_ROUTES=true`.
- Package auth helper routes disabled by default; enabled via `AI_ENGINE_AUTH_ROUTES_ENABLED=true`.

---

## [1.1.0] — 2025-11-28

### Added

- **AI models table** (`ai_models`) — database-backed model registry supporting dynamic model registration, credit-index overrides, and vision/streaming capability flags; `DynamicModelResolver` resolves unknown model IDs from this table at runtime.
- **Vector stores and documents tables** (`ai_vector_stores`, `ai_vector_store_documents`) — first-class vector store management with provider-agnostic document storage.
- **Provider tool tables** (`ai_provider_tool_runs`, `ai_provider_tool_approvals`, `ai_provider_tool_artifacts`, `ai_provider_tool_audit_events`) — structured storage for tool-call lifecycle: run initiation, human-in-the-loop approval, artifact storage, and audit.
- **Agent run tables** (`ai_agent_runs`, `ai_agent_run_steps`) — persistent agent run tracking with step-level granularity, status management, and trace fields.
- **Agent run links on provider tool tables** — foreign-key links from tool runs, approvals, artifacts, and audit events to their parent agent run.
- **Agent trace fields** — `agent_trace_id`, `agent_step_id`, and `agent_run_id` columns on `ai_provider_tool_audit_events` for end-to-end traceability.
- **Expiry on tool approvals** — `expires_at` column on `ai_provider_tool_approvals` enables time-limited human approval windows.
- **Owner fields on tool artifacts** — polymorphic owner columns on `ai_provider_tool_artifacts` for scoped artifact access.
- **AgentRunController** — REST endpoints to list, resume, and cancel agent runs.
- **ProviderToolController** — REST endpoints for tool-run lifecycle and approval resolution.
- **VectorStoreApiController** — REST endpoints for vector store and document management.
- **Admin UI** — `AdminDashboardController`, `AdminOperationsController`, `ManifestManagerController` expose an internal admin panel; enabled via `AI_ENGINE_ENABLE_ADMIN_UI=true`.
- **Engine catalog endpoint** — `EngineCatalogController` exposes available engines and models via REST for admin and tooling use.
- **Relationship analyzer** — `RelationshipAnalyzer` maps Eloquent relationships for inclusion in vector context payloads.
- **Context limitation observer** — `ContextLimitationObserver` trims conversation history when approaching model token limits.
- **Conversation sessions guide** — `docs-site/guides/conversation-sessions.mdx`.
- **Admin UI guide** — `docs-site/guides/admin-ui.mdx`.

---

## [1.0.0] — 2024-11-27

### Added

- **Vector embeddings table** (`vector_embeddings`) — stores text chunk embeddings with model, dimension, and source reference metadata.
- **Vector search logs table** (`vector_search_logs`) — records every similarity query with result counts, scores, and latency for diagnostics.
- **`Vectorizable` trait** — attach to any Eloquent model to auto-index text fields on save and enable semantic search via `vectorSearch()`.
- **`VectorizableWithMedia` trait** — extends `Vectorizable` with image and document embedding support.
- **Vectorization services** — `TokenCalculator`, `VectorizableFieldDetector`, `ContentExtractor`, `VectorContentBuilder`, `ContentChunker` support the indexing pipeline.
- **Vector driver system** — `VectorDriverManager` with `VectorDriverInterface`; includes `PineconeDriver` and `QdrantDriver` implementations.
- **Vector chunking service** — `ChunkingService` splits documents into overlapping token-bounded chunks before embedding.
- **Vector authorization service** — `VectorAuthorizationService` enforces scope-based access control on vector retrieval.
- **Vector analytics service** — `VectorAnalyticsService` tracks search usage and embedding freshness.
- **`ai:vector-index` command** — indexes all or selected Eloquent models into the vector store.
- **`ai:vector-search` command** — runs a similarity search from the CLI for diagnostics.
- **`ai:vector-status` command** — reports index coverage, staleness, and store health.
- **RAG guide** — `docs-site/guides/rag-indexing.mdx`.

---

## [0.5.0] — 2025-01-18

### Added

- **AI conversations table** (`ai_conversations`) — persistent conversation storage with title, summary, metadata, and soft deletes.
- **AI messages table** (`ai_messages`) — per-message storage with role, content, token counts, and model attribution.
- **`Message` Eloquent model** — represents a single conversation turn.
- **`AgentConversationApiController`** — REST endpoint listing user conversations with summary field resolution.
- **`AgentChatApiController`** — streaming and non-streaming chat endpoint with session continuity.
- **Memory manager** — `MemoryManager` with `RedisMemoryDriver`, `DatabaseMemoryDriver`, `FileMemoryDriver`, and optional `MongoMemoryDriver` (requires `mongodb/mongodb`).
- **Conversation context compaction** — automatic summarization of older turns when conversation history exceeds token limits.
- **Events** — `AISessionStarted`, `AISessionEnded`, `AIRequestStarted`, `AIResponseComplete`, `AIResponseChunk`, `AIStreamingError`, `AIFailoverTriggered`, `AIProviderHealthChanged`, `AIActionTriggered`.
- **Listeners** — `LogAIRequest`, `AnalyticsTrackingListener`, `StreamingLoggingListener`, `StreamingNotificationListener`, `SendWebhookNotification`.
- **Webhook manager** — `WebhookManager` dispatches signed outbound webhooks on key engine events.
- **Conversation sessions guide** — conversation inbox patterns documented.

---

## [0.4.0] — 2024-01-01 (initial public tables)

### Added

- **AI requests table** (`ai_requests`) — logs every AI request with model, tokens used, latency, and response status.
- **AI cache hits table** (`ai_cache_hits`) — records semantic cache matches to track cost savings.
- **AI errors table** (`ai_errors`) — captures provider errors, timeouts, and exceptions with full context for debugging.
- **AI analytics requests table** (`ai_analytics_requests`) — aggregated analytics layer for request volume, cost, and error-rate reporting.
- **AI job statuses table** (`ai_job_statuses`) — tracks async AI job lifecycle (pending, processing, completed, failed).
- **Credit packages table** (`credit_packages`) — defines purchasable credit bundles with pricing and validity.
- **Cache manager** — `CacheManager` wraps Laravel cache with AI-aware TTL and semantic-similarity key hashing.
- **Queued AI processor** — `QueuedAIProcessor` dispatches `ProcessAIRequestJob`, `ProcessLongRunningAITaskJob`, and `BatchProcessAIRequestsJob` for async execution.
- **`IndexModelJob`** — queue job for background vector indexing.
- **Analytics drivers** — `DatabaseAnalyticsDriver` and `RedisAnalyticsDriver` behind `AnalyticsDriverInterface`.
- **Usage report command** — `ai:usage-report` generates CSV/JSON cost and token-usage summaries.
- **Clear cache command** — `ai:clear-cache` purges AI semantic cache entries.
- **`AIEngineServiceProvider`** — auto-discovery provider registering all services, drivers, facades, commands, routes, and migrations.
- **Core engine drivers** — `OpenAIEngineDriver`, `AnthropicEngineDriver`, `GeminiEngineDriver`, `AzureEngineDriver`, `StableDiffusionEngineDriver`, `ElevenLabsEngineDriver`, `FalAIEngineDriver`, `DeepSeekEngineDriver`, `PerplexityEngineDriver`, `MidjourneyEngineDriver`, `SerperEngineDriver`, `UnsplashEngineDriver`, `PlagiarismCheckEngineDriver`.
- **OpenAI model drivers** — `GPT4ODriver`, `GPT4OMiniDriver`, `GPT35TurboDriver`, `DallE3Driver`, `DallE2Driver`, `WhisperDriver`.
- **Anthropic model drivers** — `Claude35SonnetDriver`, `Claude3HaikuDriver`, `Claude3OpusDriver`.
- **Gemini model drivers** — `Gemini15ProDriver`, `Gemini15FlashDriver`.
- **`Engine` facade** — `LaravelAIEngine\Facades\Engine` provides `engine()`, `model()`, `generate()`, `chat()`, `image()`, `audio()`, `stream()`, and `vectorChat()` entry points.
- **`EngineEnum`** — class-based engine registry with `OPENAI`, `ANTHROPIC`, `GEMINI`, `STABLE_DIFFUSION`, `ELEVEN_LABS`, `FAL_AI`, `DEEPSEEK`, `PERPLEXITY`, `MIDJOURNEY`, `AZURE`, `GOOGLE_TTS`, `SERPER`, `PLAGIARISM_CHECK`, `UNSPLASH` constants.
- **`EntityEnum`** — class-based model registry covering OpenAI GPT-4o/mini/3.5, DALL-E 2/3, Whisper, Claude 3.5 Sonnet, Claude 3 Haiku/Opus, Gemini 1.5 Pro/Flash, Stable Diffusion 3, ElevenLabs Multilingual v2, FAL FLUX Pro, DeepSeek Chat/Reasoner, Perplexity Sonar, Serper, Unsplash, Midjourney, Azure, and Google TTS.
- **`EngineDriverInterface`** and **`RAGgable`** contracts — driver contract and RAG-readiness interface.
- **`helpers.php`** — global `ai_engine()`, `ai_generate()`, and `ai_chat()` helper functions.
- **`AIEngineException`**, **`EngineNotSupportedException`**, **`ModelNotSupportedException`**, **`RateLimitExceededException`** — typed exception hierarchy.
- **DTOs** — `ExecuteActionDTO`, `ExecuteDynamicActionDTO`, `ClearHistoryDTO`, `UploadFileDTO`.
- **`GenerateApiController`** — REST endpoints for text, image, audio, and file-analysis generation.
- **`FileAnalysisApiController`** — multipart file upload and analysis endpoint.
- **`PricingController`** — credit cost preview for any model/action combination.
- **`ModuleController`** — per-module capability and configuration endpoint.
- **`HealthApiController`** — basic liveness endpoint.
- **Artisan commands** — `ai:test-package`, `ai:scaffold`, `ai:init`, `ai:list-models`, `ai:analyze-model`, `ai:usage-report`, `ai:clear-cache`.
- **Quickstart guide**, **architecture guide**, **concepts guide**, **configuration guide**, **troubleshooting guide**.
- **OpenAPI spec** — `docs-site/openapi/ai-engine.openapi.yaml`.
- Supports Laravel 8, 9, 10, 11, 12 and PHP 8.1+.

---

[Unreleased]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v0.5.0...v1.0.0
[0.5.0]: https://github.com/m-tech-stack/laravel-ai-engine/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/m-tech-stack/laravel-ai-engine/releases/tag/v0.4.0
