<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG\Retrievers;

use LaravelAIEngine\Contracts\RAGRetrieverContract;
use LaravelAIEngine\Contracts\ScopedKnowledgeIndex;
use LaravelAIEngine\DTOs\RAGCitation;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\DTOs\ScopedKnowledgeMatch;
use LaravelAIEngine\Services\Knowledge\KnowledgeSourceRegistry;

/**
 * Bridges host KnowledgeSourceProvider documents into the standard RAG path.
 *
 * Scope filtering happens in KnowledgeSourceRegistry before indexing, so a
 * private document never reaches scoring for another tenant/workspace/user.
 */
final class ScopedKnowledgeRAGRetriever implements RAGRetrieverContract
{
    public function __construct(
        private readonly KnowledgeSourceRegistry $sources,
        private readonly ScopedKnowledgeIndex $index,
    ) {
    }

    public function name(): string
    {
        return 'scoped_knowledge';
    }

    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array
    {
        if (!(bool) config('ai-agent.assistant.knowledge_index.rag_enabled', true)) {
            return [];
        }

        $context = array_filter([
            'tenant_id' => $options['tenant_id'] ?? $options['tenant'] ?? null,
            'workspace_id' => $options['workspace_id'] ?? $options['workspace'] ?? null,
            'user_id' => $userId,
            'subscription_active' => $options['subscription_active'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $documents = $this->sources->documents($context);
        if ($documents === []) {
            return [];
        }

        $limit = max(1, min(
            50,
            (int) ($options['limit'] ?? config('ai-agent.assistant.knowledge_index.limit', 8)),
        ));
        $sources = [];
        $seen = [];
        foreach ($queries as $query) {
            foreach ($this->index->search($documents, (string) $query, $limit) as $match) {
                if (isset($seen[$match->document->id])) {
                    continue;
                }
                $seen[$match->document->id] = true;
                $sources[] = $this->source($match);
                if (count($sources) >= $limit) {
                    break 2;
                }
            }
        }

        return $sources;
    }

    private function source(ScopedKnowledgeMatch $match): RAGSource
    {
        $document = $match->document;
        $title = $this->metadataString($document, 'title') ?: $document->id;
        $url = $this->metadataString($document, 'url');

        return new RAGSource(
            type: 'scoped_knowledge',
            content: mb_substr(
                $document->text,
                0,
                max(500, (int) config('ai-agent.assistant.knowledge_index.max_content_chars', 6000)),
            ),
            id: $document->id,
            title: $title,
            score: $match->score,
            metadata: [
                ...$document->metadata,
                'knowledge_scope' => $document->scope->value,
                'tenant_id' => $document->tenantId,
                'workspace_id' => $document->workspaceId,
                'user_id' => $document->userId,
            ],
            citations: [RAGCitation::fromArray([
                'type' => 'scoped_knowledge',
                'title' => $title,
                'url' => $url !== '' ? $url : null,
                'source_id' => $document->id,
                'metadata' => ['knowledge_scope' => $document->scope->value],
            ])],
        );
    }

    private function metadataString(ScopedKnowledgeDocument $document, string $key): string
    {
        $value = $document->metadata[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
