# Agent Config Minimal Guide

## What you usually need to configure

Most Laravel apps only need these keys in `config/ai-agent.php`:

1. `entity_model_map`
2. `intent`
3. `orchestrator`

Everything else is optional and already has package defaults.

## Optional sections (only if needed)

- `model_config_discovery`: custom AI config discovery paths/namespaces.
- `ai_first`: global behavior profile (`ai_first.profile` = `balanced` or `strict_ai_first`) plus optional explicit override (`ai_first.strict`).
- `routed_session`: tune cross-node continuation behavior (AI-first; explicit topic checks optional; fallback behavior is profile-driven in strict mode).
- `followup_guard`: tune AI follow-up classification limits (fallback behavior is profile-driven in strict mode).
- `positional_reference`: tune AI positional resolution limits (fallback behavior is profile-driven in strict mode).
- `autonomous_rag`: tune function-calling mode, parse-failure fallback policy, and optional routing prompt override for `AutonomousRAGAgent`.
- `policy`: override fallback messages/intents (`policy.messages`) and safety limits (`policy.limits`).
- `conversational`: tune fallback conversational generation settings.
- `protocol`: optional wire-label overrides for AI parser prompts.
- `enabled`, `default_strategy`, `complexity_thresholds`, `strategy_overrides`, `cache`:
  legacy strategy-mode controls; not required for minimal orchestrator path.
- `workflow_directories`, `workflows`: workflow discovery/legacy subworkflow support.
- `agent_mode`, `tools`: advanced/legacy agent-mode behavior.

## Recommendation

Start with defaults and only override keys after you observe concrete behavior gaps in logs/tests.
