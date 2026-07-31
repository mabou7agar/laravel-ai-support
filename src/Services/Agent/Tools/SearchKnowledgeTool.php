<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;

/**
 * Grounded knowledge-base retrieval for the AiNative runtime.
 *
 * AiNative is a pure tool-calling loop with no built-in access to the vector /
 * document RAG store. This tool exposes the SAME retrieval pipeline the classic
 * SEARCH_RAG path uses (AgentConversationService::executeSearchRAG), so the runtime
 * can ground answers in indexed knowledge. It is also what a force_rag turn calls:
 * AiNativePromptBuilder instructs the planner to call this first when the caller set
 * force_rag (see the force_rag directive there).
 */
class SearchKnowledgeTool extends SimpleAgentTool
{
    public string $name = 'search_knowledge';

    public string $description = 'Semantic search over the project knowledge base (RAG / vector store): retrieves relevant passages from indexed documents and text to ground an answer. Use for open-ended "what/why/how/about" questions or when you are unsure of a fact. For exact counts, lists, or filters of structured records, use the data_query tool instead.';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $parameters = [
        'query' => ['type' => 'string', 'required' => true, 'description' => 'What to look up in the knowledge base.'],
        'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum number of results to retrieve (1-50).'],
    ];

    public function __construct(protected AgentConversationService $conversation)
    {
    }

    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.kb_query_required', 'A non-empty "query" is required to search the knowledge base.'));
        }

        $options = array_filter([
            'force_rag' => true,
            'use_rag' => true,
            'rag_collections' => $this->scopeOption($context, 'rag_collections'),
            'search_instructions' => $this->scopeOption($context, 'search_instructions'),
            'tenant_id' => $this->scopeOption($context, 'tenant_id'),
            'workspace_id' => $this->scopeOption($context, 'workspace_id'),
            'subscription_active' => $this->scopeOption($context, 'subscription_active'),
        ], static fn (mixed $value): bool => $value !== null);
        if (isset($parameters['limit']) && is_numeric($parameters['limit'])) {
            $options['limit'] = max(1, min(50, (int) $parameters['limit']));
        }

        // executeSearchRAG can decide a request is really a CRUD action and "exit to
        // orchestrator" via the reroute callback. Inside a tool there is no orchestrator
        // to re-enter, so we return a benign note instead and let the runtime's own
        // action tools handle the write on a later step.
        $response = $this->conversation->executeSearchRAG(
            $query,
            $context,
            $options,
            static fn (string $rerouteMessage, $sessionId, $userId, array $rerouteOptions): AgentResponse => AgentResponse::conversational(
                'The knowledge base did not hold a direct answer; this request may need an action tool instead of retrieval.',
                $context
            )
        );

        $text = trim((string) $response->message);
        if ($text === '') {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.kb_no_result', 'The knowledge base returned no usable result for this query.'));
        }
        $metadata = is_array($response->metadata ?? null) ? $response->metadata : [];
        if (array_key_exists('rag_result_count', $metadata)
            && (int) $metadata['rag_result_count'] === 0) {
            return ActionResult::failure($text);
        }

        return ActionResult::success($text, [
            'query' => $query,
            'metadata' => $this->metadataForPlanner($metadata),
        ]);
    }

    /**
     * Keep the planner-facing knowledge result bounded.
     *
     * RAG response metadata can contain complete vector payloads, chunk text,
     * entity snapshots, and graph links in both `sources` and `citations`.
     * Replaying that data inside the AiNative state duplicates context which is
     * already represented by the grounded answer text and can grow one planner
     * step into hundreds of kilobytes. The host still receives the original RAG
     * response through its normal response/event path; only this internal tool
     * result is projected to citation-safe fields.
     *
     * Set ai-agent.ai_native.knowledge_tool_compact_metadata=false to preserve
     * the legacy full metadata payload.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function metadataForPlanner(array $metadata): array
    {
        if (! (bool) config('ai-agent.ai_native.knowledge_tool_compact_metadata', true)) {
            return $metadata;
        }

        $projected = $metadata;
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['sources', 'citations'], true)) {
                $projected[$key] = $this->citationSummaries($value);
            }
        }

        return array_filter(
            $projected,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function citationSummaries(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $summaries = [];
        foreach (array_slice(array_values($entries), 0, 8) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $summary = array_filter([
                'type' => $this->scalarValue($entry['type'] ?? $entry['citation_type'] ?? null),
                'title' => $this->scalarValue($entry['title'] ?? $entry['citation_title'] ?? null),
                'url' => $this->scalarValue($entry['url'] ?? $entry['citation_url'] ?? null),
                'source_id' => $this->scalarValue($entry['source_id'] ?? $entry['id'] ?? null),
                'scope_type' => $this->scalarValue(
                    $entry['scope_type'] ?? data_get($entry, 'metadata.scope_type'),
                ),
                'scope_label' => $this->scalarValue(
                    $entry['scope_label'] ?? data_get($entry, 'metadata.scope_label'),
                ),
            ], static fn (?string $value): bool => $value !== null);

            if ($summary !== []) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    private function scalarValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value !== '' ? mb_substr($value, 0, 500) : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (array_unique(array_filter([
            function_exists('app') && app()->bound('translator') ? app()->getLocale() : null,
            'en',
            'ar',
        ])) as $locale) {
            if (array_key_exists($locale, $value)) {
                $localized = $this->scalarValue($value[$locale]);
                if ($localized !== null) {
                    return $localized;
                }
            }
        }

        foreach ($value as $candidate) {
            $scalar = $this->scalarValue($candidate);
            if ($scalar !== null) {
                return $scalar;
            }
        }

        return null;
    }

    private function scopeOption(UnifiedActionContext $context, string $key): mixed
    {
        return $context->requestOptions[$key]
            ?? $context->metadata[$key]
            ?? null;
    }
}
