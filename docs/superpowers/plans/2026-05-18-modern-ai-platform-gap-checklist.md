# Modern AI Platform Gap Checklist

> Checklist from the May 2026 provider-doc audit. Do not mark an item complete until implementation and focused tests pass.

## Provider Adaptability

- [x] Add generic provider options to normal `AIRequest` flows, not only realtime descriptors.
- [x] Pass provider options through OpenAI, Anthropic, Gemini, OpenRouter, and FAL-compatible request builders without hardcoding every new provider field.
- [x] Add tests proving provider-specific and generic options merge without leaking internal `provider_options` wrapper keys.

## OpenAI Responses State

- [x] Support OpenAI Responses state fields such as `previous_response_id`, `background`, `include`, and continuation metadata.
- [x] Remember the last OpenAI response id per provider conversation when explicitly requested.
- [x] Reuse remembered OpenAI response ids on later turns when requested.

## Provider Tool Versions

- [x] Move Anthropic hosted-tool beta headers and tool type versions to configuration.
- [x] Keep current defaults compatible with existing behavior.
- [x] Add tests for code execution, computer use, and MCP configurable versions.

## AgentChat Engine Validation

- [x] Replace the hardcoded `openai,anthropic,gemini` AgentChat engine validation with registry/config-backed validation.
- [x] Add API coverage proving supported non-default engines such as OpenRouter are accepted.

## Realtime Tool Broker

- [x] Add a package-level realtime tool broker that can dispatch provider realtime tool calls through registered tools.
- [x] Return structured tool-result payloads that host WebSocket/WebRTC clients can send back to providers.
- [x] Respect tool confirmation requirements before executing realtime tool calls.

## MCP / App Adapter

- [x] Add an adapter that exports registered tools and skills in an MCP/App-compatible schema.
- [x] Add a generic call path for exported local tools.

## OpenRouter Routing Hardening

- [x] Add provider-routing passthrough for allow/deny/order, data collection, required parameters, max price, latency, and sort policy.
- [x] Keep free/cheapest cost optimization compatible with the existing model fallback behavior.

## Observability Exporters

- [x] Add generic observability exporter hooks for traces and evaluations.
- [x] Keep internal trace/evaluation storage as source of truth when no exporter is configured.
- [x] Add concrete HTTP, OpenTelemetry OTLP, LangSmith, and log exporters.

## HTTP Integration Surfaces

- [x] Expose registered tools and skills through `/api/v1/ai/mcp/tools`.
- [x] Expose MCP/App tool invocation through `/api/v1/ai/mcp/tools/{tool}/call`.
- [x] Expose realtime provider tool dispatch through `/api/v1/ai/realtime/tools/dispatch`.
- [x] Add Bruno examples for MCP/App and realtime tool integration endpoints.

## Live Validation Hardening

- [x] Add an opt-in live OpenAI Responses continuation smoke test.
- [x] Guard duplicate `ai_job_statuses` migration execution when a host app already has a published migration.
- [x] Treat unreachable root-app Neo4j Query API as a skipped optional live stage instead of a hard command failure.

## Verification

- [x] Focused unit/feature tests pass.
- [x] Full Unit+Feature suite passes.
- [x] `git diff --check` passes.
- [x] New HTTP endpoints pass package feature tests.
- [x] New HTTP endpoints pass demo-app curl checks.
- [x] Billed provider live matrix passed for available configured providers.
