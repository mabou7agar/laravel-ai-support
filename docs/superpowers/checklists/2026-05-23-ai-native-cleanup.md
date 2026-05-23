# AI Native Cleanup Checklist

Goal: reduce package complexity by deleting superseded AI-native compatibility paths and keeping only the current reusable runtime surfaces.

## Scope

- [x] Audit outdated RAG/action/legacy references and large AI-native services.
- [x] Split `AiNativeSuggestedToolContinuation` into focused collaborators.
- [x] Keep suggested-tool behavior generic: no invoice/customer/product special cases.
- [x] Preserve existing AI-native runtime behavior.
- [x] Update docs and config so removed compatibility paths are not advertised.
- [x] Split large AI-native policy classes into focused collaborators.
- [x] Remove the old `ConversationService` alias after `ConversationTranscriptService` became the explicit transcript API.
- [x] Move env-backed package defaults out of `src/` into config so runtime source files never call `env()` directly.
- [x] Run focused AI-native tests.
- [x] Run the full package test suite.

## Cleanup Decisions

- Removed the legacy package-coded `SkillFlowRunner` path after AI-native skill execution became the only supported `run_skill` behavior.
- Removed old action-draft workflow tools from the built-in registry; concrete tools and `ActionBackedTool` are the supported write-capability path.
- Removed the old `ConversationService` compatibility alias; package internals now depend on `ConversationTranscriptService` directly.
- Split `AiNativeSkillPolicy` into final-tool and payload-resolution collaborators.
- Split `AiNativeLookupPolicy` into tool-classification, label-resolution, and ask-detection collaborators.
- Moved the full default config tree into `config/ai-engine-defaults.php`; `AIEngineConfigDefaults` now only loads that config file.
- Do not remove RAG services; current docs and routing still use RAG, GraphRAG, and hybrid retrieval.
- Action-core compatibility helpers and collector services were removed in the breaking cleanup; current concrete tools use `ActionOrchestrator`, `ActionBackedTool`, and AI-native skills.

## Follow-Up Candidates

- Keep structured collection modeled as AI-native skills with target JSON, relation metadata, and declared tools.
