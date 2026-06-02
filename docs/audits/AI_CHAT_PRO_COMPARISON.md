# AI Chat Pro (MagicAI) vs Laravel AI Engine — Comparison

## What "AI Chat Pro" is in MagicAI

Not a single feature — a base `ai-chat-pro` extension plus ~11 paid marketplace add-ons layered on the base AI Chat. The extension code is **not bundled** in the distribution (`app/Extensions/` is empty); the design is reconstructed from the base `AIChatController`, marketplace registrations (`app/Domains/Marketplace/MarketplaceServiceProvider.php`), and setting/DB hooks (e.g. `ai_chat_pro_image_chat_selected_models`, `HasCreditLimit`).

## Base chat: MagicAI vs engine

| Aspect | MagicAI AI Chat | Laravel AI Engine |
|---|---|---|
| Request flow | Two-phase: `POST /chat-send` creates a message row + returns `message_id`, then `GET` streams by polling that id | Single `POST /api/v1/agent/chat` with sync/async/auto execution-mode resolution |
| Pipeline | Linear: build history → optional web-search/PDF prompt-stuffing → `OpenAI::chat()->createStreamed()` | Routing pipeline (intent → AI router → RAG decision) → dispatcher → action/tool/sub-agent → finalizer |
| Streaming | SSE `StreamedResponse`, raw text + `<br/>` substitution, `[DONE]` marker | `AgentRunSseStreamService` (durable) + broadcast fallback |
| Context window | Last 4 messages (`chat_history_limit`), no token budget | Full hydration + compaction + semantic memory |
| Storage | `user_openai_chat` / `user_openai_chat_messages` / `openai_chat_category` | `ai_conversations` / `ai_messages` |
| Model selection | `Entity` facade + `settings.openai_default_model` | `EngineEnum`/`EntityEnum` + per-request + `DriverRegistry` |
| Credits | `entity_credits` JSON, word-count | `CreditManager` (row-locked, reservations) |

The engine is a real agentic runtime; MagicAI's base chat is polished prompt-stuffing.

## Feature mapping (the 11 Pro add-ons)

| AI Chat Pro extension | Capability | Engine status | Engine evidence |
|---|---|---|---|
| `ai-chat-pro` (base) | Multi-model, guest/temp, model council | Partial (no council) | multi-model + sessions present |
| `ai-chat-pro-skills` | Selectable skills | Have | `AgentSkillRegistry`, `AgentSkillMatcher`, `SkillTools/RunSkillTool` |
| `ai-chat-pro-deep-research` | Multi-step research | Have (stronger) | `GoalAgentService`, `SubAgents/*` |
| `ai-chat-pro-file-chat` | Chat over docs | Have (stronger) | `RAGPipeline`, `FileLearningAdapter`, hybrid graph+vector |
| `ai-chat-pro-image-chat` | Vision Q&A | Have | `Media/VisionService.php` |
| `ai-chat-pro-smart-image` | Upscale/enhance | Partial | generation present; edit ops partial |
| `ai-chat-pro-memory` | Cross-chat memory | Have (stronger) | `AIConversationMemory` + semantic/lexical + scoped |
| `ai-chat-pro-folders` | Folder organization | **Have (added)** | `Conversation.folder_id`, `scopeInFolder`, `setConversationFolder`, list filter |
| `ai-chat-pro-entity-highlight` | Entity markup | Partial | `SelectedEntityContextService` tracks `entity_ids` |
| `ai-chat-pro-highlight-to-ask` | Select text → follow-up | **Have (added)** | `SendMessageDTO::composedMessage()`, `highlight_context` request field |
| `chat-pro-temp-chat` | Incognito chat | Have | `ChatService` `useMemory=false` |
| *(cross-cutting)* conversation search | Search past chats | **Have (added)** | `ConversationTranscriptService::searchUserConversations()`, `GET /conversations/search` |
| *(cross-cutting)* web search | Serper/Perplexity | Have | `Tools/Provider/WebSearch`, Serper/Perplexity drivers |

## Where each side leads

- **Engine ahead (hard stuff):** real routing/orchestration, GraphRAG, scoped semantic memory, native deep-research sub-agents, durable async runs with approval/budget/observability, multi-provider drivers (incl. Grok/Bedrock).
- **MagicAI ahead (product surface):** model council (compare N models), response entity-highlight UI, smart-image edit ops. Folders, conversation search, and highlight-to-ask were the engine-relevant gaps — now closed (see "added" rows).

## Status

The three engine-relevant gaps (conversation **search**, **folders**, **highlight-to-ask**) were implemented in Phase 7. Remaining MagicAI-only items (model council, entity-highlight markup UI, smart-image edit ops) are UI/SaaS-layer or already tracked on the image-edit backlog.
